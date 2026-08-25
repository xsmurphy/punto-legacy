/**
 * El total del turno según ESTE dispositivo — el que se muestra cuando no hay
 * servidor para preguntarle.
 *
 * Por qué ahora sí se muestra
 * ───────────────────────────
 * La versión anterior (context/51 §4) no mostraba ningún total sin red. El
 * argumento era que el device no ve las ventas que llegaron al servidor por
 * otro camino, y que un total corto en una pantalla de arqueo hace que un
 * faltante parezca cuadrar. Ese argumento supone que otro aparato puede haber
 * vendido en la misma caja sin que este se entere — y eso ya no puede pasar
 * mientras la tenencia sea de este device: `register_lease` (mig 141/143) la
 * hace EXCLUSIVA, y el grant local (`register-tenancy.ts`) la hace conocida
 * sin red. Mientras la caja es mía, mis ventas son el turno.
 *
 * Los huecos que quedan, y qué se hace con cada uno
 * ─────────────────────────────────────────────────
 * 1. **Ventas del turno anteriores a mi tenencia.** Otro device tenía la caja
 *    y la liberó a mitad del turno. DETECTABLE: `heldSince > shiftOpenDate`
 *    ⇒ hueco `tenancy-mid-shift`.
 * 2. **Movimientos de efectivo hechos desde el panel** mientras la caja estaba
 *    sin red. NO detectable desde el device — es una advertencia permanente,
 *    `panel-movements`, que acompaña siempre al total.
 * 3. **Cobros de crédito y otras operaciones que suman al arqueo** sin pasar
 *    por esta caja. Misma condición que 2 y va en la misma advertencia.
 *
 * Y dos más que aparecen del lado del propio registro:
 * 4. `no-open-entry` — el turno no se abrió desde este aparato, así que no
 *    conoce el monto inicial del cajón.
 * 5. `journal-mid-shift` — el registro local empezó con el turno ya abierto
 *    (app actualizada, base limpiada), o `shift-open-unknown` si ni siquiera
 *    se sabe cuándo abrió el turno.
 *
 * La regla que gobierna el diseño: mejor un total con la advertencia escrita
 * que un total mudo. Lo que NO se hace nunca es presentarlo como el cierre —
 * el arqueo definitivo lo calcula el servidor con el monto contado, igual que
 * siempre (ver `shift-close-reconciliation.ts` para qué pasa si difieren).
 *
 * Módulo PURO a propósito: sin IndexedDB, sin red, sin React. Es plata, y la
 * aritmética de la plata tiene que poder probarse sin montar nada.
 */

import type { ShiftJournalPayment, ShiftJournalRow } from '@/lib/pos/offline-db'

// ── Huecos ────────────────────────────────────────────────────────────────────

export type LocalShiftGap =
  /** La caja la tenía otro dispositivo cuando el turno abrió. */
  | 'tenancy-mid-shift'
  /** El turno no se abrió desde este dispositivo: no conoce el monto inicial. */
  | 'no-open-entry'
  /** El registro local arrancó con el turno ya empezado. */
  | 'journal-mid-shift'
  /** No se sabe cuándo abrió el turno, así que no hay ventana que recortar. */
  | 'shift-open-unknown'
  /** Permanente: lo que no pasa por esta caja (panel, otras cajas) no se ve. */
  | 'panel-movements'

// ── Resultado ─────────────────────────────────────────────────────────────────

export interface LocalShiftMethodRow {
  name: string
  amount: number
}

export interface LocalShiftTotals {
  /** Ventas anotadas en la ventana del turno (sin internas). */
  salesCount: number
  /** Suma de todos los medios de pago de esas ventas. */
  salesTotal: number
  /** Desglose por medio de pago, ordenado por monto desc. */
  byMethod: LocalShiftMethodRow[]
  /** Parte de `salesTotal` cobrada en efectivo. */
  cashSales: number
  /** Monto inicial del cajón, si la apertura la hizo este dispositivo. */
  openAmount: number
  cashIn: number
  cashOut: number
  /** Efectivo que debería haber en el cajón: inicial + ventas efectivo + ingresos − extracciones. */
  cashTotal: number
  /** Total del turno: inicial + ventas + ingresos − extracciones. Misma fórmula que `DrawerService::composeSummary`. */
  total: number
  /** Qué NO cubre este total. Nunca vacío: `panel-movements` está siempre. */
  gaps: LocalShiftGap[]
  /** Inicio de la ventana considerada (naive tenant-local), o `null`. */
  windowStart: string | null
  /** Sin ventas ni movimientos anotados: no hay nada que mostrar salvo el inicial. */
  empty: boolean
}

export interface LocalShiftTotalsInput {
  entries: ShiftJournalRow[]
  /** Apertura del turno en curso según el estado local (naive tenant-local). */
  shiftOpenDate: string | null
  /** Desde cuándo este device tiene la caja (naive tenant-local). */
  heldSince: string | null
  /** Desde cuándo el journal viene anotando en esta caja. */
  journalSince: string | null
  /**
   * Control de caja a ciegas. Con esto prendido el cajero arquea SIN ver lo
   * esperado, y eso no cambia porque se caiga la red: sin conexión tampoco hay
   * total. La regla vive acá, en el cálculo, y no en el JSX, para que no haya
   * una segunda pantalla que se olvide de respetarla.
   */
  blindControl: boolean
}

