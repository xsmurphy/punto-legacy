/**
 * Cliente QZ Tray tipado — placeholder Sprint 0.
 *
 * QZ Tray es el bridge browser→impresora local (térmica/fiscal/PDF) que
 * el POS legacy ya usa. Este módulo es el port tipado a TypeScript.
 *
 * TODO (Slice A): portar desde `app/scripts/app.js` namespace `ncmPrinters`:
 *   - `initQZ()` — conectar al WS de QZ Tray (localhost:8181 / 8182).
 *   - `printTicket(printerName, data)` — mandar el string del ticket a la cola.
 *   - `getPrinters()` — listar impresoras disponibles en la máquina.
 *   - Manejo de firma digital QZ (ver qz-tray-signature.php en el legacy).
 *
 * Referencia de port:
 *   app/scripts/app.js → buscar `qz.` / `ncmPrinters` / `qzTray`
 *   app/scripts/app.js → buscar `qz.websocket.connect` / `qz.print`
 *
 * Ver context/14-app-rewrite-analysis.md §3.1 (subsistema impresión).
 * Ver context/16-app-next-rewrite.md §7 Slice A.
 */

// ── Types ─────────────────────────────────────────────────────────────────────

export interface QzPrinter {
  name: string
}

export interface QzPrintJob {
  printer: string
  /** Datos del ticket como string (ESC/POS o texto plano). */
  data: string
  /** Encoding del ticket. Default 'UTF-8'. */
  encoding?: string
}

// ── Stub client ───────────────────────────────────────────────────────────────

/**
 * Conecta al daemon QZ Tray local (WS).
 * TODO (Slice A): implementar con la lib qz-tray npm + firma digital.
 */
export async function connectQZ(): Promise<void> {
  // TODO: port desde ncmPrinters.initQZ() en app.js
  throw new Error("QZ Tray: no implementado aún — ver TODO en lib/hardware/qz-tray.ts")
}

/**
 * Lista las impresoras disponibles en la máquina del cajero.
 * TODO (Slice A): implementar.
 */
export async function getQZPrinters(): Promise<QzPrinter[]> {
  // TODO: port desde ncmPrinters.getPrinters() en app.js
  throw new Error("QZ Tray: no implementado aún — ver TODO en lib/hardware/qz-tray.ts")
}

/**
 * Envía un job de impresión a la impresora seleccionada.
 * TODO (Slice A): implementar.
 */
export async function printQZ(_job: QzPrintJob): Promise<void> {
  // TODO: port desde ncmPrinters.printTicket() en app.js
  throw new Error("QZ Tray: no implementado aún — ver TODO en lib/hardware/qz-tray.ts")
}

/** Estado de conexión QZ (para mostrar indicador en el header del POS). */
export type QzStatus = "disconnected" | "connecting" | "connected" | "error"
