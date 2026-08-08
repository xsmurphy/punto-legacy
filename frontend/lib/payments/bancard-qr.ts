/**
 * Bancard QR — normalización de la respuesta del PSP.
 *
 * `POST /v1/bancard { type: 'create' }` devuelve el JSON CRUDO del proveedor
 * (ePagos/BANCARD_QR_API) tal como lo entrega — el endpoint es un port fiel
 * del handler legacy y no reinterpreta el shape (ver api/v1/bancard.php).
 *
 * Ese crudo no tiene contrato estable de nuestro lado, así que la lectura es
 * defensiva y de UN solo lugar: acá. Si el proveedor cambia el nombre de un
 * campo, se toca este archivo y nada más. Se aceptan las tres formas con las
 * que un PSP suele devolver un QR:
 *
 *   1. Imagen ya renderizada (`qr_image`, `image`, …) — data URI o URL http.
 *   2. Payload EMV/string (`qr`, `qr_data`, `emv`, …) — lo renderizamos local
 *      con qrcode.react.
 *   3. Anidado bajo `data`/`result`/`response`.
 *
 * NO se inventa ningún campo: si no aparece ni imagen ni payload, el llamador
 * muestra error en vez de un QR vacío (esto es path de dinero).
 */

export interface BancardQr {
  /** ID del QR en el PSP — necesario para refresh/cancel. '' si no vino. */
  id: string
  /** Payload del QR para renderizar local. null si el PSP mandó imagen. */
  payload: string | null
  /** URL o data URI de la imagen del QR. null si hay que renderizar el payload. */
  imageUrl: string | null
}

type Raw = Record<string, unknown>

/** Claves candidatas, en orden de preferencia. */
const ID_KEYS = ["id", "qr_id", "qrId", "transaction_id", "transactionId", "operationId"]
const IMAGE_KEYS = ["qr_image", "qrImage", "image", "qr_base64", "qrBase64", "image_url", "imageUrl"]
const PAYLOAD_KEYS = ["qr", "qr_data", "qrData", "emv", "qr_string", "qrString", "payload", "content"]
/** Contenedores en los que el PSP puede anidar el objeto útil. */
const WRAPPER_KEYS = ["data", "result", "response", "qr"]

function isObj(v: unknown): v is Raw {
  return typeof v === "object" && v !== null && !Array.isArray(v)
}

function pickString(src: Raw, keys: string[]): string | null {
  for (const k of keys) {
    const v = src[k]
    if (typeof v === "string" && v.trim() !== "") return v.trim()
    if (typeof v === "number") return String(v)
  }
  return null
}

/**
 * Aplana un nivel de anidamiento: devuelve el objeto raíz y, si existe, el
 * contenido de sus contenedores conocidos. La búsqueda de cada campo recorre
 * esta lista en orden, así un `data.qr` gana sobre nada en la raíz.
 */
function candidates(raw: unknown): Raw[] {
  if (!isObj(raw)) return []
  const out: Raw[] = [raw]
  for (const k of WRAPPER_KEYS) {
    const nested = raw[k]
    if (isObj(nested)) out.push(nested)
  }
  return out
}

/** Una imagen sirve si es data URI o URL http(s) — nada de rutas relativas. */
function isRenderableImage(v: string): boolean {
  return v.startsWith("data:image/") || /^https?:\/\//i.test(v)
}

/**
 * Normaliza la respuesta cruda. Devuelve null si no hay NI imagen NI payload
 * — el llamador debe tratarlo como error, no mostrar un QR en blanco.
 */
export function parseBancardQr(raw: unknown): BancardQr | null {
  const sources = candidates(raw)
  if (sources.length === 0) return null

  let id = ""
  let imageUrl: string | null = null
  let payload: string | null = null

  for (const src of sources) {
    if (id === "") id = pickString(src, ID_KEYS) ?? ""
    if (imageUrl === null) {
      const img = pickString(src, IMAGE_KEYS)
      if (img !== null) {
        // Base64 pelado (sin el prefijo data:) es lo más común en PSPs locales.
        imageUrl = isRenderableImage(img) ? img : `data:image/png;base64,${img}`
      }
    }
    if (payload === null) payload = pickString(src, PAYLOAD_KEYS)
  }

  // `qr` puede ser el contenedor (objeto) o el payload (string). Si terminó
  // siendo objeto, pickString lo ignoró y el payload vino de adentro.
  if (imageUrl === null && payload === null) return null

  return { id, payload, imageUrl }
}
