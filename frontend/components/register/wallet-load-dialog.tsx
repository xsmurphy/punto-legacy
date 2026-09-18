"use client"

/**
 * "Cargar saldo" (wallet F2, context/74) — agrega al carrito la línea de CARGA
 * de un bolsillo del cliente.
 *
 * La carga es una VENTA como cualquier otra: la línea se cobra con los medios
 * de pago normales, sale con su factura y funciona sin red (D15). Por eso este
 * diálogo NO cobra nada ni llama a nadie: solo arma la línea. El saldo se
 * acredita cuando la venta se guarda (también la encolada, al sincronizar).
 *
 * Captura del monto con `<NumericPad>`: en el POS los montos van SIEMPRE por
 * el pad (`context/14` Regla #11), nunca por un input.
 *
 * El bolsillo se elige con botones grandes (touch-first); con un solo bolsillo
 * activo ya viene elegido. La lista sale del snapshot del bootstrap, así que
 * está disponible sin conexión.
 */

import * as React from "react"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
} from "@/components/ui/responsive-dialog"
import { Button } from "@/components/ui/button"
import { NumericPad } from "@/components/pos/numeric-pad"
import { cn } from "@/lib/utils"
import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { walletLoadLine } from "@/lib/wallet/pos-wallet"
import { toast } from "sonner"

export function WalletLoadDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const pockets = useCatalogStore((s) => s.config?.walletPockets ?? null)
  const customer = useCartStore((s) => s.customer)
  const [pocketId, setPocketId] = React.useState<string | null>(null)
  const [draft, setDraft] = React.useState("0")

  React.useEffect(() => {
    if (!open) return
    setDraft("0")
    setPocketId(pockets && pockets.length === 1 ? pockets[0].id : null)
  }, [open, pockets])

  const pocket = (pockets ?? []).find((p) => p.id === pocketId) ?? null
  const amount = Number(draft)
  const ready = pocket !== null && Number.isFinite(amount) && amount > 0

  function handleConfirm() {
    if (!pocket) {
      toast.info("Elegí el bolsillo")
      return
    }
    if (!Number.isFinite(amount) || amount <= 0) return
    useCartStore.getState().addLines([walletLoadLine(pocket, amount)])
    onClose()
  }

  return (
    <ResponsiveDialog open={open} onOpenChange={(v) => !v && onClose()}>
      <ResponsiveDialogContent sectioned className="sm:max-w-md">
        <div className="border-b px-6 py-4">
          <h2 className="text-lg font-semibold">Cargar saldo</h2>
          {customer && <p className="text-sm text-muted-foreground">{customer.name}</p>}
        </div>

        <div className="space-y-4 px-6 py-6">
          {/* Bolsillos — fila fija de botones táctiles. */}
          <div className="grid grid-cols-2 gap-2">
            {(pockets ?? []).map((p) => (
              <Button
                key={p.id}
                type="button"
                size="lg"
                variant={p.id === pocketId ? "default" : "outline"}
                aria-pressed={p.id === pocketId}
                className={cn("truncate")}
                onClick={() => setPocketId(p.id)}
              >
                {p.name}
              </Button>
            ))}
          </div>

          <NumericPad
            mode="money"
            value={draft}
            onChange={setDraft}
            onConfirm={handleConfirm}
            onCancel={onClose}
          />
        </div>

        <div className="border-t px-6 py-4">
          <Button onClick={handleConfirm} className="w-full" size="lg" disabled={!ready}>
            Agregar carga
          </Button>
        </div>
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}
