"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface FinanceCategory {
  id: string
  name: string
  kind: "income" | "expense"
  /**
   * Código contable EXTERNO (mig 167) — el que matchea esta categoría contra
   * el plan de cuentas del contador del comercio. Opcional y único por
   * comercio; el backend lo normaliza ('' → null).
   */
  code: string | null
  parentId: string | null
  sortOrder: number
  isSystem: boolean
  status: number
}

/** Lista de categorías de Finanzas. El backend auto-crea las default (Ventas, Proveedores, etc.) al primer acceso. */
export function useFinanceCategories() {
  return useQuery<FinanceCategory[]>({
    queryKey: ["finance", "categories"],
    queryFn: () => api.get<FinanceCategory[]>("/v1/finance/categories"),
    staleTime: 30_000,
  })
}

export function useCreateFinanceCategory() {
  const qc = useQueryClient()
  return useMutation<
    FinanceCategory,
    Error,
    { name: string; kind: "income" | "expense"; code?: string | null; parentId?: string | null }
  >({
    mutationFn: (values) => api.post<FinanceCategory>("/v1/finance/categories", values),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "categories"] })
    },
  })
}

export function useUpdateFinanceCategory() {
  const qc = useQueryClient()
  return useMutation<
    FinanceCategory,
    Error,
    { id: string; name: string; code?: string | null; parentId?: string | null }
  >({
    mutationFn: ({ id, name, ...rest }) =>
      api.put<FinanceCategory>(`/v1/finance/categories?id=${id}`, { name, ...rest }),
    onSuccess: () => {
      // Invalida "finance" entero: el listado de movimientos trae el nombre y
      // el código de la categoría resueltos por JOIN.
      qc.invalidateQueries({ queryKey: ["finance"] })
    },
  })
}

/** Archiva (soft-delete) una categoría. Las default (isSystem) no se pueden archivar. */
export function useArchiveFinanceCategory() {
  const qc = useQueryClient()
  return useMutation<{ id: string; status: number }, Error, string>({
    mutationFn: (id) => api.del<{ id: string; status: number }>(`/v1/finance/categories?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["finance", "categories"] })
    },
  })
}
