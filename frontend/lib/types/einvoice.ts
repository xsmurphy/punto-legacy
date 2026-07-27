/**
 * Shapes de `/v1/einvoice` — Facturación Electrónica (Automate/SIFEN), F0.
 * Ver `context/28-facturacion-electronica-plan.md`.
 */

export type EInvoiceStatus = "unconfigured" | "ok" | "auth_error"

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
  status: EInvoiceStatus
  /** Payload crudo de `/auth/me` — el spec de Automate no tipa la respuesta. */
  emitter: Record<string, unknown>
  lastCheckAt: string | null
  lastError: string | null
  config: EInvoiceConfig
}

export interface EInvoiceSaveAccountPayload {
  username: string
  /** `undefined`/`""` conserva la contraseña guardada — nunca vuelve del backend. */
  password?: string
  config: EInvoiceConfig
}

export interface EInvoiceTestResult {
  status: EInvoiceStatus
  emitter: Record<string, unknown>
  lastError: string | null
}

/** Shape del proxy de códigos de medio de pago — sin tipar en el spec de Automate. */
export interface EInvoicePaymentMethod {
  code: string
  name: string
  [key: string]: unknown
}
