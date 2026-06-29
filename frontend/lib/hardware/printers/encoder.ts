/**
 * Wrapper sobre @point-of-sale/receipt-printer-encoder.
 * Solo expone los builders necesarios para Slice 1: ticket de prueba.
 */

import ReceiptPrinterEncoder from "@point-of-sale/receipt-printer-encoder"

export function buildTestTicket(opts: { paperWidthMm: 58 | 80 }): Uint8Array {
  const columns = opts.paperWidthMm === 58 ? 32 : 48

  const now = new Intl.DateTimeFormat("es", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
  }).format(new Date())

  const encoder = new ReceiptPrinterEncoder({ columns })

  return encoder
    .initialize()
    .align("center")
    .bold(true)
    .line("Punto — Test de impresión")
    .bold(false)
    .newline()
    .align("left")
    .line(now)
    .newline()
    .line("Si ves este texto, la impresora")
    .line("está vinculada correctamente.")
    .newline()
    .rule({ style: "single" })
    .cut()
    .encode()
}
