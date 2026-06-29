"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  AiLedgerEntry,
  BillingInvoice,
  BillingPack,
  BillingPacksResult,
  BillingPlan,
  BillingSummary,
  CheckoutResult,
  RequestPlanChangeResult,
} from "@/lib/types/billing"

/** Resumen de facturación del tenant (plan, uso, créditos, pagos). */
export function useBilling() {
  return useQuery<BillingSummary>({
    queryKey: ["billing"],
    queryFn: () => api.get<BillingSummary>("/v1/billing"),
    staleTime: 60 * 1000,
  })
}

/** Lista de planes disponibles para la pantalla de comparación. */
export function useBillingPlans() {
  return useQuery<{ plans: BillingPlan[] }>({
    queryKey: ["billing", "plans"],
    queryFn: () => api.get<{ plans: BillingPlan[] }>("/v1/billing?resource=plans"),
    staleTime: 5 * 60 * 1000, // planes cambian poco
  })
}

/** Últimos 20 movimientos del ledger de créditos IA del tenant. */
export function useAiLedger() {
  return useQuery<{ rows: AiLedgerEntry[] }>({
    queryKey: ["billing", "ai-ledger"],
    queryFn: () => api.get<{ rows: AiLedgerEntry[] }>("/v1/billing?resource=ai-ledger"),
    staleTime: 60 * 1000,
  })
}

/** Facturas emitidas por dLocal Go (historial de pagos). */
export function useBillingInvoices() {
  return useQuery<{ invoices: BillingInvoice[] }>({
    queryKey: ["billing", "invoices"],
    queryFn: () =>
      api.get<{ invoices: BillingInvoice[] }>("/v1/billing?resource=invoices"),
    staleTime: 60 * 1000,
  })
}

/** Packs de créditos disponibles para compra + flag de pagos habilitados. */
export function useBillingPacks() {
  return useQuery<BillingPacksResult>({
    queryKey: ["billing", "packs"],
    queryFn: () => api.get<BillingPacksResult>("/v1/billing?resource=packs"),
    staleTime: 60 * 1000,
  })
}

/** Inicia el checkout dLocal para comprar un pack de créditos. */
export function useCheckout() {
  return useMutation<CheckoutResult, Error, { packId: string }>({
    mutationFn: ({ packId }) =>
      api.post<CheckoutResult>("/v1/billing", {
        action: "checkout",
        packId,
      }),
  })
}

/** Solicita un cambio de plan (NO captura pagos — admin aprueba). */
export function useRequestPlanChange() {
  const qc = useQueryClient()
  return useMutation<
    RequestPlanChangeResult,
    Error,
    { planCode: number; note?: string }
  >({
    mutationFn: ({ planCode, note }) =>
      api.post<RequestPlanChangeResult>("/v1/billing", {
        action: "requestPlanChange",
        planCode,
        note: note ?? "",
      }),
    onSuccess: () => {
      // Invalidar el resumen para reflejar cualquier cambio futuro.
      qc.invalidateQueries({ queryKey: ["billing"] })
    },
  })
}
