"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  EInvoiceAccount,
  EInvoiceConfig,
  EInvoiceDocument,
  EInvoiceDocumentFilters,
  EInvoiceDocumentsPage,
  EInvoiceFiscalForm,
  EInvoicePaymentMethod,
  EInvoiceReconcileResult,
  EInvoiceRegisterNumbering,
  EInvoiceSecretStatus,
  EInvoiceSendKudeResult,
  EInvoiceTestResult,
} from "@/lib/types/einvoice"

const ACCOUNT_KEY = ["einvoice", "account"]
const DOCUMENTS_KEY = ["einvoice", "documents"]

/**
 * Estado de la cuenta de facturación electrónica del comercio. `configured:
 * false` es un estado válido (todavía no se conectó ninguna cuenta), no un
 * error — por eso no hay `enabled`/early-return acá, el componente decide
 * qué mostrar según `configured`/`status`.
 */
export function useEinvoiceAccount() {
  return useQuery<EInvoiceAccount>({
    queryKey: ACCOUNT_KEY,
    queryFn: () => api.get<EInvoiceAccount>("/v1/einvoice?resource=account"),
    staleTime: 15 * 1000,
  })
}

/**
 * F7 — crea (o retoma) el emisor con los datos legales del formulario. El
 * provisioning es reanudable server-side: si un paso falla, volver a mandar
 * el mismo form continúa desde donde quedó, sin duplicar el alta.
 */
export function useProvisionEinvoice() {
  const qc = useQueryClient()
  return useMutation<EInvoiceAccount, Error, EInvoiceFiscalForm>({
    mutationFn: (form) =>
      api.post<EInvoiceAccount>("/v1/einvoice?action=provision", {
        form: form as unknown as Record<string, unknown>,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ACCOUNT_KEY })
    },
  })
}

/** Config de emisión (autoIssue/onlyWithTaxId/paymentMethodMap) — el backend mergea clave por clave. */
export function useSaveEinvoiceConfig() {
  const qc = useQueryClient()
  return useMutation<EInvoiceAccount, Error, EInvoiceConfig>({
    mutationFn: (config) =>
      api.post<EInvoiceAccount>("/v1/einvoice?action=config", {
        config: JSON.stringify(config),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ACCOUNT_KEY })
    },
  })
}

/**
 * Certificado de firma: el `.pfx` viaja en base64, el backend lo registra ante
 * el emisor y lo guarda CIFRADO (decisión del owner 2026-09-06, context/28
 * §Custodia). Nunca vuelve — la respuesta solo confirma la carga.
 */
export function useUploadEinvoiceCert() {
  const qc = useQueryClient()
  return useMutation<{ uploaded: boolean }, Error, { certBase64: string; certPassword: string }>({
    mutationFn: (body) =>
      api.post<{ uploaded: boolean }>("/v1/einvoice?action=uploadCert", body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ACCOUNT_KEY })
    },
  })
}

/**
 * Borra de Punto el certificado en custodia. NO lo quita del emisor: el
 * comercio sigue facturando, y lo que pierde es que Punto pueda reconfigurar
 * la emisión sin volver a pedírselo.
 */
export function useDeleteEinvoiceCert() {
  const qc = useQueryClient()
  return useMutation<EInvoiceSecretStatus, Error, void>({
    mutationFn: () => api.post<EInvoiceSecretStatus>("/v1/einvoice?action=deleteCert"),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ACCOUNT_KEY })
    },
  })
}

/**
 * Guarda el CSC de producción (id + código) y lo aplica al emisor. Acción
 * propia y no un campo más del alta: el CSC se pide en el Marangatu y casi
 * siempre llega DESPUÉS de que el emisor ya existe.
 */
export function useSaveEinvoiceCsc() {
  const qc = useQueryClient()
  return useMutation<EInvoiceSecretStatus, Error, { cscId: string; cscSecret: string }>({
    mutationFn: (body) => api.post<EInvoiceSecretStatus>("/v1/einvoice?action=csc", body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ACCOUNT_KEY })
    },
  })
}

