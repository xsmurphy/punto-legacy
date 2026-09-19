/**
 * Qué bloques del dashboard se muestran — reglas puras, sin React.
 *
 * La regla del owner (cerrada): "hay que mostrar el bloque si hay info y no
 * llenar el dashboard con bloques vacíos". Punto es multi-rubro: hay comercios
 * sin stock, sin crédito, sin FE, sin gift cards, que no cargan clientes. Un
 * bloque o fila se muestra SOLO si el comercio usa esa capacidad y hay algo que
 * mostrar; si no, no se renderiza — nunca un EmptyState en su lugar. Un gráfico
 * de una sola porción al 100% tampoco informa.
 *
 * Vive acá, separado de la página, para que cada decisión tenga su test.
 */

import type {
  CustomersWidget,
  IncomeOutcomeStatsWidget,
  InfoWidget,
  PaymentStatusWidget,
  PeriodStats,
  SalesByOutletRow,
  TopHoursWidget,
  TopItemRow,
  TopTaxonomyRow,
} from "@/hooks/use-dashboard-widget"
import type { StatDelta } from "@/components/stat-tile"
import { pctDelta } from "@/lib/reports/previous-range"

// ── Ahora ──────────────────────────────────────────────────────────────────

/** Orden de pantalla. Espejo de `NowService::TILES`. */
export const NOW_KEYS = ["orders", "spaces", "drawers", "staff", "agenda", "dues"] as const
export type NowKey = (typeof NOW_KEYS)[number]

export interface NowOrdersTile {
  key: "orders"
  href: string
  active: number
  /** Enviadas a cocina hace más de `lateMinutes` y todavía no listas. */
  late: number
  lateMinutes: number
}
export interface NowSpacesTile {
  key: "spaces"
  href: string
  total: number
  free: number
  occupied: number
  billRequested: number
}
export interface NowDrawersTile {
  key: "drawers"
  href: string
  count: number
  rows: { drawerId: string; registerName: string; outletName: string; operator: string; openedAt: string }[]
}
export interface NowStaffTile {
  key: "staff"
  href: string
  count: number
  people: { employeeId: string; name: string; since: string }[]
}
export interface NowAgendaTile {
  key: "agenda"
  href: string
  count: number
  next: { id: string; from: string; to: string; customer: string }[]
}
export interface NowDuePart {
  /** Vencen de hoy a 7 días. */
  count: number
  amount: number
  /** Ya vencidos (antes de hoy). */
  overdue: number
  /** Próximo vencimiento dentro de la semana, `YYYY-MM-DD`. */
  next: string | null
  href: string
}
export interface NowDuesTile {
  key: "dues"
  checks: NowDuePart | null
  payables: NowDuePart | null
}
export type NowTile =
  | NowOrdersTile
  | NowSpacesTile
  | NowDrawersTile
  | NowStaffTile
  | NowAgendaTile
  | NowDuesTile

export interface NowWidget {
  tiles: NowTile[]
}

const hasDue = (p: NowDuePart | null | undefined): boolean =>
  !!p && (Number(p.count) > 0 || Number(p.overdue) > 0)

/**
 * Parte de vencimientos a pintar: `null` si no hay nada que vencer ni vencido.
 * Red por si el backend manda una parte en cero.
 */
export function visibleDuePart(p: NowDuePart | null | undefined): NowDuePart | null {
  return hasDue(p) ? p! : null
}

/**
 * Filas de "Ahora" a pintar. El backend (`NowService`) ya manda solo las que
 * tienen algo y solo las que la persona puede abrir; esto es la red por si no:
 * clave desconocida (backend más nuevo que el front) o una fila en cero no se
 * pintan. Sin filas, el bloque entero no existe.
 */
export function visibleNowTiles(data: NowWidget | undefined): NowTile[] {
  const tiles = Array.isArray(data?.tiles) ? data.tiles : []
  return tiles.filter((t) => {
    switch (t?.key) {
      case "orders":
        return Number(t.active) > 0
      case "spaces":
        return Number(t.total) > 0
      case "drawers":
        return Number(t.count) > 0 && Array.isArray(t.rows) && t.rows.length > 0
      case "staff":
        return Number(t.count) > 0
      case "agenda":
        return Number(t.count) > 0
      case "dues":
        return hasDue(t.checks) || hasDue(t.payables)
      default:
        return false
    }
  })
}

