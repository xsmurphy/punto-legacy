/**
 * Shape del endpoint BFF `/api/pos/bootstrap`.
 *
 * Este endpoint es la fuente de verdad del catálogo en memoria del POS.
 * Compone en una sola respuesta todo lo que `lib/catalog/store.ts` necesita
 * para hidratar sin round-trips adicionales.
 *
 * Ver context/16-app-next-rewrite.md §4 (arquitectura BFF) y §7 Sprint 0/Slice A.
 */

// ── Método de pago configurable ───────────────────────────────────────────────

export interface PaymentMethodConfig {
  id: string
  name: string
  /** Letra hotkey (A, S, D…). Opcional. */
  code?: string
  /** true = efectivo/similar: acepta vuelto cuando el monto supera el total. */
  hasChange: boolean
  /** true = pide un identificador antes de aplicar (voucher, nro de op, etc). */
  requiresIdentifier: boolean
  /** Label del campo (ej. "Nro de operación"). Null si !requiresIdentifier. */
  identifierLabel?: string
  /** Placeholder del input de identificador. */
  identifierPlaceholder?: string
  /** true = método del sistema (Efectivo, T. Crédito, T. Débito). Render destacado. */
  isDefault?: boolean
  /**
   * Discriminante estable para comportamiento especial en el POS (cash,
   * giftcard, internal). Viene de `taxonomyExtra.systemKey` en el backend —
   * usar esto en vez de comparar contra el `id` (taxonomyId), que varía por
   * tenant y no es estable entre entornos.
   */
  systemKey?: "cash" | "giftcard" | "internal" | "check" | null
  /** Key de color de la paleta unificada (lib/ui/color-palette.ts). Acento en el pill. */
  color?: string
  /** Orden de aparición en el pay-dialog (drag&drop del panel). */
  sortOrder?: number | null
}

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
  /**
   * TZ IANA del tenant (ej. "America/Asuncion"). Convención: los writes del
   * negocio se guardan en hora LOCAL del tenant, naive. Usar con tenantNow()
   * (lib/format-date.ts) para que un device en otra TZ no desfase la fecha.
   */
  timezone: string
  companyName: string
  companyId: string | number
  /** URL del logo del tenant (S3, público). null/undefined si no hay logo cargado. */
  companyLogo?: string | null
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
  /** Marca principal del item. Null si no tiene. */
  brandId: string | null
  brandName: string | null
  /** URL de imagen de portada. Null si no tiene. */
  imageUrl: string | null
  /** Unidad de medida (ej. "kg", "lt"). Null si no aplica. */
  uom: string | null
  /** kind canónico del item (ver ItemKind en frontend). */
  kind: string
  /**
   * % de descuento del catálogo (`itemDiscount`, JSONB flattened, 0-100).
   * Solo tiene semántica especial para `kind === "descuento"`: al agregarse
   * al carrito, se aplica como descuento de venta (no como línea). Para el
   * resto de los kinds es informativo (default % de descuento del producto,
   * no se usa hoy en el flujo de venta). Null = sin descuento configurado.
   */
  discountPercent: number | null
  /** Si trackea stock — para mostrar alerta de stock bajo. */
  trackInventory: boolean
  /**
   * Stock actual del ítem en la caja activa (null si no trackea inventario
   * o si no está disponible). Negativo = stock en rojo.
   * Rellenado por el BFF bootstrap desde el depósito del outlet.
   */
  stock: number | null
  /** true si es un grupo de catálogo (itemIsParent=true). Click en POS abre dialog con hijos. No se vende. */
  isGroup: boolean
  /** UUID del padre si este item es hijo de un grupo (itemParentId). null si es top-level. */
  parentId: string | null
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

// ── Empleado del outlet (para asignación por línea) ──────────────────────────

export interface PosUser {
  id: string
  name: string
  /**
   * Hash SHA-256 (hex 64 chars) del PIN del operador. Almacenado en localStorage via catalog store.
   * Decision del owner (2026-06-25): SHA-256 es más simple, rápido en browser, matchea legacy.
   * Hash visible en localStorage es suficiente para identificacion — el PIN no es una
   * contrasena critica, protege contra peeking casual, no contra atacantes con acceso al device.
   */
  pinhash?: string | null
  /**
   * @deprecated Hash bcrypt anterior. Mantener por compatibilidad hasta que el front lo deje de usar.
   */
  lockpasshash?: string | null
}

// ── Bootstrap completo ────────────────────────────────────────────────────────

/**
 * Sucursal activa del device. `lat`/`lng` son las columnas numéricas de
 * `outlet` (mig 14) y pueden ser null si nunca se cargó la ubicación — las
 * consume el PIN del local en la vista mapa de /pos/ordenes.
 */
export interface PosOutlet {
  id: string
  name: string
  lat: number | null
  lng: number | null
}

export interface PosBootstrap {
  config: PosConfig
  user: {
    id: string | number
    role: number
  }
  outlet: PosOutlet
  /** Todas las sucursales disponibles para el tenant. */
  outlets: Array<{ id: string; name: string }>
  registers: PosRegister[]
  items: PosItem[]
  customers: PosCustomer[]
  paymentMethods: PaymentMethodConfig[]
  users: PosUser[]
  /** UUID de la caja activa en el claim del JWT. '' = sin caja seleccionada. */
  activeRegisterId: string
}
