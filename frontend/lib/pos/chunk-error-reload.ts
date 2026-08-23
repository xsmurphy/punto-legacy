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

/**
 * Borra las caches del SHELL de la app (documentos, payloads RSC, estáticos de
 * Next, precache de Serwist) y fuerza al service worker a buscar versión nueva.
 *
 * Sin esto, recargar no arregla nada: el SW vuelve a servir el mismo HTML/
 * chunk viejo de cache y el ChunkLoadError se repite en cada entrada a la ruta
 * — reload, error, reload. Eso es lo que hacía que una ruta pesada se sintiera
 * como "la página se recarga sola cada vez" (reporte del owner 2026-07-29).
 *
 * NO toca `pos-bootstrap` ni `pos-items` (datos que sostienen el modo offline)
 * ni IndexedDB, donde vive la cola de ventas sin sincronizar.
 */
const SHELL_CACHE_PATTERN =
  /precache|pages-|next-static|static-js|static-style|^others$|^pos-pages$/

async function purgeStaleAppShell(): Promise<void> {
  try {
    if (typeof caches !== "undefined") {
      const keys = await caches.keys()
      await Promise.all(
        keys.filter((k) => SHELL_CACHE_PATTERN.test(k)).map((k) => caches.delete(k)),
      )
    }
  } catch {
    // best-effort: si no se puede limpiar, igual recargamos.
  }
  try {
    const reg = await navigator.serviceWorker?.getRegistration()
    await reg?.update()
  } catch {
    // idem.
  }
}

/**
 * Sin red, purgar el shell es suicida: el precache es lo ÚNICO que tiene el
 * documento y los chunks del POS, y no hay servidor del que traerlos de
 * vuelta. Borrarlo y recargar garantiza la pantalla de error del navegador —
 * el mismo cuadro que el precache existe para evitar.
 *
 * Offline la purga tampoco tiene sentido conceptual: la hipótesis que la
 * justifica es "hay una versión nueva en el servidor", y sin servidor no hay
 * versión nueva. Recargamos pelado (el precache vuelve a servir el shell) y,
 * si el error persiste, que lo muestre el error boundary.
 */
function isOffline(): boolean {
  return typeof navigator !== "undefined" && navigator.onLine === false
}

/**
 * Reload único: si ya se intentó en esta sesión de tab, no vuelve a recargar
 * (deja que el error boundary muestre el estado, en vez de loopear).
 *
 * Con red, antes de recargar purga el shell cacheado — recargar sin eso
 * reproduce el mismo error.
 */
export function reloadOnceForChunkError(): boolean {
  if (alreadyAttemptedReload()) return false
  markReloadAttempted()
  if (isOffline()) {
    window.location.reload()
    return true
  }
  void purgeStaleAppShell().finally(() => window.location.reload())
  return true
}

/**
 * Reload manual (botón "Recargar" del error boundary). Sin guard —lo pidió una
 * persona— pero con la misma purga, que es lo que hace que el reintento tenga
 * alguna chance de traer la versión nueva.
 */
export function reloadNowForChunkError(): void {
  if (isOffline()) {
    window.location.reload()
    return
  }
  void purgeStaleAppShell().finally(() => window.location.reload())
}
