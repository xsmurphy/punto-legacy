/**
 * Barrel de impresoras WebUSB. Punto de entrada para toda la UI y los comandos
 * que necesiten interactuar con impresoras térmicas.
 */

export * from "./binding"
export * from "./encoder"
export * from "./transports/usb"

import type { PrinterBinding } from "./binding"
import { buildTestTicket } from "./encoder"
import { getAuthorizedPrinters, sendBytes } from "./transports/usb"

/**
 * Imprime el ticket de prueba en la impresora identificada por `binding`.
 * Busca el dispositivo USB autorizado por vendorId+productId y le envía
 * los bytes ESC/POS generados por buildTestTicket.
 *
 * @throws Error si el dispositivo no está conectado, el permiso fue revocado,
 *         o no se encuentra un endpoint OUT bulk.
 */
export async function printTest(binding: PrinterBinding): Promise<void> {
  const devices = await getAuthorizedPrinters()
  const device = devices.find(
    (d) => d.vendorId === binding.vendorId && d.productId === binding.productId,
  )

  if (!device) {
    throw new Error(
      "La impresora no está conectada o el permiso fue revocado. " +
        "Desconectá y volvé a vincularla desde Ajustes → Impresoras.",
    )
  }

  const bytes = buildTestTicket({ paperWidthMm: binding.paperWidthMm })
  await sendBytes(device, bytes)
}
