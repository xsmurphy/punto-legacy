export type PrinterTransport = "usb"
export type PrinterMode = "escpos" | "native"
export type PrinterDocType =
  | "receipt"
  | "quote"
  | "order"
  | "withdraw"
  | "delivery"
  | "closeReg"
  | "return"

export interface PrinterBinding {
  id: string
  name: string
  color: string
  transport: PrinterTransport
  vendorId: number | null
  productId: number | null
  deviceLabel: string | null
  mode: PrinterMode
  templateId: string | null
  paperWidthMm: 58 | 80
  copies: number
  openDrawer: boolean
  autoPrint: boolean
  printDelay: number
  categoryIds: string[]
  docTypes: PrinterDocType[]
  createdAt: string
  updatedAt?: string
}

/** Filtra bindings por tipo de documento. */
export function getBindingsByDocType(
  bindings: PrinterBinding[],
  docType: PrinterDocType,
): PrinterBinding[] {
  return bindings.filter((b) => b.docTypes.includes(docType))
}

/** Filtra bindings para una venta: tipo de doc + categorías de ítems. */
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
