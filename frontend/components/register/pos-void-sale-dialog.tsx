"use client"

/**
 * PosVoidSaleDialog — anulación de venta (F6, context/40-anulacion-y-nota-credito.md).
 *
 * Abierto desde el detalle de transacción del POS (`pos-transactions-dialog.tsx`,
 * `TransactionDetail`) para ventas contado/crédito (`typeNum` 0/3) no anuladas.
 *
 * Consulta `useVoidOptions` (GET /api/pos/sales-void) para el estado real de
 * anulabilidad — D4 (ventana de 48h desde `transactionDate`) y D2 (qué línea
 * PUEDE volver a stock, ver docblock de `hooks/use-sale-void.ts`). El botón
 * deshabilitado de la UI es comodidad: el guard real vive en `SaleVoidService`
 * y responde 409/422 con `errorCode` si igual se manda el POST.
 *
 * Si `canVoid.allowed === false` no hay checklist — se explica el motivo y
 * se ofrece el camino correcto (D4: pasado el plazo, nota de crédito, acá
 * materializada como "Hacer devolución" — F6 solo tiene devolución, la NC
 * fiscal propiamente dicha es una fase posterior de context/40).
 */

import * as React from "react"
import { toast } from "sonner"

import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
  ResponsiveDialogDescription,
  ResponsiveDialogFooter,
} from "@/components/ui/responsive-dialog"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { Button } from "@/components/ui/button"
import { Switch } from "@/components/ui/switch"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import { formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import { useVoidOptions, useVoidSale, VoidSaleError, type VoidLine } from "@/hooks/use-sale-void"

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** UUID crudo de la transacción — `detail.transactionId`, NO el `encId` de la lista (son ids distintos, ver hooks/use-sale-void.ts). */
  transactionId: string
  invoiceLabel: string
  total: number
  dateLabel: string
  /** El cajero eligió "Hacer devolución" (ventana vencida / bloqueado, o error HAS_RETURNS/VOID_WINDOW_EXPIRED del POST) — el caller cierra este dialog y abre PosReturnSheet con la misma tx. */
  onOfferReturn: () => void
}

