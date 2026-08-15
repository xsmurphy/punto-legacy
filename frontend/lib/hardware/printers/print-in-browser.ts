/**
 * Impresión por navegador SIN binding de hardware — única vía. Nadie más debe
 * llamar `window.print()` sobre el DOM de la app para imprimir un documento
 * fiscal/ticket: eso imprime lo que esté en pantalla (layout de la app, con
 * dialogs/overlays de shadcn que rompen el hack de `visibility:hidden` — ver
 * bug reportado en quote-print-view) en vez de la plantilla configurada.
 *
 * `printTicketInBrowser` resuelve la plantilla del docType (la del binding si
 * hay `templateId` explícito, si no la default del tenant para ese docType) y
 * la renderiza a HTML de TICKET (58/80mm, monoespaciado) vía el mismo
 * `renderTemplateToHtml` que usa `printSale` para bindings `transport:
 * "native"` — un solo renderer, un solo transport (`triggerWindowPrint`,
 * iframe oculto).
 */
import type { PrinterDocType } from "./binding"
import type { TicketData } from "./build-ticket-data"
import type { DocumentTemplateRow, PrintTemplateConfig } from "@/lib/types/print-template"
import { formatMoney } from "./blocks"
import { renderTemplateToHtml } from "./html-renderer"
import { triggerWindowPrint } from "./transports/window-print"

/** Mapeo PrinterDocType (frontend, bindings de impresora) → docType del
 *  backend (`document_template.docType`, ver DocumentTemplateService::
 *  VALID_DOC_TYPES). `withdraw` / `closeReg` / `return` son tickets de caja
 *  no fiscales — no tienen concepto de plantilla en `document_template`, así
 *  que no mapean a nada (fallback genérico siempre). */
const DOC_TYPE_TO_BACKEND: Partial<Record<PrinterDocType, DocumentTemplateRow["docType"]>> = {
  receipt: "receipt",
  factura: "invoice",
  quote: "quote",
  order: "workorder",
  delivery: "delivery",
}

/** Etiqueta legible para el fallback genérico (sin plantilla configurada). */
const DOC_TYPE_LABEL: Record<PrinterDocType, string> = {
  receipt: "Recibo",
  factura: "Factura",
  quote: "Cotización",
  order: "Comanda",
  withdraw: "Retiro de caja",
  delivery: "Remito",
  closeReg: "Cierre de caja",
  return: "Nota de crédito",
}

/** Fetch de una plantilla puntual por id — única fuente para este fetch;
 *  reusado por `printSale` (bindings con templateId) y por este módulo. */
export async function fetchTemplateConfig(templateId: string): Promise<PrintTemplateConfig | null> {
  const res = await fetch(`/api/v1/document-templates?id=${templateId}`)
  if (!res.ok) return null
  const json = (await res.json()) as { data?: { config: PrintTemplateConfig } } | { config: PrintTemplateConfig }
  const row = (json as { data?: { config: PrintTemplateConfig } }).data ?? (json as { config: PrintTemplateConfig })
  return row?.config ?? null
}

/** Busca la plantilla default (o la primera disponible, ver TODO abajo) del
 *  tenant para el docType. `document_template.list()` ya ordena
 *  `docType, isDefault DESC, name` — la primera fila que matchee el docType
 *  es la default (o la primera alfabética si no hay ninguna marcada). */
async function fetchDefaultTemplateConfig(docType: PrinterDocType): Promise<PrintTemplateConfig | null> {
  const backendDocType = DOC_TYPE_TO_BACKEND[docType]
  if (!backendDocType) return null // withdraw/closeReg/return: sin concepto de plantilla

  const res = await fetch(`/api/v1/document-templates`)
  if (!res.ok) return null
  const json = (await res.json()) as { data?: { templates: DocumentTemplateRow[] } } | { templates: DocumentTemplateRow[] }
  const templates = (json as { data?: { templates: DocumentTemplateRow[] } }).data?.templates
    ?? (json as { templates: DocumentTemplateRow[] }).templates
    ?? []

  // TODO(print-templates): si hay > 1 template para el docType y ninguno es
  // isDefault, tomamos el primero (orden del backend = alfabético por name).
  // No hay UI hoy para que el tenant marque cuál usar como fallback de
  // navegador explícitamente — cuando exista, preferir ese campo acá.
  const candidate = templates.find((t) => t.docType === backendDocType)
  return candidate?.config ? (candidate.config as PrintTemplateConfig) : null
}

function esc(s: string): string {
  return s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
}

