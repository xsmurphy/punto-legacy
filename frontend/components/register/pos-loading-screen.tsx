"use client"

/**
 * PosLoadingScreen — pantalla de carga inicial del POS.
 *
 * Se muestra mientras el bootstrap (`useBootstrap`) aún no devolvió data.
 * Misma apariencia que el LockScreen (mismo bg, mismo logo, misma jerarquía
 * visual) — es importante para evitar el "flash" donde se ve el contenido
 * del POS antes de que se decida si bloquear o no. Lo único distinto es
 * que en vez de PIN hay una barra de progreso animada (indeterminada).
 *
 * Una vez el bootstrap llega, el layout decide entre:
 *   - LockScreen (si userCount > 1)
 *   - Contenido del POS (si userCount <= 1)
 */

import { PuntoLogo } from "@/components/layout/punto-logo"

export function PosLoadingScreen() {
  return (
    <div
      role="status"
      aria-label="Cargando caja"
      className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-background"
    >
      <div className="mb-8">
        <PuntoLogo variant="mark" className="size-[35px]" />
      </div>
      {/* Barra de progreso indeterminada — animación CSS pura,
          sin dependencia de progress de estado. */}
      <div
        className="h-0.5 w-56 overflow-hidden rounded-full bg-muted"
        aria-hidden="true"
      >
        <div className="h-full w-1/3 animate-pos-loading rounded-full bg-foreground/70" />
      </div>
    </div>
  )
}
