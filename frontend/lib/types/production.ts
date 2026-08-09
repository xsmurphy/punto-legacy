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

export interface ProductionCapacityIngredient {
  itemId: string
  qtyPerUnit: number
  onHand: number | null
  wastePercent: number
  tracked: boolean
}

export interface ProductionCapacity {
  /** null = receta sin insumos con control de stock → capacidad ilimitada. */
  capacity: number | null
  ingredients: ProductionCapacityIngredient[]
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
