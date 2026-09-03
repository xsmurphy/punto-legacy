import { after } from "next/server"

import { extractInvoice, completeAndBill } from "@/lib/ai/extract-invoice"
import { assertAiCredits, AiCreditsError } from "@/lib/ai/billing-gate"

/**
 * Vacía la cola de extracción: toma borradores en `queued` y los procesa.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El camino normal procesa cada factura en el `after()` de su propio upload,
 * que corre UNA vez. Si ese proceso muere a mitad (deploy que recicla el
 * contenedor), el job `ocr-requeue` de PHP devuelve el borrador a `queued` —
 * pero nadie lo volvía a mirar, así que quedaba colgado para siempre: invisible
 * salvo en "Todos", no editable y no aprobable.
 *
 * ── Por qué desde el front y no desde el crond ──────────────────────────────
 * La extracción vive en Next y el crond vive en el contenedor del API PHP, que
 * no le pega a Next. Además el trabajo necesita una credencial del tenant para
 * leer y escribir sus borradores, y un cron no tiene sesión. Lo dispara el
 * front cuando el usuario mira sus borradores y hay alguno estancado: es
 * exactamente el momento en que importa que se destrabe, y va con el Bearer del
 * usuario, con el mismo scoping por tenant que el resto del endpoint.
 *
 * POST /api/ocr-invoice/drain → { processed: number }
 */

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

/** Tope por llamada: mantiene la request corta y acota el gasto de créditos. */
const MAX_PER_RUN = 3

interface DraftRow {
  id: string
  status: string
  outletId?: string
}

export async function POST(req: Request) {
  const apiKey = process.env.OPENROUTER_API_KEY
  const apiUrl = process.env.API_URL ?? ""
  if (!apiKey || !apiUrl) {
    return Response.json(
      { ok: false, error: { message: "OCR no configurado" } },
      { status: 500 },
    )
  }
  const authHeader = req.headers.get("authorization") ?? ""
  if (!/^Bearer\s+\S+/i.test(authHeader)) {
    return Response.json({ ok: false, error: { message: "No autenticado" } }, { status: 401 })
  }

  // Gate de créditos antes de tocar el modelo: sin saldo no tiene sentido
  // destrabar nada, y el borrador sigue en cola para cuando lo recarguen.
  try {
    await assertAiCredits({ apiUrl, authHeader, logPrefix: "[ocr-drain]" })
  } catch (e) {
    if (e instanceof AiCreditsError) {
      return Response.json({ ok: false, error: { message: e.message } }, { status: e.status })
    }
    throw e
  }

  let queued: DraftRow[] = []
  try {
    const listRes = await fetch(`${apiUrl}/v1/purchase-drafts?status=queued`, {
      headers: { Authorization: authHeader },
    })
    if (!listRes.ok) {
      return Response.json(
        { ok: false, error: { message: "No se pudo leer la cola" } },
        { status: 502 },
      )
    }
    const json = (await listRes.json()) as { data?: { rows?: DraftRow[] } }
    queued = (json?.data?.rows ?? []).slice(0, MAX_PER_RUN)
  } catch {
    return Response.json(
      { ok: false, error: { message: "No se pudo leer la cola" } },
      { status: 502 },
    )
  }

  if (queued.length === 0) {
    return Response.json({ ok: true, data: { processed: 0 } })
  }

  // Se responde ya y el trabajo sigue en `after()`: el usuario no espera a que
  // se lean las facturas atrasadas, igual que en el upload.
  after(async () => {
    for (const draft of queued) {
      try {
        const claimRes = await fetch(
          `${apiUrl}/v1/purchase-drafts?id=${encodeURIComponent(draft.id)}&resource=claim`,
          { method: "POST", headers: { Authorization: authHeader } },
        )
        // 409 = otro proceso se lo llevó; seguimos con el próximo.
        if (!claimRes.ok) continue

        const detailRes = await fetch(
          `${apiUrl}/v1/purchase-drafts?id=${encodeURIComponent(draft.id)}`,
          { headers: { Authorization: authHeader } },
        )
        if (!detailRes.ok) continue
        const detail = (await detailRes.json()) as {
          data?: { imageUrl?: string | null; outletId?: string }
        }
        const imageUrl = detail?.data?.imageUrl ?? null
        if (!imageUrl) continue

        // La imagen ya está en el storage del tenant: se relee de ahí en vez de
        // pedírsela otra vez al usuario.
        const imgRes = await fetch(imageUrl)
        if (!imgRes.ok) continue
        const mediaType = imgRes.headers.get("content-type") ?? "image/jpeg"
        const buffer = Buffer.from(await imgRes.arrayBuffer())

        const modelId = await resolveVisionModel(apiUrl, authHeader)
        const requestId = crypto.randomUUID()
        const { extracted, extractError, tokensIn, tokensOut } = await extractInvoice({
          apiUrl,
          authHeader,
          modelId,
          apiKey,
          buffer,
          mediaType,
          isPdf: mediaType === "application/pdf",
          tenantRuc: null,
        })

        // Antes esta ruta ni miraba la respuesta del `complete` y debitaba
        // igual: era el camino que más créditos quemaba, porque el drain se
        // dispara en cada visita a la bandeja. Ahora usa la misma función que
        // el upload, que solo cobra si el guardado entró.
        await completeAndBill({
          apiUrl,
          authHeader,
          draftId: draft.id,
          extracted,
          extractError,
          tokensIn,
          tokensOut,
          modelId,
          requestId,
          logPrefix: "[ocr-drain]",
        })
      } catch (e) {
        console.error(`[ocr-drain] falló el borrador ${draft.id}`, e)
        // Queda en 'processing'; `ocr-requeue` lo devuelve a la cola o lo marca
        // fallido al agotar intentos.
      }
    }
  })

  return Response.json({ ok: true, data: { processed: queued.length } })
}

/** Modelo de la capability 'vision' (ai_model_config). Fallback al seed. */
async function resolveVisionModel(apiUrl: string, authHeader: string): Promise<string> {
  try {
    const res = await fetch(`${apiUrl}/v1/ai/config`, { headers: { Authorization: authHeader } })
    if (res.ok) {
      const config = (await res.json()) as Record<string, { model?: string }>
      if (config?.vision?.model) return config.vision.model
    }
  } catch {
    // cae al default
  }
  return "google/gemini-3.5-flash"
}
