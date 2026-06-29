"use client"

/**
 * Dialog para elegir una impresora cuando no hay binding asignado al docType.
 * Lista todas las impresoras disponibles de la caja; si no hay ninguna,
 * muestra un mensaje de ayuda.
 */

import * as React from "react"
import { Printer } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { EmptyState } from "@/components/empty-state"
import type { PrinterBinding } from "@/lib/hardware/printers/binding"

interface PrinterPickerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  printers: PrinterBinding[]
  title?: string
  onSelect: (printer: PrinterBinding) => void
}

export function PrinterPickerDialog({
  open,
  onOpenChange,
  printers,
  title = "Elegí una impresora",
  onSelect,
}: PrinterPickerDialogProps) {
  function handleSelect(printer: PrinterBinding) {
    onSelect(printer)
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* Bucket xs — lista corta, sin formulario */}
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">{title}</DialogTitle>
        </DialogHeader>

        {printers.length === 0 ? (
          <EmptyState
            icon={Printer}
            title="No hay impresoras creadas"
            description="Configurá una en Ajustes del POS, sección Impresoras."
          />
        ) : (
          <div className="flex flex-col gap-2 py-2">
            {printers.map((printer) => (
              <Button
                key={printer.id}
                variant="outline"
                className="justify-start gap-3 h-11"
                onClick={() => handleSelect(printer)}
              >
                <Printer className="size-4 shrink-0 text-muted-foreground" />
                <span>{printer.name}</span>
              </Button>
            ))}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
