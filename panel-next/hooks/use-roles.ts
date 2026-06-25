"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface Role {
  id: string
  name: string
  isSeed: boolean
  slug: string | null
  permissions: string[]
}

export interface PermissionCatalogGroup {
  [group: string]: Array<{ id: string; label: string }>
}

export function useRoles() {
  return useQuery<{ roles: Role[] }>({
    queryKey: ["roles"],
    queryFn: () => api.get("/v1/roles"),
    staleTime: 60 * 1000,
  })
}

export function useRole(id: string | null) {
  const { data } = useRoles()
  return data?.roles.find((r) => r.id === id) ?? null
}

export function usePermissionCatalog() {
  return useQuery<{ groups: PermissionCatalogGroup }>({
    queryKey: ["permission-catalog"],
    queryFn: () => api.get("/v1/permissions"),
    staleTime: Infinity,
  })
}

export function useCreateRole() {
  const qc = useQueryClient()
  return useMutation<
    { id: string; name: string; permissions: string[] },
    Error,
    { name: string; permissions: string[] }
  >({
    mutationFn: (body) => api.post("/v1/roles", body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["roles"] })
    },
  })
}

export function useUpdateRole() {
  const qc = useQueryClient()
  return useMutation<
    void,
    Error,
    { id: string; name?: string; permissions?: string[] }
  >({
    mutationFn: ({ id, ...body }) => api.put(`/v1/roles?id=${id}`, body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["roles"] })
    },
  })
}

export function useDeleteRole() {
  const qc = useQueryClient()
  return useMutation<void, Error, string>({
    mutationFn: (id) => api.del(`/v1/roles?id=${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["roles"] })
    },
  })
}

export function useRoleUsers(roleId: string | null) {
  return useQuery<{ users: Array<{ id: string; name: string }> }>({
    queryKey: ["role-users", roleId],
    queryFn: () => api.get(`/v1/roles/users?id=${roleId}`),
    enabled: !!roleId,
    staleTime: 30 * 1000,
  })
}
