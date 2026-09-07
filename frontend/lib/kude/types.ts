/**
 * Contrato del KuDE propio — K1 de `context/73-kude-propio.md`.
 *
 * Lo arma el backend PHP (`api/lib/EInvoice/KudeService.php`) y lo consume el
 * renderer. El template NO consulta nada ni decide nada de negocio: recibe el
 * documento ya resuelto y lo dibuja.
 *
 * Todo lo opcional es literalmente "no lo tenemos". Un campo ausente se omite;
 * jamás se rellena con un valor plausible — es un documento fiscal.
 */

/**
 * Versión del TEMPLATE. Entra en la clave del caché S3, así que subirla
 * invalida los PDF ya generados. ESPEJO de `KudeService::TEMPLATE_VERSION` en
 * PHP: si divergen, el caché sirve el diseño viejo con la clave del nuevo.
 */
export const KUDE_TEMPLATE_VERSION = 1

export interface KudeStamp {
  /** Número de timbrado (C004). */
  number: string
  /** Inicio de vigencia (C008). */
  start: string
  /** Establecimiento-punto de expedición, "001-001". */
  prefix: string
}

export interface KudeEmitter {
  /** Razón social FISCAL. Nunca el nombre comercial. */
  name: string
  /** Nombre de fantasía (D106). */
  tradeName: string
  ruc: string
  address: string
  city: string
  phone: string
  email: string
  /** Actividad económica (D131). Hoy Punto no la tiene: llega null. */
  activity: string | null
  logoUrl: string | null
  stamp: KudeStamp | null
}

export interface KudeReceiver {
  name: string
  ruc: string | null
  documentId: string | null
  address: string | null
}

export interface KudeItem {
  description: string
  quantity: number
  /** Precio unitario CON IVA incluido — igual que lo declarado a SIFEN. */
  unitPrice: number
  /** 0 | 5 | 10 */
  taxRate: number
  total: number
}

export interface KudeTotals {
  exempt: number
  taxed5: number
  taxed10: number
  iva5: number
  iva10: number
  ivaTotal: number
  total: number
}

export interface KudeFormat {
  /** Separador de miles del tenant. */
  thousand: string
  /** Separador decimal del tenant. */
  decimal: string
  /** Decimales a mostrar (0 en monedas sin centavos). */
  decimals: number
  /** Código/símbolo de moneda del tenant. Sin default de ningún país. */
  currency: string
}

export interface KudeDocument {
  /** Denominación oficial: "KuDE de Factura Electrónica". */
  title: string
  number: string
  cdc: string
  issuedAt: string
  condition: string
  currency: string
  exchangeRate: number | null
  /** Campo J002 (`dCarQR`) tal cual lo devolvió la emisión. */
  qrData: string | null
}

export interface KudePayload {
  templateVersion: number
  document: KudeDocument
  emitter: KudeEmitter
  receiver: KudeReceiver
  items: KudeItem[]
  totals: KudeTotals
  format: KudeFormat
}

/** Monto con el separador y los decimales del TENANT. */
export function formatAmount(value: number, format: KudeFormat): string {
  const decimals = Number.isFinite(format.decimals) ? Math.max(0, Math.min(4, format.decimals)) : 0
  const safe = Number.isFinite(value) ? value : 0
  const fixed = Math.abs(safe).toFixed(decimals)
  const [int, dec] = fixed.split(".")
  const grouped = int.replace(/\B(?=(\d{3})+(?!\d))/g, format.thousand || ".")
  const sign = safe < 0 ? "-" : ""

  return dec ? `${sign}${grouped}${format.decimal || ","}${dec}` : `${sign}${grouped}`
}

/** Cantidades: hasta 3 decimales, sin ceros de relleno (1,5 kg / 2 unidades). */
export function formatQuantity(value: number, format: KudeFormat): string {
  const safe = Number.isFinite(value) ? value : 0
  const text = Number(safe.toFixed(3)).toString()

  return text.replace(".", format.decimal || ",")
}

/**
 * CDC en once grupos de cuatro posiciones — MT §13.4.4. Es un requisito de
 * legibilidad de la norma, no una decisión estética.
 */
export function groupCdc(cdc: string): string {
  const clean = (cdc || "").replace(/\s+/g, "")

  return (clean.match(/.{1,4}/g) ?? []).join(" ")
}

/**
 * URL de consulta pública del DE. Se DERIVA de la cadena del QR que devolvió
 * la emisión (su origen + path, sin los parámetros): no se escribe a mano un
 * dominio de la SET en el código.
 */
export function consultationUrl(qrData: string | null): string | null {
  if (!qrData) return null
  try {
    const url = new URL(qrData)
    return `${url.origin}${url.pathname}`
  } catch {
    return null
  }
}
