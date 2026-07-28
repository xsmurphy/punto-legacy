export type DeviceKind = "pos" | "screen" | "kds" | "display" | "print"

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
  activeSessions: number      // sesiones auth_session activas para este device
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
