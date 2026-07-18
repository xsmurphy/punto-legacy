/**
 * Detección + auto-recuperación de ChunkLoadError en el POS (PWA, Serwist
 * con skipWaiting+clientsClaim). Tras un deploy, un cliente con el shell
 * viejo puede pedir un chunk JS con hash que ya no existe → ChunkLoadError /
 * "Failed to fetch dynamically imported module" → sin este handler, Chrome
 * termina mostrando su pantalla nativa "This page couldn't load".
 *
 * Patrón estándar Next+PWA: al detectar el error, un (1) reload automático
 * de la página, guardado con un flag en sessionStorage para no loopear si
 * el reload no resuelve el problema (deploy roto, offline, etc.) — en ese
 * caso se deja ver el error boundary (`app/(pos)/error.tsx`).
 */

const RELOAD_GUARD_KEY = "pos:chunk-error-reload-attempted"

const CHUNK_ERROR_PATTERNS = [
  /ChunkLoadError/i,
  /Loading chunk [\d]+ failed/i,
  /Loading CSS chunk/i,
  /Failed to fetch dynamically imported module/i,
  /error loading dynamically imported module/i,
]

export function isChunkLoadError(error: unknown): boolean {
  if (!error) return false
  const name = error instanceof Error ? error.name : ""
  const message = error instanceof Error ? error.message : String(error)
  return CHUNK_ERROR_PATTERNS.some((re) => re.test(name) || re.test(message))
}

function alreadyAttemptedReload(): boolean {
  try {
    return sessionStorage.getItem(RELOAD_GUARD_KEY) === "1"
  } catch {
    // sessionStorage inaccesible (modo privado estricto, etc.) — no bloqueamos el reload.
    return false
  }
}

function markReloadAttempted(): void {
  try {
    sessionStorage.setItem(RELOAD_GUARD_KEY, "1")
  } catch {
    // no-op — si no se puede persistir el guard, en el peor caso se intenta de nuevo.
  }
}

/** Reload único: si ya se intentó en esta sesión de tab, no vuelve a recargar. */
export function reloadOnceForChunkError(): boolean {
  if (alreadyAttemptedReload()) return false
  markReloadAttempted()
  window.location.reload()
  return true
}
