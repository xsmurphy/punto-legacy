/**
 * Datos demo para la Vista Previa del editor — sustituye cada bloque
 * tipado por una string realista. Replica el `fakeSale` de
 * panel/scripts/documentPrintBuilder.source.js cuando el motor corre con
 * `demo=true`.
 *
 * NO es el motor de impresión real — solo un sustituidor sencillo para
 * que el usuario vea cómo queda la plantilla antes de guardar. El motor
 * canónico vive en panel/scripts/documentPrintBuilder.source.js y corre
 * en el POS al imprimir un comprobante.
 */

import type { BlockType } from "@/lib/types/print-template"
import { normalizeBlockType } from "@/lib/hardware/printers/blocks"

/**
 * Texto mock por tipo de bloque. Para `custom`, `hor_line`, `ver_line`
 * y `company_logo` el bloque se renderiza distinto (no texto plano), así
 * que no entran en el mapa.
 */
const MOCK_TEXT: Partial<Record<BlockType, string>> = {
  // Empresa
  company_name:         "Mi Empresa S.A.",
  company_billing_name: "Mi Empresa Sociedad Anónima",
  company_tin:          "80012345-6",
  company_address:      "Av. España 1234, Asunción",
  company_email:        "info@miempresa.com",
  company_phone:        "021 111-222",
  company_website:      "www.miempresa.com",
  // Sucursal
  outlet_name:          "Casa Central",
  outlet_billing_name:  "Mi Empresa S.A. – Casa Central",
  outlet_tin:           "80012345-6",
  outlet_address:       "Av. España 1234, Asunción",
  outlet_phone:         "021 111-222",
  // Caja / usuario / impresora
  register_name:        "Caja Principal",
  printer_name:         "Impresora 1",
  user_name:            "Juan Pérez",
  // Timbrado
  auth_number:          "12345678",
  auth_start_date:      "2025-01-01",
  auth_expiration:      "2026-12-31",
  // Cliente
  customer_name:        "Comercial Ejemplo S.R.L.",
  customer_full_name:   "María González",
  customer_tin:         "80098765-4",
  customer_ci:          "1.234.567",
  customer_address:     "Mariscal López 567",
  customer_address_2:   "Edificio Torre, Piso 4",
  customer_location:    "Trinidad",
  customer_city:        "Asunción",
  customer_country:     "Paraguay",
  customer_phone:       "0981 234-567",
  customer_phone_2:     "0971 765-432",
  customer_email:       "cliente@email.com",
  customer_note:        "Nota del cliente",
  customer_loyalty:     "1.250",
  customer_birthday:    "1985-03-15",
  table_number:         "Mesa: 3",
  // Transacción
  document_number:      "001-001-0000123",
  document_prefix:      "001-001-",
  document_sufix:       "0000123",
  document_type:        "FACTURA",
  date:                 "2026-06-12 14:35",
  duedate:              "2026-07-12",
  discount:             "5.000",
  subtotal:             "100.000",
  tax_total:            "10.909",
  total:                "120.000",
  nums_to_words:        "Ciento veinte mil guaraníes",
  sale_type:            "Contado",
  sale_type_contado:    "✕",
  sale_type_credit:     "",
  payment_methods:      "Efectivo",
  tags:                 "etiqueta1, etiqueta2",
  note:                 "Gracias por su compra",
  transaction_id:       "TX-789456",
  transaction_id_barcode: "*789456*",
  associated_document:  "001-001-0000099",
  fe_py:                "QR FE",
  tax_single:           "10.909",
  // F3c (context/38 §D): agregados por tasa — preview plano, no distingue
  // por taxId (no hay tenant real en este mock).
  subtotal_by_rate:     "100.000",
  iva_by_rate:          "10.909",
  item_total_by_rate:   "110.909",
  iva_total:            "10.909",
  // Artículos — el motor real los desglosa en filas. Para preview, plana.
  item:                 "Producto A\nProducto B",
  item_id:              "PRD001\nPRD002",
  item_uid:             "SKU-001\nSKU-002",
  item_units:           "2\n1",
  item_note:            "\n",
  item_tags:            "\n",
  item_tax:             "10%\n10%",
  // D4: renombrados a snake_case — ver normalizeBlockType (blocks.ts).
  item_tax_amount:       "9.091\n1.818",
  item_tax_amount_single: "9.091\n1.818",
  item_discount:        "0\n0",
  item_price:           "50.000\n20.000",
  item_uni_price:       "50.000\n20.000",
  item_price_notax:     "45.455\n18.182",
  item_total:           "100.000\n20.000",
  item_subtotal:        "120.000",
  // Listados receipt — preview minimalista
  item_receipt:         "Producto A    10%  2  PRD001  50.000  100.000\nProducto B    10%  1  PRD002  20.000   20.000",
  item_receipt_2:       "Producto A     2  PRD001\nProducto B     1  PRD002",
  item_receipt_3:       "Producto A  100.000\nProducto B   20.000",
  item_receipt_4:       "Producto A   2  PRD001  50.000  100.000\nProducto B   1  PRD002  20.000   20.000",
}

/**
 * Devuelve el texto a mostrar en preview para un bloque.
 * - `custom`: respeta el texto del usuario.
 * - `hor_line` / `ver_line` / `company_logo`: se renderizan visual, no texto.
 * - Resto: usa MOCK_TEXT o cae al texto original (placeholder) si no hay mock.
 */
export function getBlockMockText(type: BlockType, originalText: string): string {
  // D4: plantillas guardadas antes del renombre siguen trayendo el string
  // legacy en `config.data[].type` (sin migración) — normalizar acá para
  // que la preview no se rompa.
  const normalized = normalizeBlockType(type)
  if (normalized === "custom") return originalText
  return MOCK_TEXT[normalized] ?? originalText
}
