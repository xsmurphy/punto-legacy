export * from "./binding"
export * from "./encoder"
export * from "./transports/usb"
export type { TicketData, TicketItem, TicketPayment } from "./build-ticket-data"
export { printTicketInBrowser } from "./print-in-browser"

import type { PrinterBinding, PrinterDocType } from "./binding"
import { getBindingsForSale } from "./binding"
import { getAuthorizedPrinters, sendBytes as sendBytesUsb } from "./transports/usb"
import { sendBytesViaBluetooth } from "./transports/bluetooth"
import { sendBytesViaNetwork } from "./transports/network"
import { triggerWindowPrint } from "./transports/window-print"
import { renderTemplateToEscPos } from "./render-template"
import { renderTemplateToHtml } from "./html-renderer"
import { fetchTemplateConfig } from "./print-in-browser"
import { buildTicketDataForTest } from "./build-ticket-data"
import type { TicketData } from "./build-ticket-data"
import { posApi } from "@/lib/api/pos-client"
import { isReceipt, type PaperSize, type PrintTemplateConfig } from "@/lib/types/print-template"

/** base64 sin `Buffer` (browser) — btoa sobre chunks para no pegarle a los
 *  límites de `String.fromCharCode.apply` en tickets largos. */
function bytesToBase64(bytes: Uint8Array): string {
  const CHUNK = 0x8000
  let binary = ""
  for (let i = 0; i < bytes.length; i += CHUNK) {
    binary += String.fromCharCode(...bytes.subarray(i, i + CHUNK))
  }
  return btoa(binary)
}

/**
 * Encola el payload YA RENDERIZADO en print_job (Estación de Impresión,
 * transport "station" — decisión del owner 2026-08-01, context/26). El
 * ruteo (docTypes/categorías/template/copies) es el mismo de siempre; esto
 * solo reemplaza el último paso de `dispatchBytes`/render-html: en vez de
 * mandar bytes al hardware local, POST a `/v1/print-jobs?action=enqueue` vía
 * `posApi` (Bearer del device POS — mismo cliente que el resto del POS).
 */
async function enqueueStationJob(
  binding: PrinterBinding,
  opts: { format: "escpos" | "html"; payload: string; docType: PrinterDocType },
): Promise<void> {
  if (!binding.stationPrinterId) {
    throw new Error(`${binding.name}: sin impresora de estación asociada (vinculá la impresora nuevamente)`)
  }
  await posApi.post("/v1/print-jobs?action=enqueue", {
    stationPrinterId: binding.stationPrinterId,
    format: opts.format,
    payload: opts.payload,
    docType: opts.docType,
    copies: binding.copies,
    openDrawer: binding.openDrawer,
  })
}

async function dispatchBytes(binding: PrinterBinding, bytes: Uint8Array, docType: PrinterDocType): Promise<void> {
  switch (binding.transport) {
    case "usb": {
      if (binding.vendorId == null || binding.productId == null) {
        throw new Error(`${binding.name}: sin dispositivo USB asociado (vinculá la impresora nuevamente)`)
      }
      const devices = await getAuthorizedPrinters()
      const device = devices.find(
        (d) => d.vendorId === binding.vendorId && d.productId === binding.productId,
      )
      if (!device) throw new Error(`Dispositivo USB no encontrado para ${binding.name}`)
      await sendBytesUsb(device, bytes)
      break
    }
    case "bluetooth": {
      if (!binding.bluetoothDeviceId) {
        throw new Error(`${binding.name}: sin dispositivo Bluetooth asociado`)
      }
      await sendBytesViaBluetooth(binding.bluetoothDeviceId, bytes)
      break
    }
    case "network": {
      if (!binding.networkHost) throw new Error(`${binding.name}: sin host de red configurado`)
      await sendBytesViaNetwork(
        binding.networkHost,
        binding.networkPort ?? 9100,
        bytes,
      )
      break
    }
    case "station": {
      await enqueueStationJob(binding, { format: "escpos", payload: bytesToBase64(bytes), docType })
      break
    }
    case "native":
      break
  }
}

