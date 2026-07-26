/**
 * Tipos de la Estación de Impresión (P1, context/26-print-station-plan.md).
 *
 * Espejo exacto de `presentPrinter()` / `presentJob()` de
 * `api/lib/Printing/PrintPoolService.php` — si cambia el backend, cambia acá.
 */

export type PrinterTransport = "usb" | "bluetooth" | "network" | "native"
export type PrinterKind = "thermal" | "inkjet" | "matrix" | "generic"
export type PrintJobFormat = "escpos" | "html" | "raw"
export type PrintJobStatus = "queued" | "printing" | "done" | "failed" | "cancelled"

/** Config física del transport. Cada transport usa su subconjunto. */
export interface TransportConfig {
  /** usb */
  vendorId?: number
  productId?: number
  deviceLabel?: string
  /** bluetooth */
  bluetoothDeviceId?: string
  /** network */
  networkHost?: string
  networkPort?: number
}

export interface StationPrinter {
  id: string
  companyId: string
  outletId: string
  /** device de la estación a la que pertenece la impresora física. */
  deviceId: string
  name: string
  kind: PrinterKind
  transport: PrinterTransport
  transportConfig: TransportConfig
  /** 1 activa, 0 soft-deleted desde el panel. */
  status: number
  createdAt: string | null
  updatedAt: string | null
}

export interface PrintJob {
  id: string
  companyId: string
  outletId: string
  stationPrinterId: string
  docType: string | null
  format: PrintJobFormat
  /** base64 para escpos|raw, HTML plano para html. */
  payload: string
  copies: number
  openDrawer: boolean
  status: PrintJobStatus
  attempts: number
  lastError: string | null
  createdAt: string | null
  updatedAt: string | null
}

/**
 * Entrada del log en vivo de la pantalla (estado local, no persiste).
 * Guarda el `job` completo para que "Reintentar" pueda re-despacharlo sin
 * volver a pedirlo (un job fallido ya no aparece en ?resource=pending).
 */
export interface StationLogEntry {
  jobId: string
  at: number
  job: PrintJob
  printerName: string
  state: "printing" | "done" | "failed"
  error?: string
}