// ── Comparativa con el período anterior ────────────────────────────────────

export type KpiKey = "total" | "expenses" | "revenue" | "margin" | "count" | "customerAverage"

/**
 * El delta de cada KPI del período contra el anterior (`stats.previous`), o
 * `undefined` cuando NO se muestra. La regla del dashboard es no pintar un
 * delta sin base — más estricta que el `StatTile` de los reportes, que dice
 * "Sin base para comparar":
 *
 *   - Sin período anterior con datos (`previous` null/ausente) → ningún delta.
 *   - Anterior en cero para ESA cifra → sin delta (el porcentaje sería infinito).
 *   - Ticket promedio: hace falta haber vendido en los DOS períodos; si no, el
 *     promedio de uno de ellos es un cero que no promedia nada.
 *   - Margen: en PUNTOS y solo con ingresos y egresos en los dos períodos — sin
 *     egresos el backend informa 100%, que no es un margen medido.
 *   - Egresos: subir es malo (`higherIsBetter: false`).
 */
export function kpiDeltas(
  stats: IncomeOutcomeStatsWidget | undefined,
): Partial<Record<KpiKey, StatDelta>> {
  const prev: PeriodStats | null | undefined = stats?.previous
  if (!stats || !prev) return {}
  const out: Partial<Record<KpiKey, StatDelta>> = {}

  const rel = (key: KpiKey, higherIsBetter = true) => {
    const curr = Number(stats[key] ?? 0)
    const before = Number(prev[key] ?? 0)
    if (before === 0) return
    const pct = pctDelta(curr, before)
    if (pct !== null) out[key] = { pct, higherIsBetter }
  }

  rel("total")
  rel("expenses", false)
  rel("revenue")
  rel("count")
  if (Number(stats.count) > 0 && Number(prev.count) > 0) rel("customerAverage")

  const measured = (p: PeriodStats) => Number(p.total) > 0 && Number(p.expenses) > 0
  if (measured(stats) && measured(prev)) {
    out.margin = { pct: Number(stats.margin) - Number(prev.margin), kind: "points" }
  }
  return out
}

// ── Ventas por sucursal ────────────────────────────────────────────────────

/**
 * Con una sola sucursal vendiendo, "por sucursal" es el mismo número que
 * Ingresos: el bloque solo existe con DOS o más sucursales con ventas en el
 * período, dentro del alcance del usuario (el backend ya filtra el alcance y
 * manda solo las que vendieron).
 */
export function showSalesByOutlet(rows: SalesByOutletRow[] | undefined): boolean {
  return Array.isArray(rows) && rows.filter((r) => Number(r.total) > 0).length >= 2
}

/** Delta de una sucursal contra su período anterior; `undefined` sin base. */
export function outletDelta(row: SalesByOutletRow): StatDelta | undefined {
  if (row.previous === null || row.previous === undefined || Number(row.previous) === 0) return undefined
  const pct = pctDelta(Number(row.total), Number(row.previous))
  return pct === null ? undefined : { pct }
}

// ── Requiere atención ──────────────────────────────────────────────────────

export const ATTENTION_KEYS = ["einvoice", "stock", "margin", "receivables", "attendance"] as const
export type AttentionKey = (typeof ATTENTION_KEYS)[number]

export interface AttentionRow {
  key: AttentionKey
  count: number
  /** Solo `receivables`: el monto vencido. `count` es la cantidad de clientes. */
  amount: number | null
  href: string
}

export interface AttentionWidget {
  rows: AttentionRow[]
}

/**
 * Filas a pintar. El backend ya manda solo las que tienen algo; esto es la
 * red por si no: clave desconocida (backend más nuevo que el front) o conteo
 * en cero no se pintan. Sin filas → la card entera no existe (silencio, no
 * "todo en orden").
 */
