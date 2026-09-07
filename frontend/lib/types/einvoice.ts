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

/**
 * Formulario legal del emisor (F7). Pide SOLO lo que Punto no tiene en otro
 * lado: el RUC y la razón social viven en los ajustes de la empresa (la
 * pantalla de FE los edita, pero escribiendo a ESE destino), y los timbrados
 * en las CAJAS (cada caja es un punto de expedición — se editan en la sección
 * Timbrados por caja, que escribe sobre la caja). `cscSecret` va al backend y
 * nunca vuelve.
 */
export interface EInvoiceActivity {
  /** Código SIFEN de la actividad económica (ej. 62010). */
  codigo: number | ""
  nombre: string
}

export interface EInvoiceFiscalForm {
  /** Email de facturación — identidad del emisor, único en el sistema fiscal. */
  email: string
  /** 1 = persona física, 2 = persona jurídica. */
  taxpayerType?: number
  /**
   * Actividades económicas de la constancia de RUC: la PRIMERA es la
   * principal y las que siguen, secundarias. SIFEN las acepta todas y el
   * backend da de alta una por una (ver `ensureActivityCreated`).
   */
  actividades: EInvoiceActivity[]
  /**
   * Shape anterior a 2026-09-06 (una sola actividad). Solo se LEE, para las
   * cuentas provisionadas antes del cambio — nunca se escribe: el backend
   * reescribe `fiscal` con `actividades` en cada guardado.
   * @deprecated usar `actividades`
   */
  actividadCodigo?: number | ""
  /** @deprecated usar `actividades` */
  actividadNombre?: string
  /** Id del CSC de SIFEN (producción) — opcional hasta operar en prod. */
  cscId?: string
  /** Secreto del CSC — sube, se guarda cifrado y NUNCA vuelve del backend. */
  cscSecret?: string
  /** Texto adicional impreso en la factura. */
  infoAdicional?: string
}

/**
 * Contribuyente encontrado en el padrón (`/v1/settings?view=taxpayer`). Es la
 * MISMA respuesta que consume el alta de contactos; acá se usa para traer la
 * razón social del RUC del propio comercio.
 */
export interface TaxpayerLookup {
  ruc: string
  name: string
  status: string | null
  /** De dónde salió el dato: el emisor del comercio, o el padrón público. */
  source: string
}

export interface EInvoiceAccount {
  configured: boolean
  /** true = el emisor ya existe del lado fiscal; la UI muestra estado, no formulario. */
  provisioned: boolean
  status: EInvoiceStatus
  /** Espejo del formulario legal guardado (sin secretos). */
  fiscal: Partial<EInvoiceFiscalForm>
  /** El EMISOR tiene el certificado cargado (checkpoint del provisioning). */
  certUploaded: boolean
  /**
   * PUNTO tiene el certificado en custodia cifrada (mig 195). Distinto de
   * `certUploaded`: el comercio puede pedir que Punto lo borre y seguir
   * facturando igual. El contenido no vuelve nunca — solo esta bandera y la
   * fecha.
   */
  certStored: boolean
  certUploadedAt: string | null
  /** Hay un secreto de CSC guardado. El código en sí nunca vuelve. */
  cscStored: boolean
  cscUpdatedAt: string | null
  /** Payload crudo del emisor según el proveedor — shape sin tipar. */
  emitter: Record<string, unknown>
  /** Timbrado vigente cacheado — shape sin tipar; el correlativo lo lleva el proveedor. */
  stamp: Record<string, unknown>
  stampSyncedAt: string | null
  lastCheckAt: string | null
  lastError: string | null
  config: EInvoiceConfig
}

/**
 * Lo ÚNICO que el backend cuenta sobre los secretos en custodia: si hay algo
 * guardado y desde cuándo. Ni el certificado, ni su contraseña, ni el código
 * del CSC cruzan la API — es el contrato de `FiscalSecretStore::status()`.
 */
