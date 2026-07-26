// Minimal Web Bluetooth API type declarations (not all browsers expose these in lib.dom.d.ts)
interface BluetoothRemoteGATTCharacteristic {
  writeValueWithoutResponse(value: BufferSource): Promise<void>
}

interface BluetoothRemoteGATTService {
  getCharacteristic(uuid: string): Promise<BluetoothRemoteGATTCharacteristic>
}

interface BluetoothRemoteGATTServer {
  getPrimaryService(uuid: string): Promise<BluetoothRemoteGATTService>
  disconnect(): void
}

interface BluetoothGATT {
  connect(): Promise<BluetoothRemoteGATTServer>
}

export interface BTDevice {
  id: string
  name?: string
  gatt?: BluetoothGATT
}

interface BTBluetoothAPI {
  requestDevice(options: { acceptAllDevices: boolean; optionalServices?: string[] }): Promise<BTDevice>
  getDevices(): Promise<BTDevice[]>
}

interface NavWithBluetooth extends Navigator {
  bluetooth: BTBluetoothAPI
}

export function isWebBluetoothSupported(): boolean {
  return typeof navigator !== "undefined" && "bluetooth" in navigator
}

export async function requestBluetoothPrinter(): Promise<BTDevice> {
  if (!isWebBluetoothSupported()) {
    throw new Error("Web Bluetooth no está disponible en este navegador.")
  }
  return (navigator as NavWithBluetooth).bluetooth.requestDevice({
    acceptAllDevices: true,
    optionalServices: ["000018f0-0000-1000-8000-00805f9b34fb"],
  })
}

/**
 * Dispositivos Bluetooth ya autorizados por el usuario en este browser
 * (equivalente a `getAuthorizedPrinters()` de WebUSB). La Estación de
 * Impresión los usa para saber qué `station_printer` está realmente vinculada
 * al arrancar — Web Bluetooth no permite reabrir un device sin permiso previo.
 */
export async function getAuthorizedBluetoothPrinters(): Promise<BTDevice[]> {
  if (!isWebBluetoothSupported()) return []
  try {
    return await (navigator as NavWithBluetooth).bluetooth.getDevices()
  } catch {
    // `getDevices()` requiere el flag chrome://flags/#enable-web-bluetooth-new-permissions-backend
    // en algunas versiones; sin él tira. Tratar como "ninguno autorizado".
    return []
  }
}

export async function sendBytesViaBluetooth(
  deviceId: string,
  bytes: Uint8Array,
): Promise<void> {
  if (!isWebBluetoothSupported()) {
    throw new Error("Web Bluetooth no disponible.")
  }
  const bluetooth = (navigator as NavWithBluetooth).bluetooth

  const devices = await bluetooth.getDevices()
  const device = devices.find((d) => d.id === deviceId)
  if (!device) {
    throw new Error(
      "Dispositivo Bluetooth no encontrado. El browser perdió el acceso — vinculá la impresora nuevamente.",
    )
  }

  if (!device.gatt) throw new Error("Dispositivo no soporta GATT.")

  const server = await device.gatt.connect()
  try {
    const service = await server.getPrimaryService("000018f0-0000-1000-8000-00805f9b34fb")
    const characteristic = await service.getCharacteristic("00002af1-0000-1000-8000-00805f9b34fb")

    const CHUNK = 512
    for (let i = 0; i < bytes.length; i += CHUNK) {
      await characteristic.writeValueWithoutResponse(bytes.slice(i, i + CHUNK))
    }
  } finally {
    server.disconnect()
  }
}
