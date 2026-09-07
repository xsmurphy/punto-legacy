/**
 * Shapes de `/v1/production`, `/v1/waste`, `/v1/waste-reasons` — módulo de
 * Producción F2. Ver `context/23-production-module-plan.md`.
 */

export type ProductionStatus = "draft" | "in_progress" | "completed" | "cancelled"

export interface RecipeSnapshotLine {
  itemId: string
  qty: number
  unitCost: number
  lineCost: number
  tracked: boolean
}

export interface ProductionOrder {
  id: string
  /** Correlativo del documento por sucursal (mig 129). Null en los
   *  registros anteriores que no se pudieron numerar. */
  docNumber: number | null
  companyId: string
  outletId: string
  locationId: string | null
  outputLocationId: string | null
  itemId: string
  itemName: string | null
  qtyPlanned: number
  qtyProduced: number | null
  qtyWaste: number
  status: ProductionStatus
  recipeSnapshot: RecipeSnapshotLine[] | null
  ingredientCost: number | null
  unitCogs: number | null
  note: string | null
  userId: string | null
  createdAt: string | null
  startedAt: string | null
  completedAt: string | null
}

/**
 * Un insumo del desglose: una HOJA de la explosión de la receta, o sea algo que
 * la operación realmente descuenta. Un semielaborado con stock propio aparece
 * como tal (no se re-explota); una sub-preparación sin stock propio no aparece
 * — sí sus insumos.
 */
export interface ProductionCapacityIngredient {
  itemId: string
  itemName: string
  /** Saldo en la sucursal. `null` = sin control de inventario: DESCONOCIDO, no cero. */
  onHand: number | null
  /**
   * Cuánto consume UNA unidad del producto, con la merma planificada ya
   * aplicada nivel por nivel. No hay un `wastePercent` escalar a propósito: una
   * hoja alcanzada por dos ramas compone dos mermas distintas y ese número no
   * existiría.
   */
  neededPerUnit: number
  /** Cuántas unidades del producto soporta ESTE insumo. `null` = no limita. */
  unitsSupported: number | null
  /** El que corta. Ante un empate, el primero de la receta. */
  limiting: boolean
  tracked: boolean
}

/** Nivel 1 de la receta — la base de `ingredientAdjustments` al completar. */
export interface ProductionDirectIngredient {
  itemId: string
  itemName: string
  /** Consumo teórico por unidad planificada, con merma (lo que descuenta el server). */
  neededPerUnit: number
  tracked: boolean
}

export interface RecipeCapacityResult {
  /**
   * Unidades completas que salen con el stock de HOY.
   * `null` = ningún insumo con control de stock limita, o sea que no hay número
   * que dar. NO es 0: 0 significa "no se puede producir ni una".
   */
  capacity: number | null
  limiting: ProductionCapacityIngredient | null
  ingredients: ProductionCapacityIngredient[]
}

export interface ProductionCapacity extends RecipeCapacityResult {
  directIngredients: ProductionDirectIngredient[]
}

/** Una sucursal en la respuesta de "producibles ahora". */
export interface ProducibleOutlet extends RecipeCapacityResult {
  outletId: string
  outletName: string
}

export interface ProducibleNow {
  /** false = el ítem no tiene receta: la sección no se renderiza. */
  hasRecipe: boolean
  outlets: ProducibleOutlet[]
}

export interface IngredientAdjustment {
  itemId: string
  actualQty: number
}

export interface CreateProductionOrderPayload {
  itemId: string
  outletId: string
  qtyPlanned: number
  locationId?: string | null
  outputLocationId?: string | null
  mode?: "draft" | "immediate"
  note?: string | null
  // Solo si mode === 'immediate'
  qtyProduced?: number
  wasteUnits?: number
  wasteReasonId?: string | null
  ingredientAdjustments?: IngredientAdjustment[]
}

export interface CompleteProductionOrderPayload {
  qtyProduced: number
  wasteUnits?: number
  wasteReasonId?: string | null
  ingredientAdjustments?: IngredientAdjustment[]
}

export interface ProductionListFilters {
  status?: ProductionStatus | null
  outletId?: string | null
  from?: string | null
  to?: string | null
  q?: string | null
}

export type WasteSource = "manual" | "production"

export interface WasteEvent {
  id: string
  /** Correlativo del documento por sucursal (mig 129). Null en los
   *  registros anteriores que no se pudieron numerar. */
  docNumber: number | null
  itemId: string
  itemName: string
  qty: number
  reasonId: string | null
  reasonName: string | null
  source: WasteSource
  orderId: string | null
  cost: number | null
  note: string | null
  userId: string | null
  userName: string | null
  createdAt: string | null
}

export interface RegisterWastePayload {
  itemId: string
  qty: number
  reasonId: string
  outletId: string
  locationId?: string | null
  note?: string | null
}

export interface WasteListFilters {
  from?: string | null
  to?: string | null
  reasonId?: string | null
  outletId?: string | null
}

export interface WasteReason {
  id: string
  name: string
  sortOrder: number
}

export interface WasteReasonPayload {
  name: string
}
