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
 *
 * Prompt y guía espacial (bloques A-D, formatos PY, validación aritmética):
 * técnicas tomadas del pipeline de facturas de Urban Domus (proyecto hermano
 * del owner), adaptadas a multi-tenant — ver context/32-ocr-facturas-compra.md
 * §"Criterios de extracción" para el detalle completo.
 */

export const runtime = "nodejs"
export const maxDuration = 60

const ExtractionSchema = z.object({
  supplier: z.object({
    name: z.string().nullable(),
    ruc: z.string().nullable(),
  }),
  receiver: z.object({
    ruc: z.string().nullable(),
    name: z.string().nullable(),
  }),
  invoice: z.object({
    number: z.string().nullable(),
    timbrado: z.string().nullable(),
    timbradoStart: z.string().nullable(),
    timbradoEnd: z.string().nullable(),
    date: z.string().nullable(),
    condition: z.enum(["contado", "credito"]).nullable(),
    dueDate: z.string().nullable(),
    isElectronic: z.boolean().nullable(),
    cdc: z.string().nullable(),
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
    exempt: z.number().nullable(),
    discount: z.number().nullable(),
    iva5: z.number().nullable(),
    iva10: z.number().nullable(),
    total: z.number().nullable(),
  }),
  currency: z.string().nullable(),
  isInvoice: z.boolean(),
  // El modelo intenta la comparación (se lo pedimos en el prompt cuando hay
  // RUC de tenant), pero el valor final que se persiste SIEMPRE se recalcula
  // en código (ver `rucsMatch` abajo) — no confiamos en que el LLM compare
  // bien dos strings de RUC, solo en que extraiga `receiver.ruc` con fidelidad.
  receiverMatchesTenant: z.boolean().nullable(),
  confidence: z.number().min(0).max(1),
})

/**
 * REGLA CRÍTICA DE NULLS — NO TOCAR sin releer context/32:
 * el borrador lo aprueba un humano; un default inventado (timbrado
 * "11111111", descripción "SERVICIOS PRESTADOS", etc.) se cuela como si
 * fuera un dato real leído de la factura. Para Punto eso es inaceptable —
 * campo ilegible SIEMPRE es `null`, nunca un placeholder. El único default
 * real que existe es `currency` = "PYG" cuando no se detecta (es la moneda
 * del tenant, no un dato inventado de la factura).
 */
function buildExtractionPrompt(todayISO: string, tenantRuc: string | null): string {
  const receiverSection = tenantRuc
    ? `
# Verificación de destinatario

El RUC de la empresa que sube esta factura es ${tenantRuc}. Extraé
"receiver.ruc" con el RUC del receptor tal como figura impreso en la
factura — NO lo fuerces a coincidir con ${tenantRuc}, reportá lo que
realmente dice el documento. Después comparalo: si "receiver.ruc" coincide
con ${tenantRuc}, "receiverMatchesTenant": true; si no coincide,
"receiverMatchesTenant": false; si el RUC del receptor no se puede leer,
"receiverMatchesTenant": null.
`
    : ""

  return `Sos un especialista en OCR de facturas de compra de proveedores
paraguayos. Te paso la foto de UNA factura (el negocio que la recibe es el
comprador — extraé los datos del EMISOR/proveedor Y del receptor/cliente,
ambos figuran impresos en el documento).

REGLA CRÍTICA — NUNCA INVENTES: si un dato no se lee con claridad en la
imagen, devolvé null en ese campo. Es preferible null a un dato incorrecto:
un humano revisa y completa cada borrador antes de que se registre nada. NO
uses valores por defecto inventados (timbrados de relleno, descripciones
genéricas tipo "SERVICIOS PRESTADOS", ítems que no están impresos) para
tapar huecos — eso es exactamente lo que NO tenés que hacer.

# Dónde mirar (guía espacial)

Las facturas paraguayas siguen un layout consistente en bloques — usalo para
ubicar cada dato y para decidir cuál usar si un valor aparece repetido en
más de un lugar (priorizá siempre el bloque que le corresponde):

- Bloque A (superior): tipo de documento, timbrado, fecha de inicio y de
  vencimiento del timbrado, número de factura, RUC y razón social del
  EMISOR (proveedor), actividad comercial.
- Bloque B (centro — en formatos tipo ticket puede estar al pie): fecha de
  emisión, fecha de vencimiento, RUC/razón social/dirección del CLIENTE
  (receptor), condición de venta.
- Bloque C (centro): detalle de ítems — descripción, cantidad, precio
  unitario, IVA, total por línea.
- Bloque D (inferior): subtotal, descuentos, total general, total en
  letras, exentas, IVA 5%, IVA 10%, suma de IVAs, moneda.

# Formato de campos críticos

- "invoice.number": XXX-XXX-XXXXXXX (3-3-7). Si viene sin guiones, agregalos
  (ej. "1234567890123" → "123-456-7890123").
- "invoice.timbrado": 8 dígitos exactos, sin letras ni símbolos.
- RUC del emisor y del receptor: hasta 8 dígitos + guion + 1 dígito
  verificador (ej. "80012345-0", "7659194-1").
- Números: convertí a punto decimal (ej. "1.234,56" → 1234.56). Montos en
  guaraníes (PYG): enteros, sin separadores ni decimales (ej. "10.000,00" →
  10000). Montos en otras monedas: punto decimal.
- Fechas: siempre YYYY-MM-DD.
- "invoice.condition": buscá CONTADO o CRÉDITO — puede estar marcado con una
  X o un check en una casilla en vez de estar escrito como texto.

# Identificación del documento

Para que el documento sea una factura legal paraguaya válida tiene que
contener la palabra "factura" o "timbrado" (o variantes: FACT., FACTURA
ELECTRÓNICA, TIMBRADO). Si la contiene, "isInvoice": true. Si no la
contiene (foto de otra cosa, remito, comprobante no fiscal), "isInvoice":
false — igual completá el resto de los campos con lo que se alcance a leer.

# Fechas relativas

Hoy es ${todayISO}. Si la condición de venta es CRÉDITO y no hay fecha de
vencimiento legible, copiá la fecha de emisión como vencimiento.
${receiverSection}
# Reglas de mapeo

- "supplier": RUC y razón social del EMISOR (proveedor) — nunca del cliente.
- "receiver": RUC y razón social del CLIENTE (receptor) — nunca del emisor.
- "invoice.isElectronic": true si el documento se identifica como factura
  electrónica (KuDE, CDC visible, texto "factura electrónica" o similar);
  false si es una factura preimpresa común; null si no se puede determinar.
- "invoice.cdc": código de control de 44 dígitos de la factura electrónica,
  si figura (usualmente al pie, cerca del código QR).
- "items": una entrada por cada línea de producto/servicio facturado.
- "items[].ivaRate": 0, 5 o 10 (tasas de IVA vigentes en Paraguay) según lo
  que indique la línea; null si no se puede determinar.
- "totals": subtotal, exentas, descuento, IVA discriminado (5% y 10%) y
  total general, tal como figuran impresos.
- "currency": código de moneda si se detecta algo distinto a guaraníes (ej.
  "USD"); null si no se especifica ninguna (el sistema asume PYG por
  defecto — vos no completes ese default, dejalo null).
- "confidence": tu confianza global en la lectura completa, de 0 (nada
  legible) a 1 (perfectamente legible).

Devolvé ÚNICAMENTE el JSON con el schema pedido.`
}