// ── Medios de pago ────────────────────────────────────────────────────────────

/**
 * ¿Este medio es efectivo? Mismo criterio que `composeSummary` server-side
 * (`in_array(strtolower($type), ['cash','efectivo'])`), con el nombre como
 * respaldo para medios viejos que viajaban sin `type`.
 */
export function isCashPayment(p: ShiftJournalPayment): boolean {
  const type = (p.type ?? '').toLowerCase()
  if (type !== '') return type === 'efectivo' || type === 'cash'
  const name = (p.name ?? '').toLowerCase()
  return name === 'efectivo' || name === 'cash'
}

/**
 * Clave con la que un medio de pago se identifica dentro del arqueo.
 *
 * Espejo exacto de `DrawerService::paymentGroupKey()` (PHP): el nombre
 * resuelto, en minúsculas y sin espacios sobrantes. Las dos mitades del arqueo
 * —lo que el servidor espera y lo que la caja contó— tienen que producir la
 * MISMA clave o el emparejamiento no ocurre, así que la fórmula está escrita
 * en los dos lados y en ninguno más.
 */
export function paymentGroupKey(name: string): string {
  return name.trim().toLowerCase()
}

/** Un medio de pago que hubo que contar, sin ningún acumulado adentro. */
export interface ShiftMethod {
  key: string
  name: string
  /**
   * Slug/id del medio tal como lo guardó la venta (`type` del pago). Es la
   * identidad que el servidor y la caja comparten con certeza: el NOMBRE lo
   * reescribe el backend al resolver la taxonomía, así que el que esta caja
   * anotó al vender puede ser otro texto del mismo medio. Sin esto, un cierre
   * hecho sin conexión emparejaba mal y el medio entero salía como sobrante.
   */
  code?: string
  isCash: boolean
}

/**
 * Lo que el cajero declaró haber contado de un medio. Es lo que viaja al
 * servidor en el cierre (`counted` del payload) y lo que queda congelado en
 * `drawer_count` (mig 167).
 *
 * Vive con el cálculo y no con el diálogo que lo captura: lo consumen el
 * diálogo, el hook de la mutación y la cola de operaciones, y ninguno de esos
 * tres debería tener que importar un componente para saber qué manda.
 */
export interface CountedMethod extends ShiftMethod {
  counted: number
}

/**
 * QUÉ medios de pago tuvo el turno según este dispositivo — sin CUÁNTO.
 *
 * Existe separada de `computeLocalShiftTotals()` por una razón concreta: esa
 * función devuelve `null` con el control a ciegas prendido, y tiene que seguir
 * haciéndolo. Pero el cajero que arquea a ciegas igual necesita saber qué
 * medios contar: no ver los acumulados no es lo mismo que no saber que hubo
 * ventas con tarjeta.
 *
 * Es blind-safe POR CONSTRUCCIÓN, no por una condición que alguien pueda
 * olvidarse de escribir: no computa montos, así que no hay monto que se pueda
 * filtrar. Por eso no recibe `blindControl` — no tendría qué hacer con él.
 *
 * El efectivo va SIEMPRE y va primero, haya habido ventas en efectivo o no: el
 * fondo inicial está en el cajón desde que el turno abrió.
 */
export function computeLocalShiftMethods(input: {
  entries: ShiftJournalRow[]
  shiftOpenDate: string | null
  /** Nombre con el que este comercio llama al efectivo (del catálogo del POS). */
  cashName?: string
}): ShiftMethod[] {
  const lastOpenEntry = [...input.entries]
    .filter((e) => e.kind === 'drawerOpen')
    .sort((a, b) => (a.date < b.date ? -1 : 1))
    .pop()
  const windowStart = input.shiftOpenDate ?? lastOpenEntry?.date ?? null
  const inWindow = windowStart
    ? input.entries.filter((e) => e.date >= windowStart)
    : input.entries

  const cashName = (input.cashName ?? '').trim() || 'Efectivo'
  const byKey = new Map<string, ShiftMethod>()
  byKey.set(paymentGroupKey(cashName), {
    key: paymentGroupKey(cashName),
    name: cashName,
    code: 'cash',
    isCash: true,
  })

  for (const sale of inWindow) {
    if (sale.kind !== 'sale' || sale.internal === true) continue
    for (const p of sale.payments ?? []) {
      const name = (p.name ?? '').trim()
      if (name === '') continue
      // El efectivo ya está sembrado con el nombre del catálogo. Si las ventas
      // lo trajeron con otro nombre (histórico con el slug crudo), el del
      // catálogo manda y no se duplica la fila del cajón.
      if (isCashPayment(p)) continue
      const key = paymentGroupKey(name)
      if (!byKey.has(key)) {
        byKey.set(key, { key, name, code: (p.type ?? '').trim() || undefined, isCash: false })
      }
    }
  }

  const rows = [...byKey.values()]
  return [...rows.filter((r) => r.isCash), ...rows.filter((r) => !r.isCash)]
}

