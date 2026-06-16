/**
 * Store del lock screen del POS.
 *
 * `locked === true` → el overlay del lock screen tapa todo y captura input.
 * Desbloquea con PIN. El PIN real vive contra el backend (F2/RBAC, pendiente);
 * por ahora hardcodeado a "1234" para iterar la UX.
 *
 * Lo dispara el item "Bloquear" del menú de usuario en /pos.
 */

import { create } from "zustand"

interface LockState {
  locked: boolean
  lock: () => void
  unlock: () => void
}

export const useLockStore = create<LockState>()((set) => ({
  locked: false,
  lock: () => set({ locked: true }),
  unlock: () => set({ locked: false }),
}))

// TODO (F2): validar contra backend (POST /v1/lock-screen/verify con el PIN).
// Por ahora stub: 4 dígitos == "1234". El backend devolverá éxito / fracaso
// y emitirá un nuevo _jwt_pos al validar.
export const STUB_PIN = "1234"
