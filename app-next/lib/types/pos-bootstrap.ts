/**
 * Shape del endpoint BFF `/api/pos/bootstrap`.
 *
 * Este endpoint es la fuente de verdad del catálogo en memoria del POS.
 * Compone en una sola respuesta todo lo que `lib/catalog/store.ts` necesita
 * para hidratar sin round-trips adicionales.
 *
 * Ver context/16-app-next-rewrite.md §4 (arquitectura BFF) y §7 Sprint 0/Slice A.
 */

// ── Config del tenant ─────────────────────────────────────────────────────────

export interface PosConfig {
  currency: string
  /** 'yes' | 'no' — si se muestran decimales en la moneda local. */
  decimal: string
  /** 'comma' | 'dot' — separador de miles. */
  thousand: "comma" | "dot"
  /** Etiqueta del impuesto fiscal (ej. "IVA"). */
  taxName: string
  /** Etiqueta del documento fiscal del cliente (ej. "RUC"). */
  tinName: string
  /** Código ISO de país (ej. "PY"). */
  country: string
  companyName: string
  companyId: string | number
  /** Base URL de screens standalone (impresión, KDS, etc). */
  publicUrl: string
}

// ── Caja (register) ───────────────────────────────────────────────────────────

export interface PosRegister {
  id: string
  name: string
  /** UUID del outlet al que pertenece. */
  outletId: string
  /** Punto de expedición fiscal (timbrado PY, etc). */
  expeditionPoint: string | null
}

// ── Item vendible en el POS ───────────────────────────────────────────────────

export interface PosItem {
  id: string
  name: string
  sku: string | null
  price: number
  /** Precio incluye impuesto. */
  taxIncluded: boolean
  taxId: string | null
  /** Categoría principal (para la grilla de categorías del POS). */
  categoryId: string | null
  categoryName: string | null
  /** URL de imagen de portada. Null si no tiene. */
  imageUrl: string | null
  /** Unidad de medida (ej. "kg", "lt"). Null si no aplica. */
  uom: string | null
  /** kind canónico del item (ver ItemKind en panel-next). */
  kind: string
  /** Si trackea stock — para mostrar alerta de stock bajo. */
  trackInventory: boolean
  /**
   * Stock actual del ítem en la caja activa (null si no trackea inventario
   * o si no está disponible). Negativo = stock en rojo.
   * Rellenado por el BFF bootstrap desde el depósito del outlet.
   */
  stock: number | null
}

// ── Cliente (para búsqueda en el POS) ────────────────────────────────────────

export interface PosCustomer {
  id: string
  /** Nombre display (razón social o nombre persona). */
  name: string
  /** Teléfono en E.164 (convención §31). Null si no tiene. */
  phone: string | null
  /** Documento fiscal (RUC PY, etc). */
  tin: string | null
  /** Crédito en cuenta corriente disponible. */
  storeCredit: number
  /** Es acreedor (permite venta a crédito type=3). */
  isCreditable: boolean
}

// ── Bootstrap completo ────────────────────────────────────────────────────────

export interface PosBootstrap {
  config: PosConfig
  user: {
    id: string | number
    role: number
  }
  outlet: {
    id: string
    name: string
  }
  registers: PosRegister[]
  items: PosItem[]
  customers: PosCustomer[]
}