/** Prueba de humo del certificado contra la SET (consulta de RUC real). */
export function useTestEinvoiceSet() {
  return useMutation<Record<string, unknown>, Error, void>({
    mutationFn: () => api.post<Record<string, unknown>>("/v1/einvoice?action=testSet"),
  })
}

/** Re-verifica el emisor (auth + timbrado) y refresca el cache — persiste status/emitter/stamp/lastError server-side. */
export function useTestEinvoiceConnection() {
  const qc = useQueryClient()
  return useMutation<EInvoiceTestResult, Error, void>({
    mutationFn: () => api.post<EInvoiceTestResult>("/v1/einvoice?action=test"),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ACCOUNT_KEY })
    },
  })
}

/**
 * Proxy de los medios de pago de Factomate (F3 los mapea contra los medios
 * de pago de Punto). 409 si la cuenta no está conectada — `enabled` evita
 * disparar la query hasta que `status === 'ok'`.
 */
export function useEinvoicePaymentMethods(enabled: boolean) {
  return useQuery<EInvoicePaymentMethod[]>({
    queryKey: ["einvoice", "paymentMethods"],
    queryFn: () => api.get<EInvoicePaymentMethod[]>("/v1/einvoice?resource=paymentMethods"),
    enabled,
    staleTime: 60 * 1000,
  })
}

// ── F2 — operación de documentos ya emitidos ────────────────────────────

function buildDocumentsQuery(filters: EInvoiceDocumentFilters): string {
  const params = new URLSearchParams({ resource: "documents" })
  if (filters.from) params.set("from", filters.from)
  if (filters.to) params.set("to", filters.to)
  if (filters.status) params.set("status", filters.status)
  if (filters.search) params.set("search", filters.search)
  params.set("page", String(filters.page ?? 1))
  params.set("pageSize", String(filters.pageSize ?? 25))
  return params.toString()
}

/** Listado paginado de documentos emitidos/en outbox — filtros de fecha/estado/búsqueda. */
export function useEinvoiceDocuments(filters: EInvoiceDocumentFilters) {
  return useQuery<EInvoiceDocumentsPage>({
    queryKey: [...DOCUMENTS_KEY, filters],
    queryFn: () => api.get<EInvoiceDocumentsPage>(`/v1/einvoice?${buildDocumentsQuery(filters)}`),
    staleTime: 15 * 1000,
  })
}

/** Reencola un documento `error` → `pending`. Solo válido desde error (ver EInvoiceService::retry). */
export function useRetryEinvoiceDocument() {
  const qc = useQueryClient()
  return useMutation<EInvoiceDocument, Error, string>({
    mutationFn: (id) => api.post<EInvoiceDocument>(`/v1/einvoice?action=retry&id=${encodeURIComponent(id)}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: DOCUMENTS_KEY }),
  })
}

/**
 * "Corregir y emitir de nuevo" (F7/N2) — NO es un retry: el rechazado está
 * `issued` y reintentarlo emitiría el documento fiscal dos veces. El backend
 * encola un documento NUEVO y deja el rechazado como registro, reconstruyendo
 * el payload desde los datos YA corregidos (ficha del cliente, timbrado de la
 * caja, emisor). Por eso no manda body: no hay nada que editar acá.
 */
export function useReissueEinvoiceDocument() {
  const qc = useQueryClient()
  return useMutation<EInvoiceDocument, Error, string>({
    mutationFn: (id) => api.post<EInvoiceDocument>(`/v1/einvoice?action=reissue&id=${encodeURIComponent(id)}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: DOCUMENTS_KEY }),
  })
}

/**
 * Anula un documento `issued` en SIFEN — irreversible. El motivo es
 * obligatorio (ver EInvoiceService::cancel — largo mín/máx sin verificar).
 */
export function useCancelEinvoiceDocument() {
  const qc = useQueryClient()
  return useMutation<EInvoiceDocument, Error, { id: string; reason: string }>({
    mutationFn: ({ id, reason }) =>
      api.post<EInvoiceDocument>(`/v1/einvoice?action=cancel&id=${encodeURIComponent(id)}`, { reason }),
    onSuccess: () => qc.invalidateQueries({ queryKey: DOCUMENTS_KEY }),
  })
}

