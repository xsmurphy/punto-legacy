/**
 * Shapes de `/v1/einvoice` — Facturación Electrónica (Factomate/SIFEN), F0.
 * Ver `context/28-facturacion-electronica-plan.md`.
 */

export type EInvoiceStatus = "unconfigured" | "ok" | "auth_error"

/** Factomate tiene HOSTS DISTINTOS para test y prod — no es un flag cosmético. */
export type EInvoiceEnvironment = "test" | "prod"

/** Config de emisión — `autoIssue`/`onlyWithTaxId` recién se aplican en F1; F0 solo los guarda. */
export interface EInvoiceConfig {
  autoIssue?: boolean
  onlyWithTaxId?: boolean
  [key: string]: unknown
}

export interface EInvoiceAccount {
  configured: boolean
  provider: string
  username: string
  /** E.164 SIN '+' (convención de storage del proyecto), ej "595981612192". */
  phone: string
  environment: EInvoiceEnvironment
  status: EInvoiceStatus
  /** Payload crudo de GetUserInfo — el spec de Factomate no tipa la respuesta. */
  emitter: Record<string, unknown>
  /** Timbrado vigente (`stamps[0]` de sincro/config) — shape sin tipar, se lee, no se crea. */
  stamp: Record<string, unknown>
  stampSyncedAt: string | null
  lastCheckAt: string | null
  lastError: string | null
  config: EInvoiceConfig
}

export interface EInvoiceSaveAccountPayload {
  username: string
  /** E.164, con o sin '+' — el backend normaliza con el helper canónico de teléfonos. */
  phone: string
  environment: EInvoiceEnvironment
  /** `undefined`/`""` conserva la contraseña guardada — nunca vuelve del backend. */
  password?: string
  config: EInvoiceConfig
}

export interface EInvoiceTestResult {
  status: EInvoiceStatus
  emitter: Record<string, unknown>
  stamp: Record<string, unknown>
  lastError: string | null
}

/** Shape del proxy de códigos de medio de pago — sin tipar en el spec de Factomate. */
export interface EInvoicePaymentMethod {
  code: string
  name: string
  [key: string]: unknown
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
