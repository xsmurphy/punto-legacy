"use client"

/**
 * Wrapper único reutilizable para dialogs que usan NumericPad.
 * Unififica tamaño (sm:max-w-md), footer estándar, y labels consistentes.
 *
 * Props:
 * - open, onClose: control del dialog
 * - title, subtitle: encabezado (subtitle es opcional, ej. nombre del ítem)
 * - mode: "int" | "decimal" | "money" | "percent"
 * - value, onValueChange: control del NumericPad
 * - onShiftToggle: handler de cambio de modo (si undefined, no se muestra hint)
 * - onConfirm: callback al presionar "Confirmar"
 * - confirmLabel: texto del botón confirmar (default "Aceptar")
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { NumericPad } from "@/components/pos/numeric-pad"

export interface NumericPadDialogProps {
  open: boolean
  onClose: () => void
  title: string
  subtitle?: string
  mode: "int" | "decimal" | "money" | "percent"
  value: string
  onValueChange: (v: string) => void
  onShiftToggle?: () => void
  onConfirm: () => void
  confirmLabel?: string
}

export function NumericPadDialog({
  open,
  onClose,
  title,
  subtitle,
  mode,
  value,
  onValueChange,
  onShiftToggle,
  onConfirm,
  confirmLabel = "Aceptar",
}: NumericPadDialogProps) {
  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          {subtitle && (
            <DialogDescription className="truncate">
              {subtitle}
            </DialogDescription>
          )}
        </DialogHeader>

        <NumericPad
          mode={mode}
          value={value}
          onChange={onValueChange}
          onShiftToggle={onShiftToggle}
          onConfirm={onConfirm}
          onCancel={onClose}
        />

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button onClick={onConfirm}>{confirmLabel}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
