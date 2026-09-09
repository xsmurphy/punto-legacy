/**
 * Identidad de la SERIE fiscal en el device: (timbrado, punto de expedición).
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
 * ── Esta clave es del localStorage, no de la base ──
 * En el servidor la identidad de la serie son DOS COLUMNAS de
 * `document_sequence` (`invoiceauth`, `prefix` — mig 209). Este string existe
 * solo para namespacear el almacenamiento local del device; no viaja en
 * ningún payload y no tiene que coincidir byte a byte con nada del backend.
 */

import type { PosRegister } from "@/lib/types/pos-bootstrap"

/**
 * Clave canónica de una serie. El separador `|` no puede aparecer en ninguna
 * de las dos partes: el timbrado valida `^\d+$` y el punto de expedición
 * `^\d{3}-\d{3}$`.
 *
 * Una caja sin datos fiscales cargados da `"|"` — la "serie vacía", que es
 * una serie legítima: es la caja que todavía no tiene timbrado. Su contador no
 * se mezcla con el de ninguna serie real, que es justamente lo que queremos.
 */
export function invoiceSeriesKey(
  auth: string | null | undefined,
  prefix: string | null | undefined,
): string {
  return `${(auth ?? "").trim()}|${(prefix ?? "").trim()}`
}

/**
 * Serie de una caja dentro de una lista de cajas (la del bootstrap o la del
 * store). `PosRegister` ya trae las dos mitades: `authNumber` es el timbrado y
 * `expeditionPoint` el `EEE-PPP`.
 *
 * ── `null` cuando la caja NO está en la lista ──
 * NO cae a la serie vacía. El BFF degrada `registers` a `[]` cuando el fetch
 * de cajas falla (`app/api/pos/bootstrap/route.ts`, "si el fetch falló,
 * degradar a lista vacía"), y ahí "no sé cuál es la serie" y "la caja no tiene
 * timbrado" son cosas distintas: confundirlas sembraría el contador bajo la
 * serie vacía y la venta siguiente —ya con las cajas cargadas— lo buscaría
 * bajo la serie real, no lo encontraría y cortaría con `NO_INVOICE_NUMBER`
 * teniendo el cajero conexión.
 *
 * Cada llamador decide qué hacer sin serie; ninguno puede inventarla. Nunca
 * lanza: esto corre en el camino de emisión y sin red.
 */
export function invoiceSeriesForRegister(
  registers: readonly PosRegister[] | null | undefined,
  registerId: string | null | undefined,
): string | null {
  if (!registerId) return null
  const register = (registers ?? []).find((r) => r.id === registerId)
  if (!register) return null
  return invoiceSeriesKey(register.authNumber, register.expeditionPoint)
}
