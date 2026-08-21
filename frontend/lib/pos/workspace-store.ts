/**
 * Store de preferencias de workspace del POS.
 *
 * `modulesFullscreen` oculta el `CartPanel` en los módulos que lo soportan
 * (`/pos/espacios`, `/pos/ordenes`) para que el módulo ocupe todo el ancho —
 * útil para un mozo con su propia computadora que quiere ver más mesas/
 * órdenes sin scrollear. Toggleable desde una barra flotante del módulo.
 *
 * ── Por qué persiste en localStorage y no sessionStorage ────────────────
 * A diferencia del lock screen (preferencia de LA SESIÓN del operador), esto
 * es preferencia del DISPOSITIVO/pantalla: si un mozo configuró su compu
 * para ver espacios a pantalla completa, eso debe sobrevivir cerrar la app
 * y volver a abrirla — no solo la pestaña actual.
 *
 * Solo aplica en desktop y solo en `FULLSCREEN_ROUTES`. En `/pos` (home,
 * venta) el carrito SIEMPRE se ve — la venta nunca cambia de layout, es
 * memoria muscular del cajero.
 */

import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"

interface WorkspaceState {
  /** Oculta el CartPanel en los módulos que lo soportan (/pos/espacios, /pos/ordenes). Solo desktop. */
  modulesFullscreen: boolean
  toggleModulesFullscreen: () => void
}

export const useWorkspaceStore = create<WorkspaceState>()(
  persist(
    (set) => ({
      modulesFullscreen: false,
      toggleModulesFullscreen: () => set((s) => ({ modulesFullscreen: !s.modulesFullscreen })),
    }),
    {
      name: "pos-workspace",
      storage: createJSONStorage(() => localStorage),
      partialize: (s) => ({ modulesFullscreen: s.modulesFullscreen }),
    },
  ),
)

/** Rutas donde el toggle de pantalla completa está soportado. */
export const FULLSCREEN_ROUTES = ["/pos/espacios", "/pos/ordenes"]

/** True si `pathname` corresponde a un módulo que soporta pantalla completa. */
export function supportsFullscreen(pathname: string): boolean {
  return FULLSCREEN_ROUTES.some((route) => pathname.startsWith(route))
}
