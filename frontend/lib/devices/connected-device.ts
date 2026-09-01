export type DeviceKind = "pos" | "screen" | "kds" | "display" | "print"

/**
 * Dispositivo que tiene TOMADA una caja (`register_lease` activa, mig 141 —
 * context/29). Vive acá, con los tipos de dominio, y no en el hook que lo
 * consume: lo usan tanto el DTO de `/v1/devices` (`PosDevice`) como la fila
 * de la tabla (`ConnectedDevice`), y la dependencia correcta va de los hooks
 * hacia `lib/`, nunca al revés.
 */
export interface RegisterHolder {
  deviceId: string
  deviceName: string
}

export interface ConnectedDevice {
  key: string                 // `${kind}:${id}` — id único de fila
  kind: DeviceKind
  id: string                  // deviceId (pos) | id (screen)
  name: string
  outletName: string | null
  registerName: string | null
  module: string | null
  ipLast: string | null
  pairedByName: string | null
  pairedAt: string | null
  lastSeenAt: string | null
  status: number              // 1 activo, 0 revocado
  /**
   * Sesiones `auth_session` activas del device. Desde el fix de
   * `DeviceAuth::buildToken()` (2026-09-01) el invariante es 1: cada emisión
   * revoca la anterior. Un valor mayor es una ANOMALÍA, no un estado normal
   * — ver el comentario de la columna Estado en settings/devices/page.tsx.
   */
  activeSessions: number
  /** Este dispositivo tiene tomada su caja asignada (`register_lease` activa). */
  holdsRegister: boolean
  /** La caja asignada la tiene OTRO dispositivo; null si la tiene este o está libre. */
  registerHeldBy: RegisterHolder | null
}

export const DEVICE_KIND_LABELS: Record<DeviceKind, string> = {
  pos: "Caja POS",
  screen: "Pantalla cliente",
  kds: "KDS (preparación)",
  display: "Pantalla de despacho",
  print: "Estación de impresión",
}

/**
 * Ruta de la pantalla de cada tipo de dispositivo.
 *
 * El pareo guarda el token en el localStorage del browser del dispositivo
 * (namespaced por module, ver `lib/auth/device-token.ts`), así que volver a
 * abrir la ruta ahí recupera la sesión sin re-parear. El agujero que esto
 * tapa: si alguien cerraba la pestaña del KDS, la URL no estaba escrita en
 * ningún lado del panel y no había forma de volver a entrar salvo adivinarla.
 *
 * `screen` es `/checkout` — la pantalla del cliente no vive en `/screen`.
 */
export const DEVICE_KIND_ROUTES: Record<DeviceKind, string> = {
  pos: "/pos",
  screen: "/checkout",
  kds: "/kds",
  display: "/display",
  print: "/print",
}