export async function printSale(opts: {
  docType: PrinterDocType
  data: TicketData
  bindings: PrinterBinding[]
}): Promise<{ printed: number; failed: number; errors: string[] }> {
  const categoryIds = opts.data.items
    .map((i) => i.categoryId)
    .filter((id): id is string => id !== null)
  const uniqueCategoryIds = [...new Set(categoryIds)]
  const bindings = getBindingsForSale(opts.bindings, opts.docType, uniqueCategoryIds)

  let printed = 0
  const errors: string[] = []

  for (const binding of bindings) {
    try {
      const filteredItems =
        binding.categoryIds.length > 0
          ? opts.data.items.filter(
              (item) =>
                item.categoryId !== null &&
                binding.categoryIds.includes(item.categoryId),
            )
          : opts.data.items

      if (binding.categoryIds.length > 0 && filteredItems.length === 0) continue

      const dataForPrinter: TicketData = { ...opts.data, items: filteredItems, printerName: binding.name }

      // Station con mode "native" renderiza HTML igual que transport nativo
      // (mismo renderer, un solo lugar), pero lo encola en vez de disparar
      // window.print() local — de ahí que transport==="native" pura y
      // transport==="station"+mode==="native" compartan esta rama.
      if (binding.transport === "native" || (binding.transport === "station" && binding.mode === "native")) {
        if (!binding.templateId) {
          // window.print() acá imprimía el contenido del sitio (no la plantilla)
          // y causaba doble diálogo cuando había varias impresoras. Skip con
          // error — la binding debe tener una plantilla asignada en Ajustes.
          errors.push(`${binding.name}: sin plantilla asignada`)
          continue
        }
        // Resolución SIEMPRE local (context/08 §53, hueco P0 cerrado
        // 2026-08-16) — ver docblock de `fetchTemplateConfig` en
        // print-in-browser.ts. `null` acá significa que la plantilla no está
        // (o ya no está) en la copia local del bootstrap — no un fetch que
        // falló, así que reintentar no cambiaría nada; el binding queda sin
        // imprimir y el error lo dice explícito.
        const config = fetchTemplateConfig(binding.templateId)
        if (!config) throw new Error(`No se pudo resolver la plantilla asignada (revisá Ajustes → Impresoras)`)
        const html = renderTemplateToHtml(config, dataForPrinter, { paperWidthMm: binding.paperWidthMm })
        if (binding.transport === "station") {
          await enqueueStationJob(binding, { format: "html", payload: html, docType: opts.docType })
        } else {
          triggerWindowPrint(html)
        }
        printed++
        continue
      }

      if (binding.mode === "escpos") {
        if (!binding.templateId) {
          errors.push(`${binding.name}: sin plantilla`)
          continue
        }
        const config = fetchTemplateConfig(binding.templateId)
        if (!config) throw new Error(`No se pudo resolver la plantilla asignada (revisá Ajustes → Impresoras)`)
        const bytes = renderTemplateToEscPos({
          template: config as Parameters<typeof renderTemplateToEscPos>[0]["template"],
          data: dataForPrinter,
          paperWidthMm: binding.paperWidthMm,
          openDrawer: binding.openDrawer,
          copies: binding.copies,
        })
        await dispatchBytes(binding, bytes, opts.docType)

        if (binding.printDelay > 0 && bindings.indexOf(binding) < bindings.length - 1) {
          await new Promise<void>((r) => setTimeout(r, binding.printDelay))
        }

        printed++
      }
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      errors.push(`${binding.name}: ${msg}`)
      console.error("[printSale] Error imprimiendo en", binding.name, err)
    }
  }

  return { printed, failed: errors.length, errors }
}

/**
 * Prueba de impresión — mismo camino que `printSale` (regla del owner: la
 * prueba tiene que mostrar el FORMATO REAL de la plantilla, no un texto fijo
 * "Prueba de impresión" que no dice nada de cómo va a salir el documento).
 *
 * Requiere `binding.templateId`, igual que `printSale`: si el binding no
 * tiene plantilla asignada, una venta real tampoco imprimiría nada ahí (ver
 * el error "sin plantilla asignada" arriba) — la prueba surfacea el mismo
 * bloqueo en vez de disimularlo con un ticket fijo que nunca reflejó la
 * realidad.
 *
 * Los datos vienen de `buildTicketDataForTest()` (build-ticket-data.ts): el
 * carrito en curso si hay líneas, si no una venta de demo — nunca toca
 * numeración/stock/cola offline (ver docblock de esa función).
 */
