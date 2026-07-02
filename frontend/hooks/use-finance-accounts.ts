"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface FinanceAccount {
  id: string
  name: string
  type: "cash" | "bank" | "wallet"
  openingBalance: number
  currentBalance: number
  bankName: string | null
  accountNumber: string | null
  outletId: string | null
  isSystem: boolean
  status: number
}

export interface FinanceAccountFormValues {
  name: string
  type: "cash" | "bank" | "wallet"
  openingBalance?: number | null
  bankName?: string | null
  accountNumber?: string | null
}

/** Lista de cuentas de Finanzas. El backend auto-crea "Efectivo" al primer acceso. */
export function useFinanceAccounts() {
  return useQuery<FinanceAccount[]>({
    queryKey: ["finance", "accounts"],
    queryFn: () => api.get<FinanceAccount[]>("/v1/finance/accounts"),
    staleTime: 30_000,
  })
}

export function useCreateFinanceAccount() {
  const qc = useQueryClient()
  return useMutation<FinanceAccount, Error, FinanceAccountFormValues>({
    mutationFn: (values) =>
      api.post<FinanceAccount>("/v1/finance/accounts", {
        name: values.name,
        type: values.type,
        openingbalance: values.openingBalance ?? 0,
        bankname: values.bankName ?? undefined,
        accountnumber: values.accountNumber ?? undefined,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "accounts"] })
      qc.invalidateQueries({ queryKey: ["finance", "summary"] })
      qc.invalidateQueries({ queryKey: ["finance", "config"] })
    },
  })
}

export function useUpdateFinanceAccount() {
  const qc = useQueryClient()
  return useMutation<
    FinanceAccount,
    Error,
    { id: string; values: Partial<Pick<FinanceAccountFormValues, "name" | "bankName" | "accountNumber">> }
  >({
    mutationFn: ({ id, values }) =>
      api.put<FinanceAccount>(`/v1/finance/accounts?id=${id}`, {
        name: values.name,
        bankname: values.bankName,
        accountnumber: values.accountNumber,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "accounts"] })
    },
  })
}

/** Archiva (soft-delete) una cuenta. La cuenta Efectivo (isSystem) no se puede archivar. */
export function useArchiveFinanceAccount() {
  const qc = useQueryClient()
  return useMutation<{ id: string; status: number }, Error, string>({
    mutationFn: (id) => api.del<{ id: string; status: number }>(`/v1/finance/accounts?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "accounts"] })
      qc.invalidateQueries({ queryKey: ["finance", "config"] })
    },
  })
}
