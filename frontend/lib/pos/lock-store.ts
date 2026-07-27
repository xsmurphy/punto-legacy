/**
 * Store del lock screen del POS.
 *
 * `locked === true` → el overlay del lock screen tapa todo y captura input.
 * Desbloquea con PIN validado contra los users locales del catalog store
 * (precacheados en el bootstrap — sin roundtrip al backend).
 *
 * Lo dispara el item "Bloquear" del menú de usuario en /pos.
 *
 * ── Por qué persiste en sessionStorage ──────────────────────────────────
 * El estado vivía SOLO en memoria, así que CUALQUIER recarga de la página lo
 * perdía y el POS volvía a pedir el PIN: F5, la actualización del service
 * worker, o el reload automático por ChunkLoadError tras un deploy (ver
 * `lib/pos/chunk-error-reload.ts` — el caso reportado: navegar a
 * /pos/guardadas con el shell viejo). Para un cajero en hora pico eso es
 * fricción pura y sin ganancia de seguridad: el dispositivo YA está pareado
 * y autenticado con su propio token (`_jwt`); el lock existe para saber QUÉ
 * operador está trabajando, no para autenticar la caja.
 *
 * `sessionStorage` y no `localStorage` es la semántica correcta: sobrevive
 * recargas de ESA pestaña, pero cerrar la app vuelve a pedir el PIN — un
 * operador no queda desbloqueado para siempre.
 *
 * `locked` también se persiste: si el operador bloqueó a propósito y la
 * página se recarga, tiene que seguir bloqueada.
 */

import { create } from "zustand"
import { persist, createJSONStorage } from "zustand/middleware"

interface LockState {
  locked: boolean
  activeUser: { id: string; name: string } | null
  /** True después del primer auto-lock por sesión, para que el layout no
   * vuelva a lockear si se remonta (Next puede invalidar la cache del layout
   * al navegar entre rutas hijas — un useRef local se resetea, este flag no)
   * ni tras una recarga de la página. Reset en logout/re-pair. */
  autoLockDone: boolean
  lock: () => void
  unlock: () => void
  setActiveUser: (user: { id: string; name: string } | null) => void
  markAutoLockDone: () => void
  /** Reset completo (logout / re-pair). */
  reset: () => void
}

export const useLockStore = create<LockState>()(
  persist(
    (set) => ({
      locked: false,
      activeUser: null,
      autoLockDone: false,
      lock: () => set({ locked: true }),
      unlock: () => set({ locked: false }),
      setActiveUser: (user) => set({ activeUser: user }),
      markAutoLockDone: () => set({ autoLockDone: true }),
      reset: () => set({ locked: false, activeUser: null, autoLockDone: false }),
    }),
    {
      name: "punto.pos.lock",
      storage: createJSONStorage(() => sessionStorage),
      /** Solo el estado — las acciones no se serializan. */
      partialize: (s) => ({
        locked: s.locked,
        activeUser: s.activeUser,
        autoLockDone: s.autoLockDone,
      }),
    },
  ),
)
