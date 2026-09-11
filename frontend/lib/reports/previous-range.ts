/**
 * El rango del período anterior, para las comparativas de los reportes.
 *
 * Vive fuera de la página porque es aritmética pura y tiene guard propio: el
 * bug que corrige no se ve en pantalla —la comparativa simplemente compara
 * contra el día equivocado— así que un test es la única forma de sostenerlo.
 */

function toBackendFormat(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0")
  return (
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ` +
    `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
  )
}

/**
 * El período inmediatamente anterior, de la misma cantidad de DÍAS.
 *
 * Se cuenta en días de calendario y no restando milisegundos, que era el bug:
 * el rango llega como `00:00:00` → `23:59:59.999999`, así que restar esa
 * duración dejaba el período anterior arrancando a las 23:59:59 del primer
 * día. Dos consecuencias, las dos silenciosas:
 *
 *  1. El backend rellena un bucket POR FECHA de calendario, así que ese
 *     arranque a último momento agregaba un día más: 31 buckets contra 30, y
 *     como el chart aparea por POSICIÓN, toda la comparativa quedaba corrida
 *     un día.
 *  2. Ese primer bucket cubría un solo segundo, o sea entraba como un ~0 que
 *     parecía un día pésimo del período anterior.
 */
/**
 * Variación porcentual contra el período anterior, en el formato que espera
 * `StatTile.delta.pct`.
 *
 * `null` cuando el anterior fue cero y este no: el porcentaje sería infinito
 * y el tile dice "Sin base para comparar" en vez de inventar un número. Cero
 * contra cero es "sin cambios" (0), no una división imposible.
 *
 * Vive acá, al lado de `shiftRangeBackwards`, porque las dos mitades de "compará
 * contra el período anterior" van juntas; hasta 2026-09-10 era una función
 * local de `/reports/summary` y el siguiente reporte con delta la iba a copiar.
 */
export function pctDelta(curr: number, prev: number): number | null {
  if (prev === 0) {
    if (curr === 0) return 0
    return null
  }
  return ((curr - prev) / Math.abs(prev)) * 100
}

export function shiftRangeBackwards(from: string, to: string): { from: string; to: string } {
  // 'YYYY-MM-DD HH:mm:ss' es compatible con `new Date` en navegador y server.
  const f = new Date(from.replace(" ", "T"))
  const t = new Date(to.replace(" ", "T"))

  const startOfDay = (d: Date) =>
    new Date(d.getFullYear(), d.getMonth(), d.getDate())
  const addDays = (d: Date, n: number) =>
    new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)

  const fDay = startOfDay(f)
  const tDay = startOfDay(t)
  // Días que abarca el rango, inclusive. Un rango de un solo día da 1.
  const days = Math.max(
    1,
    Math.round((tDay.getTime() - fDay.getTime()) / 86_400_000) + 1,
  )

  const prevFromDay = addDays(fDay, -days)
  const prevToDay = addDays(fDay, -1)
  const prevTo = new Date(prevToDay)
  prevTo.setHours(23, 59, 59, 999)

  return {
    from: toBackendFormat(prevFromDay),
    to: toBackendFormat(prevTo),
  }
}
