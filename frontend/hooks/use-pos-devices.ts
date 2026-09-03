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
  activeSessions: number
  /**
   * Tenencia DE ESTE DISPOSITIVO, que no es lo mismo que la asignación:
   * `registerId` dice a qué caja pertenece el aparato, esto dice si está
   * reteniendo alguna caja ahora. Facturar exige tenerla, y solo un
   * dispositivo puede tener cada caja a la vez (context/29).
   */
  holdsRegister: boolean
  /**
   * Nombre de la caja que tiene TOMADA. Puede diferir de `registerName` si el
   * aparato fue reasignado sin liberar su tenencia vieja — es la caja que el
   * revoke va a liberar. null cuando no retiene ninguna.
   */
  heldRegisterName: string | null
  /**
   * Rastro operativo del aparato (`DeviceHistoryService` en la API). Con
   * cualquier elemento, el DELETE duro lo rechaza con 409 y la UI deshabilita
   * la acción. Opcional para tolerar un backend anterior a 2026-09-01: sin el
   * campo, el listado se comporta como antes y el 409 del servidor sigue
   * siendo la última defensa.
   */
  historyKinds?: string[]
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
