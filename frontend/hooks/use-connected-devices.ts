"use client"
import { useMemo } from "react"
import { usePosDevices } from "@/hooks/use-pos-devices"
import type { ConnectedDevice, DeviceKind } from "@/lib/devices/connected-device"

/**
 * Fuente única de devices conectados: lee de `/v1/devices` (que retorna TODAS
 * las filas de `device`, sin discriminar por module) y deriva el `kind` desde
 * el campo `module`. Reemplaza la fusión previa `useScreens + usePosDevices`
 * que duplicaba filas y hardcodeaba `kind="pos"` para todo (bug: pantallas
 * cliente aparecían etiquetadas como "Caja POS" en la columna Tipo).
 */
function moduleToKind(module: string | null | undefined): DeviceKind {
  if (module === "screen" || module === "kds" || module === "display" || module === "print") return module
  return "pos"
}

export function useConnectedDevices(opts: { showRevoked?: boolean } = {}) {
  const showRevoked = opts.showRevoked === true
  const { data: posData, isLoading } = usePosDevices({ showRevoked })

  const devices = useMemo<ConnectedDevice[]>(() => {
    return (posData ?? []).map((d) => {
      const kind = moduleToKind(d.module)
      return {
        key: `${kind}:${d.deviceId}`,
        kind,
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
        activeSessions: d.activeSessions,
        holdsRegister: d.holdsRegister ?? false,
        registerHeldBy: d.registerHeldBy ?? null,
        historyKinds: d.historyKinds ?? [],
      }
    })
  }, [posData])

  return { devices, isLoading }
}
