"use client"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface DeviceInvitation {
  id: string
  userCode: string | null
  module: string
  outletId: string | null
  registerId: string | null
  deviceName: string | null
  // `consumed` (mig 171) es el estado terminal del canje de un solo uso: la
  // invitación entregó su token y ya no entrega otro. Desde ese cambio es el
  // destino normal de una invitación aprobada — antes se quedaban en
  // `approved` para siempre, que es justamente lo que las volvía emisores de
  // credenciales permanentes.
  status: "pending" | "opened" | "approved" | "consumed" | "denied" | "expired"
  deviceUa: string | null
  deviceIp: string | null
  openedAt: string | null
  createdAt: string
  expiresAt: string
}

interface CreateInvitationPayload {
  module: string
  outletId?: string
  registerId?: string
  deviceName?: string
  ttlHours?: number
}

export interface CreateInvitationResponse {
  id: string
  url: string
  expiresAt: string
}

export function useDeviceInvitations() {
  return useQuery<DeviceInvitation[]>({
    queryKey: ["device-invitations"],
    queryFn: async () => {
      const res = await api.get<{ invitations: DeviceInvitation[] }>(
        "/v1/device_invitations?resource=list"
      )
      return res.invitations ?? []
    },
    refetchInterval: 10_000,
    staleTime: 5_000,
  })
}

export function useCreateDeviceInvitation() {
  const qc = useQueryClient()
  return useMutation<CreateInvitationResponse, Error, CreateInvitationPayload>({
    mutationFn: (payload) => {
      const form = new FormData()
      form.append("action", "create")
      form.append("module", payload.module)
      if (payload.outletId) form.append("outletId", payload.outletId)
      if (payload.registerId) form.append("registerId", payload.registerId)
      if (payload.deviceName) form.append("deviceName", payload.deviceName)
      if (payload.ttlHours != null) form.append("ttlHours", String(payload.ttlHours))
      return api.postForm<CreateInvitationResponse>("/v1/device_invitations", form)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["device-invitations"] })
    },
  })
}

export function useApproveDeviceInvitation() {
  const qc = useQueryClient()
  return useMutation<{ deviceId: string }, Error, { id: string; userCodeConfirm: string }>({
    mutationFn: ({ id, userCodeConfirm }) => {
      const form = new FormData()
      form.append("action", "approve")
      form.append("id", id)
      form.append("userCode", userCodeConfirm)
      return api.postForm<{ deviceId: string }>("/v1/device_invitations", form)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["device-invitations"] })
      qc.invalidateQueries({ queryKey: ["pos-devices"] })
    },
  })
}

export function useDenyDeviceInvitation() {
  const qc = useQueryClient()
  return useMutation<{ denied: boolean }, Error, string>({
    mutationFn: (id) => {
      const form = new FormData()
      form.append("action", "deny")
      form.append("id", id)
      return api.postForm<{ denied: boolean }>("/v1/device_invitations", form)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["device-invitations"] })
    },
  })
}
