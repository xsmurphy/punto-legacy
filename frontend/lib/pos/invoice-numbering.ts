/**
 * Numeración de comprobantes del POS — "último correlativo de mi caja + 1".
 *
 * Reemplaza a `numbering-lease.ts` (arriendo de bloques de números vía
 * `/v1/numbering/lease`), RECHAZADO por el owner 2026-08-17 — ver
 * context/29-numeracion-y-exclusividad-de-caja.md §6: la unicidad del punto
 * de expedición ya resuelve sola el problema que el arriendo intentaba
 * resolver (cada caja tiene su propia rama de numeración fiscal, ninguna
 * otra caja puede pisarla — "una caja no tiene con quién chocar", §3).
 *
 * Modelo nuevo: el device conoce el último correlativo que emitió SU caja y
 * simplemente suma uno. No hay reserva, no hay TTL, no hay "renovar" — el
 * único requisito es que el device sepa cuál fue su último número, y eso
 * sobrevive en `localStorage` (a diferencia del carrito, que NO se persiste
 * por decisión del owner: un número ya EMITIDO no puede "perderse" en un
 * reload offline, se volvería un hueco invisible y el cajero podría
 * reemitir el mismo número por accidente).
 *
 * Scope por `registerId`: un device puede cambiar de caja desde Ajustes, y
 * el contador de una caja nunca debe contaminar al de otra.
 */

const KEY_PREFIX = 'pos_invoice_next_no:'

function storageKey(registerId: string): string {
  return KEY_PREFIX + registerId
}

function loadNext(registerId: string): number | null {
  try {
    const raw = localStorage.getItem(storageKey(registerId))
    if (!raw) return null
    const n = Number(raw)
    return Number.isFinite(n) && n >= 1 ? n : null
  } catch {
    return null
  }
}

function saveNext(registerId: string, next: number): void {
  try {
    localStorage.setItem(storageKey(registerId), String(next))
  } catch {
    // best-effort — un localStorage lleno/bloqueado (privado/incógnito) no
    // debe tirar la venta; el próximo getNextInvoiceNo() simplemente no
    // tendrá este valor persistido y, si el bootstrap no lo puede sembrar
    // de nuevo, cae al gate de "sin número" (mismo criterio que siempre).
  }
}

/**
 * Siembra (o corrige hacia adelante) el contador local desde lo que el
 * servidor reporta como próximo correlativo de esta caja (`GET /v1/register`
 * → `docNumbers().invoiceNo`, expuesto en el bootstrap del POS como
 * `PosBootstrap.nextInvoiceNo` — ver `frontend/app/api/pos/bootstrap/
 * route.ts` y `use-catalog-seed.ts`, que llama a esto en cada hidratación).
 *
 * Llamar SIEMPRE que el bootstrap traiga un valor nuevo — primer arranque
 * del device (nunca vendió acá, no hay nada en localStorage) y también en
 * cada refresh posterior, por si otro proceso (panel editando la
 * numeración, otro device que tuvo la caja antes) movió la secuencia hacia
 * adelante.
 *
 * NUNCA pisa hacia ABAJO: si el valor local es MAYOR que el del servidor
 * (ventas emitidas offline que el server todavía no sincronizó), el local
 * manda — bajarlo reemitiría un número ya usado por este mismo device.
 */
export function primeInvoiceNumbering(registerId: string, serverNext: number | null): void {
  if (!registerId || serverNext === null || !Number.isFinite(serverNext) || serverNext < 1) return
  const local = loadNext(registerId)
  if (local === null || serverNext > local) {
    saveNext(registerId, serverNext)
  }
}

/**
 * Consume el próximo correlativo de `registerId` y persiste el siguiente de
 * inmediato — antes de que el caller intente nada con el número, para que
 * un reload a mitad de venta nunca reemita el mismo valor.
 *
 * Lanza `NO_INVOICE_NUMBER` si este device nunca llegó a conocer un
 * correlativo para esta caja (ni local, ni bootstrap alguna vez exitoso) —
 * el gate de "ningún documento sale sin número" (context/29 §5) sigue
 * valiendo, pero ahora es un caso RARÍSIMO (un device que jamás tuvo
 * conexión en esta caja), no el modo normal de operar offline que era con
 * los bloques arrendados.
 */
export function getNextInvoiceNo(registerId: string): number {
  const current = loadNext(registerId)
  if (current === null) {
    throw new Error('NO_INVOICE_NUMBER')
  }
  saveNext(registerId, current + 1)
  return current
}
