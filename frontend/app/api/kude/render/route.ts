import QRCode from "qrcode"

import { renderKudePdf } from "@/lib/kude/document"
import { KUDE_TEMPLATE_VERSION, type KudePayload } from "@/lib/kude/types"

/**
 * Renderer del KuDE propio — K1 de `context/73-kude-propio.md`.
 *
 * POST /api/kude/render  →  application/pdf
 *
 * ── Quién lo llama ──────────────────────────────────────────────────────────
 * El BACKEND PHP (`api/lib/EInvoice/KudeService.php`), nunca un browser. No
 * hay sesión de tenant acá: llega el documento ya resuelto y autorizado del
 * otro lado. Por eso la autenticación es una clave compartida
 * (`INTERNAL_RENDER_KEY`) y no una cookie ni un Bearer de usuario.
 *
 * FALLA CERRADO: sin la env configurada este endpoint no atiende a nadie. Un
 * renderer sin clave es un renderer abierto a internet al que además se le
 * dicta qué imprimir — y lo que imprime tiene pinta de factura.
 *
 * Si algo acá falla, el lado PHP sirve el KuDE de Factomate (K2). Este route
 * puede devolver 500 con tranquilidad: nadie se queda sin su factura.
 */

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

/** El logo es del tenant y vive en S3; no se espera indefinidamente por él. */
const LOGO_TIMEOUT_MS = 4000
/** Tope del logo embebido: un PDF de factura no es una galería. */
const LOGO_MAX_BYTES = 2 * 1024 * 1024

function unauthorized() {
  return Response.json({ ok: false, error: { message: "No autorizado" } }, { status: 401 })
}

/**
 * Comparación en tiempo constante sobre el hash: las claves pueden diferir en
 * longitud y `timingSafeEqual` exige buffers iguales.
 */
async function keyMatches(provided: string, expected: string): Promise<boolean> {
  const { createHash, timingSafeEqual } = await import("node:crypto")
  const a = createHash("sha256").update(provided).digest()
  const b = createHash("sha256").update(expected).digest()

  return timingSafeEqual(a, b)
}

/**
 * Baja el logo y lo devuelve como data URL. Devuelve null ante CUALQUIER
 * problema: un KuDE sin logo es un KuDE válido; uno que no se generó porque
 * el logo tardó, no.
 */
async function fetchLogo(url: string | null): Promise<string | null> {
  if (!url) return null
  try {
    const parsed = new URL(url)
    if (parsed.protocol !== "https:" && parsed.protocol !== "http:") return null

    const res = await fetch(parsed, { signal: AbortSignal.timeout(LOGO_TIMEOUT_MS) })
    if (!res.ok) return null

    const type = res.headers.get("content-type") ?? ""
    if (!type.startsWith("image/")) return null

    const buffer = Buffer.from(await res.arrayBuffer())
    if (buffer.byteLength === 0 || buffer.byteLength > LOGO_MAX_BYTES) return null

    return `data:${type.split(";")[0]};base64,${buffer.toString("base64")}`
  } catch {
    return null
  }
}

/**
 * QR fiscal desde la cadena J002 (`dCarQR`) que devolvió la emisión. NO se
 * arma un QR propio con otra URL: el QR del KuDE es J002 por norma, cualquier
 * otro contenido lo invalida (`context/73`, arquitecturas rechazadas).
 *
 * Se rasteriza a 400 px para que los 80 pt (~28 mm, por encima del mínimo de
 * 25 mm del MT §13.8) impriman nítidos.
 */
async function renderQr(data: string | null): Promise<string | null> {
  if (!data) return null
  try {
    return await QRCode.toDataURL(data, {
      errorCorrectionLevel: "M",
      margin: 2,
      width: 400,
      type: "image/png",
    })
  } catch {
    return null
  }
}

export async function POST(req: Request) {
  const expected = (process.env.INTERNAL_RENDER_KEY ?? "").trim()
  if (expected === "") {
    console.error("[kude/render] INTERNAL_RENDER_KEY no configurada — el renderer no atiende.")
    return unauthorized()
  }

  const provided = (req.headers.get("x-internal-key") ?? "").trim()
  if (provided === "" || !(await keyMatches(provided, expected))) {
    return unauthorized()
  }

  let payload: KudePayload
  try {
    payload = (await req.json()) as KudePayload
  } catch {
    return Response.json(
      { ok: false, error: { message: "Cuerpo inválido" } },
      { status: 400 },
    )
  }

  if (!payload?.document || !Array.isArray(payload.items) || !payload.format) {
    return Response.json(
      { ok: false, error: { message: "Documento incompleto" } },
      { status: 422 },
    )
  }
  if (payload.templateVersion !== KUDE_TEMPLATE_VERSION) {
    // Desalineación entre PHP y el front: el PDF se cachearía bajo una clave
    // que no describe el diseño que se está dibujando. Mejor fallar y que se
    // sirva el fallback que ensuciar el caché.
    return Response.json(
      {
        ok: false,
        error: {
          message: `Versión de template ${payload.templateVersion} — este renderer es la ${KUDE_TEMPLATE_VERSION}`,
        },
      },
      { status: 409 },
    )
  }

  try {
    const [qrImage, logoImage] = await Promise.all([
      renderQr(payload.document.qrData),
      fetchLogo(payload.emitter?.logoUrl ?? null),
    ])

    const pdf = await renderKudePdf({ payload, qrImage, logoImage })

    return new Response(new Uint8Array(pdf), {
      status: 200,
      headers: {
        "Content-Type": "application/pdf",
        "Content-Length": String(pdf.byteLength),
        "Cache-Control": "no-store",
      },
    })
  } catch (e) {
    console.error("[kude/render] no se pudo generar el PDF:", e)
    return Response.json(
      { ok: false, error: { message: "No se pudo generar el KuDE" } },
      { status: 500 },
    )
  }
}
