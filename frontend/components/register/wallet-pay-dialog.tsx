"use client"

/**
 * Cobro con SALDO — elegir el bolsillo (wallet F2, context/74 D4/D6/D14).
 *
 * Lo elige el cajero (D4), viendo cuánto tiene cada uno. El saldo se pide
 * FRESCO al abrir (no del cache del carrito): es estado compartido entre cajas
 * y lo que se muestra acá es lo que el cajero usa para decidir. Igual NADA se
 * aprueba contra este número — el débito real lo chequea el servidor bajo el
 * lock del bolsillo al confirmar (§5).
 *
 * Si el bolsillo no alcanza, se aplica lo que hay y la diferencia se cobra con
 * otro medio en el mismo cobro: por detrás es una CARGA de la diferencia (con
 * su factura) y después el consumo entero con saldo (D14). Para eso el
 * operador necesita además `pos.wallet.load`; sin él, un bolsillo que no
 * alcanza no se puede elegir.
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { toast } from "sonner"
import { cn } from "@/lib/utils"
import { formatMoney } from "@/lib/format-money"
import type { PosConfig } from "@/lib/types/pos-bootstrap"
import { fetchWalletBalances, type PosWalletBalance } from "@/lib/wallet/pos-wallet"

export interface WalletPaySelection {
  pocketId: string
  pocketName: string
  available: number
}

interface WalletPayDialogProps {
  open: boolean
  customerId: string | null
  customerName: string | null
  /** Lo que falta cobrar. */
  remaining: number
  /** El operador puede cargar la diferencia si el bolsillo no alcanza. */
  canLoadDifference: boolean
  config: PosConfig | null
  onApply: (selection: WalletPaySelection) => void
  onCancel: () => void
}

export function WalletPayDialog({
  open,
  customerId,
  customerName,
  remaining,
  canLoadDifference,
  config,
  onApply,
  onCancel,
}: WalletPayDialogProps) {
  const [balances, setBalances] = React.useState<PosWalletBalance[] | null>(null)
  const [error, setError] = React.useState<string | null>(null)
  const [loading, setLoading] = React.useState(false)

  React.useEffect(() => {
    if (!open || !customerId) return
    let cancelled = false
    setBalances(null)
    setError(null)
    setLoading(true)
    fetchWalletBalances(customerId)
      .then((res) => {
        if (!cancelled) setBalances(res.balances.filter((b) => b.active))
      })
      .catch((err: unknown) => {
        if (!cancelled) setError(err instanceof Error ? err.message : "No se pudo consultar el saldo")
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [open, customerId])

  function choose(b: PosWalletBalance) {
    if (b.balance <= 0) {
      toast.info(`${b.name} no tiene saldo`)
      return
    }
    if (b.balance < remaining && !canLoadDifference) {
      toast.info(`El saldo de ${b.name} no alcanza para este cobro`)
      return
    }
    onApply({ pocketId: b.pocketId, pocketName: b.name, available: b.balance })
  }

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onCancel() }}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Pagar con saldo</DialogTitle>
          {customerName && <p className="text-sm text-muted-foreground">{customerName}</p>}
        </DialogHeader>

        <div className="space-y-2 py-2">
          {loading && <p className="text-sm text-muted-foreground">Consultando saldo…</p>}
          {error && <p className="text-sm text-destructive">{error}</p>}
          {balances !== null && balances.length === 0 && (
            <p className="text-sm text-muted-foreground">Este cliente no tiene bolsillos con saldo</p>
          )}
          {(balances ?? []).map((b) => {
            const short = b.balance < remaining
            const empty = b.balance <= 0
            return (
              <Button
                key={b.pocketId}
                type="button"
                variant="outline"
                size="lg"
                // h-auto: dos líneas (nombre + qué pasa si no alcanza) en un
                // target táctil a ancho completo (context/14 §2.2).
                className={cn("h-auto w-full justify-between py-3", empty && "opacity-50")}
                aria-disabled={empty || (short && !canLoadDifference) ? true : undefined}
                onClick={() => choose(b)}
              >
                <span className="flex flex-col items-start">
                  <span className="font-medium">{b.name}</span>
                  {short && !empty && (
                    <span className="text-xs text-muted-foreground">
                      {canLoadDifference
                        ? `Faltan ${formatMoney(remaining - b.balance, config)}: se cobran con otro medio`
                        : "No alcanza"}
                    </span>
                  )}
                </span>
                <span className="tabular-nums font-semibold">{formatMoney(b.balance, config)}</span>
              </Button>
            )
          })}
        </div>

        <DialogFooter>
          <Button variant="outline" size="lg" className="w-full" onClick={onCancel}>
            Cancelar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
