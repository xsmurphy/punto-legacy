"use client"

import * as React from "react"
import { useRouter, usePathname } from "next/navigation"
import { useQueryClient } from "@tanstack/react-query"
import { toast } from "sonner"

/**
 * Listener global del evento `api:unauthorized`. Cualquier 401 del api-client
 * dispara: toast informativo + clear de cache + redirect a /login.
 *
 * firedRef debouncea el handler — cuando múltiples queries fallan en paralelo
 * evitamos spamear router.replace y duplicar toasts.
 *
 * pathnameRef mantiene el pathname actual sin necesidad de re-registrar el
 * listener en cada navegación (evita la ventana de miss entre remove/add).
 *
 * Montar UNA vez por route group. El POS no monta este componente porque el
 * realm del dispositivo (_jwt) tiene un flujo de re-pair distinto al login
 * humano — solo se monta en PanelAuthGuard (realm panel).
 */
export function AuthSentinel() {
  const router = useRouter()
  const pathname = usePathname()
  const qc = useQueryClient()
  const firedRef = React.useRef(false)
  const pathnameRef = React.useRef(pathname)

  // Mantener pathnameRef actualizado en cada render sin re-registrar el listener.
  React.useEffect(() => {
    pathnameRef.current = pathname
  }, [pathname])

  React.useEffect(() => {
    function handler(_e: Event) {
      if (firedRef.current) return
      firedRef.current = true

      // En la pantalla de login el 401 del bootstrap inicial es esperado.
      if (pathnameRef.current?.startsWith("/login")) {
        firedRef.current = false
        return
      }

      qc.clear()
      toast.error("Tu sesión expiró. Iniciá sesión nuevamente.")
      router.replace("/login")

      // Reset después de 2 s — por si el user vuelve a entrar.
      setTimeout(() => {
        firedRef.current = false
      }, 2000)
    }

    window.addEventListener("api:unauthorized", handler)
    return () => window.removeEventListener("api:unauthorized", handler)
    // Sin pathname en deps: se lee via pathnameRef para que el listener sea
    // estable durante toda la vida del componente.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [router, qc])

  return null
}
