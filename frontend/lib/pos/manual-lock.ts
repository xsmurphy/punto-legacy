/**
 * Marca "esta caja se bloqueó A MANO" (owner, 2026-09-16 — context/72 §9.3).
 *
 * Con un solo usuario en la sucursal la caja abre sin PIN, pero ese
 * desbloqueo aplica SOLO al abrirla. Un bloqueo manual ("Bloquear") pide PIN
 * aunque el roster sea de uno — y como el lock store arranca siempre en
 * `locked: true` y el sin-PIN se dispara al arrancar, recargar la página
 * salteaba el bloqueo. Esta marca lo impide: vive en `localStorage` del
 * dispositivo (sobrevive recargas y cerrar la app), se pone al bloquear a
 * mano y se levanta ÚNICAMENTE al desbloquear con PIN. Mientras exista, no se
 * llama a `/api/pos/unlock-sole`.
 *
 * El bloqueo por inactividad NO la pone: no es un acto de la persona, y con
 * un solo usuario no hay a quién cederle la caja.
 *
 * Todo acceso va con try/catch: sin storage (modo privado, bloqueado) la
 * marca no persiste y el comportamiento degrada al de antes de este cambio.
 */

const KEY = "punto.pos.manual-lock"

function storage(): Storage | null {
  try {
    const s = (globalThis as { localStorage?: Storage }).localStorage
    return s ?? null
  } catch {
    return null
  }
}

export function readManualLock(): boolean {
  try {
    return storage()?.getItem(KEY) === "1"
  } catch {
    return false
  }
}

export function writeManualLock(on: boolean): void {
  try {
    const s = storage()
    if (!s) return
    if (on) s.setItem(KEY, "1")
    else s.removeItem(KEY)
  } catch {
    // best-effort: ver docblock
  }
}
