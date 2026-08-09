"use client"

/**
 * Dialog de CANJE de vale (F2, context/36-vouchers-plan.md).
 *
 * Entrypoint: menú "Opciones de venta" → "Vale" (sale-options-drawer.tsx),
 * NO el diálogo de cobro — el vale se ingresa AL ARMAR la venta (decisión del
 * owner, 2026-08-06). Validar un código no aplica un pago: carga las líneas
 * del vale al carrito con `useCartStore.getState().applyVoucher()`.
 *
 * NO confundir con `giftcard-validation-dialog.tsx` (canje de gift card COMO
 * MEDIO DE PAGO en pay-dialog.tsx) — ese sí registra un pago; el vale nunca
 * es un medio de pago, sus líneas simplemente no suman al total
 * (CartLine.voucher, lib/cart/store.ts).
 *
 * También lista los vales YA aplicados en el carrito (derivado de las líneas,
 * agrupado por voucherId) con la única forma de deshacer: "Quitar vale",
 * que saca TODAS sus líneas de una vez.
 */

import * as React from "react"
import { Ticket, X } from "lucide-react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
  ResponsiveDialogFooter,
} from "@/components/ui/responsive-dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { formatMoney } from "@/lib/format-money"
import { posApi as api } from "@/lib/api/pos-client"
import { useCartStore, type VoucherRedeemItem } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { toast } from "sonner"

interface VoucherValidateResponse {
  ok: boolean
  voucherId: string
  code: string
  expiresAt: string | null
  items: VoucherRedeemItem[]
}

