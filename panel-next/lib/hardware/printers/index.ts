/**
 * Barrel de impresoras WebUSB. Punto de entrada para toda la UI y los comandos
 * que necesiten interactuar con impresoras térmicas.
 */

export * from "./binding"
export * from "./encoder"
export * from "./transports/usb"
export type { TicketData, TicketItem, TicketPayment } from "./build-ticket-data"

import type { PrinterBinding } from "./binding"
import { buildTestTicket } from "./encoder"
import { getAuthorizedPrinters, sendBytes } from "./transports/usb"
import { usePrinterBindingsStore } from "./binding"
import type { PrinterDocType } from "./binding"
import type { TicketData } from "./build-ticket-data"
import { renderTemplateToEscPos } from "./render-template"
import type { DocumentTemplateRow } from "@/lib/types/print-template"

/**
 * Imprime el ticket de prueba en la impresora identificada por `binding`.
 * Busca el dispositivo USB autorizado por vendorId+productId y le envía
 * los bytes ESC/POS generados por buildTestTicket.
 *
 * @throws Error si el dispositivo no está conectado, el permiso fue revocado,
 *         o no se encuentra un endpoint OUT bulk.
 */
export async function printSale(opts: {
  docType: PrinterDocType
  data: TicketData
}): Promise<{ printed: number; failed: number; errors: string[] }> {
  const store = usePrinterBindingsStore.getState()

  const categoryIds = opts.data.items
    .map((i) => i.categoryId)
    .filter((id): id is string => id !== null)

  const uniqueCategoryIds = [...new Set(categoryIds)]
  const bindings = store.getBindingsForSale(opts.docType, uniqueCategoryIds)

  let printed = 0
  const errors: string[] = []

  for (const binding of bindings) {
    try {
      if (binding.mode === "escpos") {
        const filteredItems =
          binding.categoryIds.length > 0
            ? opts.data.items.filter(
                (item) =>
                  item.categoryId !== null &&
                  binding.categoryIds.includes(item.categoryId),
              )
            : opts.data.items

        if (binding.categoryIds.length > 0 && filteredItems.length === 0) {
          continue
        }

        if (!binding.templateId) {
          console.warn(`[printSale] Binding "${binding.name}" sin plantilla asignada, skip`)
          errors.push(`${binding.name}: sin plantilla`)
          continue
        }

        const res = await fetch(`/api/v1/document-templates?id=${binding.templateId}`)
        if (!res.ok) throw new Error(`Template fetch failed: ${res.status}`)
        const json = (await res.json()) as { data?: DocumentTemplateRow } | DocumentTemplateRow
        const template: DocumentTemplateRow =
          (json as { data?: DocumentTemplateRow }).data ?? (json as DocumentTemplateRow)

        const dataForPrinter: TicketData = { ...opts.data, items: filteredItems }
        const bytes = renderTemplateToEscPos({
          template: template.config as Parameters<typeof renderTemplateToEscPos>[0]["template"],
          data: dataForPrinter,
          paperWidthMm: binding.paperWidthMm,
          openDrawer: binding.openDrawer,
          copies: binding.copies,
        })

        const devices = await getAuthorizedPrinters()
        const device = devices.find(
          (d) => d.vendorId === binding.vendorId && d.productId === binding.productId,
        )
        if (!device)
          throw new Error(`Dispositivo USB no encontrado para ${binding.name}`)

        await sendBytes(device, bytes)

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
  const devices = await getAuthorizedPrinters()
  const device = devices.find(
    (d) => d.vendorId === binding.vendorId && d.productId === binding.productId,
  )

  if (!device) {
    throw new Error(
      "La impresora no está conectada o el permiso fue revocado. " +
        "Desconectá y volvé a vincularla desde Ajustes → Impresoras.",
    )
  }

  const bytes = buildTestTicket({ paperWidthMm: binding.paperWidthMm })
  await sendBytes(device, bytes)
}
