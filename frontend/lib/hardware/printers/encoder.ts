/**
 * Wrapper sobre @point-of-sale/receipt-printer-encoder.
 * Solo expone los builders necesarios para Slice 1: ticket de prueba.
 */

import ReceiptPrinterEncoder from "@point-of-sale/receipt-printer-encoder"

/**
 * Pulso de apertura de cajón (device 0, on 25ms, off 250ms) — mismos valores
 * que `render-template.ts`. La Estación de Impresión lo necesita suelto porque
 * recibe el payload YA renderizado por el origen y solo sabe que el job trae
 * `openDrawer: true`; no renderiza plantillas.
 */
export function buildDrawerPulse(): Uint8Array {
  // Los tipos publicados del paquete no declaran `pulse()` aunque el método
  // existe en runtime (`render-template.ts` lo usa igual, vía `type Encoder = any`).
  const encoder = new ReceiptPrinterEncoder({ columns: 48 }) as unknown as {
    pulse(device: number, on: number, off: number): { encode(): Uint8Array }
  }
  return encoder.pulse(0, 25, 250).encode()
}

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