export function visibleAttentionRows(data: AttentionWidget | undefined): AttentionRow[] {
  const rows = Array.isArray(data?.rows) ? data.rows : []
  return rows.filter(
    (r) => (ATTENTION_KEYS as readonly string[]).includes(r.key) && Number(r.count) > 0,
  )
}

// ── Donuts de tipo de venta / cobranza ─────────────────────────────────────

/**
 * Un donut informa solo con DOS porciones. Contado vs crédito en un comercio
 * que no vende a crédito es un anillo entero de "al contado"; cobrado vs por
 * cobrar sin ventas a crédito son dos ceros.
 */
export function showSplitDonut(
  data: PaymentStatusWidget | undefined,
  mode: "sale-type" | "receivables",
): boolean {
  if (!data) return false
  const [a, b] = mode === "sale-type" ? [data.contado, data.credito] : [data.cobrado, data.porcobrar]
  return Number(a) > 0 && Number(b) > 0
}

// ── Rankings del período ───────────────────────────────────────────────────

export function showTopItems(rows: TopItemRow[] | undefined): boolean {
  return Array.isArray(rows) && rows.length > 0
}

export function showTopHours(data: TopHoursWidget | undefined): boolean {
  return Array.isArray(data?.hour) && data.hour.length > 0
}

/**
 * Hace falta más de UNA categoría: un comercio que no categoriza tiene todo en
 * "Sin categoría", y una barra sola al 100% es el mismo caso que el donut de
 * una porción.
 */
export function showTopCategories(rows: TopTaxonomyRow[] | undefined): boolean {
  return Array.isArray(rows) && rows.length > 1
}

// ── Clientes ───────────────────────────────────────────────────────────────

/**
 * La card habla de los clientes DEL PERÍODO (nuevos, recurrentes, retorno). Un
 * comercio que no identifica al cliente en la venta no tiene nada de eso: el
 * total histórico solo reflejaría el catálogo de contactos.
 */
export function showCustomers(data: CustomersWidget | undefined): boolean {
  return Number(data?.totalPeriod ?? 0) > 0
}

// ── Información general ────────────────────────────────────────────────────

export type InfoRowKey = "giftCards"

/**
 * Filas de "Información general":
 *   - Gift cards vigentes: solo si hay alguna.
 * El ticket promedio se mudó junto a Margen y Cant. ventas (owner): es un KPI
 * del período, va con los otros KPIs del período.
 * Sin filas, la card no se pinta.
 *
 * "Cajas abiertas" ya no vive acá: es un dato del MOMENTO, no del período, y
 * se mudó al bloque "Ahora" (con cuál caja, quién y desde cuándo). Un número,
 * una vez.
 */
export function visibleInfoRows(
  stats: IncomeOutcomeStatsWidget | undefined,
  info: InfoWidget | undefined,
): InfoRowKey[] {
  const out: InfoRowKey[] = []
  if (Number(info?.giftCardsCount ?? 0) > 0) out.push("giftCards")
  return out
}

// ── Armado de la grilla sin huecos ─────────────────────────────────────────

export interface GridBlock<K extends string = string> {
  key: K
  /** `true` = ocupa la fila entera; `false` = media fila. */
  full: boolean
}

/**
 * Acomoda los bloques visibles de una grilla de 2 columnas sin dejar huecos
 * (`context/20` changelog 2026-09-09: no dejar huecos sin sentido).
 *
 * Respeta el orden, salvo cuando un bloque de media fila quedaría SOLO (el que
 * sigue es de fila entera, o no hay más): ahí sube el próximo de media fila
 * para acompañarlo, y si no hay ninguno se estira a la fila entera.
 */
export function packGrid<K extends string>(blocks: GridBlock<K>[]): GridBlock<K>[] {
  const queue = [...blocks]
  const out: GridBlock<K>[] = []
  while (queue.length > 0) {
    const b = queue.shift()!
    if (b.full) {
      out.push(b)
      continue
    }
    if (queue[0] && !queue[0].full) {
      out.push(b, queue.shift()!)
      continue
    }
    const partner = queue.findIndex((q) => !q.full)
    if (partner >= 0) {
      out.push(b, queue.splice(partner, 1)[0])
    } else {
      out.push({ ...b, full: true })
    }
  }
  return out
}
