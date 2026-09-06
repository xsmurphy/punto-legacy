/**
 * Impresión de la ORDEN DE PAGO A PROVEEDOR — el papel que se firma.
 *
 * ── Por qué NO pasa por el document builder ─────────────────────────────────
 *
 * `printTicketInBrowser()` resuelve la plantilla que el comercio configuró para
 * un `PrinterDocType`. La orden de pago no tiene ninguno: el tipo es
 * `receipt|factura|quote|order|withdraw|delivery|closeReg|return`
 * (`binding.ts`), y darle uno significaría sumar un `PrinterDocType`, extender
 * el `VALID_DOC_TYPES` del backend y construir el editor de plantilla para un
 * documento que nadie pidió personalizar. Es exactamente el precedente que ya
 * sentó `print-production-batch.ts`, y antes de eso `context/56` con la
 * cotización en PDF: un documento sale fuera del builder cuando el motor de
 * hoja no lo cubre.
 *
 * Lo que SÍ se respeta es el transporte: `triggerWindowPrint()`, el mismo
 * wrapper compartido del módulo. La prohibición del design system es
 * `window.print()` sobre DOM arbitrario; esto es lo contrario — un documento
 * propio, aislado en un iframe, con su CSS de impresión.
 *
 * Formato A4 y no rollo de 80mm porque este papel no es un comprobante de
 * mostrador: es una autorización que alguien FIRMA. De ahí el pie con los tres
 * casilleros (preparó / aprobó / pagó), que son la representación en papel de
 * la misma segregación de tareas que el permiso `.approve` hace cumplir en el
 * server.
 *
 * Los montos llegan YA FORMATEADOS por el caller (`formatMoney` con el
 * bootstrap del tenant): este módulo no conoce la moneda ni los separadores del
 * comercio, y nada acá puede asumir Paraguay.
 */

import { triggerWindowPrint } from "./transports/window-print"

export interface PaymentOrderSheetLine {
  invoiceNo: string
  date: string
  dueDate: string
  /** Total de la factura, formateado. */
  total: string
  /** Saldo pendiente al momento de imprimir, formateado. */
  debt: string
  /** Monto que esta orden imputa a la factura, formateado. */
  amount: string
}

export interface PaymentOrderSheetData {
  companyName: string
  docNumber: number | null
  statusLabel: string
  supplierName: string
  outletName: string
  /** Fecha de pago propuesta, ya formateada. `null` si la orden no la fijó. */
  paymentDate: string | null
  createdAt: string
  approvedAt: string | null
  paidAt: string | null
  printedAt: string
  notes: string | null
  /** Total de la orden, formateado. */
  total: string
  lines: PaymentOrderSheetLine[]
}

/** Escapa el texto del tenant: los nombres de proveedor son datos, no markup. */
function esc(value: string | null | undefined): string {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
}

function metaRow(label: string, value: string | null): string {
  if (!value) return ""
  return `<div><span class="k">${esc(label)}</span> ${esc(value)}</div>`
}

/**
 * El CSS va embebido y en unidades absolutas a propósito: el iframe es un
 * documento aislado que no hereda los tokens del design system de la app, y el
 * destino es papel — no hay tema claro/oscuro que respetar.
 */
export function printPaymentOrderSheet(data: PaymentOrderSheetData): void {
  const rows = data.lines
    .map(
      (l) =>
        `<tr><td>${esc(l.invoiceNo || "—")}</td><td>${esc(l.date)}</td>` +
        `<td>${esc(l.dueDate)}</td><td class="num">${esc(l.total)}</td>` +
        `<td class="num">${esc(l.debt)}</td><td class="num">${esc(l.amount)}</td></tr>`,
    )
    .join("")

  const html = `<!doctype html>
<html lang="es"><head><meta charset="utf-8" />
<title>Orden de pago${data.docNumber !== null ? ` N.° ${data.docNumber}` : ""}</title>
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         font-size: 11pt; color: #000; margin: 0; }
  h1 { font-size: 16pt; margin: 0 0 1mm; }
  .company { font-size: 10pt; font-weight: 600; margin-bottom: 4mm; }
  h2 { font-size: 12pt; margin: 8mm 0 2mm; border-bottom: 1px solid #000; padding-bottom: 1mm; }
  .meta { font-size: 9pt; line-height: 1.5; margin-bottom: 4mm; }
  .meta .k { font-weight: 600; }
  .note { font-size: 9pt; margin-top: 3mm; padding: 2mm; border: 1px solid #999; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 1.5mm 2mm; border-bottom: 1px solid #ccc; }
  th { font-size: 9pt; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #000; }
  .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  tbody tr:last-child td { border-bottom: 1px solid #000; }
  tfoot td { font-weight: 700; border-bottom: none; padding-top: 2mm; }
  .empty { font-size: 9pt; padding: 3mm 0; }
  .signatures { margin-top: 18mm; display: flex; gap: 8mm; }
  .signatures div { flex: 1; border-top: 1px solid #000; padding-top: 2mm;
                    font-size: 9pt; text-align: center; }
  .foot { margin-top: 8mm; font-size: 8pt; color: #444; }
</style></head>
<body>
  <div class="company">${esc(data.companyName)}</div>
  <h1>Orden de pago${data.docNumber !== null ? ` N.° ${data.docNumber}` : ""}</h1>
  <div class="meta">
    ${metaRow("Proveedor:", data.supplierName)}
    ${metaRow("Sucursal:", data.outletName)}
    ${metaRow("Estado:", data.statusLabel)}
    ${metaRow("Pagar el:", data.paymentDate)}
    ${metaRow("Creada:", data.createdAt)}
    ${metaRow("Aprobada:", data.approvedAt)}
    ${metaRow("Pagada:", data.paidAt)}
    ${metaRow("Impresa:", data.printedAt)}
  </div>

  <h2>Facturas a pagar</h2>
  ${
    rows
      ? `<table><thead><tr><th>Comprobante</th><th>Fecha</th><th>Vence</th>` +
        `<th class="num">Total</th><th class="num">Saldo</th><th class="num">A pagar</th></tr></thead>` +
        `<tbody>${rows}</tbody>` +
        `<tfoot><tr><td colspan="5" class="num">Total de la orden</td>` +
        `<td class="num">${esc(data.total)}</td></tr></tfoot></table>`
      : `<p class="empty">Sin facturas.</p>`
  }

  ${data.notes ? `<div class="note">${esc(data.notes)}</div>` : ""}

  <div class="signatures">
    <div>Preparó</div>
    <div>Aprobó</div>
    <div>Pagó</div>
  </div>
  <p class="foot">
    Documento interno de autorización de pago. No es un comprobante fiscal ni reemplaza
    al recibo: el recibo lo emite el pago cuando la orden se ejecuta.
  </p>
</body></html>`

  triggerWindowPrint(html)
}
