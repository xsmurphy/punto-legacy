/**
 * Formateador ÚNICO de números de documento del lado front.
 *
 * Espejo exacto de `api/lib/Documents/DocumentNumber.php` (mig 159). Los dos
 * tienen que dar el mismo string para el mismo documento: el panel lo muestra,
 * el ticket lo imprime y el reporte fiscal lo declara ante la SET. Si divergen,
 * el mismo documento aparece con dos números.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El correlativo (`transaction.invoiceNo`, `document_sequence.nextnumber`) es
 * un ENTERO. Los ceros a la izquierda son FORMATO: no se guardan, se pintan.
 * Guardarlos dentro del valor rompería el asignador (`nextnumber + 1`), el
 * corte por rango del timbrado y la unicidad por caja.
 *
 * Antes de este archivo convivían cuatro formatos distintos para el mismo
 * número: `prefix-NNNNNNN` con padding 7 hardcodeado (compras),
 * `prefix+NNNN` sin separador (detalle de transacción), `prefix-NNNN` con el
 * ancho de la caja (listado) y `prefix NNNN` con espacio (cobros).
 *
 * ── Regla de uso ────────────────────────────────────────────────────────────
 * Cuando el backend ya manda un campo formateado (`docNo`), se pinta ESE — no
 * se recompone desde `invoicePrefix` + `invoiceNo`. Este helper es para el POS,
 * que emite offline y arma el número en el device.
 */

/**
 * Ancho por defecto: `EEE-PPP-NNNNNNN`, formato fiscal PY (context/29 §1).
 * Espejo del DEFAULT de `document_sequence.padwidth` y de
 * `DocumentNumber::DEFAULT_PAD_WIDTH`. Si cambia uno, cambian los tres.
 */
export const DEFAULT_PAD_WIDTH = 7

const MIN_PAD_WIDTH = 1
const MAX_PAD_WIDTH = 12

/**
 * Ancho utilizable. Fuera del rango del CHECK de la mig 159 (o ausente) cae al
 * default legal — nunca lanza: un ancho corrupto no puede impedir que se
 * imprima un documento ya emitido.
 */
export function normalizePadWidth(padWidth?: number | null): number {
  if (padWidth == null || !Number.isFinite(padWidth)) return DEFAULT_PAD_WIDTH
  const w = Math.trunc(padWidth)
  return w >= MIN_PAD_WIDTH && w <= MAX_PAD_WIDTH ? w : DEFAULT_PAD_WIDTH
}

/**
 * Correlativo con ceros a la izquierda, SIN prefijo.
 *
 * Devuelve "" para un número ausente o < 1: una transacción sin `invoiceNo` no
 * tiene número, y rellenarla daría un "0000000" fantasma.
 */
export function padDocumentNumber(
  n: number | string | null | undefined,
  padWidth?: number | null,
): string {
  const parsed = typeof n === "number" ? n : Number.parseInt(String(n ?? "").trim(), 10)
  if (!Number.isFinite(parsed) || parsed < 1) return ""
  return String(Math.trunc(parsed)).padStart(normalizePadWidth(padWidth), "0")
}

/**
 * Número de documento completo, tal como se imprime: `001-001-0002129`.
 *
 * El guion entre prefijo y correlativo es parte del formato fiscal PY. Sin
 * prefijo devuelve solo el correlativo padeado — no inventa un separador
 * huérfano.
 */
export function formatDocumentNumber(
  n: number | string | null | undefined,
  prefix?: string | null,
  padWidth?: number | null,
): string {
  const padded = padDocumentNumber(n, padWidth)
  const pfx = (prefix ?? "").trim()

  if (!pfx) return padded
  if (!padded) return pfx
  return `${pfx}-${padded}`
}
