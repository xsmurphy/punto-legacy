import { createOpenRouter } from "@openrouter/ai-sdk-provider"
import { generateObject } from "ai"
import { z } from "zod"
import { assertAiCredits, debitAiUsage, AiCreditsError } from "@/lib/ai/billing-gate"

/**
 * BFF — OCR de facturas de compra (context/32-ocr-facturas-compra.md).
 *
 *   POST /api/ocr-invoice   multipart/form-data { image: File, outletId: string }
 *
 * Patrón calcado de `app/api/agent/chat/route.ts`: mismo mecanismo de elegir
 * modelo (`/v1/ai/config`, acá capability `vision`). El gate de créditos
 * (pre-check fail-closed) y el débito (post, best-effort) están en el
 * wrapper compartido `lib/ai/billing-gate.ts` — no duplicar esa lógica acá.
 * NO Anthropic SDK — OpenRouter vía `@openrouter/ai-sdk-provider`.
 *
 * Flujo:
 *   1. Gate de créditos ANTES de gastar nada.
 *   2. Vision model de la capability `vision` (`ai_model_config`, mig 43/98).
 *   3. `generateObject` con schema estricto — campos ilegibles quedan `null`,
 *      la IA nunca inventa (instruido en el prompt).
 *   4. Débito de créditos (best-effort, no bloquea la respuesta si falla).
 *   5. Crea el borrador vía PHP (`POST /v1/purchase-drafts`) — ahí se sube la
 *      imagen a S3/DO Spaces (mismo mecanismo que `items.php`) y se persiste
 *      `extracted`. Este BFF NUNCA toca stock/finanzas ni escribe en BD
 *      directamente — el draft nace `pending`, la aprobación humana es la
 *      única puerta a `PurchasesService::create` (ver purchase-drafts.php).
 *
 * Si la extracción IA falla (error de red, JSON inválido, modelo caído), el
 * borrador se crea IGUAL con `extracted={}` + `error` seteado — así el
 * usuario no pierde la foto y puede cargar los datos a mano en la pantalla
 * de revisión, en vez de un upload que desaparece en un error 500.
 */

export const runtime = "nodejs"
export const maxDuration = 60

const ExtractionSchema = z.object({
  supplier: z.object({
    name: z.string().nullable(),
    ruc: z.string().nullable(),
  }),
  invoice: z.object({
    number: z.string().nullable(),
    timbrado: z.string().nullable(),
    date: z.string().nullable(),
    condition: z.enum(["contado", "credito"]).nullable(),
    dueDate: z.string().nullable(),
  }),
  items: z.array(
    z.object({
      description: z.string().nullable(),
      quantity: z.number().nullable(),
      unitPrice: z.number().nullable(),
      total: z.number().nullable(),
      ivaRate: z.union([z.literal(0), z.literal(5), z.literal(10)]).nullable(),
    }),
  ),
  totals: z.object({
    subtotal: z.number().nullable(),
    iva5: z.number().nullable(),
    iva10: z.number().nullable(),
    total: z.number().nullable(),
  }),
  confidence: z.number().min(0).max(1),
})

const EXTRACTION_PROMPT = `Sos un extractor de datos de facturas de compra paraguayas.
Te paso la foto de UNA factura de compra (el negocio que la recibe es el
comprador — extraé los datos del PROVEEDOR/vendedor, no del comprador).

REGLA CRÍTICA: nunca inventes ni adivines. Si un dato no se lee con
claridad en la imagen, devolvé null en ese campo. Es preferible null a un
dato incorrecto — un humano va a revisar y completar cada borrador antes de
que se registre nada.

Reglas de mapeo:
- "supplier.ruc": RUC/timbrado del EMISOR (proveedor), no del cliente.
- "invoice.number": número de factura tal como figura impreso (con guiones si los tiene).
- "invoice.timbrado": número de timbrado, si figura.
- "invoice.date": fecha de emisión en formato YYYY-MM-DD.
- "invoice.condition": 'contado' o 'credito' según lo marcado en la factura; null si no se especifica.
- "invoice.dueDate": fecha de vencimiento si es a crédito y figura, formato YYYY-MM-DD.
- "items": una entrada por cada línea de producto/servicio facturado.
- "items[].ivaRate": 0, 5 o 10 (tasas de IVA vigentes en Paraguay) según lo que indique la línea; null si no se puede determinar.
- "totals": subtotal e IVA discriminado (5% y 10%) y total general, tal como figuran impresos.
- "confidence": tu confianza global en la lectura completa, de 0 (nada legible) a 1 (perfectamente legible).

Devolvé ÚNICAMENTE el JSON con el schema pedido.`

