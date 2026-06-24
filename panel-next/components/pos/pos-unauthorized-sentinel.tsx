"use client"

import * as React from "react"
import { useRouter } from "next/navigation"

/**
 * Listener del evento `pos:unauthorized` para el POS.
 *
 * Cuando una operación POS recibe 401:
 * - Si detail.code === "DEVICE_REVOKED" → redirect a /pos-pair
 * - Caso contrario → lock() (bloquear con LockScreen para que el operador
 *   re-tipee su PIN sin perder el carrito)
 *
 * Montar dentro del SidebarInset del layout del POS.
 */
export function PosUnauthorizedSentinel() {
  const router = useRouter()

  React.useEffect(() => {
    function handlePosUnauthorized(e: Event) {
      const detail = (e as CustomEvent<{ path: string; message: string; code?: string | number }>).detail
      if (detail?.code === "DEVICE_REVOKED") {
        router.replace("/pos-pair")
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
  }, [router])

  return null
}
