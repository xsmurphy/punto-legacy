/**
 * Shapes de `/v1/einvoice` — Facturación Electrónica (SIFEN).
 * Ver `context/28-facturacion-electronica-plan.md`.
 *
 * WHITE-LABEL (F7): el comercio nunca ve al proveedor de FE. El alta es un
 * formulario de datos LEGALES (RUC, actividad, timbrado) y Punto provisiona
 * el emisor por detrás — acá no existe ningún shape de credencial.
 */

export type EInvoiceStatus = "unconfigured" | "provisioning" | "ok" | "auth_error"

/**
 * Config de emisión. El backend la MERGEA clave por clave (ver
 * EInvoiceService::saveAccount), así que cada sección de la pantalla manda
 * solo lo suyo — no hace falta reenviar la config entera para no pisarla.
 */
export interface EInvoiceConfig {
  autoIssue?: boolean
  onlyWithTaxId?: boolean
  /**
   * F3 — `taxonomyId` del medio de pago de Punto → código de medio de pago de
   * SIFEN (el `Identifier` que expone Factomate). Los métodos sin entrada acá
   * se emiten con `defaultPaymentMethodCode`.
   */
  paymentMethodMap?: Record<string, number>
  /** Código usado cuando el medio de pago no está mapeado. 1 = Efectivo. */
  defaultPaymentMethodCode?: number
  [key: string]: unknown
}

/** Datos del timbrado que el comercio recibe de la SET. */
export interface EInvoiceStampForm {
  /** Número de timbrado (lo asigna la SET). */
  numero: string
  /** Establecimiento, 3 dígitos (ej. "001"). */
  establecimiento: string
  /** Punto de expedición, 3 dígitos (ej. "001"). */
  puntoExpedicion: string
  /** Fecha de inicio de vigencia, "YYYY-MM-DD". */
  fechaInicio: string
  serie?: string
}

/**
 * Formulario legal del emisor (F7). Es TODO lo que el comercio completa —
 * el resto (credenciales, cuenta del proveedor) lo resuelve Punto.
 * `cscSecret` pasa al proveedor y nunca vuelve del backend.
 */
export interface EInvoiceFiscalForm {
  ruc: string
  razonSocial: string
  nombreFantasia?: string
  /** Email de facturación — identidad del emisor, único en el sistema fiscal. */
  email: string
  /** 1 = persona física, 2 = persona jurídica. */
  taxpayerType?: number
  /** Código SIFEN de la actividad económica (ej. 62010). */
  actividadCodigo: number | ""
  actividadNombre: string
  /** Id del CSC de SIFEN (producción) — opcional hasta operar en prod. */
  cscId?: string
  /** Secreto del CSC — pasa, no se guarda. */
  cscSecret?: string
  /** Texto adicional impreso en la factura. */
  infoAdicional?: string
  timbrado: EInvoiceStampForm
}

export interface EInvoiceAccount {
  configured: boolean
  /** true = el emisor ya existe del lado fiscal; la UI muestra estado, no formulario. */
  provisioned: boolean
  status: EInvoiceStatus
  /** Espejo del formulario legal guardado (sin secretos). */
  fiscal: Partial<EInvoiceFiscalForm>
  certUploaded: boolean
  /** Payload crudo del emisor según el proveedor — shape sin tipar. */
  emitter: Record<string, unknown>
  /** Timbrado vigente cacheado — shape sin tipar; el correlativo lo lleva el proveedor. */
  stamp: Record<string, unknown>
  stampSyncedAt: string | null
  lastCheckAt: string | null
  lastError: string | null
  config: EInvoiceConfig
}

export interface EInvoiceTestResult {
  status: EInvoiceStatus
  emitter: Record<string, unknown>
  stamp: Record<string, unknown>
  lastError: string | null
}

/**
 * Códigos de medio de pago de SIFEN, normalizados por el backend
 * (EInvoiceService::normalizePaymentMethods) desde el payload crudo de
 * Factomate: `code` es el `Identifier` que espera SIFEN, NO el `Id`.
 */
export interface EInvoicePaymentMethod {
  code: number
  name: string
}

// ── F2 — operación de documentos ya emitidos ────────────────────────────

/**
 * Estados del outbox de Punto (`einvoice_document.status`, mig 92) MÁS
 * `stuck` — filtro sintético del panel (no existe en la BD): `sending`
 * varado más de 15 min sin nadie reintentándolo (ver EInvoiceService::
 * documents — no se auto-reintenta, la emisión no es idempotente).
 */
export type EInvoiceDocumentStatus =
  | "pending"
  | "sending"
  | "issued"
  | "error"
  | "cancelled"
  | "skipped"

export interface EInvoiceDocument {
  id: string
  doctype: string
  status: EInvoiceDocumentStatus
  /** true si status='sending' con updated_at > 15 min — trabado, necesita revisión manual. */
  stuck: boolean
  cdc: string | null
  documentNumber: string | null
  errorMessage: string | null
  issuedAt: string | null
  cancelledAt: string | null
  attempts: number
  createdAt: string | null
  /** Estado FISCAL real (SIFEN), distinto de `status` (outbox) — puede quedar null si nunca se reconcilió. */
  sifenStatus: string | null
  sifenCheckedAt: string | null
  total: number | null
  currency: string | null
  clientName: string | null
}

export interface EInvoiceDocumentFilters {
  from?: string
  to?: string
  status?: EInvoiceDocumentStatus | "stuck" | ""
  search?: string
  page?: number
  pageSize?: number
}

export interface EInvoiceDocumentsPage {
  items: EInvoiceDocument[]
  page: number
  pageSize: number
  total: number
}

export interface EInvoiceReconcileResult {
  checked: number
  updated: number
}