export async function POST(req: Request) {
  const apiKey = process.env.OPENROUTER_API_KEY
  if (!apiKey) {
    return Response.json(
      { ok: false, error: { message: "OPENROUTER_API_KEY no configurada" } },
      { status: 500 },
    )
  }

  const apiUrl = process.env.API_URL ?? ""
  if (!apiUrl) {
    return Response.json(
      { ok: false, error: { message: "API_URL no configurada" } },
      { status: 500 },
    )
  }

  const cookie = req.headers.get("cookie") ?? ""

  let form: FormData
  try {
    form = await req.formData()
  } catch {
    return Response.json(
      { ok: false, error: { message: "Body inválido — se espera multipart/form-data" } },
      { status: 400 },
    )
  }

  const file = form.get("image")
  if (!(file instanceof File)) {
    return Response.json(
      { ok: false, error: { message: 'Falta archivo (campo "image")' } },
      { status: 422 },
    )
  }
  const outletId = String(form.get("outletId") ?? "")

  // Gate de créditos ANTES de procesar — wrapper compartido con el chat
  // (lib/ai/billing-gate.ts). FAIL-CLOSED: si no se puede verificar el
  // balance, no procede (antes era fail-open).
  const requestId = crypto.randomUUID()
  try {
    await assertAiCredits({ apiUrl, cookie, logPrefix: "[ocr-invoice]" })
  } catch (e) {
    if (e instanceof AiCreditsError) {
      const message = e.status === 402 ? "Sin créditos para procesar facturas con IA" : e.message
      return Response.json({ ok: false, error: { message } }, { status: e.status })
    }
    throw e
  }

  // Modelo de la capability 'vision' (ai_model_config, mig 43/98). Fallback
  // alineado al seed por si /v1/ai/config no responde.
  let modelId = "google/gemini-3.5-flash"
  try {
    const configRes = await fetch(`${apiUrl}/v1/ai/config`, { headers: { cookie } })
    if (configRes.ok) {
      const config = (await configRes.json()) as Record<
        string,
        { model: string; creditsperktoken: number }
      >
      if (config?.vision?.model) {
        modelId = config.vision.model
      }
    } else {
      console.error(`[ocr-invoice] ai/config respondió ${configRes.status}, usando default ${modelId}`)
    }
  } catch (e) {
    console.error("[ocr-invoice] fallo al leer ai/config, usando default", e)
  }

  const arrayBuffer = await file.arrayBuffer()
  const buffer = Buffer.from(arrayBuffer)
  const mediaType = file.type || "image/jpeg"
  const dataUrl = `data:${mediaType};base64,${buffer.toString("base64")}`

  const openrouter = createOpenRouter({ apiKey })
  const model = openrouter(modelId)

  let extracted: z.infer<typeof ExtractionSchema> | null = null
  let extractError: string | null = null
  let tokensIn = 0
  let tokensOut = 0

  try {
    const result = await generateObject({
      model,
      schema: ExtractionSchema,
      messages: [
        {
          role: "user",
          content: [
            { type: "text", text: EXTRACTION_PROMPT },
            { type: "image", image: dataUrl },
          ],
        },
      ],
      maxOutputTokens: 2000,
      temperature: 0.1,
    })
    extracted = result.object
    tokensIn = Number(result.usage?.inputTokens ?? 0)
    tokensOut = Number(result.usage?.outputTokens ?? 0)
  } catch (e) {
    console.error("[ocr-invoice] fallo la extracción IA", e)
    extractError = e instanceof Error ? e.message : "No se pudo leer la factura"
  }

  // Débito best-effort — mismo wrapper que el chat: si falla, se loguea con
  // requestId para reconciliar pero NO bloquea la creación del borrador.
  await debitAiUsage({
    apiUrl,
    cookie,
    tokensIn,
    tokensOut,
    capability: "vision",
    model: modelId,
    requestId,
    logPrefix: "[ocr-invoice]",
  })

  // Crear el borrador vía PHP — sube la imagen (S3/DO Spaces, mismo
  // mecanismo que items.php) y persiste `extracted`. Reenviamos los MISMOS
  // bytes que ya leímos para la IA — un solo upload del lado del cliente.
  const phpForm = new FormData()
  phpForm.set("image", new Blob([buffer], { type: mediaType }), file.name || "invoice.jpg")
  phpForm.set("outletId", outletId)
  phpForm.set("extracted", JSON.stringify(extracted ?? {}))
  if (extractError) {
    phpForm.set("error", extractError)
  }

  let createRes: Response
  try {
    createRes = await fetch(`${apiUrl}/v1/purchase-drafts`, {
      method: "POST",
      headers: { cookie },
      body: phpForm,
    })
  } catch (e) {
    console.error("[ocr-invoice] fallo al crear el borrador", e)
    return Response.json(
      { ok: false, error: { message: "No se pudo guardar el borrador" } },
      { status: 502 },
    )
  }

  const createText = await createRes.text()
  const createJson = createText ? safeJson(createText) : null

  if (!createRes.ok) {
    const backendMsg =
      createJson && typeof createJson === "object"
        ? ((createJson as { error?: { message?: string } }).error?.message ?? null)
        : null
    return Response.json(
      { ok: false, error: { message: backendMsg ?? `No se pudo crear el borrador (${createRes.status})` } },
      { status: createRes.status },
    )
  }

  return Response.json(createJson, { status: 201 })
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}