/**
 * Reenvío manual de la factura al cliente (D8 de `context/57`). `email` vacío
 * = la casilla del cliente de la venta.
 *
 * ENCOLA, no manda: el backend deja la notificación en `notification_outbox` y
 * el drainer la entrega en la próxima corrida (≤5 min). Por eso el toast dice
 * "se va a enviar" y no "enviado" — afirmar lo segundo sería mentirle al
 * operador si el proveedor falla y el ítem se reintenta.
 *
 * Invalida el listado: la columna de entrega se deriva del outbox, así que
 * pasa a "En cola" apenas vuelve.
 */
export function useSendKudeEmail() {
  const qc = useQueryClient()
  return useMutation<EInvoiceSendKudeResult, Error, { id: string; email?: string }>({
    mutationFn: ({ id, email }) =>
      api.post<EInvoiceSendKudeResult>(
        `/v1/einvoice?action=sendKude&id=${encodeURIComponent(id)}`,
        email ? { email } : {},
      ),
    onSuccess: () => qc.invalidateQueries({ queryKey: DOCUMENTS_KEY }),
  })
}

/** Dispara la reconciliación contra GetAll (sifen_status) — panel, manual. */
export function useReconcileEinvoiceDocuments() {
  const qc = useQueryClient()
  return useMutation<EInvoiceReconcileResult, Error, void>({
    mutationFn: () => api.post<EInvoiceReconcileResult>("/v1/einvoice?action=reconcile"),
    onSuccess: () => qc.invalidateQueries({ queryKey: DOCUMENTS_KEY }),
  })
}

/**
 * URL del KuDE (PDF) para abrir en pestaña nueva — se navega directo al BFF
 * (mismo-origin, cookie de panel viaja sola) en vez de traer el blob por
 * `api-client` (que solo maneja JSON): un `<a target="_blank">`/`window.open`
 * deja que el browser maneje la descarga/preview nativamente.
 */
export function einvoiceKudeUrl(id: string): string {
  return `/api/v1/einvoice?resource=kude&id=${encodeURIComponent(id)}`
}

const NUMBERING_KEY = ["einvoice", "numbering"]

/**
 * Desde qué número emite cada caja, y de dónde sale ese número.
 *
 * Lectura barata (el backend usa solo el caché del talonario del emisor), así
 * que se puede pintar en la pantalla sin costar una llamada al proveedor. El
 * dato real se busca UNA vez, al dar de alta el emisor.
 */
export function useEinvoiceNumbering(enabled = true) {
  return useQuery<{ registers: EInvoiceRegisterNumbering[] }>({
    queryKey: NUMBERING_KEY,
    queryFn: () =>
      api.get<{ registers: EInvoiceRegisterNumbering[] }>("/v1/einvoice?resource=numbering"),
    enabled,
  })
}

/**
 * La respuesta a "¿cuál fue la última factura que emitiste con este
 * talonario?". Se manda el ÚLTIMO emitido, no el próximo: es el dato que el
 * comercio tiene delante en su último comprobante, y el servidor nunca baja la
 * secuencia, así que una respuesta corta se corrige contra el piso en vez de
 * pisar un documento.
 *
 * `lastInvoiceNumber: 0` es la respuesta explícita "talonario nuevo".
 */
export function useAnswerEinvoiceNumbering() {
  const qc = useQueryClient()
  return useMutation<
    EInvoiceRegisterNumbering,
    Error,
    { registerId: string; lastInvoiceNumber: number }
  >({
    mutationFn: (body) =>
      api.post<EInvoiceRegisterNumbering>("/v1/einvoice?action=numbering", {
        registerId: body.registerId,
        lastInvoiceNumber: String(body.lastInvoiceNumber),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: NUMBERING_KEY })
      // La numeración vive en `document_sequence` y el panel de cajas la lee
      // de ahí: sin esto, Sucursales → Cajas seguiría mostrando el número
      // viejo hasta un refresh manual.
      qc.invalidateQueries({ queryKey: ["registers", "admin"] })
    },
  })
}