/** Ticket genérico cuando el tenant no tiene NINGUNA plantilla configurada
 *  para el docType (tenant nuevo — ver DocumentTemplateService, no hay
 *  seeding automático) o cuando el docType no tiene concepto de plantilla
 *  (withdraw/closeReg/return). Reusa el layout monoespaciado de ticket, solo
 *  que arma el HTML directo en vez de pasar por `renderTemplateToHtml` +
 *  `PrintBlock[]` (no tiene sentido sintetizar un template JSON solo para
 *  volver a interpretarlo). */
function renderFallbackTicketHtml(docType: PrinterDocType, data: TicketData, paperWidthMm: 58 | 80): string {
  const rows = data.items
    .map(
      (item) =>
        `<tr><td>${esc(item.name)}</td><td style="text-align:right">${item.qty}</td>` +
        `<td style="text-align:right">${esc(formatMoney(item.unitPrice, data))}</td>` +
        `<td style="text-align:right">${esc(formatMoney(item.total, data))}</td></tr>`,
    )
    .join("")
  const paymentLines = data.payments
    .map((p) => `<div>${esc(p.method)}: ${esc(formatMoney(p.amount, data))}</div>`)
    .join("")

  const body = `
    <div style="font-weight:bold;text-align:center">${esc(data.companyName)}</div>
    <div style="text-align:center">${esc(DOC_TYPE_LABEL[docType])}</div>
    <hr/>
    ${data.documentPrefix || data.documentNumber ? `<div>Nº ${esc([data.documentPrefix, data.documentNumber].filter(Boolean).join("-"))}</div>` : ""}
    <div>${esc(data.date)}</div>
    ${data.customerName ? `<div>Cliente: ${esc(data.customerName)}</div>` : ""}
    <hr/>
    ${
      data.items.length > 0
        ? `<table style="width:100%;border-collapse:collapse;font-size:inherit">
            <thead><tr><th style="text-align:left">Ítem</th><th style="text-align:right">Cant.</th>
            <th style="text-align:right">P.Unit</th><th style="text-align:right">Total</th></tr></thead>
            <tbody>${rows}</tbody></table><hr/>`
        : ""
    }
    ${data.discount > 0 ? `<div>Descuento: ${esc(formatMoney(data.discount, data))}</div>` : ""}
    <div style="font-weight:bold">Total: ${esc(formatMoney(data.total, data))}</div>
    ${paymentLines ? `<hr/>${paymentLines}` : ""}
    ${data.note ? `<hr/><div>${esc(data.note)}</div>` : ""}
  `

  return `<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
  @page { size: ${paperWidthMm}mm auto; margin: 0; }
  body { font-family: monospace; font-size: 9pt; width: ${paperWidthMm}mm; margin: 0 auto; }
  @media print { body { margin: 0; } }
  hr { border: none; border-top: 1px dashed #000; margin: 4px 0; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 1px 2px; }
</style>
</head>
<body>
${body}
</body>
</html>`
}

export interface PrintTicketInBrowserOptions {
  docType: PrinterDocType
  data: TicketData
  /** Si viene (ej. binding con transport nativo pero sin picker), se usa esa
   *  plantilla puntual en vez de resolver la default del docType. */
  templateId?: string | null
  paperWidthMm?: 58 | 80
}

/**
 * Imprime vía diálogo nativo del browser cuando NO hay binding de hardware
 * físico para el docType. Resuelve la plantilla (explícita → default del
 * tenant → fallback genérico embebido) y la manda al iframe oculto de
 * `triggerWindowPrint`. Esta es la ÚNICA función que debe invocarse para este
 * caso — no volver a hacer `window.print()` del DOM en ningún call-site.
 */
export async function printTicketInBrowser(opts: PrintTicketInBrowserOptions): Promise<void> {
  const paperWidthMm = opts.paperWidthMm ?? 80

  let config: PrintTemplateConfig | null = null
  try {
    config = opts.templateId
      ? await fetchTemplateConfig(opts.templateId)
      : await fetchDefaultTemplateConfig(opts.docType)
  } catch (err) {
    console.error("[printTicketInBrowser] No se pudo resolver la plantilla", err)
  }

  // Una plantilla existe como fila (config no-null) pero puede no tener NINGÚN
  // bloque cargado — típicamente un docType que el tenant nunca abrió en el
  // editor de plantillas (ej. "quote"). renderTemplateToHtml con data:[] arma
  // igual un <html> válido pero con el <body> vacío: "imprime" en blanco sin
  // ningún error. Tratamos ese caso como "sin plantilla usable" y caemos al
  // mismo fallback genérico que usamos cuando no hay fila en absoluto.
  const html = config && config.data.length > 0
    ? renderTemplateToHtml(config, opts.data, { paperWidthMm })
    : renderFallbackTicketHtml(opts.docType, opts.data, paperWidthMm)

  triggerWindowPrint(html)
}
