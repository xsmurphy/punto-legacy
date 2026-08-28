"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

// ── tipos ──────────────────────────────────────────────────────────────────

export interface TeamRole {
  id: string
  name: string
}

export interface TeamMember {
  id: string
  name: string
  email: string | null
  phone: string | null
  status: 0 | 1
  color: string | null
  lockPass: string | null
  inCalendar: boolean
  calendarPosition: number
  roleId: string | null
  roleName: string | null
  /** @deprecated Usar outletIds (primer item o null). Mantenido para back-compat. */
  outletId: string | null
  /** @deprecated Usar outletNames. Mantenido para back-compat. */
  outletName: string | null
  outletIds: string[]
  outletNames: string[]
  createdAt: string | null
  updatedAt: string | null
}

export interface TeamMemberFormValues {
  name: string
  email: string
  phone: string
  password: string
  roleId: string
  outletIds: string[]
  lockPass: string
  inCalendar: boolean
  color: string
  status: "1" | "0"
}

// ── queries ────────────────────────────────────────────────────────────────

export function useTeamMembers(opts?: { q?: string; status?: string }) {
  return useQuery<{ users: TeamMember[] }>({
    queryKey: ["team", opts?.q ?? "", opts?.status ?? ""],
    queryFn: () => {
      const params = new URLSearchParams()
      if (opts?.q) params.set("q", opts.q)
      if (opts?.status !== undefined && opts.status !== "") {
        params.set("status", opts.status)
      }
      const qs = params.toString()
      return api.get(`/v1/users${qs ? `?${qs}` : ""}`)
    },
    staleTime: 30 * 1000,
  })
}

export function useTeamMember(id: string | undefined) {
  return useQuery<TeamMember>({
    queryKey: ["team", id],
    queryFn: () => api.get<TeamMember>(`/v1/users?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

export function useTeamRoles() {
  return useQuery<{ roles: TeamRole[] }>({
    queryKey: ["team-roles"],
    queryFn: () => api.get("/v1/users?resource=roles"),
    staleTime: 5 * 60 * 1000,
  })
}

// ── mutations ──────────────────────────────────────────────────────────────

const NONE = "__none__"

/**
 * `originalRoleId`: el rol con el que se ABRIÓ la ficha (`NONE` si no tenía).
 * En edición, `roleId` viaja SOLO si cambió.
 *
 * Mandarlo siempre rompía dos cosas a la vez, las dos reportadas por el owner:
 *
 *   1. Editar tu PROPIA ficha (aunque solo cambiaras el PIN) daba 403 "No podés
 *      cambiar tu propio rol": el guard de `/v1/users` mira si la clave `roleId`
 *      viene en el body, no si el valor cambió.
 *   2. Si el rol del usuario no matcheaba ninguna opción del selector, el
 *      selector mostraba "Sin rol asignado" y el submit mandaba `roleId: null`,
 *      BORRÁNDOLE el rol a alguien que nadie quiso tocar.
 *
 * `NONE` sigue siendo mandable como `null` cuando es un cambio REAL: "Sin rol
 * asignado" es una opción explícita del selector, no solo un placeholder.
 */
function serialize(
  values: TeamMemberFormValues,
  isEdit: boolean,
  originalRoleId?: string,
) {
  const payload: Record<string, unknown> = {
    name:             values.name,
    email:            values.email || null,
    phone:            values.phone || null,
    outletIds:        values.outletIds ?? [],
    lockPass:         values.lockPass || null,
    inCalendar:       values.inCalendar,
    color:            values.color || null,
    status:           Number(values.status),
  }
  const roleChanged = !isEdit || values.roleId !== (originalRoleId ?? NONE)
  if (roleChanged) {
    payload.roleId = (!values.roleId || values.roleId === NONE) ? null : values.roleId
  }
  if (!isEdit || values.password) {
    payload.password = values.password
  }
  return payload
}

export function useCreateTeamMember() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (values: TeamMemberFormValues) =>
      api.post<TeamMember>("/v1/users", serialize(values, false)),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["team"] })
    },
  })
}

export function useUpdateTeamMember() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({
      id,
      values,
      originalRoleId,
    }: {
      id: string
      values: TeamMemberFormValues
      /** Rol con el que se abrió la ficha — ver `serialize`. */
      originalRoleId?: string
    }) => api.put<TeamMember>(`/v1/users?id=${id}`, serialize(values, true, originalRoleId)),
    onSuccess: (_data, { id }) => {
      qc.invalidateQueries({ queryKey: ["team"] })
      qc.invalidateQueries({ queryKey: ["team", id] })
    },
  })
}

export function useDeleteTeamMember() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => api.del<{ id: string; status: number }>(`/v1/users?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["team"] })
    },
  })
}