export function PosVoidSaleDialog({
  open,
  onOpenChange,
  transactionId,
  invoiceLabel,
  total,
  dateLabel,
  onOfferReturn,
}: Props) {
  const config = useCatalogStore((s) => s.config)
  const { data, isLoading, isError } = useVoidOptions(transactionId, open)
  const voidSale = useVoidSale()
  const [reason, setReason] = React.useState("")
  const [restockByLine, setRestockByLine] = React.useState<Record<string, boolean>>({})
  const [confirmOpen, setConfirmOpen] = React.useState(false)

  React.useEffect(() => {
    if (open) {
      setReason("")
      setConfirmOpen(false)
    }
  }, [open, transactionId])

  React.useEffect(() => {
    if (!data?.lines) return
    const initial: Record<string, boolean> = {}
    for (const line of data.lines) initial[line.itemSoldId] = line.defaultRestock
    setRestockByLine(initial)
  }, [data?.lines])

  function handleOpenChange(v: boolean) {
    if (voidSale.isPending) return
    onOpenChange(v)
  }

  function handleVoid() {
    const lines = (data?.lines ?? []).map((l: VoidLine) => ({
      itemSoldId: l.itemSoldId,
      restock: Boolean(restockByLine[l.itemSoldId]),
    }))
    voidSale.mutate(
      { id: transactionId, reason: reason.trim(), lines },
      {
        onSuccess: () => {
          toast.success("Factura anulada")
          setConfirmOpen(false)
          onOpenChange(false)
        },
        onError: (err) => {
          setConfirmOpen(false)
          const code = err instanceof VoidSaleError ? err.errorCode : undefined
          if (code === "HAS_RETURNS" || code === "VOID_WINDOW_EXPIRED") {
            toast.error(err.message, {
              action: { label: "Hacer devolución", onClick: onOfferReturn },
            })
          } else {
            toast.error(err.message || "No se pudo anular la venta")
          }
        },
      },
    )
  }

  const canVoid = data?.canVoid
  const blocked = Boolean(canVoid && !canVoid.allowed)
  const allowed = Boolean(canVoid?.allowed)

  return (
    <>
      <ResponsiveDialog open={open} onOpenChange={handleOpenChange}>
        <ResponsiveDialogContent className="sm:max-w-2xl">
          <ResponsiveDialogHeader>
            <ResponsiveDialogTitle>
              Anular factura{invoiceLabel ? ` #${invoiceLabel}` : ""}
            </ResponsiveDialogTitle>
            <ResponsiveDialogDescription>
              {dateLabel} · {formatMoney(total, config)}
            </ResponsiveDialogDescription>
          </ResponsiveDialogHeader>

          {isLoading && (
            <div className="flex flex-col gap-3 py-2">
              <Skeleton className="h-4 w-2/3" />
              <Skeleton className="h-24 w-full" />
            </div>
          )}

          {!isLoading && isError && (
            <p className="text-sm text-muted-foreground py-2">
              No se pudo consultar el estado de esta venta. Cerrá e intentá de nuevo.
            </p>
          )}

          {!isLoading && blocked && (
            <div className="flex flex-col gap-4 py-2">
              <p className="text-sm text-muted-foreground">{canVoid?.reason}</p>
              <Button variant="secondary" size="lg" onClick={onOfferReturn}>
                Hacer devolución
              </Button>
            </div>
          )}

          {!isLoading && allowed && (
            <div className="flex flex-col gap-4 py-2">
              <div className="rounded-lg bg-muted/40 divide-y divide-border/60">
                {(data?.lines ?? []).map((line) => (
                  <div key={line.itemSoldId} className="flex items-center justify-between gap-3 px-3 py-2.5">
                    <div className="min-w-0 flex-1">
                      <p className="text-sm truncate">{line.name}</p>
                      <p className="text-xs text-muted-foreground tabular-nums">
                        {line.qty} × {formatMoney(line.unitPrice, config)}
                      </p>
                      {!line.canRestock && (
                        <p className="text-xs text-muted-foreground">No repone stock</p>
                      )}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      <Label htmlFor={`restock-${line.itemSoldId}`} className="text-xs text-muted-foreground">
                        Vuelve al stock
                      </Label>
                      <Switch
                        id={`restock-${line.itemSoldId}`}
                        checked={Boolean(restockByLine[line.itemSoldId])}
                        disabled={!line.canRestock}
                        onCheckedChange={(v) =>
                          setRestockByLine((prev) => ({ ...prev, [line.itemSoldId]: v }))
                        }
                      />
                    </div>
                  </div>
                ))}
              </div>

              <div className="flex flex-col gap-1.5">
                <Label htmlFor="void-reason">Motivo</Label>
                <Textarea
                  id="void-reason"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="Por qué se anula esta factura"
                  rows={2}
                />
              </div>
            </div>
          )}

          {!isLoading && allowed && (
            <ResponsiveDialogFooter>
              <Button
                variant="destructive"
                size="lg"
                disabled={!reason.trim() || voidSale.isPending}
                onClick={() => setConfirmOpen(true)}
              >
                Anular factura
              </Button>
            </ResponsiveDialogFooter>
          )}
        </ResponsiveDialogContent>
      </ResponsiveDialog>

      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Confirmar anulación</AlertDialogTitle>
            <AlertDialogDescription>
              El número de factura{invoiceLabel ? ` #${invoiceLabel}` : ""} queda usado —no se
              libera— y esta venta deja de sumar al total vendido. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={voidSale.isPending}>Cancelar</AlertDialogCancel>
            {/* Button plano, no AlertDialogAction — mismo criterio que el
                "Anular" legacy de transactions-list.tsx: AlertDialogAction
                cierra el dialog al click, antes de que `isPending` alcance a
                bloquear un doble-click. */}
            <Button variant="destructive" disabled={voidSale.isPending} onClick={handleVoid}>
              {voidSale.isPending ? "Anulando…" : "Anular factura"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
