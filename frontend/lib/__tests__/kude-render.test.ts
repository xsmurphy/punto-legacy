import { describe, expect, it } from "vitest"

import { renderKudePdf } from "@/lib/kude/document"
import { KUDE_TEMPLATE_VERSION, type KudePayload } from "@/lib/kude/types"

/**
 * Humo del template del KuDE (`context/73`). No compara pixeles: verifica que
 * el documento REALMENTE se genera con un payload como el que manda PHP, y que
 * sigue generándose cuando faltan los campos opcionales (sin logo, sin QR, sin
 * timbrado, receptor innominado) — que es el caso más común en una caja.
 */

const payload: KudePayload = {
  templateVersion: KUDE_TEMPLATE_VERSION,
  document: {
    title: "KuDE de Factura Electrónica",
    number: "001-001-0000123",
    cdc: "01800123456001001000012320260907123456789012",
    issuedAt: "07/09/2026 10:15",
    condition: "Contado",
    currency: "CLP",
    exchangeRate: null,
    qrData: "https://ekuatia.set.gov.py/consultas/qr?nVersion=150&Id=018001234560010010000123",
  },
  emitter: {
    name: "COMERCIO DE PRUEBA S.A.",
    tradeName: "Comercio de Prueba",
    ruc: "80012345-6",
    address: "Avenida Siempre Viva 742",
    city: "Asuncion",
    phone: "021 123456",
    email: "ventas@ejemplo.com",
    activity: null,
    logoUrl: null,
    stamp: { number: "12345678", start: "01/01/2026", prefix: "001-001" },
  },
  receiver: {
    name: "Cliente de Prueba",
    ruc: "80098765-4",
    documentId: null,
    address: null,
  },
  items: [
    { description: "Producto gravado 10%", quantity: 2, unitPrice: 55000, taxRate: 10, total: 110000 },
    { description: "Producto gravado 5%", quantity: 1, unitPrice: 21000, taxRate: 5, total: 21000 },
    { description: "Producto exento", quantity: 3, unitPrice: 10000, taxRate: 0, total: 30000 },
  ],
  totals: {
    exempt: 30000,
    taxed5: 21000,
    taxed10: 110000,
    iva5: 1000,
    iva10: 10000,
    ivaTotal: 11000,
    total: 161000,
  },
  format: { thousand: ".", decimal: ",", decimals: 0, currency: "CLP" },
}

/** Los primeros bytes de todo PDF. Es lo que el lado PHP también verifica. */
const PDF_MAGIC = "%PDF-"

describe("renderKudePdf", () => {
  it("genera un PDF con el documento completo", async () => {
    const pdf = await renderKudePdf({ payload, qrImage: null, logoImage: null })

    expect(pdf.byteLength).toBeGreaterThan(1000)
    expect(pdf.subarray(0, 5).toString("latin1")).toBe(PDF_MAGIC)
  }, 30000)

  it("genera igual sin timbrado, sin QR y con receptor sin identificar", async () => {
    const minimal: KudePayload = {
      ...payload,
      document: { ...payload.document, qrData: null },
      emitter: { ...payload.emitter, stamp: null },
      receiver: { name: "", ruc: null, documentId: null, address: null },
    }

    const pdf = await renderKudePdf({ payload: minimal, qrImage: null, logoImage: null })

    expect(pdf.subarray(0, 5).toString("latin1")).toBe(PDF_MAGIC)
  }, 30000)
})
