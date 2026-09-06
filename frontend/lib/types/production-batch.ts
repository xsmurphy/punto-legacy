/**
 * Lote de producción multi-plato (context/70-viandas.md, etapa B).
 *
 * Un lote toma `{plato, cantidad} × N`, explota todas las recetas y AGREGA POR
 * INSUMO: es la pregunta "de 10 pedidos, cuánta pechuga necesito en total".
 * Sus LÍNEAS son `production_order` reales (`production_order.batchid`), así
 * que el consumo de stock y el costeo salen del mismo camino de siempre.
 */

export type ProductionBatchStatus = "draft" | "confirmed" | "cancelled"

/** Una línea del lote = una orden de producción hija. */
export interface ProductionBatchLine {
  orderId: string
  /** Correlativo `produccion` propio de la orden hija. */
  docNumber: number | null
  itemId: string
  itemName: string | null
  qtyPlanned: number
  qtyProduced: number | null
  status: "draft" | "in_progress" | "completed" | "cancelled"
  unitCogs: number | null
  ingredientCost: number | null
  completedAt: string | null
}

/** De qué plato del lote salió cuánto de este insumo. */
export interface ProductionNeedSource {
  itemId: string
  qty: number
}

export interface ProductionNeedIngredient {
  itemId: string
  itemName: string | null
  /** Necesidad TOTAL, con la merma planificada ya aplicada por nivel. */
  needed: number
  /**
   * `false` = el insumo no lleva control de inventario (D1 de context/70).
   * Para esos NO hay faltante: hay necesidad total, y `onHand`/`missing`
   * vienen en `null`. No es un 0 disfrazado.
   */
  tracked: boolean
  onHand: number | null
  missing: number | null
  bySource: ProductionNeedSource[]
}

export interface ProductionNeedLine {
  itemId: string
  itemName: string | null
  qty: number
  hasRecipe: boolean
  tracked: boolean
  /** `false` = se puede estimar pero no producir; `reason` dice por qué. */
  producible: boolean
  reason: string | null
}

export interface ProductionBatchEstimate {
  lines: ProductionNeedLine[]
  ingredients: ProductionNeedIngredient[]
  /**
   * Cuántas veces entra el lote COMPLETO con el stock actual: el mínimo, entre
   * los insumos con control de inventario, de `onHand / necesidad`.
   * `null` = ningún insumo con control de inventario lo limita — NUNCA
   * confundir con 0, que significa "no alcanza".
   */
  batchCapacity: number | null
  /** `false` si alguna línea no se puede producir (sin receta o sin stock propio). */
  producible: boolean
}

export interface ProductionBatch {
  id: string
  docNumber: number | null
  companyId: string
  outletId: string
  locationId: string | null
  outputLocationId: string | null
  status: ProductionBatchStatus
  note: string | null
  userId: string | null
  createdAt: string | null
  confirmedAt: string | null
  cancelledAt: string | null
  lines?: ProductionBatchLine[]
  /** Solo en `draft`: un lote confirmado no recalcula la necesidad. */
  estimate?: ProductionBatchEstimate | null
  /** Solo en el listado. */
  lineCount?: number
  qtyTotal?: number
}

export interface ProductionBatchLineInput {
  itemId: string
  qty: number
}

export interface EstimateBatchPayload {
  outletId: string
  locationId?: string | null
  lines: ProductionBatchLineInput[]
}

export interface CreateProductionBatchPayload {
  outletId: string
  locationId?: string | null
  outputLocationId?: string | null
  note?: string | null
  lines: ProductionBatchLineInput[]
}

/**
 * De qué orden salió cuánto de un producto de la cola. Es la trazabilidad del
 * armado: sin esto la demanda consolidada es un número que el cocinero no
 * puede auditar contra sus comandas.
 */
export interface OrderDemandSource {
  orderId: string
  /** `null` en órdenes viejas sin correlativo — la UI muestra el id corto. */
  orderNumber: number | null
  qty: number
}

export interface OrderDemandLine {
  itemId: string
  itemName: string
  qty: number
  sources: OrderDemandSource[]
}

/**
 * La cola de órdenes pendientes de una sucursal, agregada por producto — el
 * alimentador del lote (context/70, etapa B).
 *
 * Es una FOTO del instante `takenAt`, no un vivo: un pedido que entra después
 * NO muta un lote ya armado. El operador vuelve a traer si quiere.
 */
export interface OrderDemand {
  outletId: string
  /** Hora del comercio (naive `Y-m-d H:i:s`) — usar los helpers de format-date. */
  takenAt: string
  orderCount: number
  /** Líneas de texto libre (sin producto de catálogo) que quedaron afuera. */
  skippedFreeText: number
  /** `true` si la cola superó el techo de la query y viene incompleta. */
  truncated: boolean
  lines: OrderDemandLine[]
}

export interface ProductionBatchListFilters {
  status?: ProductionBatchStatus
  outletId?: string
  from?: string
  to?: string
}
