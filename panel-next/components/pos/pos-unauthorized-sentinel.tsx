"use client"

import * as React from "react"
import { useQueryClient } from "@tanstack/react-query"
import { clearDeviceToken } from "@/lib/auth/device-token"

/**
 * Listener del evento `pos:unauthorized` para el POS.
 *
 * Cuando una operación POS recibe 401:
 * - DEVICE_REVOKED → invalida la query del bootstrap → PosAuthGuard re-evalúa →
 *   muestra <DeviceNotConnected /> (sin redirect; el viejo /pos-pair fue eliminado).
 * - Caso contrario → lock() para forzar PIN re-entry sin perder el carrito.
 *
 * Montar dentro del SidebarInset del layout del POS.
 */
export function PosUnauthorizedSentinel() {
  const qc = useQueryClient()

  React.useEffect(() => {
    function handlePosUnauthorized(e: Event) {
      const detail = (e as CustomEvent<{ path: string; message: string; code?: string | number }>).detail
      if (detail?.code === "DEVICE_REVOKED" || detail?.code === "device_revoked") {
        clearDeviceToken() // defensa en profundidad — api-client también lo hace
        qc.invalidateQueries({ queryKey: ["pos-bootstrap-auth"] })
      } else {
        import("@/lib/pos/lock-store")
          .then(({ useLockStore }) => {
            useLockStore.getState().lock()
          })
          .catch(() => {
            // lock-store no disponible — ignorar
          })
      }
    }

    window.addEventListener("pos:unauthorized", handlePosUnauthorized)
    return () => window.removeEventListener("pos:unauthorized", handlePosUnauthorized)
  }, [qc])

  return null
}
