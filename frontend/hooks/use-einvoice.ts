"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type {
  EInvoiceAccount,
  EInvoicePaymentMethod,
  EInvoiceSaveAccountPayload,
  EInvoiceTestResult,
} from "@/lib/types/einvoice"

const ACCOUNT_KEY = ["einvoice", "account"]

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

/** Guarda usuario/contraseña (opcional) + config. Resetea status a 'unconfigured' si cambia la credencial (server-side). */
export function useSaveEinvoiceAccount() {
  const qc = useQueryClient()
  return useMutation<EInvoiceAccount, Error, EInvoiceSaveAccountPayload>({
    mutationFn: ({ username, password, config }) =>
      api.post<EInvoiceAccount>("/v1/einvoice?action=account", {
        username,
        password: password ?? "",
        config: JSON.stringify(config),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ACCOUNT_KEY })
    },
  })
}

/** Login + /auth/me contra Automate — persiste status/emitter/lastError server-side. */
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
 * Proxy de los medios de pago de Automate (F3 los mapea contra los medios
 * de pago de Punto). 403 si la cuenta no está conectada — `enabled` evita
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
