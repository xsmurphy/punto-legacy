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
