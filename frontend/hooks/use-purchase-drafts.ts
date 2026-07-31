"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { PurchaseCreatePayload } from "@/hooks/use-purchases"

/**
 * Borradores de compra generados por OCR/IA — context/32-ocr-facturas-compra.md.
 *
 * Flujo: `useUploadInvoice` sube la foto a `/api/ocr-invoice` (BFF Next, hace
 * la extracción IA + crea el draft vía PHP). El draft nace `pending` con
 * `extracted` (JSON crudo de la IA, inmutable). La pantalla de revisión
 * arma `edited` (mismo shape que `PurchaseCreatePayload`) a partir de
 * `extracted` y lo guarda con `useUpdatePurchaseDraft` a medida que el
 * usuario corrige. `useApprovePurchaseDraft` crea la compra real — la IA
 * nunca escribe stock/finanzas por su cuenta.
 */

export type PurchaseDraftStatus = "pending" | "approved" | "rejected"

export interface ExtractedInvoiceItem {
  description: string | null
  quantity: number | null
  unitPrice: number | null
  total: number | null
  ivaRate: 0 | 5 | 10 | null
}

export interface ExtractedInvoice {
  supplier: { name: string | null; ruc: string | null }
  receiver: { ruc: string | null; name: string | null }
  invoice: {
    number: string | null
    timbrado: string | null
    timbradoStart: string | null
    timbradoEnd: string | null
    date: string | null
    condition: "contado" | "credito" | null
    dueDate?: string | null
    isElectronic: boolean | null
    cdc: string | null
  }
  items: ExtractedInvoiceItem[]
  totals: {
    subtotal: number | null
    exempt: number | null
    discount: number | null
    iva5: number | null
    iva10: number | null
    total: number | null
  }
  currency: string
  isInvoice: boolean
  /** null = no había RUC de tenant cargado en la sucursal para comparar. */
  receiverMatchesTenant: boolean | null
  confidence: number
}

export interface PurchaseDraftSummary {
  id: string
  status: PurchaseDraftStatus
  imageUrl: string | null
  supplierName: string | null
  invoiceNumber: string | null
  total: number | null
  confidence: number | null
  transactionId: string | null
  error: string | null
  createdAt: string
}

export interface PurchaseDraftDetail {
  id: string
  status: PurchaseDraftStatus
  imageUrl: string | null
  extracted: ExtractedInvoice | null
  edited: PurchaseCreatePayload | null
  /** Validación aritmética post-extracción (subtotal/IVA/total) — no bloqueante. */
  warnings: string[]
  contactId: string | null
  contactName: string | null
  contactTin: string | null
  transactionId: string | null
  outletId: string
  userId: string
  error: string | null
  createdAt: string
  approvedAt: string | null
}

export interface PurchaseDraftListResponse {
  rows: PurchaseDraftSummary[]
  total: number
}

export interface ApproveDraftResult {
  draftId: string
  status: PurchaseDraftStatus
  transactionId: string | null
  alreadyApproved: boolean
  warning: string | null
}

const DRAFTS_KEY = ["purchase-drafts"] as const

/** Lista de borradores, opcionalmente filtrada por status. */
export function usePurchaseDrafts(status?: PurchaseDraftStatus) {
  const qs = status ? `?status=${status}` : ""
  return useQuery<PurchaseDraftListResponse>({
    queryKey: [...DRAFTS_KEY, "list", status ?? "all"],
    queryFn: () => api.get<PurchaseDraftListResponse>(`/v1/purchase-drafts${qs}`),
    staleTime: 15_000,
  })
}

/** Count de borradores pendientes — para el badge del botón "Subir factura". */
export function usePendingDraftsCount() {
  const { data } = usePurchaseDrafts("pending")
  return data?.total ?? 0
}

/** Detalle completo de un borrador (imagen + extracted + edited). */
export function usePurchaseDraft(id: string | null) {
  return useQuery<PurchaseDraftDetail>({
    queryKey: [...DRAFTS_KEY, "detail", id],
    queryFn: () => api.get<PurchaseDraftDetail>(`/v1/purchase-drafts?id=${id}`),
    enabled: !!id,
    staleTime: 15_000,
  })
}

/**
 * Sube UNA foto de factura → crea un borrador. El caller (UI) itera por
 * cada archivo seleccionado y llama esto una vez por foto (una factura =
 * un borrador = una compra al aprobar).
 */
export function useUploadInvoice() {
  const qc = useQueryClient()
  return useMutation<PurchaseDraftDetail, Error, { file: File; outletId: string }>({
    mutationFn: ({ file, outletId }) => {
      const form = new FormData()
      form.set("image", file, file.name)
      form.set("outletId", outletId)
      return api.postForm<PurchaseDraftDetail>("/ocr-invoice", form)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: DRAFTS_KEY })
    },
  })
}

/** Guarda correcciones del usuario sobre un borrador pendiente. */
export function useUpdatePurchaseDraft() {
  const qc = useQueryClient()
  return useMutation<
    PurchaseDraftDetail,
    Error,
    { id: string; edited?: PurchaseCreatePayload; contactId?: string | null }
  >({
    mutationFn: ({ id, edited, contactId }) =>
      api.put<PurchaseDraftDetail>(`/v1/purchase-drafts?id=${id}`, {
        ...(edited !== undefined ? { edited } : {}),
        ...(contactId !== undefined ? { contactId } : {}),
      } as Record<string, unknown>),
    onSuccess: (_, { id }) => {
      qc.invalidateQueries({ queryKey: DRAFTS_KEY })
      qc.invalidateQueries({ queryKey: [...DRAFTS_KEY, "detail", id] })
    },
  })
}

/**
 * Aprueba el borrador — crea la compra real (reusa PurchasesService::create
 * server-side). `edited` opcional: guarda+aprueba en un solo request.
 * Invalida `purchases`/`dashboard-widget` además de `purchase-drafts`
 * porque una compra real nueva pasa a existir.
 */
export function useApprovePurchaseDraft() {
  const qc = useQueryClient()
  return useMutation<ApproveDraftResult, Error, { id: string; edited?: PurchaseCreatePayload }>({
    mutationFn: ({ id, edited }) =>
      api.post<ApproveDraftResult>(`/v1/purchase-drafts?id=${id}&resource=approve`, {
        ...(edited !== undefined ? { edited } : {}),
      } as Record<string, unknown>),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: DRAFTS_KEY })
      qc.invalidateQueries({ queryKey: ["purchases"] })
      qc.invalidateQueries({ queryKey: ["dashboard-widget"] })
    },
  })
}

/** Rechaza el borrador — no crea nada, queda como registro descartado. */
export function useRejectPurchaseDraft() {
  const qc = useQueryClient()
  return useMutation<{ draftId: string; status: PurchaseDraftStatus }, Error, string>({
    mutationFn: (id) =>
      api.post<{ draftId: string; status: PurchaseDraftStatus }>(
        `/v1/purchase-drafts?id=${id}&resource=reject`,
        {},
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: DRAFTS_KEY })
    },
  })
}
