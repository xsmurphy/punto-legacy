/**
 * Layout del POS — full-screen, sin sidebar.
 *
 * El POS es una pantalla de caja de pantalla completa que el cajero
 * usa sin ninguna chrome de navegación extra. El layout es completamente
 * distinto al del panel-next (que tiene sidebar + header).
 *
 * Este layout:
 *   1. Gate de auth via PosAuthGuard (verifica cookie _jwt realm pos-app).
 *   2. Fullscreen: body ocupa 100vh / 100dvh, sin scroll externo.
 *
 * Las rutas hijas (register, pay, customers, etc.) manejan su propio
 * layout interno con las columnas/filas del POS.
 *
 * Ver context/16-app-next-rewrite.md §2 (fidelidad visual legacy = fullscreen)
 * y §7 Sprint 0.
 */

import { PosAuthGuard } from "@/components/layout/pos-auth-guard"

export default function PosLayout({ children }: { children: React.ReactNode }) {
  return (
    <PosAuthGuard>
      {/* Full-screen container: ocupa toda la ventana, sin scroll externo.
          Las pantallas del POS manejan su propio scroll interno si lo necesitan. */}
      <div className="flex h-svh w-full flex-col overflow-hidden bg-background">
        {children}
      </div>
    </PosAuthGuard>
  )
}
