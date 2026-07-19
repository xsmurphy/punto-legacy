/**
 * Storage del Bearer token del device, namespaced por `module` para que
 * convivan en el mismo browser sin pisarse.
 *
 * Bug previo (2026-06-28): un solo key `punto.device.token` causaba que si
 * el admin pareaba primero el POS y después una pantalla cliente en el MISMO
 * browser (por ejemplo abriendo /connect/[id] desde el POS para probar), el
 * token de screen sobrescribía al de POS — el POS quedaba publicando con
 * Bearer del screen y el guard backend rechazaba con 403.
 *
 * Default `"pos"` en todas las funciones por back-compat: la mayoría de los
 * call-sites son del POS y el cambio queda transparent. Una migración one-shot
 * (al leer) mueve el token legacy `punto.device.token` → `...token.pos` la
 * primera vez que un browser viejo abre la app.
 *
 * `kds`/`display` (O2, context/24-orders-module-plan.md): mismo patrón de
 * namespacing — un browser podría en teoría parear varias pantallas (poco
 * común, pero el namespace evita la misma clase de bug que causó el
 * incidente 2026-06-28 con pos/screen).
 */

export type DeviceModule = "pos" | "screen" | "kds" | "display"

const KEY_PREFIX = "punto.device.token"
const LEGACY_KEY = KEY_PREFIX // pre-namespacing — se trata como POS al migrar

function storageKey(module: DeviceModule): string {
  return `${KEY_PREFIX}.${module}`
}

/** Migra el token legacy (sin namespace) al slot POS si existe y todavía
 * no hay un POS token namespaced. One-shot, idempotente. */
function migrateLegacyIfNeeded(): void {
  if (typeof window === "undefined") return
  const legacy = window.localStorage.getItem(LEGACY_KEY)
  if (!legacy) return
  const posKey = storageKey("pos")
  if (window.localStorage.getItem(posKey) === null) {
    window.localStorage.setItem(posKey, legacy)
  }
  // El legacy key ahora colisiona con `posKey` para browsers viejos —
  // dejarlo NO causa problemas funcionales (nadie lo lee ya), pero
  // limpiarlo evita estados raros con polling cross-tab futuros.
  window.localStorage.removeItem(LEGACY_KEY)
}

export function getDeviceToken(module: DeviceModule = "pos"): string | null {
  if (typeof window === "undefined") return null
  migrateLegacyIfNeeded()
  return window.localStorage.getItem(storageKey(module))
}

export function setDeviceToken(token: string, module: DeviceModule = "pos"): void {
  if (typeof window === "undefined") return
  window.localStorage.setItem(storageKey(module), token)
}

export function clearDeviceToken(module: DeviceModule = "pos"): void {
  if (typeof window === "undefined") return
  window.localStorage.removeItem(storageKey(module))
}
