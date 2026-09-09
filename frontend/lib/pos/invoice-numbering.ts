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
 * Scope por `registerId` + SERIE: un device puede cambiar de caja desde
 * Ajustes, y el contador de una caja nunca debe contaminar al de otra.
 *
 * ── Por qué la serie entra en la clave (2026-09-09) ──
 * Hasta hoy la clave era solo `registerId`, o sea UNA para toda la vida de la
 * caja. Pero el correlativo no existe solo: la identidad fiscal es la tripleta
 * (timbrado, punto de expedición, correlativo) y cambiar cualquiera de los dos
 * primeros ABRE UNA SERIE NUEVA, que arranca en 1 (context/29 §2). Con la
 * clave vieja, cambiar el punto de expedición de `001-001` a `001-002` dejaba
 * al device contando por la serie anterior: mandó el número 838 contra un
 * punto que iba por 614.
 *
 * Con la serie en la clave el problema desaparece sin lógica extra —una serie
 * distinta es otra clave, sin contador viejo que proteger— y la regla de
 * "nunca pisar hacia abajo" queda donde tiene sentido: DENTRO de una serie.
 * Ver `lib/pos/invoice-series.ts`.
 */

const KEY_PREFIX = 'pos_invoice_next_no:'
const RANGE_KEY_PREFIX = 'pos_invoice_range_to:'

function storageKey(registerId: string, series: string): string {
  return `${KEY_PREFIX}${registerId}:${series}`
}

function rangeKey(registerId: string, series: string): string {
  return `${RANGE_KEY_PREFIX}${registerId}:${series}`
}

function loadNext(registerId: string, series: string): number | null {
  try {
    const raw = localStorage.getItem(storageKey(registerId, series))
    if (!raw) return null
    const n = Number(raw)
    return Number.isFinite(n) && n >= 1 ? n : null
  } catch {
    return null
  }
}

