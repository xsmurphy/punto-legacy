"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Timbrado de la caja — la caja ES el punto de expedición (context/29 §1),
 * así que su timbrado (número, EEE-PPP, vigencia) vive acá y no en la
 * pantalla de facturación electrónica, que solo lo LEE.
 */
export interface RegisterFiscal {
  /** Número de timbrado asignado por la SET. */
  invoiceAuth: string
  /** "EEE-PPP" — establecimiento y punto de expedición (ej. "001-001"). */
  invoicePrefix: string
  /** Inicio de vigencia, "YYYY-MM-DD". */
  invoiceAuthStart: string
  /** Vencimiento, "YYYY-MM-DD". */
  invoiceAuthExpiration: string
}

export interface RegisterListItem {
  id: string
  name: string
  outletId: string
  outletName: string
  status: boolean
  fiscal: RegisterFiscal
}

export function useRegistersAdmin() {
  return useQuery<{ registers: RegisterListItem[] }>({
    queryKey: ["registers", "admin"],
    queryFn: () => api.get<{ registers: RegisterListItem[] }>("/v1/register?resource=listAll"),
    staleTime: 30 * 1000,
  })
}

export function useCreateRegister() {
  const qc = useQueryClient()
  return useMutation<{ id: string; name: string }, Error, { outletId: string; name: string }>({
    mutationFn: (vars) =>
      api.post<{ id: string; name: string }>("/v1/register", {
        action: "create",
        outletId: vars.outletId,
        name: vars.name,
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["registers"] })
      qc.invalidateQueries({ queryKey: ["bootstrap"] })
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
    },
  })
}

export function useUpdateRegister() {
  const qc = useQueryClient()
  return useMutation<
    { ok: boolean },
    Error,
    { id: string; name?: string; status?: boolean; fiscal?: Partial<RegisterFiscal> }
  >({
    mutationFn: (vars) => {
      const payload: Record<string, unknown> = { action: "update", id: vars.id }
      if (vars.name !== undefined)   payload.name   = vars.name
      if (vars.status !== undefined) payload.status = vars.status
      if (vars.fiscal !== undefined) payload.fiscal = vars.fiscal
      return api.post<{ ok: boolean }>("/v1/register", payload)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["registers"] })
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
    },
  })
}

export function useDeleteRegister() {
  const qc = useQueryClient()
  return useMutation<{ deleted: "soft" | "hard"; reason?: string }, Error, string>({
    mutationFn: (id) =>
      api.post<{ deleted: "soft" | "hard" }>("/v1/register", { action: "delete", id }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["registers"] })
      qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
    },
  })
}
