/**
 * Impresión de la ORDEN DE PRODUCCIÓN de un lote — el papel que va a la cocina.
 *
 * ── Por qué NO pasa por el document builder ─────────────────────────────────
 *
 * `printTicketInBrowser()` resuelve la plantilla que el comercio configuró para
 * un `PrinterDocType`. Producción no tiene plantilla: `VALID_DOC_TYPES` del
 * backend es `receipt|invoice|quote|workorder|giftcard|delivery` y no incluye
 * `produccion` (hueco ya documentado en `context/modules/06-produccion.md` §5 —
 * la mig 129 le dio numeración correlativa, no binding de impresión).
 *
 * Darle uno significaría sumar un `PrinterDocType`, extender `VALID_DOC_TYPES`
 * y construir el editor de plantilla para un documento que nadie pidió
 * personalizar: es una feature propia, no un detalle de esta pantalla. El
 * precedente está en `context/56-cotizacion-pdf.md`, donde el owner ya aceptó
 * que un documento salga fuera del document builder cuando el motor de hoja no
 * lo cubre.
 *
 * Lo que SÍ se respeta es el transporte: `triggerWindowPrint()`, el mismo
 * wrapper compartido que usa el resto del módulo. La prohibición del design
 * system es `window.print()` sobre DOM arbitrario, y esto es lo contrario —
 * un documento propio, aislado en un iframe, con su CSS de impresión. Sobre el
 * DOM de la página no se puede: `DialogContent` de shadcn usa `transform` y
 * rompe el truco de visibility-hidden (bug ya documentado en
 * `quote-print-view.tsx`).
 *
 * Formato A4/carta y no rollo de 80mm: es una hoja de trabajo con dos tablas
 * (qué cocinar, qué sacar del depósito) que se cuelga en la cocina, no un
 * ticket.
 */

import { triggerWindowPrint } from "./transports/window-print"

export interface ProductionBatchSheetLine {
  itemName: string
  qty: string
}

export interface ProductionBatchSheetIngredient {
  itemName: string
  needed: string
  /** Ya formateado, o el texto de la degradación cuando no hay control de stock. */
  onHand: string
  missing: string
}

export interface ProductionBatchSheetData {
  title: string
  docNumber: number | null
  outletName: string | null
  locationName: string | null
  outputLocationName: string | null
  printedAt: string
  note: string | null
  lines: ProductionBatchSheetLine[]
  ingredients: ProductionBatchSheetIngredient[]
}

/** Escapa el texto del tenant: los nombres de ítems son datos, no markup. */
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
 * documento aislado que no hereda los tokens del design system de la app, y
 * el destino es papel — no hay tema claro/oscuro que respetar.
 */
export function printProductionBatchSheet(data: ProductionBatchSheetData): void {
  const lines = data.lines
    .map(
      (l) => `<tr><td>${esc(l.itemName)}</td><td class="num">${esc(l.qty)}</td></tr>`,
    )
    .join("")

  const ingredients = data.ingredients
    .map(
      (i) =>
        `<tr><td>${esc(i.itemName)}</td><td class="num">${esc(i.needed)}</td>` +
        `<td class="num">${esc(i.onHand)}</td><td class="num">${esc(i.missing)}</td></tr>`,
    )
    .join("")

  const html = `<!doctype html>
<html lang="es"><head><meta charset="utf-8" />
<title>${esc(data.title)}</title>
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         font-size: 11pt; color: #000; margin: 0; }
  h1 { font-size: 16pt; margin: 0 0 2mm; }
  h2 { font-size: 12pt; margin: 8mm 0 2mm; border-bottom: 1px solid #000; padding-bottom: 1mm; }
  .meta { font-size: 9pt; line-height: 1.5; margin-bottom: 4mm; }
  .meta .k { font-weight: 600; }
  .note { font-size: 9pt; margin-top: 3mm; padding: 2mm; border: 1px solid #999; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 1.5mm 2mm; border-bottom: 1px solid #ccc; }
  th { font-size: 9pt; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #000; }
  .num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  tbody tr:last-child td { border-bottom: 1px solid #000; }
  .empty { font-size: 9pt; padding: 3mm 0; }
  .foot { margin-top: 10mm; font-size: 8pt; color: #444; }
</style></head>
<body>
  <h1>${esc(data.title)}${data.docNumber !== null ? ` N.° ${data.docNumber}` : ""}</h1>
  <div class="meta">
    ${metaRow("Sucursal:", data.outletName)}
    ${metaRow("Insumos desde:", data.locationName)}
    ${metaRow("Terminado a:", data.outputLocationName)}
    ${metaRow("Impreso:", data.printedAt)}
  </div>

  <h2>A producir</h2>
  ${
    lines
      ? `<table><thead><tr><th>Producto</th><th class="num">Cantidad</th></tr></thead><tbody>${lines}</tbody></table>`
      : `<p class="empty">Sin líneas.</p>`
  }

  <h2>Insumos necesarios</h2>
  ${
    ingredients
      ? `<table><thead><tr><th>Insumo</th><th class="num">Necesita</th><th class="num">Hay</th><th class="num">Falta</th></tr></thead><tbody>${ingredients}</tbody></table>`
      : `<p class="empty">Sin insumos calculados.</p>`
  }

  ${data.note ? `<div class="note">${esc(data.note)}</div>` : ""}
  <p class="foot">La necesidad incluye la merma planificada de cada insumo.</p>
</body></html>`

  triggerWindowPrint(html)
}
