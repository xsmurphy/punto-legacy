"use client"

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { formatMoney } from "@/lib/format-money"
import type { PaymentMethodConfig, PosConfig } from "@/lib/types/pos-bootstrap"

interface PaymentIdentifierDialogProps {
  open: boolean
  method: PaymentMethodConfig | null
  amount: number
  config: PosConfig | null
  onConfirm: (identifier: string) => void
  onCancel: () => void
}

export function PaymentIdentifierDialog({
  open,
  method,
  amount,
  config,
  onConfirm,
  onCancel,
}: PaymentIdentifierDialogProps) {
  const [value, setValue] = React.useState("")
  const [error, setError] = React.useState<string | null>(null)
  const inputRef = React.useRef<HTMLInputElement>(null)

  React.useEffect(() => {
    if (open) {
      setValue("")
      setError(null)
      // autofocus tras render
      setTimeout(() => inputRef.current?.focus(), 50)
    }
  }, [open])

  function handleConfirm() {
    const trimmed = value.trim()
    if (!trimmed) {
      setError("Campo requerido")
      return
    }
    if (trimmed.length > 30) {
      setError("Máximo 30 caracteres")
      return
    }
    onConfirm(trimmed)
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      e.preventDefault()
      handleConfirm()
    }
    if (e.key === "Escape") {
      e.preventDefault()
      onCancel()
    }
  }

  if (!method) return null

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onCancel() }}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>{method.identifierLabel ?? "Nro de operación"}</DialogTitle>
        </DialogHeader>
        <div className="space-y-3 py-2">
          {/* Contexto del pago */}
          <div className="rounded-md bg-muted px-3 py-2 text-sm">
            <span className="text-muted-foreground">{method.name} · </span>
            <span className="font-semibold tabular-nums">{formatMoney(amount, config)}</span>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="identifier-input">
              {method.identifierLabel ?? "Identificador"}
            </Label>
            <Input
              id="identifier-input"
              ref={inputRef}
              value={value}
              onChange={(e) => {
                setValue(e.target.value)
                setError(null)
              }}
              onKeyDown={handleKeyDown}
              placeholder={method.identifierPlaceholder ?? "Ej. 123456"}
              maxLength={30}
              autoComplete="off"
            />
            {error && <p className="text-xs text-destructive">{error}</p>}
          </div>
        </div>
        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onCancel}>
            Cancelar
          </Button>
          <Button onClick={handleConfirm}>Confirmar</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
