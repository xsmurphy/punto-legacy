/**
 * Contrato compartido del cobro con QR de una pasarela de pago (PSP).
 *
 * ── Qué vive acá y qué vive en el adapter ───────────────────────────────────
 *
 * El ciclo de cobro es IDÉNTICO para cualquier pasarela de QR: crear el QR,
 * pintarlo, espejarlo en la pantalla del cliente, pollear la confirmación,
 * cancelar/vencer. Eso lo implementa una sola vez `<PspQrDialog>`
 * (`frontend/components/register/psp-qr-dialog.tsx`).
 *
 * Lo específico de cada pasarela —el endpoint de creación, el shape crudo de
 * su respuesta, cómo se cancela— entra por un `PspQrAdapter`. Los adapters
 * viven en `frontend/lib/payments/psp/` y se registran en el índice de esa
 * carpeta. Sumar una pasarela nueva = un archivo de adapter + una entrada en
 * el registry + una entrada en `PspCatalog` del backend. Nada más.
 *
 * ── Normalización de la respuesta del PSP ───────────────────────────────────
 *
 * Un endpoint de pasarela devuelve el JSON CRUDO del proveedor (los nuestros
 * son ports fieles del handler legacy y no reinterpretan el shape — ver
 * api/v1/bancard.php). Ese crudo no tiene contrato estable de nuestro lado,
 * así que la lectura es defensiva y de UN solo lugar: `parsePspQrResponse`.
 * Se aceptan las tres formas con las que un PSP suele devolver un QR:
 *
 *   1. Imagen ya renderizada (`qr_image`, `image`, …) — data URI o URL http.
 *   2. Payload EMV/string (`qr`, `qr_data`, `emv`, …) — lo renderizamos local
 *      con qrcode.react.
 *   3. Anidado bajo `data`/`result`/`response`.
 *
 * NO se inventa ningún campo: si no aparece ni imagen ni payload, el llamador
 * muestra error en vez de un QR vacío (esto es path de dinero).
 */

import { posApi as api } from "@/lib/api/pos-client"

/** QR ya normalizado, listo para pintar. */
export interface PspQr {
  /** ID del QR en el PSP — necesario para refresh/cancel. '' si no vino. */
  id: string
  /** Payload del QR para renderizar local. null si el PSP mandó imagen. */
  payload: string | null
  /** URL o data URI de la imagen del QR. null si hay que renderizar el payload. */
  imageUrl: string | null
}

/** Lo que el POS le pide a la pasarela para abrir un cobro. */
export interface PspQrCreateInput {
  /** UUID de ESTA operación, generado por el POS. Es la llave con vPayments. */
  uid: string
  /** Monto a cobrar con el QR (el restante del cobro, no el total de la venta). */
  amount: number
  /** Total de la venta — el PSP lo recibe aparte del monto cobrado. */
  saleAmount: number
}

/** Confirmación de pago acreditado. */
export interface PspQrConfirmation {
  /** Monto realmente acreditado por el PSP (manda sobre el pedido). */
  amount: number | null
}

/**
 * Lo que tiene que implementar una pasarela nueva. Tres métodos y tres
 * constantes — todo lo demás (UI, estados, polling, pantalla del cliente,
 * degradación sin red) ya es compartido.
 */
export interface PspQrAdapter {
  /** Key de la pasarela — la MISMA que en `PspCatalog` del backend. */
  readonly provider: string
  /** systemKey del medio de pago que provisiona el backend para esta pasarela. */
  readonly systemKey: string
  /** Título del dialog de cobro, en el idioma del cajero. */
  readonly title: string
  /** Abre el cobro en el PSP. Devuelve null si la respuesta no trae un QR usable. */
  create(input: PspQrCreateInput): Promise<PspQr | null>
  /** Revierte un QR que quedó sin pagar. Best-effort: nunca debe lanzar. */
  cancel(qrId: string): Promise<void>
  /**
   * Confirmación del pago. Opcional: el default (`confirmPspPaymentByUid`)
   * lee la fila que el webhook del PSP deja en `vPayments`, que es el patrón
   * compartido. Una pasarela que solo soporte consulta directa la sobrescribe.
   */
  confirm?(uid: string): Promise<PspQrConfirmation | null>
}

/**
 * Confirmación por defecto: `GET /v1/vpayments?resource=byUID`.
 *
 * El webhook del PSP deja la fila; la aparición de esa fila ES la
 * confirmación. Un 404 (pago todavía no acreditado) lo tira el api client
 * como error — por eso el catch devuelve null en vez de propagar.
 */
export async function confirmPspPaymentByUid(uid: string): Promise<PspQrConfirmation | null> {
  try {
    const res = await api.get<{ success?: { amount?: number | string } }>(
      `/v1/vpayments?resource=byUID&uid=${encodeURIComponent(uid)}`,
    )
    if (!res?.success) return null
    const raw = Number(res.success.amount)
    return { amount: Number.isFinite(raw) && raw !== 0 ? raw : null }
  } catch {
    // pago todavía no acreditado
    return null
  }
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
 * Normaliza la respuesta cruda de CUALQUIER pasarela. Devuelve null si no hay
 * NI imagen NI payload — el llamador debe tratarlo como error, no mostrar un
 * QR en blanco.
 */
export function parsePspQrResponse(raw: unknown): PspQr | null {
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
