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
  lock: () => void
  unlock: () => void
  setActiveUser: (user: { id: string; name: string } | null) => void
}

export const useLockStore = create<LockState>()((set) => ({
  locked: false,
  activeUser: null,
  lock: () => set({ locked: true }),
  unlock: () => set({ locked: false }),
  setActiveUser: (user) => set({ activeUser: user }),
}))
