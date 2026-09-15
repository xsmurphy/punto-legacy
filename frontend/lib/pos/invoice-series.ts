/**
 * Identidad de la SERIE fiscal en el device: (timbrado, punto de expedición,
 * serie SIFEN).
 *
 * El correlativo no existe solo. Un número de comprobante paraguayo es la
 * tripleta (timbrado, punto de expedición, correlativo) —
 * `context/29-numeracion-y-exclusividad-de-caja.md` §1-2 — y `001-001-1234567`
 * y `001-002-1234567` conviven legalmente porque son dos ramas de numeración
 * independientes.
 *
 * Consecuencia, y decisión del owner (2026-09-09): **cambiar el timbrado o el
 * punto de expedición abre una serie NUEVA.** No se resetea ningún contador —
 * la serie nueva arranca sola en 1 y la vieja queda como registro de lo que
 * emitió.
 *
 * El contador local del device (`lib/pos/invoice-numbering.ts`) vivía en
 * `pos_invoice_next_no:<registerId>`, o sea UNA clave para toda la vida de la
 * caja. Al cambiar el punto de expedición de `001-001` a `001-002` esa clave
 * siguió corriendo bajo el punto nuevo: el POS mandó el número 838 contra un
 * punto que iba por 614. Con la serie en la clave, una serie distinta es otra
 * clave y el problema no puede existir.
 *
 * ── La serie SIFEN (`dSerieNum`) es la tercera parte (mig 223) ──
 * Si el punto ya emitió con serie, SIFEN exige la misma (rechazo 1110), y al
 * cambiarla (AA → AB) la numeración reinicia: es identidad, igual que las otras
 * dos. Además de estar en la clave, la serie VIAJA en cada venta
 * (`invoiceserie`): el POS numera offline bajo la serie que conoce y el
 * servidor congela esa, no la que la caja tenga cuando la venta sincroniza.
 *
 * ── Esta clave es del localStorage, no de la base ──
 * En el servidor la identidad de la serie son COLUMNAS de `document_sequence`
 * (`invoiceauth`, `prefix`, `serie` — migs 209 y 223). Este string existe solo
 * para namespacear el almacenamiento local del device; no viaja en ningún
 * payload y no tiene que coincidir byte a byte con nada del backend.
 */

import type { PosRegister } from "@/lib/types/pos-bootstrap"
import { normalizeSerie } from "@/lib/documents/serie"

/**
 * Clave canónica de una serie. El separador `|` no puede aparecer en ninguna
 * de las partes: el timbrado valida `^\d+$`, el punto de expedición
 * `^\d{3}-\d{3}$` y la serie `^[A-Z]{2}$`.
 *
 * Una caja sin datos fiscales cargados da `"|"` — la "serie vacía", que es
 * una serie legítima: es la caja que todavía no tiene timbrado. Su contador no
 * se mezcla con el de ninguna serie real, que es justamente lo que queremos.
 *
 * ── Sin serie SIFEN la clave es la de ANTES de la mig 223 ──
 * La tercera parte se agrega SOLO cuando hay serie. No es cosmético: Punto está
 * en producción y hay devices con ventas emitidas offline que el servidor
 * todavía no vio, contando bajo `timbrado|punto`. Si la clave cambiara para
 * todas las cajas, esos contadores quedarían huérfanos, el bootstrap sembraría
 * la clave nueva con el número del servidor (atrasado) y el device reemitiría
 * números ya impresos. Con la forma compatible, una caja sin serie sigue en su
 * misma clave, y configurar una serie abre —como corresponde— otra.
 */
export function invoiceSeriesKey(
  auth: string | null | undefined,
  prefix: string | null | undefined,
  serie?: string | null,
): string {
  const base = `${(auth ?? "").trim()}|${(prefix ?? "").trim()}`
  const s = normalizeSerie(serie)
  return s === "" ? base : `${base}|${s}`
}

/**
 * La clave SIN serie SIFEN del mismo (timbrado, punto) de una clave con serie,
 * o `null` si la clave no tiene serie.
 *
 * Existe para una sola cosa: el piso del contador cuando se CONFIGURA una serie
 * en un punto que venía emitiendo sin ella (ver `primeInvoiceNumbering()`). Un
 * device con ventas offline sin sincronizar lleva ese punto más adelante que el
 * servidor; si la serie nueva se sembrara solo con el número del servidor, el
 * device volvería a usar números que ya imprimió en ese mismo punto.
 */
export function invoiceSeriesKeyWithoutSerie(seriesKey: string): string | null {
  const parts = seriesKey.split("|")
  return parts.length === 3 && parts[2] !== "" ? `${parts[0]}|${parts[1]}` : null
}

/**
 * Caja de una lista de cajas (la del bootstrap o la del store), o `null` si no
 * está. `PosRegister` trae las tres partes de la serie: `authNumber` es el
 * timbrado, `expeditionPoint` el `EEE-PPP` e `invoiceSerie` la serie SIFEN.
 *
 * ── `null` cuando la caja NO está en la lista ──
 * NO cae a la serie vacía. El BFF degrada `registers` a `[]` cuando el fetch
 * de cajas falla (`app/api/pos/bootstrap/route.ts`, "si el fetch falló,
 * degradar a lista vacía"), y ahí "no sé cuál es la serie" y "la caja no tiene
 * timbrado" son cosas distintas: confundirlas sembraría el contador bajo la
 * serie vacía y la venta siguiente —ya con las cajas cargadas— lo buscaría
 * bajo la serie real, no lo encontraría y cortaría con `NO_INVOICE_NUMBER`
 * teniendo el cajero conexión.
 */
function findRegister(
  registers: readonly PosRegister[] | null | undefined,
  registerId: string | null | undefined,
): PosRegister | null {
  if (!registerId) return null
  return (registers ?? []).find((r) => r.id === registerId) ?? null
}

/**
 * Clave de la serie de una caja. Cada llamador decide qué hacer sin serie;
 * ninguno puede inventarla. Nunca lanza: esto corre en el camino de emisión y
 * sin red.
 */
export function invoiceSeriesForRegister(
  registers: readonly PosRegister[] | null | undefined,
  registerId: string | null | undefined,
): string | null {
  const register = findRegister(registers, registerId)
  if (!register) return null
  return invoiceSeriesKey(register.authNumber, register.expeditionPoint, register.invoiceSerie)
}

/**
 * Serie SIFEN (`dSerieNum`) con la que la caja numera sus facturas, tal como
 * viaja congelada en la venta (`invoiceserie`). `''` = sin serie. `null` si la
 * caja no está en la lista — mismo criterio que `invoiceSeriesForRegister()`,
 * y los dos se leen de la MISMA caja en el mismo click, así que el número y la
 * serie que viajan en la venta no pueden salir de cajas distintas.
 */
export function invoiceSerieForRegister(
  registers: readonly PosRegister[] | null | undefined,
  registerId: string | null | undefined,
): string | null {
  const register = findRegister(registers, registerId)
  if (!register) return null
  return normalizeSerie(register.invoiceSerie)
}
