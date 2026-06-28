/**
 * Store del lock screen del POS.
 *
 * `locked === true` → el overlay del lock screen tapa todo y captura input.
 * Desbloquea con PIN validado contra los users locales del catalog store
 * (precacheados en el bootstrap — sin roundtrip al backend).
 *
 * Lo dispara el item "Bloquear" del menú de usuario en /pos.
 */

import { create } from "zustand"

interface LockState {
  locked: boolean
  activeUser: { id: string; name: string } | null
  /** True después del primer auto-lock por sesión, para que el layout no
   * vuelva a lockear si se remonta (Next puede invalidar la cache del layout
   * al navegar entre rutas hijas — un useRef local se resetea, este flag no).
   * Reset en logout/re-pair. */
  autoLockDone: boolean
  lock: () => void
  unlock: () => void
  setActiveUser: (user: { id: string; name: string } | null) => void
  markAutoLockDone: () => void
  /** Reset completo (logout / re-pair). */
  reset: () => void
}

export const useLockStore = create<LockState>()((set) => ({
  locked: false,
  activeUser: null,
  autoLockDone: false,
  lock: () => set({ locked: true }),
  unlock: () => set({ locked: false }),
  setActiveUser: (user) => set({ activeUser: user }),
  markAutoLockDone: () => set({ autoLockDone: true }),
  reset: () => set({ locked: false, activeUser: null, autoLockDone: false }),
}))