export interface EInvoiceSecretStatus {
  certStored: boolean
  certUploadedAt: string | null
  cscStored: boolean
  cscUpdatedAt: string | null
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
  /**
   * Estado FISCAL real (SIFEN), distinto de `status` (outbox) — puede quedar
   * null si todavía no se reconcilió (el job `einvoice-reconcile` corre cada
   * 10 min). MANDA sobre `status`: ver `lib/einvoice/sifen-status.ts`.
   */
  sifenStatus: string | null
  sifenCheckedAt: string | null
  /**
   * Motivo legible del veredicto de SIFEN (típicamente el del RECHAZO, ej.
   * "1002 — documento duplicado"), parseado server-side desde `sifen_result`.
   * null si SIFEN no dio detalle o el documento no se reconcilió todavía.
   */
  sifenReason: string | null
  /**
   * Id del documento que REEMPLAZA a este tras una reemisión por rechazo
   * (`context/28` §F7 N2, mig 201). No-null = el documento quedó solo como
   * registro histórico: no se le ofrece emitir de nuevo y la pantalla lo pinta
   * "Reemplazado". El activo de esa venta es el otro.
   */
  supersededBy: string | null
  /**
   * Guard de numeración (mig 204): el documento se emitió, pero el CDC que
   * devolvió el proveedor NO describe el comprobante que la caja imprimió —
   * típicamente porque no respetó el correlativo congelado de la venta.
   * No-null = la descripción de la discrepancia, en castellano.
   *
   * NO es `errorMessage`: ese acompaña a `status='error'` y significa "falló,
   * se puede reintentar". Este documento SÍ salió y existe en SIFEN, así que
   * reintentarlo lo emitiría dos veces — la acción que corresponde es humana.
   * Mientras esté marcado, su CDC y su QR no se imprimen, no salen al portal
   * del comprador y no se envían por email.
   */
  numberingMismatch: string | null
  total: number | null
  currency: string | null
  /**
   * Cliente y sucursal de la VENTA (no del documento) — el rechazo se corrige
   * en la ficha del cliente o en el timbrado de la caja, y sin estos ids el
   * panel solo podría describir el camino en vez de linkearlo.
   */
  contactId: string | null
  outletId: string | null
  clientName: string | null
  /**
   * Casilla del cliente de la venta. Precarga el diálogo de reenvío (D8 de
   * `context/57`) — editable ahí mismo, porque el destino puede ser otro (la
   * del contador). `null` = la venta no tiene cliente, o el cliente no tiene
   * email: ahí el envío automático nunca se encoló.
   */
  clientEmail: string | null
  /**
   * ENTREGA DIGITAL (`context/57`). Derivado de `notification_outbox`, no de
   * una columna de `einvoice_document`: mandar un email no cambia el
   * documento, y duplicar el dato crearía dos verdades.
   *
   * `emailSentAt` = cuándo salió el ÚLTIMO envío (puede haber varios: el
   * automático más los reenvíos manuales a otras direcciones).
   */
  emailSentAt: string | null
  /** Hay un envío en cola — sale en la próxima corrida del drainer (≤5 min). */
  emailPending: boolean
  /** Algún envío agotó los reintentos. El motivo queda en el outbox. */
  emailFailed: boolean
}

export interface EInvoiceDocumentFilters {
  from?: string
  to?: string
  /**
   * `stuck` y `rejected` son filtros SINTÉTICOS del panel, no valores de
   * `einvoice_document.status`: `stuck` = `sending` varado, `rejected` =
   * `sifen_status='Rechazado'` (el rechazo fiscal no vive en el outbox).
   */
  status?: EInvoiceDocumentStatus | "stuck" | "rejected" | ""
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

/**
 * Resultado de encolar la entrega del KuDE (`action=sendKude`). El backend NO
 * manda en el acto: encola en `notification_outbox` y el drainer lo entrega en
 * la próxima corrida — así el envío tiene reintento y queda registrado.
 *
 * `queued: false` = ya había un envío encolado a ESA dirección (idempotencia
 * del outbox). No es un error: es "ya está pedido".
 */
export interface EInvoiceSendKudeResult {
  queued: boolean
  recipient: string
}
