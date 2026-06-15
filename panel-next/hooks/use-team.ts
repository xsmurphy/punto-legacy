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
  outletId: string | null
  outletName: string | null
  createdAt: string | null
  updatedAt: string | null
}

export interface TeamMemberFormValues {
  name: string
  email: string
  phone: string
  password: string
  roleId: string
  outletId: string
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

function serialize(values: TeamMemberFormValues, isEdit: boolean) {
  const payload: Record<string, unknown> = {
    name:             values.name,
    email:            values.email || null,
    phone:            values.phone || null,
    roleId:           (!values.roleId || values.roleId === NONE) ? null : values.roleId,
    outletId:         (!values.outletId || values.outletId === NONE) ? null : values.outletId,
    lockPass:         values.lockPass || null,
    inCalendar:       values.inCalendar,
    color:            values.color || null,
    status:           Number(values.status),
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
    mutationFn: ({ id, values }: { id: string; values: TeamMemberFormValues }) =>
      api.put<TeamMember>(`/v1/users?id=${id}`, serialize(values, true)),
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
