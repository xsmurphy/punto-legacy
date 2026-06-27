"use client"
import { useMemo } from "react"
import { useScreens } from "@/hooks/use-screens"
import { usePosDevices } from "@/hooks/use-pos-devices"
import type { ConnectedDevice } from "@/lib/devices/connected-device"

export function useConnectedDevices(opts: { showRevoked?: boolean } = {}) {
  const showRevoked = opts.showRevoked === true
  const { data: screensData, isLoading: screensLoading } = useScreens()
  const { data: posData, isLoading: posLoading } = usePosDevices({ showRevoked })

  const devices = useMemo<ConnectedDevice[]>(() => {
    // El endpoint /v1/screens no acepta param showRevoked — filtramos client-side
    // para que el toggle se comporte igual que con pos-devices.
    const screens = (screensData?.screens ?? [])
      .filter((s) => showRevoked || s.status === 1)
      .map((s) => ({
      key: `screen:${s.id}`,
      kind: "screen" as const,
      id: s.id,
      name: s.name,
      outletName: null,
      registerName: s.registerName,
      module: null,
      ipLast: s.ipLast,
      pairedByName: null,
      pairedAt: null,
      lastSeenAt: s.lastSeenAt,
      status: s.status,
    }))

    const posDevices = (posData ?? []).map((d) => ({
      key: `pos:${d.deviceId}`,
      kind: "pos" as const,
      id: d.deviceId,
      name: d.deviceName || "Sin nombre",
      outletName: d.outletName,
      registerName: d.registerName,
      module: d.module,
      ipLast: d.ipLast,
      pairedByName: d.pairedByName,
      pairedAt: d.pairedAt,
      lastSeenAt: d.lastSeenAt,
      status: d.status,
    }))

    return [...posDevices, ...screens]
  }, [screensData, posData, showRevoked])

  return {
    devices,
    isLoading: screensLoading || posLoading,
  }
}
