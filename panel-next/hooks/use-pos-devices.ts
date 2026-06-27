"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

export interface PosDevice {
  deviceId: string
  deviceName: string
  outletId: string | null
  outletName: string | null
  registerId: string | null
  registerName: string | null
  pairedByContactId: string | null
  pairedByName: string | null
  pairedAt: string | null
  lastSeenAt: string | null
  status: number
  revokedAt: string | null
  module: string | null
  ipLast: string | null
}

export function usePosDevices(opts: { showRevoked?: boolean } = {}) {
  const qs = opts.showRevoked ? "?showRevoked=1" : ""
  return useQuery<PosDevice[]>({
    queryKey: ["pos-devices", { showRevoked: !!opts.showRevoked }],
    queryFn: async () => {
      const res = await api.get<{ devices: PosDevice[] }>(`/v1/devices${qs}`)
      return res.devices ?? []
    },
    staleTime: 30 * 1000,
  })
}

/** Soft revoke: status=0. Preserva auditoría. */
export function useRevokePosDevice() {
  const qc = useQueryClient()
  return useMutation<{ ok: boolean }, Error, string>({
    mutationFn: (deviceId) =>
      api.del<{ ok: boolean }>(`/v1/devices?id=${deviceId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["pos-devices"] })
    },
  })
}

/** Delete físico. Solo permitido si el device ya está revocado (status=0). */
export function useDeletePosDevice() {
  const qc = useQueryClient()
  return useMutation<{ ok: boolean }, Error, string>({
    mutationFn: (deviceId) =>
      api.del<{ ok: boolean }>(`/v1/devices?id=${deviceId}&hard=1`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["pos-devices"] })
    },
  })
}
