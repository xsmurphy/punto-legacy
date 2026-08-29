import type { PaperWidthMm } from "./roll-grid"

export type PrinterTransport = "usb" | "bluetooth" | "network" | "native" | "station"
export type PrinterMode = "escpos" | "native"
export type PrinterDocType =
  | "receipt" | "factura" | "quote" | "order" | "withdraw" | "delivery" | "closeReg" | "return"

export interface PrinterBinding {
  id: string
  name: string
  color: string
  transport: PrinterTransport
  // USB
  vendorId: number | null
  productId: number | null
  deviceLabel: string | null
  // Bluetooth
  bluetoothDeviceId: string | null
  // Network
  networkHost: string | null
  networkPort: number | null
  // Station (Servidor de impresión — station_printer.id, ver print-station/types.ts)
  stationPrinterId: string | null
  // Config
  mode: PrinterMode
  templateId: string | null
  paperWidthMm: PaperWidthMm
  copies: number
  openDrawer: boolean
  autoPrint: boolean
  printDelay: number
  categoryIds: string[]
  docTypes: PrinterDocType[]
  createdAt: string
  updatedAt?: string
}

export function getBindingsByDocType(
  bindings: PrinterBinding[],
  docType: PrinterDocType,
): PrinterBinding[] {
  return bindings.filter((b) => b.docTypes.includes(docType))
}

export function getBindingsForSale(
  bindings: PrinterBinding[],
  docType: PrinterDocType,
  itemCategoryIds: string[],
): PrinterBinding[] {
  return bindings.filter(
    (b) =>
      b.docTypes.includes(docType) &&
      (b.categoryIds.length === 0 ||
        b.categoryIds.some((c) => itemCategoryIds.includes(c))),
  )
}
