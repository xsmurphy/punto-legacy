/**
 * Qué pasó con el cierre que se hizo sin red, cuando por fin llega al servidor.
 *
 * El problema
 * ───────────
 * Sin conexión el cajero ve el total que este dispositivo registró
 * (`local-shift-total.ts`) y cierra con el efectivo que contó. Horas después la
 * cola drena y el servidor arma el arqueo REAL, con todo lo que el device no
 * podía ver: ventas anteriores a su tenencia, extracciones hechas desde el
 * panel, cobros de crédito. Si esos dos números no coinciden, nadie se entera:
 * la operación sale de la cola en silencio y el número del servidor vive en un
 * reporte que quizás nadie abra. Y la diferencia entre esos dos números es
 * exactamente la información que un arqueo existe para producir.
 *
 * La solución
 * ───────────
 * El cierre viaja con el total que el device tenía (`localTotals` en el payload
 * de la operación — se guarda, no se manda: el servidor no lo necesita para
 * cerrar) y `POST /api/pos/drawer` con `action=close` devuelve ahora los
 * totales del turno tal como quedaron server-side. Al aplicarse la operación se
 * comparan y el resultado se PERSISTE, así que sobrevive al reinicio y espera
 * ahí hasta que alguien lo mire. Coincidan o no: el cajero cerró a ciegas y
 * tiene derecho a ver cómo terminó. Si difieren, además, se dice fuerte.
 *
 * Por qué la comparación es confiable: el motor no manda el cierre hasta que
 * las ventas del turno terminaron de sincronizar (ver `canSendPendingOp` en
 * `pending-ops-transport.ts`). Sin esa espera, el servidor cerraría el turno
 * sin las ventas que todavía están en la cola y toda diferencia sería un falso
 * positivo.
 */

import { getPosOfflineDB } from '@/lib/pos/offline-db'

/** Totales que el device tenía al momento de cerrar. Viajan dentro del payload de la operación. */
export interface LocalCloseTotals {
  /** Total del turno según el device (misma fórmula que `composeSummary`). */
  total: number
  /** Efectivo esperado en el cajón según el device. */
  cash: number
  /** Ventas que el device registró en el turno. */
  salesCount: number
  /** Huecos declarados al mostrar ese total — el "sí, ya sabíamos que podía faltar esto". */
  gaps: string[]
}

/** Una fila del arqueo del servidor: un medio de pago, esperado contra contado. */
export interface ServerCloseMethodRow {
  key: string
  name: string
  isCash: boolean
  expected: number | null
  /** `null` = el cierre no declaró nada de ese medio (cliente sin actualizar). */
  counted: number | null
  difference: number | null
}

/** Lo que devuelve el servidor al cerrar: el arqueo tal como quedó. */
export interface ServerCloseTotals {
  date: string
  total: number
  subtotal: number
  salesTotal: number
  returns: number
  /**
   * Arqueo por medio de pago (mig 167). `[]` con un backend sin deployar —
   * nunca filas inventadas: una fila de más acá es un medio que el informe
   * afirma haber arqueado y no arqueó.
   */
  byMethod: ServerCloseMethodRow[]
}

/**
 * Informe guardado del cierre. `null` en los campos que no se pudieron
 * conocer: un backend sin deployar no devuelve totales, y eso no puede romper
 * nada — simplemente no hay comparación que mostrar.
 */
export interface ShiftCloseReport {
  registerId: string
  /** Hora local del tenant en que el cajero cerró. */
  closedAt: string
  /** Monto contado que el cajero ingresó. */
  counted: number
  local: LocalCloseTotals | null
  server: ServerCloseTotals | null
  /** `server.total − local.total`. `null` si falta alguno de los dos. */
  diff: number | null
  /** ISO en que se sincronizó el cierre (reloj del device). */
  syncedAt: string
}

/**
 * Tolerancia de la comparación: medio centavo. No es para "amortiguar"
 * diferencias reales sino para no reportar el ruido del punto flotante — dos
 * sumas de los mismos importes hechas en distinto orden pueden diferir en
 * 1e-10, y eso no es un faltante.
 */
export const CLOSE_DIFF_EPSILON = 0.005

