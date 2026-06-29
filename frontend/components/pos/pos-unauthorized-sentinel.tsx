"use client"

import * as React from "react"

/**
 * Listener del evento `module:logged-out` para el POS.
 *
 * El api-client invoca `moduleLogout()` directamente ante cualquier 401 POS —
 * el cleanup (token, stores, query cache, evento) ya ocurrió ANTES de que
 * este handler corra. El sentinel es un punto de extensión para acciones
 * post-logout (toast, telemetría, redirect futuro). NO debe llamar
 * moduleLogout() nuevamente: causaría un loop infinito (moduleLogout
 * despacha "module:logged-out" → re-entra el handler → ...).
 *
 * Montar dentro del SidebarInset del layout del POS.
 */
export function PosUnauthorizedSentinel() {
  React.useEffect(() => {
    function handleModuleLoggedOut() {
      // Extensión futura: mostrar toast "Sesión cerrada", redirigir, etc.
      // El cleanup ya fue hecho por moduleLogout() en el api-client interceptor.
    }

    window.addEventListener("module:logged-out", handleModuleLoggedOut)
    return () => window.removeEventListener("module:logged-out", handleModuleLoggedOut)
  }, [])

  return null
}
