/**
 * Builder de string de ticket térmico — placeholder Sprint 0.
 *
 * Toma los datos de una venta y construye el string listo para mandar
 * a QZ Tray (ESC/POS o texto plano según la config de la impresora).
 *
 * TODO (Slice A): portar desde `app/scripts/app.js`:
 *   - Buscar namespace `ncmPrinters` → función `buildTicket()` / `formatTicket()`
 *   - Respetar: encabezado empresa, líneas de items, totales, pie, código de barras
 *     del comprobante (si aplica), numeración fiscal.
 *   - El fallback `window.print()` genera un HTML printable desde el
 *     template de pantalla — mantener el fallback para impresoras sin QZ.
 *
 * Referencia de port:
 *   app/scripts/app.js → buscar `buildTicket` / `printTicket` / `ESC`
 *   app/scripts/app.js → buscar `ncmPrinters.template` / receipt HTML
 *
 * Ver context/14-app-rewrite-analysis.md §3.1.
 * Ver context/16-app-next-rewrite.md §7 Slice A.
 */

import type { CreateSalePayload, CreateSaleResult } from "@/lib/commands/create-sale"
import type { PosConfig } from "@/lib/types/pos-bootstrap"

// ── Types ─────────────────────────────────────────────────────────────────────

export interface TicketContext {
  sale: CreateSalePayload
  result: CreateSaleResult
  config: PosConfig
  /** Nombre del cajero para el pie del ticket. */
  cashierName: string
  /** Nombre de la sucursal. */
  outletName: string
  /** Nombre de la caja. */
  registerName: string
}

// ── Builder ───────────────────────────────────────────────────────────────────

/**
 * Construye el string del ticket listo para imprimir.
 * TODO (Slice A): implementar portando desde app.js ncmPrinters.
 */
export function buildTicket(_ctx: TicketContext): string {
  // TODO: port desde app/scripts/app.js → ncmPrinters.buildTicket()
  throw new Error("ticket-builder: no implementado aún — ver TODO en lib/hardware/ticket-builder.ts")
}

/**
 * Fallback impresión por `window.print()` (sin QZ Tray).
 * Abre el HTML del ticket en una ventana emergente y lanza el diálogo de impresión.
 * TODO (Slice A): implementar.
 */
export function printFallback(_ctx: TicketContext): void {
  // TODO: abrir ventana con HTML del ticket + window.print()
  throw new Error("printFallback: no implementado aún — ver TODO en lib/hardware/ticket-builder.ts")
}
