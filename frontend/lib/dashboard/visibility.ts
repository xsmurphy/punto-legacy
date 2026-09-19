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
  TopHoursWidget,
  TopItemRow,
  TopTaxonomyRow,
} from "@/hooks/use-dashboard-widget"

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

export type InfoRowKey = "ticket" | "drawers" | "giftCards"

/**
 * Filas de "Información general":
 *   - Ticket promedio: solo con ventas en el período (sin ventas es un cero
 *     que no promedia nada).
 *   - Cajas abiertas: si el comercio usa control de caja. En 0 SÍ se muestra
 *     —"no hay ninguna abierta" es un dato operativo para quien trabaja con
 *     caja—; para quien nunca abrió una, la fila no existe.
 *   - Gift cards vigentes: solo si hay alguna.
 * Sin filas, la card no se pinta.
 */
export function visibleInfoRows(
  stats: IncomeOutcomeStatsWidget | undefined,
  info: InfoWidget | undefined,
): InfoRowKey[] {
  const out: InfoRowKey[] = []
  if (Number(stats?.count ?? 0) > 0) out.push("ticket")
  if (info?.usesDrawers === true) out.push("drawers")
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