export async function printTest(binding: PrinterBinding): Promise<void> {
  if (!binding.templateId) {
    throw new Error(`${binding.name}: sin plantilla asignada (asigná una en Ajustes → Impresoras)`)
  }
  const config = fetchTemplateConfig(binding.templateId)
  if (!config) throw new Error(`No se pudo resolver la plantilla asignada (revisá Ajustes → Impresoras)`)

  const data = buildTicketDataForTest()

  if (binding.transport === "native" || (binding.transport === "station" && binding.mode === "native")) {
    const html = renderTemplateToHtml(config, data, { paperWidthMm: binding.paperWidthMm })
    if (binding.transport === "station") {
      await enqueueStationJob(binding, { format: "html", payload: html, docType: "receipt" })
    } else {
      triggerWindowPrint(html)
    }
    return
  }

  const bytes = renderTemplateToEscPos({
    template: config as Parameters<typeof renderTemplateToEscPos>[0]["template"],
    data,
    paperWidthMm: binding.paperWidthMm,
    openDrawer: binding.openDrawer,
    copies: binding.copies,
  })
  await dispatchBytes(binding, bytes, "receipt")
}

/**
 * Rollo nominal de la plantilla (57/76/80mm, `PAPER_DIMENSIONS`) → ancho
 * FÍSICO más cercano que soporta el pipeline de impresión real (58/80mm,
 * `RenderTemplateToHtmlOptions.paperWidthMm`/`PrinterBinding.paperWidthMm`
 * en binding.ts/render-template.ts — no hay impresora de 76mm en el catálogo
 * de bindings de la app). El editor no tiene una impresora física atada
 * (`PrinterBinding`) de la que leer el ancho calibrado, así que aproximamos
 * al más angosto (receipt57→58mm) o al más ancho (receipt76/80→80mm).
 */
function nearestReceiptPaperWidthMm(pageSize: PaperSize): 58 | 80 {
  return pageSize === "receipt57" ? 58 : 80
}

/**
 * HTML final de una plantilla en modo editor (`/settings/print-templates`) —
 * ÚNICO punto que decide `paperWidthMm` cuando no hay `PrinterBinding` de por
 * medio (el editor no está ligado a una impresora física). Usa
 * `nearestReceiptPaperWidthMm` para rollo, nada para hoja (hoja resuelve su
 * ancho físico solo, vía `PAPER_DIMENSIONS`/`template.mm` dentro de
 * `renderTemplateToHtml`).
 *
 * Consumida por DOS superficies que antes divergían — `PreviewDialog`
 * (vista previa en pantalla, ahora un `<iframe>` que renderiza este mismo
 * HTML en vez de reimplementar el layout en CSS) y `simulateTemplatePrint`
 * (botón "Simular impresión") — así que lo que el owner ve en pantalla y lo
 * que sale por window.print() son BYTE A BYTE el mismo documento, generado
 * una sola vez por el mismo motor que usan `printSale`/`printTest`.
 *
 * `template`/`data` van TAL CUAL — `template` es el `PrintTemplateConfig` en
 * el estado de React del editor (incluye cambios sin guardar), `data` la
 * venta de demo (o de prueba, enmascarada) que ya alimenta el canvas.
 */
export function buildTemplatePreviewHtml(template: PrintTemplateConfig, data: TicketData): string {
  const paperWidthMm = isReceipt(template.page_size)
    ? nearestReceiptPaperWidthMm(template.page_size)
    : undefined
  return renderTemplateToHtml(template, data, { paperWidthMm })
}

/**
 * "Simular impresión" — `/settings/print-templates` (TemplateEditor) y el
 * botón "Imprimir" de `PreviewDialog`: MISMO código, mismo HTML
 * (`buildTemplatePreviewHtml`), mismo transporte que `printTest`/`printSale`
 * en transport `native` — `triggerWindowPrint` (iframe oculto →
 * `window.print()`) — para que el owner pueda mandarlo a papel o a "Guardar
 * como PDF" y ver el resultado físico exacto.
 */
export function simulateTemplatePrint(template: PrintTemplateConfig, data: TicketData): void {
  triggerWindowPrint(buildTemplatePreviewHtml(template, data))
}