function saveNext(registerId: string, series: string, next: number): void {
  try {
    localStorage.setItem(storageKey(registerId, series), String(next))
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
 * NUNCA pisa hacia ABAJO **dentro de la misma serie**: si el valor local es
 * MAYOR que el del servidor (ventas emitidas offline que el server todavía no
 * sincronizó), el local manda — bajarlo reemitiría un número ya usado por este
 * mismo device.
 *
 * Entre series distintas esa regla no tiene sentido —el 838 de `001-001` no
 * dice nada sobre `001-002`— y ya no puede aplicarse por accidente: la serie
 * es parte de la clave, así que una serie nueva no encuentra contador local y
 * se siembra limpia con lo que diga el servidor.
 */
export function primeInvoiceNumbering(
  registerId: string,
  series: string,
  serverNext: number | null,
): void {
  if (!registerId || serverNext === null || !Number.isFinite(serverNext) || serverNext < 1) return

  discardLegacyCounter(registerId)
  const local = loadNext(registerId, series)
  // El máximo DENTRO DE LA SERIE, nunca entre series: `local` sale de la clave
  // nueva, que ya lleva la serie, así que comparar es legítimo. Si el device
  // está adelante (ventas emitidas offline que el servidor todavía no vio), su
  // valor manda — bajarlo reemitiría un número ya usado por este mismo device.
  //
  // Se escribe siempre, incluso cuando `local` ya es el mayor: es un no-op
  // barato y deja la clave sembrada aunque el flujo llegue acá dos veces.
  saveNext(registerId, series, Math.max(local ?? 0, serverNext))
}

/**
 * Migración de un solo uso de la clave ANTERIOR a las series
 * (`pos_invoice_next_no:<registerId>`, sin serie), que quedó huérfana al pasar
 * la serie a la clave.
 *
 * Sin esto, un device que estaba en la calle con ventas emitidas OFFLINE y sin
 * sincronizar (local 850, servidor todavía en 838) no encontraría contador
 * bajo la clave nueva y se resembraría en 838: reemitiría 838-849, que es
 * justamente el comprobante duplicado que las series vienen a evitar.
 *
 * ── Por qué el piso solo se adopta con `serverNext > 1` ──
 * La clave vieja NO dice a qué serie pertenece su número, y las dos lecturas
 * posibles son opuestas:
 *
 *   a) la serie no cambió → ese contador ES de la serie actual y adoptarlo
 *      como piso evita la reemisión;
 *   b) la serie cambió (el incidente: el punto pasó de `001-001` a `001-002`)
 *      → ese contador es de la serie VIEJA y adoptarlo la arrastraría a la
 *      nueva. Es exactamente el bug del 838 contra un punto que iba por 614.
 *
 * `serverNext` desempata sin adivinar: una serie recién abierta no tiene fila
 * en `document_sequence` y el servidor reporta 1 — caso (b), no se adopta. Si
 * reporta más de 1, la serie ya venía emitiendo y el contador viejo le
 * corresponde — caso (a), se adopta.
 *
 * La clave vieja se BORRA en los dos casos: dejarla viva permitiría que una
 * serie posterior la adoptara por error, y el dato ya se usó (o se descartó a
 * conciencia) en la primera hidratación.
 */
/**
 * Descarta el contador de la clave VIEJA (la que no llevaba serie).
 *
 * Se borra y NO se adopta como piso, y esa es una decisión deliberada del
 * owner (2026-09-09). La adopción existía para no perder el piso de un device
 * con ventas emitidas offline que el servidor todavía no vio — pero ese valor
 * NO sabe a qué serie pertenecía, porque la clave vieja no guardaba serie.
 *
 * En la caja del incidente eso resucitaba el bug entero: el device tenía 839
 * de la serie `001-001`, el servidor decía 615 para `001-002`, y el
 * `Math.max` devolvía 839 — el número que se mandó contra el punto
 * equivocado y que originó todo este trabajo.
 *
 * Se puede descartar sin pérdida porque al momento de este cambio NO hay
 * ningún documento emitido: nada llegó a SIFEN, nada se imprimió, y no hay
 * ventas offline pendientes de sincronizar. Protegía algo que no existe a
 * cambio de un riesgo que sí.
 *
 * De acá en adelante el piso lo protege la clave nueva, que sí lleva la
 * serie: dentro de una misma serie el local adelantado sigue mandando.
 */
function discardLegacyCounter(registerId: string): void {
  try {
    localStorage.removeItem(KEY_PREFIX + registerId)
    localStorage.removeItem(RANGE_KEY_PREFIX + registerId)
  } catch {
    // localStorage bloqueado (incógnito, cuota llena): no hay nada que
    // limpiar y tampoco nada que adoptar. El bootstrap siembra igual.
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
export function getNextInvoiceNo(registerId: string, series: string): number {
  const current = loadNext(registerId, series)
  if (current === null) {
    throw new Error('NO_INVOICE_NUMBER')
  }
  saveNext(registerId, series, current + 1)
  return current
}

/**
 * El próximo comprobante SIN consumirlo — solo para mostrarlo en pantalla.
 *
 * Existe porque el cajero tiene que poder ver con qué número va a salir la
 * factura ANTES de confirmar el cobro, y `getNextInvoiceNo()` no sirve para
 * eso: consume el número y lo persiste en el acto. Mirar no puede gastar un
 * correlativo.
 *
 * `null` = este device todavía no conoce el correlativo de esta serie. El
 * caller no muestra nada; el impedimento de vender sin número ya lo resuelve
 * el gate del botón de cobro, no este helper.
 */
export function peekNextInvoiceNo(registerId: string, series: string): number | null {
  if (!registerId) return null
  return loadNext(registerId, series)
}

/**
 * Persiste el techo del rango autorizado del timbrado (D5, context/37) junto
 * al contador — misma vida y mismo scope por caja. `null` explícito BORRA el
 * valor guardado: si el panel quitó el rango, el preaviso deja de aplicar (no
 * puede quedar un techo viejo avisando de un timbrado que ya no rige).
 */
export function primeInvoiceRange(
  registerId: string,
  series: string,
  rangeTo: number | null,
): void {
  if (!registerId) return
  try {
    if (rangeTo === null || !Number.isFinite(rangeTo) || rangeTo < 1) {
      localStorage.removeItem(rangeKey(registerId, series))
    } else {
      localStorage.setItem(rangeKey(registerId, series), String(rangeTo))
    }
  } catch {
    // best-effort, mismo criterio que saveNext().
  }
}

/**
 * Cuántos números autorizados le quedan a la caja, contando el próximo a
 * emitir — `techo - próximo + 1`. Usa el contador LOCAL (el que numera de
 * verdad, incluidas las ventas offline que el server todavía no vio), así el
 * preaviso no se queda atrás justo cuando más importa: emitiendo sin red.
 *
 * `null` = no se puede saber (sin rango cargado, o el device nunca conoció el
 * correlativo de esta caja) → el caller no avisa nada.
 */
export function peekInvoiceRemaining(registerId: string, series: string): number | null {
  if (!registerId) return null
  const next = loadNext(registerId, series)
  if (next === null) return null
  let rangeTo: number | null = null
  try {
    const raw = localStorage.getItem(rangeKey(registerId, series))
    if (raw) {
      const n = Number(raw)
      rangeTo = Number.isFinite(n) && n >= 1 ? n : null
    }
  } catch {
    return null
  }
  if (rangeTo === null) return null
  return rangeTo - next + 1
}
