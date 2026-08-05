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
import { posApi as api } from "@/lib/api/pos-client"
import type { PosConfig } from "@/lib/types/pos-bootstrap"

interface GiftcardInfo {
  id: string
  code: string
  currentBalance: number
  expiresAt: string | null
}

interface GiftcardValidationDialogProps {
  open: boolean
  total: number
  config: PosConfig | null
  onApply: (code: string, amount: number) => void
  onCancel: () => void
}

export function GiftcardValidationDialog({
  open,
  total,
  config,
  onApply,
  onCancel,
}: GiftcardValidationDialogProps) {
  const [code, setCode] = React.useState("")
  const [validating, setValidating] = React.useState(false)
  const [giftcard, setGiftcard] = React.useState<GiftcardInfo | null>(null)
  const [error, setError] = React.useState<string | null>(null)
  const inputRef = React.useRef<HTMLInputElement>(null)

  React.useEffect(() => {
    if (open) {
      setCode("")
      setGiftcard(null)
      setError(null)
      setTimeout(() => inputRef.current?.focus(), 50)
    }
  }, [open])

  async function handleValidate() {
    const trimmed = code.trim().toUpperCase()
    if (!trimmed) {
      setError("Ingresá el código de la giftcard")
      return
    }
    setValidating(true)
    setError(null)
    setGiftcard(null)
    try {
      // `total` va al servidor para que la regla "la venta debe cubrir el
      // saldo" se valide ANTES de cobrar. Validar solo en el cliente dejaría
      // que un caller mal hecho queme una tarjeta en una venta más chica.
      const res = await api.post<GiftcardInfo>(
        "/v1/giftcards?resource=validate",
        { code: trimmed, total },
      )
      setGiftcard(res)
    } catch (err) {
      const msg =
        err instanceof Error ? err.message : "Error al validar la giftcard"
      setError(msg)
    } finally {
      setValidating(false)
    }
  }

  function handleApply() {
    if (!giftcard) return
    // Regla del negocio (owner, 2026-08-05): la gift card es de UN SOLO USO y
    // se consume entera, así que la venta tiene que valer al menos su saldo.
    // Si valiera menos, el excedente se perdería: `consume` pone
    // currentBalance en 0 sin importar cuánto se aplicó.
    //
    // La condición estaba INVERTIDA (exigía que la tarjeta cubriera la venta y
    // aplicaba el total como importe), que es justamente el caso que hace
    // desaparecer plata: una tarjeta de 700.000 en una venta de 100.000
    // quemaba 600.000.
    if (total < giftcard.currentBalance) {
      setError(
        `El total de la venta debe ser igual o mayor al saldo de la giftcard. Saldo: ${formatMoney(giftcard.currentBalance, config)}; Total: ${formatMoney(total, config)}`,
      )
      return
    }
    // Se aplica el saldo COMPLETO; el resto de la venta lo cubre otro medio.
    onApply(giftcard.code, giftcard.currentBalance)
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      e.preventDefault()
      if (giftcard) {
        handleApply()
      } else {
        void handleValidate()
      }
    }
    if (e.key === "Escape") {
      e.preventDefault()
      onCancel()
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onCancel() }}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>Giftcard</DialogTitle>
        </DialogHeader>

        <div className="space-y-3 py-2">
          <div className="space-y-1.5">
            <Label htmlFor="giftcard-code-input">Código de giftcard</Label>
            <div className="flex gap-2">
              <Input
                id="giftcard-code-input"
                ref={inputRef}
                value={code}
                onChange={(e) => {
                  setCode(e.target.value)
                  setError(null)
                  setGiftcard(null)
                }}
                onKeyDown={handleKeyDown}
                placeholder="Ej. GC-1234-5678"
                autoComplete="off"
                disabled={validating}
                className="h-14 text-2xl tabular-nums text-center"
              />
              <Button
                variant="outline"
                onClick={() => void handleValidate()}
                disabled={validating || !code.trim()}
              >
                {validating ? "..." : "Validar"}
              </Button>
            </div>
            {error && (
              <p className="text-xs text-destructive">{error}</p>
            )}
          </div>

          {giftcard && (
            <div className="rounded-md border border-border bg-muted/50 px-3 py-2 space-y-0.5 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Código</span>
                <span className="font-mono font-medium">{giftcard.code}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">Saldo</span>
                <span className="font-semibold tabular-nums">
                  {formatMoney(giftcard.currentBalance, config)}
                </span>
              </div>
              {giftcard.expiresAt && (
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Vence</span>
                  <span className="tabular-nums">
                    {new Date(giftcard.expiresAt).toLocaleDateString("es-PY")}
                  </span>
                </div>
              )}
            </div>
          )}
        </div>

        <DialogFooter className="gap-2">
          <Button variant="outline" onClick={onCancel}>
            Cancelar
          </Button>
          <Button
            onClick={handleApply}
            disabled={!giftcard}
          >
            Aplicar como pago
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
