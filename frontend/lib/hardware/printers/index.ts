export * from "./binding"
export * from "./encoder"
export * from "./transports/usb"
export type { TicketData, TicketItem, TicketPayment } from "./build-ticket-data"
export { printTicketInBrowser } from "./print-in-browser"

import type { PrinterBinding, PrinterDocType } from "./binding"
import { getBindingsForSale } from "./binding"
import { buildTestTicket } from "./encoder"
import { getAuthorizedPrinters, sendBytes as sendBytesUsb } from "./transports/usb"
import { sendBytesViaBluetooth } from "./transports/bluetooth"
import { sendBytesViaNetwork } from "./transports/network"
import { triggerWindowPrint } from "./transports/window-print"
import { renderTemplateToEscPos } from "./render-template"
import { renderTemplateToHtml } from "./html-renderer"
import { fetchTemplateConfig } from "./print-in-browser"
import type { TicketData } from "./build-ticket-data"

async function dispatchBytes(binding: PrinterBinding, bytes: Uint8Array): Promise<void> {
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

      const dataForPrinter: TicketData = { ...opts.data, items: filteredItems }

      if (binding.transport === "native") {
        if (!binding.templateId) {
          // window.print() acá imprimía el contenido del sitio (no la plantilla)
          // y causaba doble diálogo cuando había varias impresoras. Skip con
          // error — la binding debe tener una plantilla asignada en Ajustes.
          errors.push(`${binding.name}: sin plantilla asignada`)
          continue
        }
        const config = await fetchTemplateConfig(binding.templateId)
        if (!config) throw new Error(`Template fetch failed`)
        const html = renderTemplateToHtml(config, dataForPrinter, { paperWidthMm: binding.paperWidthMm })
        triggerWindowPrint(html)
        printed++
        continue
      }

      if (binding.mode === "escpos") {
        if (!binding.templateId) {
          errors.push(`${binding.name}: sin plantilla`)
          continue
        }
        const config = await fetchTemplateConfig(binding.templateId)
        if (!config) throw new Error(`Template fetch failed`)
        const bytes = renderTemplateToEscPos({
          template: config as Parameters<typeof renderTemplateToEscPos>[0]["template"],
          data: dataForPrinter,
          paperWidthMm: binding.paperWidthMm,
          openDrawer: binding.openDrawer,
          copies: binding.copies,
        })
        await dispatchBytes(binding, bytes)

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

export async function printTest(binding: PrinterBinding): Promise<void> {
  if (binding.transport === "native") {
    triggerWindowPrint("<html><body><p>Prueba de impresión</p></body></html>")
    return
  }

  const bytes = buildTestTicket({ paperWidthMm: binding.paperWidthMm })
  await dispatchBytes(binding, bytes)
}
