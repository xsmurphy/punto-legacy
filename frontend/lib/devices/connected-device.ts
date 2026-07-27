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
}

export const DEVICE_KIND_LABELS: Record<DeviceKind, string> = {
  pos: "Caja POS",
  screen: "Pantalla cliente",
  kds: "KDS (preparación)",
  display: "Pantalla de despacho",
  print: "Estación de impresión",
}