/** Normaliza un RUC a solo dígitos para comparar sin importar guiones/espacios. */
function normalizeRuc(raw: string | null | undefined): string {
  return (raw ?? "").replace(/[^0-9]/g, "")
}

/** Fuente única de la comparación receptor↔tenant — nunca se confía en el juicio del LLM. */
function rucsMatch(receiverRuc: string | null, tenantRuc: string | null): boolean | null {
  if (!tenantRuc) return null
  const a = normalizeRuc(receiverRuc)
  const b = normalizeRuc(tenantRuc)
  if (a === "" || b === "") return null
  return a === b
}

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

  // RUC del tenant (de la sucursal que sube la factura) — habilita la
  // sección de "verificación de destinatario" del prompt. Multi-tenant: NO
  // hay allowlist hardcodeada (a diferencia del proyecto de referencia),
  // se resuelve por request desde `outlet.ruc`. Si la sucursal no tiene RUC
  // cargado, se omite la sección entera del prompt y `receiverMatchesTenant`
  // queda `null` — nunca invalida la factura.
  let tenantRuc: string | null = null
  if (outletId !== "") {
    try {
      const outletRes = await fetch(`${apiUrl}/v1/outlets?id=${encodeURIComponent(outletId)}`, {
        headers: { cookie },
      })
      if (outletRes.ok) {
        const outletJson = (await outletRes.json()) as { data?: { ruc?: string } }
        const ruc = (outletJson?.data?.ruc ?? "").trim()
        tenantRuc = ruc !== "" ? ruc : null
      } else {
        console.error(`[ocr-invoice] outlets respondió ${outletRes.status} al leer RUC de sucursal`)
      }
    } catch (e) {
      console.error("[ocr-invoice] fallo al leer RUC de la sucursal", e)
    }
  }

  const arrayBuffer = await file.arrayBuffer()
  const buffer = Buffer.from(arrayBuffer)
  // Mime REAL del archivo subido, no un valor hardcodeado — bug conocido del
  // pipeline de referencia (siempre mandaba "image/jpeg" al modelo aunque la
  // imagen fuera PNG/WEBP). `file.type` es el contentType real del upload;
  // el fallback solo aplica si el browser no lo mandó.
  const mediaType = file.type || "image/jpeg"
  const dataUrl = `data:${mediaType};base64,${buffer.toString("base64")}`

  const openrouter = createOpenRouter({ apiKey })
  const model = openrouter(modelId)

  const todayISO = new Date().toISOString().slice(0, 10)
  const extractionPrompt = buildExtractionPrompt(todayISO, tenantRuc)

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
            { type: "text", text: extractionPrompt },
            { type: "image", image: dataUrl },
          ],
        },
      ],
      maxOutputTokens: 2000,
      temperature: 0.4,
    })
    extracted = result.object
    // `currency` y `receiverMatchesTenant` finales: código, no el LLM.
    // - currency: default PYG es la moneda del tenant, no un dato de la
    //   factura — corresponde aplicarlo acá, no pedírselo al modelo.
    // - receiverMatchesTenant: recalculado siempre con `rucsMatch` (fuente
    //   única), pisa lo que haya puesto el modelo.
    extracted = {
      ...extracted,
      currency: extracted.currency && extracted.currency.trim() !== "" ? extracted.currency : "PYG",
      receiverMatchesTenant: rucsMatch(extracted.receiver?.ruc ?? null, tenantRuc),
    }
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
