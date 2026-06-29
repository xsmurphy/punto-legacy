/**
 * Capa de transporte WebUSB para impresoras térmicas.
 * Requiere navegador con soporte de navigator.usb (Chrome/Edge en escritorio).
 */

export function isWebUsbSupported(): boolean {
  return typeof navigator !== "undefined" && "usb" in navigator
}

/**
 * Abre el diálogo de selección de dispositivo USB del navegador.
 * El usuario debe aprobar el acceso manualmente.
 */
export async function requestUsbPrinter(): Promise<USBDevice> {
  if (!isWebUsbSupported()) {
    throw new Error("WebUSB no está disponible en este navegador.")
  }
  return navigator.usb.requestDevice({ filters: [] })
}

/**
 * Devuelve los dispositivos USB previamente autorizados por el usuario.
 */
export async function getAuthorizedPrinters(): Promise<USBDevice[]> {
  if (!isWebUsbSupported()) return []
  return navigator.usb.getDevices()
}

/**
 * Envía bytes a la impresora USB.
 * Abre el dispositivo → reclama la interfaz 0 → encuentra el endpoint
 * OUT bulk → transfiere → libera la interfaz.
 */
export async function sendBytes(device: USBDevice, bytes: Uint8Array): Promise<void> {
  await device.open()

  try {
    if (device.configuration === null) {
      await device.selectConfiguration(1)
    }

    await device.claimInterface(0)

    try {
      const iface = device.configuration?.interfaces[0]
      if (!iface) throw new Error("Impresora sin interfaz USB disponible.")

      const endpoint = iface.alternate.endpoints.find(
        (e: USBEndpoint) => e.direction === "out" && e.type === "bulk",
      )
      if (!endpoint) {
        throw new Error("No se encontró endpoint OUT bulk en la impresora.")
      }

      // transferOut expects BufferSource (ArrayBuffer | ArrayBufferView<ArrayBuffer>).
      // The encoder returns Uint8Array<ArrayBufferLike>; at runtime the buffer is
      // always a plain ArrayBuffer, so casting here is safe.
      const result = await device.transferOut(
        endpoint.endpointNumber,
        bytes as Uint8Array<ArrayBuffer>,
      )
      if (result.status !== "ok") {
        throw new Error(`Error al transferir datos: ${result.status}`)
      }
    } finally {
      await device.releaseInterface(0)
    }
  } finally {
    await device.close()
  }
}
