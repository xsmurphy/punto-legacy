/**
 * Shapes de Document Templates (builder de tickets/facturas/cotizaciones).
 * Backend: api/lib/Settings/DocumentTemplateService.php
 */

export type DocType =
  | "receipt"     // ticket POS
  | "invoice"     // factura
  | "quote"       // presupuesto / cotización
  | "workorder"   // orden de trabajo
  | "giftcard"    // gift card
  | "delivery"    // remito

export type PageSize =
  | "57mm"
  | "76mm"
  | "80mm"
  | "A4"
  | "A4-landscape"
  | "letter"
  | "legal"

/** Config JSONB — toggles de bloques visibles + textos + tipografía. */
export interface DocumentTemplateConfig {
  // Identidad de la empresa
  showLogo?: boolean
  showCompanyName?: boolean
  showCompanyTIN?: boolean
  showCompanyAddress?: boolean
  showCompanyPhone?: boolean

  // Sucursal y caja
  showOutletName?: boolean
  showOutletAddress?: boolean
  showRegisterInfo?: boolean
  showCashier?: boolean

  // Documento
  showDocNumber?: boolean
  showDocDate?: boolean
  showCustomer?: boolean

  // Items
  showItemsTable?: boolean
  showItemSKU?: boolean
  showItemQty?: boolean
  showItemUnitPrice?: boolean
  showItemDiscount?: boolean
  showItemTaxBreakdown?: boolean

  // Totales
  showSubtotal?: boolean
  showDiscount?: boolean
  showTaxBreakdown?: boolean
  showTotal?: boolean
  showAmountPaid?: boolean
  showChange?: boolean

  // Texto custom
  headerText?: string
  footerText?: string
  notesAfterItems?: string

  // Tipografía y layout
  font?: string                // 'Courier New' | 'Arial' | 'Times New Roman' | 'Helvetica'
  fontSize?: number            // 8..16 típico
  uppercase?: boolean
  marginLeft?: number          // mm (solo aplica a rolls 57/76/80mm)
}

export interface DocumentTemplate {
  templateId: string
  name: string
  docType: DocType
  pageSize: PageSize
  isDefault: boolean
  config: DocumentTemplateConfig
  created_at: string | null
  updated_at: string | null
}

export const DOC_TYPE_LABELS: Record<DocType, string> = {
  receipt:   "Ticket POS",
  invoice:   "Factura",
  quote:     "Presupuesto",
  workorder: "Orden de trabajo",
  giftcard:  "Gift card",
  delivery:  "Remito",
}

export const PAGE_SIZE_LABELS: Record<PageSize, string> = {
  "57mm":          "Rollo 57 mm",
  "76mm":          "Rollo 76 mm",
  "80mm":          "Rollo 80 mm",
  "A4":            "A4",
  "A4-landscape":  "A4 (horizontal)",
  "letter":        "Carta",
  "legal":         "Oficio",
}

export const FONT_OPTIONS = [
  "Courier New",
  "Arial",
  "Helvetica",
  "Times New Roman",
  "Verdana",
  "Tahoma",
] as const

export function defaultConfig(docType: DocType): DocumentTemplateConfig {
  // Defaults sensatos por tipo de doc.
  if (docType === "receipt") {
    return {
      showLogo: false,
      showCompanyName: true,
      showCompanyTIN: true,
      showCompanyAddress: true,
      showCompanyPhone: true,
      showOutletName: true,
      showOutletAddress: false,
      showRegisterInfo: true,
      showCashier: true,
      showDocNumber: true,
      showDocDate: true,
      showCustomer: true,
      showItemsTable: true,
      showItemSKU: false,
      showItemQty: true,
      showItemUnitPrice: true,
      showItemDiscount: true,
      showItemTaxBreakdown: false,
      showSubtotal: true,
      showDiscount: true,
      showTaxBreakdown: true,
      showTotal: true,
      showAmountPaid: true,
      showChange: true,
      headerText: "",
      footerText: "Gracias por su compra",
      font: "Courier New",
      fontSize: 10,
      uppercase: false,
      marginLeft: 2,
    }
  }
  // invoice / quote / workorder / delivery → defaults más formales
  return {
    showLogo: true,
    showCompanyName: true,
    showCompanyTIN: true,
    showCompanyAddress: true,
    showCompanyPhone: true,
    showOutletName: true,
    showOutletAddress: true,
    showRegisterInfo: false,
    showCashier: false,
    showDocNumber: true,
    showDocDate: true,
    showCustomer: true,
    showItemsTable: true,
    showItemSKU: true,
    showItemQty: true,
    showItemUnitPrice: true,
    showItemDiscount: true,
    showItemTaxBreakdown: true,
    showSubtotal: true,
    showDiscount: true,
    showTaxBreakdown: true,
    showTotal: true,
    showAmountPaid: false,
    showChange: false,
    headerText: "",
    footerText: "",
    font: "Helvetica",
    fontSize: 11,
    uppercase: false,
    marginLeft: 0,
  }
}
