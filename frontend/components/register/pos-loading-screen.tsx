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
 * Una vez el bootstrap llega, lo que aparece es SIEMPRE el LockScreen (owner
 * 2026-08-24 — ver `lib/pos/lock-store.ts`). Ya no hay decisión por cantidad
 * de operadores: el POS entra bloqueado y el contenido de la caja se ve recién
 * después del PIN. Por eso esta pantalla comparte apariencia con el lock — la
 * transición de una a la otra no debe parpadear.
 */

import { PuntoLogo } from "@/components/layout/punto-logo"

export function PosLoadingScreen() {
  return (
    <div
      role="status"
      aria-label="Cargando caja"
      className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-background safe-area"
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