export function VoucherApplyDialog({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const [code, setCode] = React.useState("")
  const [validating, setValidating] = React.useState(false)
  const [preview, setPreview] = React.useState<VoucherValidateResponse | null>(null)
  const [error, setError] = React.useState<string | null>(null)
  const inputRef = React.useRef<HTMLInputElement>(null)
  const config = useCatalogStore((s) => s.config)

  const lines = useCartStore((s) => s.lines)
  const applyVoucher = useCartStore((s) => s.applyVoucher)
  const removeVoucher = useCartStore((s) => s.removeVoucher)

  // Vales YA aplicados en el carrito, agrupados por voucherId — una fila por
  // vale (no por línea), con la cantidad de ítems que trajo.
  const appliedVouchers = React.useMemo(() => {
    const map = new Map<string, { code: string; lineCount: number }>()
    for (const line of lines) {
      if (!line.voucher) continue
      const existing = map.get(line.voucher.voucherId)
      if (existing) {
        existing.lineCount += 1
      } else {
        map.set(line.voucher.voucherId, { code: line.voucher.code, lineCount: 1 })
      }
    }
    return [...map.entries()].map(([voucherId, v]) => ({ voucherId, ...v }))
  }, [lines])

  React.useEffect(() => {
    if (open) {
      setCode("")
      setPreview(null)
      setError(null)
      setTimeout(() => inputRef.current?.focus(), 50)
    }
  }, [open])

  async function handleValidate() {
    const trimmed = code.trim().toUpperCase()
    if (!trimmed) {
      setError("Ingresá el código del vale")
      return
    }
    if (appliedVouchers.some((v) => v.code.toUpperCase() === trimmed)) {
      setError("Ese vale ya está aplicado a esta venta")
      return
    }
    setValidating(true)
    setError(null)
    setPreview(null)
    try {
      const res = await api.post<VoucherValidateResponse>(
        "/v1/vouchers?resource=validate",
        { code: trimmed },
      )
      setPreview(res)
    } catch (err) {
      const msg = err instanceof Error ? err.message : "Error al validar el vale"
      setError(msg)
    } finally {
      setValidating(false)
    }
  }

  function handleApply() {
    if (!preview) return
    applyVoucher({
      voucherId: preview.voucherId,
      code: preview.code,
      items: preview.items,
    })
    toast.success(`Vale ${preview.code} aplicado — ${preview.items.length} ítem(es) al carrito`)
    setCode("")
    setPreview(null)
    inputRef.current?.focus()
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter") {
      e.preventDefault()
      if (preview) {
        handleApply()
      } else {
        void handleValidate()
      }
    }
  }

  return (
    <ResponsiveDialog open={open} onOpenChange={(o) => { if (!o) onClose() }}>
      <ResponsiveDialogContent className="sm:max-w-sm">
        <ResponsiveDialogHeader>
          <ResponsiveDialogTitle>Vale</ResponsiveDialogTitle>
        </ResponsiveDialogHeader>

        <div className="space-y-3 py-2">
          {appliedVouchers.length > 0 && (
            <div className="space-y-1.5">
              <Label>Aplicados a esta venta</Label>
              <div className="space-y-1.5">
                {appliedVouchers.map((v) => (
                  <div
                    key={v.voucherId}
                    className="flex items-center justify-between gap-2 rounded-md border border-blue-500/30 bg-blue-500/10 px-3 py-2"
                  >
                    <span className="inline-flex items-center gap-1.5 text-sm font-medium">
                      <Ticket className="size-3.5 text-blue-500" aria-hidden />
                      <span className="font-mono">{v.code}</span>
                      <span className="text-xs text-muted-foreground">
                        ({v.lineCount} ítem{v.lineCount === 1 ? "" : "s"})
                      </span>
                    </span>
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="size-7 text-muted-foreground hover:text-destructive"
                      aria-label={`Quitar vale ${v.code}`}
                      onClick={() => {
                        removeVoucher(v.voucherId)
                        toast.success(`Vale ${v.code} quitado`)
                      }}
                    >
                      <X className="size-4" />
                    </Button>
                  </div>
                ))}
              </div>
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="voucher-code-input">Código de vale</Label>
            <div className="flex gap-2">
              <Input
                id="voucher-code-input"
                ref={inputRef}
                value={code}
                onChange={(e) => {
                  setCode(e.target.value)
                  setError(null)
                  setPreview(null)
                }}
                onKeyDown={handleKeyDown}
                placeholder="Ej. VC-1234-5678"
                autoComplete="off"
                disabled={validating}
                // Mismo shape que payment-identifier-dialog y el de giftcard:
                // h-10 y sin forzar tamaño de fuente. El h-14 text-2xl que
                // había dejaba el campo mucho más alto que el botón "Validar"
                // de al lado (desalineado), y el text-2xl ni siquiera se
                // aplicaba: `.pos-scope input` de globals.css ya fija la
                // tipografía táctil del POS y le gana por especificidad.
                className="h-10 flex-1 tabular-nums text-center"
              />
              <Button
                variant="outline"
                onClick={() => void handleValidate()}
                disabled={validating || !code.trim()}
              >
                {validating ? "..." : "Validar"}
              </Button>
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
          </div>

          {preview && (
            <div className="rounded-md border border-border bg-muted/50 px-3 py-2 space-y-1.5 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">Código</span>
                <span className="font-mono font-medium">{preview.code}</span>
              </div>
              <div className="space-y-1">
                {preview.items.map((it, i) => (
                  <div key={i} className="flex justify-between text-xs">
                    <span className="truncate">
                      {it.qty}× {it.name}
                    </span>
                    <span className="tabular-nums text-muted-foreground">
                      {formatMoney(it.qty * it.unitPriceAtIssue, config)}
                    </span>
                  </div>
                ))}
              </div>
              <p className="text-[11px] text-muted-foreground">
                Estos ítems se agregan al carrito sin sumar al total — ya fueron pagados al emitir el vale.
              </p>
            </div>
          )}
        </div>

        <ResponsiveDialogFooter className="gap-2">
          <Button variant="outline" onClick={onClose}>
            Cerrar
          </Button>
          <Button onClick={handleApply} disabled={!preview}>
            Agregar al carrito
          </Button>
        </ResponsiveDialogFooter>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
