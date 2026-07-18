"use client"

/**
 * Hook que encapsula la lógica de impresión con fallback a picker de impresora.
 *
 * - Si hay bindings para el docType → printSale directo.
 * - Si no hay match pero sí impresoras configuradas → abre PrinterPickerDialog
 *   (el operador puede tener una impresora física sin bindear a este docType
 *   todavía y prefiere usarla igual); al elegir, inyecta el docType en el
 *   binding clonado y llama printSale.
 * - Si NO hay ninguna impresora configurada (caso más común — tenant sin
 *   hardware térmico) → `printTicketInBrowser`: diálogo nativo del browser
 *   con la plantilla del docType, en vez del toast/no-op de antes.
 */

import * as React from "react"
import { toast } from "sonner"
import { printSale, printTicketInBrowser } from "@/lib/hardware/printers/index"
import { getBindingsForSale } from "@/lib/hardware/printers/binding"
import { PrinterPickerDialog } from "@/components/register/printer-picker-dialog"
import type { PrinterBinding, PrinterDocType } from "@/lib/hardware/printers/binding"
import type { TicketData } from "@/lib/hardware/printers/build-ticket-data"

interface PendingPrint {
  docType: PrinterDocType
  data: TicketData
  allBindings: PrinterBinding[]
}

export function usePrintWithPicker() {
  const [pickerOpen, setPickerOpen] = React.useState(false)
  const [pickerPrinters, setPickerPrinters] = React.useState<PrinterBinding[]>([])
  const pendingRef = React.useRef<PendingPrint | null>(null)

  function requestPrint(
    docType: PrinterDocType,
    data: TicketData,
    allBindings: PrinterBinding[],
  ) {
    const categoryIds = data.items
      .map((i) => i.categoryId)
      .filter((id): id is string => id !== null)

    const matched = getBindingsForSale(allBindings, docType, categoryIds)

    if (matched.length > 0) {
      // Happy path: hay binding configurado para este docType.
      printSale({ docType, data, bindings: allBindings })
        .then((r) => {
          if (r.failed > 0) toast.warning(`${r.failed} impresora(s) fallaron`)
        })
        .catch(console.error)
      return
    }

    if (allBindings.length > 0) {
      // Sin binding para el docType pero sí hay impresoras: mostrar picker.
      pendingRef.current = { docType, data, allBindings }
      setPickerPrinters(allBindings)
      setPickerOpen(true)
      return
    }

    // Sin impresoras configuradas: fallback a diálogo nativo del browser con
    // la plantilla del docType (o el genérico si el tenant no configuró
    // ninguna todavía).
    printTicketInBrowser({ docType, data }).catch((err) => {
      console.error("[printTicketInBrowser]", err)
      toast.error("No se pudo generar el documento para imprimir")
    })
  }

  function handlePickerSelect(chosen: PrinterBinding) {
    const pending = pendingRef.current
    if (!pending) return
    pendingRef.current = null

    // Inyectar docType en el binding clonado para que printSale lo encuentre.
    const syntheticBinding: PrinterBinding = {
      ...chosen,
      docTypes: [pending.docType],
    }

    printSale({ docType: pending.docType, data: pending.data, bindings: [syntheticBinding] })
      .then((r) => {
        if (r.failed > 0) toast.warning(`${r.failed} impresora(s) fallaron`)
      })
      .catch(console.error)
  }

  const pickerDialog = React.createElement(PrinterPickerDialog, {
    open: pickerOpen,
    onOpenChange: setPickerOpen,
    printers: pickerPrinters,
    onSelect: handlePickerSelect,
  })

  return { requestPrint, pickerDialog }
}