/** ¿El total del servidor coincide con el del device? Pura, para poder probarla. */
export function closeTotalsMatch(
  local: LocalCloseTotals | null,
  server: ServerCloseTotals | null,
): boolean {
  if (!local || !server) return true
  return Math.abs(server.total - local.total) < CLOSE_DIFF_EPSILON
}

const reportKey = (registerId: string) => `shift-close-report:${registerId}`

/**
 * Arma el informe y lo guarda. Devuelve lo guardado para que el llamador
 * pueda avisar en el momento, además de dejarlo para después.
 */
export async function saveShiftCloseReport(input: {
  registerId: string
  closedAt: string
  counted: number
  local: LocalCloseTotals | null
  server: ServerCloseTotals | null
}): Promise<ShiftCloseReport> {
  const report: ShiftCloseReport = {
    registerId: input.registerId,
    closedAt: input.closedAt,
    counted: input.counted,
    local: input.local,
    server: input.server,
    diff:
      input.local && input.server
        ? Math.round((input.server.total - input.local.total) * 100) / 100
        : null,
    syncedAt: new Date().toISOString(),
  }
  if (typeof indexedDB !== 'undefined') {
    try {
      const db = await getPosOfflineDB()
      await db.put('snapshots', {
        key: reportKey(input.registerId),
        savedAt: report.syncedAt,
        payload: report,
      })
    } catch {
      // Si no se puede persistir, el aviso del momento sigue saliendo. Lo que
      // se pierde es poder volver a leerlo mañana.
    }
  }
  return report
}

/** El informe pendiente de esta caja, si hay alguno sin descartar. */
export async function readShiftCloseReport(
  registerId: string,
): Promise<ShiftCloseReport | null> {
  if (typeof indexedDB === 'undefined' || registerId === '') return null
  try {
    const db = await getPosOfflineDB()
    const row = await db.get('snapshots', reportKey(registerId))
    return (row?.payload as ShiftCloseReport | undefined) ?? null
  } catch {
    return null
  }
}

/** El operador lo leyó y lo da por visto. */
export async function clearShiftCloseReport(registerId: string): Promise<void> {
  if (typeof indexedDB === 'undefined' || registerId === '') return
  try {
    const db = await getPosOfflineDB()
    await db.delete('snapshots', reportKey(registerId))
  } catch {
    /* nada que hacer: el informe queda y se puede descartar de nuevo */
  }
}

/**
 * Lee la respuesta del cierre. Tolera un backend sin el campo (devuelve
 * `null`) en vez de asumir ceros — un cero inventado acá se leería como "el
 * servidor dice que el turno fue de 0", que es una acusación, no un dato.
 */
export function parseServerCloseTotals(result: unknown): ServerCloseTotals | null {
  const closing = (result as { closing?: unknown } | null | undefined)?.closing
  if (!closing || typeof closing !== 'object') return null
  const c = closing as Record<string, unknown>
  const num = (v: unknown) => (typeof v === 'number' ? v : Number(v))
  const total = num(c.total)
  if (!Number.isFinite(total)) return null
  /** `null` se conserva: es "no se sabe", que no es lo mismo que cero. */
  const nullableNum = (v: unknown): number | null => {
    if (v === null || v === undefined) return null
    const n = num(v)
    return Number.isFinite(n) ? n : null
  }
  return {
    date: typeof c.date === 'string' ? c.date : '',
    total,
    subtotal: Number.isFinite(num(c.subtotal)) ? num(c.subtotal) : 0,
    salesTotal: Number.isFinite(num(c.salesTotal)) ? num(c.salesTotal) : 0,
    returns: Number.isFinite(num(c.returns)) ? num(c.returns) : 0,
    byMethod: Array.isArray(c.byMethod)
      ? c.byMethod.flatMap((raw): ServerCloseMethodRow[] => {
          if (!raw || typeof raw !== 'object') return []
          const r = raw as Record<string, unknown>
          const key = typeof r.key === 'string' ? r.key : ''
          if (key === '') return []
          return [{
            key,
            name: typeof r.name === 'string' && r.name !== '' ? r.name : key,
            isCash: r.isCash === true,
            expected: nullableNum(r.expected),
            counted: nullableNum(r.counted),
            difference: nullableNum(r.difference),
          }]
        })
      : [],
  }
}
