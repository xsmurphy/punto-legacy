import { extractInvoice } from "@/lib/ai/extract-invoice"
import { after } from "next/server"
import { assertAiCredits, debitAiUsage, AiCreditsError } from "@/lib/ai/billing-gate"

/**
 * POST /api/ocr-invoice   multipart/form-data { image: File, outletId: string }
 *
 * Sube una factura y devuelve el borrador RECIÉN CREADO, sin esperar a la IA:
 *
 *   1. Verifica créditos del tenant (fail-closed — sin saldo no se guarda nada).
 *   2. Crea el borrador con la imagen, en estado `queued`, y RESPONDE.
 *   3. En `after()` (ya mandada la respuesta, pero server-side): toma el
 *      borrador con `claim`, lo lee con el modelo de visión, guarda el
 *      resultado con `complete` y debita los créditos consumidos.
 *
 * Antes los pasos 2 y 3 estaban invertidos y el request esperaba al modelo
 * (10-30s) antes de persistir: subir un lote era inviable y cerrar la pestaña
 * perdía el trabajo. Como `after()` no sobrevive a que el proceso muera, la red
 * de seguridad es el job `ocr-requeue` (PHP/crond) más el drain de
 * `/api/ocr-invoice/drain`. Ver context/32-ocr-facturas-compra.md.
 */

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

  // Bearer del panel (context/54 F2): se reenvía tal cual al backend.
  const authHeader = req.headers.get("authorization") ?? ""

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
    await assertAiCredits({ apiUrl, authHeader, logPrefix: "[ocr-invoice]" })
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
    const configRes = await fetch(`${apiUrl}/v1/ai/config`, { headers: { Authorization: authHeader } })
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
        headers: { Authorization: authHeader },
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
  const isPdf = mediaType === "application/pdf"

  // ── 1. Guardar el borrador YA, sin extracción ──────────────────────────────
  // El borrador nace en 'queued' (mig 176) y respondemos enseguida. Antes este
  // request esperaba a la IA (10-30s) antes de persistir nada: con eso no se
  // puede subir un lote —el usuario mira la pantalla y si la cierra pierde
  // todo— y una factura que tardaba de más se caía por timeout sin dejar
  // rastro. Ahora la imagen queda guardada pase lo que pase.
  const phpForm = new FormData()
  phpForm.set(
    "image",
    new Blob([buffer], { type: mediaType }),
    file.name || (isPdf ? "invoice.pdf" : "invoice.jpg"),
  )
  phpForm.set("outletId", outletId)

  let createRes: Response
  try {
    createRes = await fetch(`${apiUrl}/v1/purchase-drafts`, {
      method: "POST",
      headers: { Authorization: authHeader },
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

  // El presenter del borrador expone la PK como `id` (no `draftId`) — ver
  // PurchaseDraftService::present(). Con la clave equivocada acá el borrador se
  // crea igual pero nunca se procesa: queda en la bandeja, vacío y en cola.
  const draftId = String(
    (createJson as { data?: { id?: string } } | null)?.data?.id ?? "",
  )
  if (draftId === "") {
    console.error("[ocr-invoice] el borrador se creó sin id — no se puede procesar", createJson)
  }

  // ── 2. Extraer en segundo plano ────────────────────────────────────────────
  // `after()` corre DESPUÉS de mandar la respuesta, pero dentro del server: no
  // depende de que el browser siga abierto, así que el usuario puede cerrar la
  // pantalla o subir la siguiente factura mientras esta se procesa.
  //
  // Si el contenedor se recicla a mitad (deploy), el borrador queda en
  // 'processing' y el job `ocr-requeue` (crond, cada 5') lo devuelve a la cola.
  // Esa es la red de seguridad: `after()` no sobrevive a que el proceso muera.
  if (draftId !== "") {
    after(async () => {
      try {
        // Lock: si el requeue ya lo tomó, este llamador se retira. Sin esto la
        // misma factura se extrae dos veces y se cobra dos veces.
        const claimRes = await fetch(
          `${apiUrl}/v1/purchase-drafts?id=${encodeURIComponent(draftId)}&resource=claim`,
          { method: "POST", headers: { Authorization: authHeader } },
        )
        if (!claimRes.ok) {
          // 409 = otro proceso ganó el lock, es el caso sano. Cualquier otra
          // cosa (401 por token vencido, 5xx) es un fallo real: si lo tragamos
          // como si fuera un lock perdido, el borrador queda en cola sin que
          // nadie lo vuelva a mirar.
          if (claimRes.status !== 409) {
            console.error(
              `[ocr-invoice] no se pudo tomar el borrador ${draftId} (${claimRes.status})`,
            )
          }
          return
        }

        const { extracted, extractError, tokensIn, tokensOut } = await extractInvoice({
          apiUrl,
          authHeader,
          modelId,
          apiKey,
          buffer,
          mediaType,
          isPdf,
          tenantRuc,
        })

        // Persistir ANTES de debitar: si el orden fuera al revés y el
        // `complete` fallara (token vencido, red), al tenant se le cobraron
        // créditos por una lectura que nunca llegó a su borrador. Al revés, el
        // peor caso es una lectura entregada y no cobrada — que se reconcilia
        // por `requestId` y no perjudica al comercio.
        const doneForm = new FormData()
        doneForm.set("extracted", JSON.stringify(extracted ?? {}))
        if (extractError) doneForm.set("error", extractError)
        const doneRes = await fetch(
          `${apiUrl}/v1/purchase-drafts?id=${encodeURIComponent(draftId)}&resource=complete`,
          { method: "POST", headers: { Authorization: authHeader }, body: doneForm },
        )
        if (!doneRes.ok) {
          console.error(
            `[ocr-invoice] no se pudo guardar la extracción del borrador ${draftId} (${doneRes.status})`,
          )
        }

        // Débito best-effort — mismo wrapper que el chat: si falla, se loguea
        // con requestId para reconciliar pero NO bloquea el borrador.
        await debitAiUsage({
          apiUrl,
          authHeader,
          tokensIn,
          tokensOut,
          capability: "vision",
          model: modelId,
          requestId,
          logPrefix: "[ocr-invoice]",
        })
      } catch (e) {
        console.error("[ocr-invoice] fallo el procesamiento en segundo plano", e)
        // El borrador queda en 'processing'; lo rescata `ocr-requeue`.
      }
    })
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

/**
 * Llama al modelo de visión y devuelve la extracción normalizada.
 *
 * Vive aparte del handler porque ahora corre en `after()`, después de que la
 * respuesta salió: mezclarla con el flujo del request hacía difícil ver qué
 * parte bloquea al usuario (subir la imagen) y qué parte no (leerla).
 */
