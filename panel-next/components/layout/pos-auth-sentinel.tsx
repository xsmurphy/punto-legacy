"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { toast } from "sonner"
import { clearDeviceLocalId } from "@/lib/pos/device-local-id"

/**
 * Escucha el evento global `pos:unauthorized` que `api-client.ts` dispara
 * cuando un endpoint POS devuelve 401 (cookie `_jwt` ausente, JWT inválido
 * o device revocado).
 *
 * Acción: limpia el deviceLocalId del browser (porque el device asociado
 * dejó de existir) y redirige a `/pos-pair` para iniciar un pairing nuevo.
 *
 * Sin este sentinel, la 401 quedaba en el aire y el operador veía el
 * lockscreen o un estado roto sin saber por qué.
 *
 * Montar UNA vez en `(pos)/layout.tsx`.
 */
export function PosAuthSentinel() {
  const router = useRouter()
  const handledRef = React.useRef(false)

  React.useEffect(() => {
    function handleUnauthorized(_e: Event) {
      if (handledRef.current) return
      handledRef.current = true
      clearDeviceLocalId()
      toast.error("Tu dispositivo ya no está vinculado a esta caja.", {
        description: "Vinculá el dispositivo de nuevo para continuar.",
      })
      router.replace("/pos-pair")
    }
    window.addEventListener("pos:unauthorized", handleUnauthorized)
    return () => {
      window.removeEventListener("pos:unauthorized", handleUnauthorized)
    }
  }, [router])

  return null
}