// ── Cálculo ───────────────────────────────────────────────────────────────────

/**
 * Total del turno según lo que este dispositivo registró.
 *
 * Devuelve `null` cuando no hay nada que mostrar: con `blindControl` prendido
 * (por diseño) — un `null` que el call-site tiene que respetar sin buscarle la
 * vuelta.
 */
export function computeLocalShiftTotals(
  input: LocalShiftTotalsInput,
): LocalShiftTotals | null {
  if (input.blindControl) return null

  const gaps = new Set<LocalShiftGap>()
  // Siempre presente: nada de lo que no pasa por esta caja —una extracción
  // hecha desde el panel, un cobro de crédito en otra caja— puede verse desde
  // acá, y esa limitación no depende del estado de nada.
  gaps.add('panel-movements')

  // Ventana: la apertura conocida manda; si no se sabe, la última apertura
  // anotada; si tampoco, se toma todo lo que haya y se dice que no se sabe.
  const lastOpenEntry = [...input.entries]
    .filter((e) => e.kind === 'drawerOpen')
    .sort((a, b) => (a.date < b.date ? -1 : 1))
    .pop()
  const windowStart = input.shiftOpenDate ?? lastOpenEntry?.date ?? null
  if (input.shiftOpenDate === null) gaps.add('shift-open-unknown')

  const inWindow = windowStart
    ? input.entries.filter((e) => e.date >= windowStart)
    : input.entries

  // Apertura DEL turno en curso: la que cae dentro de la ventana. Sin ella no
  // se conoce el monto inicial del cajón, y el efectivo esperado sale corto en
  // exactamente esa cantidad — hay que decirlo.
  const openEntry = inWindow.filter((e) => e.kind === 'drawerOpen').pop()
  if (!openEntry) gaps.add('no-open-entry')

  if (windowStart && input.heldSince && input.heldSince > windowStart) {
    gaps.add('tenancy-mid-shift')
  }
  if (windowStart && input.journalSince && input.journalSince > windowStart) {
    gaps.add('journal-mid-shift')
  }

  const sales = inWindow.filter((e) => e.kind === 'sale' && e.internal !== true)
  const byMethodMap = new Map<string, number>()
  let salesTotal = 0
  let cashSales = 0
  for (const sale of sales) {
    for (const p of sale.payments ?? []) {
      const amount = p.total || 0
      salesTotal += amount
      if (isCashPayment(p)) cashSales += amount
      byMethodMap.set(p.name, (byMethodMap.get(p.name) ?? 0) + amount)
    }
  }

  const sumOf = (kind: ShiftJournalRow['kind']) =>
    inWindow.filter((e) => e.kind === kind).reduce((sum, e) => sum + (e.amount || 0), 0)

  const openAmount = openEntry?.amount ?? 0
  const cashIn = sumOf('drawerIncome')
  const cashOut = sumOf('drawerExpense')

  return {
    salesCount: sales.length,
    salesTotal,
    byMethod: [...byMethodMap.entries()]
      .map(([name, amount]) => ({ name, amount }))
      .sort((a, b) => b.amount - a.amount),
    cashSales,
    openAmount,
    cashIn,
    cashOut,
    cashTotal: openAmount + cashSales + cashIn - cashOut,
    total: openAmount + salesTotal + cashIn - cashOut,
    gaps: [...gaps],
    windowStart,
    empty: sales.length === 0 && cashIn === 0 && cashOut === 0,
  }
}

// ── Copy de los huecos ────────────────────────────────────────────────────────

/**
 * Una línea por hueco, en el orden en que le importan al cajero: primero lo
 * que puede explicar una diferencia grande, después lo permanente.
 *
 * El texto vive con el cálculo porque es parte de la misma respuesta: mostrar
 * el número sin la advertencia que le corresponde sería peor que no mostrarlo.
 */
export function gapMessages(gaps: LocalShiftGap[]): string[] {
  const order: LocalShiftGap[] = [
    'tenancy-mid-shift',
    'no-open-entry',
    'shift-open-unknown',
    'journal-mid-shift',
    'panel-movements',
  ]
  const text: Record<LocalShiftGap, string> = {
    'tenancy-mid-shift':
      'Este dispositivo tomó la caja con el turno ya abierto: puede haber ventas anteriores que no registró.',
    'no-open-entry':
      'El turno no se abrió desde este dispositivo, así que no conoce el monto inicial del cajón.',
    'shift-open-unknown':
      'No se pudo confirmar cuándo abrió el turno: el total puede incluir operaciones de un turno anterior.',
    'journal-mid-shift':
      'Este dispositivo empezó a registrar con el turno ya empezado.',
    'panel-movements':
      'No incluye movimientos hechos desde el panel ni operaciones de otras cajas.',
  }
  return order.filter((g) => gaps.includes(g)).map((g) => text[g])
}
