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

/**
 * Tipos de rastro operativo que un dispositivo puede dejar. Las CLAVES son el
 * contrato con el backend (`DeviceHistoryService::SOURCES`, `historyKinds` del
 * DTO); el castellano vive acá, del lado que lo muestra.
 */
export type DeviceHistoryKind =
  | "register_lease"
  | "auth_session"
  | "pos_order_event"
  | "station_printer"

/**
 * Cómo se le nombra cada rastro al admin. En singular y en su idioma, no en el
 * de la tabla: quien mira esta pantalla no sabe qué es `pos_order_event`.
 */
export const DEVICE_HISTORY_LABELS: Record<DeviceHistoryKind, string> = {
  register_lease: "tenencia de cajas",
  auth_session: "sesiones de acceso",
  pos_order_event: "actividad sobre órdenes",
  station_printer: "impresoras de la estación",
}

/**
 * "tiene historial de tenencia de cajas y sesiones de acceso" — el motivo que
 * acompaña a la acción "Eliminar" deshabilitada.
 *
 * Devuelve `null` cuando no hay historial: sin motivo, la acción se ofrece
 * normal. Una clave desconocida (backend más nuevo que este bundle) se ignora
 * en la enumeración pero NO en la decisión de bloquear — eso lo decide quien
 * llama, mirando si la lista viene vacía o no. Errar hacia "no borrar" es el
 * lado correcto.
 */
export function deviceHistoryReason(kinds: string[]): string | null {
  if (kinds.length === 0) return null
  const labels = kinds
    .map((k) => DEVICE_HISTORY_LABELS[k as DeviceHistoryKind])
    .filter((l): l is string => Boolean(l))
  if (labels.length === 0) {
    return "Tiene historial operativo y se conserva para auditoría"
  }
  const list =
    labels.length === 1
      ? labels[0]
      : `${labels.slice(0, -1).join(", ")} y ${labels[labels.length - 1]}`
  return `Tiene historial de ${list} y se conserva para auditoría`
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
  /**
   * Rastro operativo que dejó este aparato, de cualquier tipo y en cualquier
   * estado (NO "tiene la caja ahora"). Con al menos un elemento, el borrado
   * físico está vedado y la acción "Eliminar" se ofrece deshabilitada con el
   * motivo. Vacío = se puede borrar.
   */
  historyKinds: string[]
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
