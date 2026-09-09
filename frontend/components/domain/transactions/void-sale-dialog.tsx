"use client"

/**
 * VoidSaleDialog — anulación de una VENTA (F6, context/40-anulacion-y-nota-credito.md).
 *
 * Compartido por los DOS realms: el detalle de transacción del POS
 * (`components/register/pos-transactions-dialog.tsx`) y el del panel
 * (`app/(panel)/transactions/[id]/page.tsx`). Es el mismo hecho de negocio,
 * el mismo endpoint y las mismas reglas, así que es el mismo componente —
 * copiarlo al panel era garantizar que un día se arregle un caso y el otro no.
 *
 * Lo que cambia por realm entra por props, no por una segunda copia:
 *   - `transport` → qué cliente HTTP (y por lo tanto qué credencial) usa el
 *     hook. Ver el invariante "un cliente = un realm" en `hooks/use-sale-void.ts`.
 *   - `formatAmount` → el POS formatea con la config del catálogo offline
 *     (`lib/format-money`), el panel con la del bootstrap (`lib/format`).
 *   - `onOfferReturn` → solo el POS tiene la hoja de devolución; en el panel
 *     se omite y el bloqueo se explica sin ofrecer un camino que no existe.
 *
 * Consulta `useVoidOptions` para el estado REAL de anulabilidad — D4 (ventana
 * de 48h desde `transactionDate`) y D2 (qué línea PUEDE volver a stock, ver
 * docblock de `hooks/use-sale-void.ts`). El botón deshabilitado de la UI es
 * comodidad: el guard real vive en `SaleVoidService` y responde 409/422 con
 * `errorCode` si igual se manda el POST.
 *
 * Si `canVoid.allowed === false` no hay checklist — se explica el motivo y,
 * cuando el caller lo ofrece, el camino correcto (D4: pasado el plazo, nota de
 * crédito, acá materializada como "Hacer devolución" — F6 solo tiene
 * devolución, la NC fiscal propiamente dicha es una fase posterior de
 * context/40).
 *
 * ── El documento fiscal ─────────────────────────────────────────────────────
 * Anular la venta CANCELA en cascada su documento electrónico, dentro de la
 * misma transacción de BD (`SaleVoidService` paso 5). Eso no puede ser una
 * sorpresa: con `einvoiceIssued` el diálogo lo dice ANTES de confirmar, en el
 * cuerpo y en la confirmación. Y al terminar informa lo que REALMENTE pasó
 * leyendo `einvoiceCancelled` de la respuesta — no lo que se anticipó.
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
import {
  useVoidOptions,
  useVoidSale,
  VoidSaleError,
  type SaleVoidTransport,
  type VoidLine,
} from "@/hooks/use-sale-void"

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** UUID crudo de la transacción — `detail.transactionId`, NO el `encId` de la lista (son ids distintos, ver hooks/use-sale-void.ts). */
  transactionId: string
  invoiceLabel: string
  total: number
  dateLabel: string
  /** Formateador de moneda del realm que abre el diálogo (Regla #7 de context/14 — nunca `Intl` a mano acá). */
  formatAmount: (value: number) => string
  /** Cliente HTTP + ruta upstream. Omitido = panel. Ver `hooks/use-sale-void.ts`. */
  transport?: SaleVoidTransport
  /** ¿Esta venta tiene factura electrónica vigente? Si sí, el diálogo avisa que anular la cancela ante SIFEN. */
  einvoiceIssued?: boolean
  /** El cajero eligió "Hacer devolución" (ventana vencida / bloqueado, o error HAS_RETURNS/VOID_WINDOW_EXPIRED del POST) — el caller cierra este dialog y abre PosReturnSheet con la misma tx. Omitido: no se ofrece (el panel no tiene esa hoja). */
  onOfferReturn?: () => void
}

const EINVOICE_NOTICE =
  "Esta venta tiene factura electrónica emitida. Al anularla se cancela también el documento ante SIFEN, en el mismo acto."

export function VoidSaleDialog({
  open,
  onOpenChange,
  transactionId,
  invoiceLabel,
  total,
  dateLabel,
  formatAmount,
  transport,
  einvoiceIssued = false,
  onOfferReturn,
}: Props) {
  const { data, isLoading, isError } = useVoidOptions(transactionId, open, transport)
  const voidSale = useVoidSale(transport)
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
        onSuccess: (res) => {
          // El resultado del documento fiscal se INFORMA, no se asume: puede
          // no haber habido documento vigente que cancelar (nunca emitido, ya
          // cancelado, o reemplazado por un rechazo previo — mig 201). Si el
          // diálogo prometió la cancelación y el backend dice que no la hubo,
          // eso es un aviso, no un éxito silencioso.
          if (res.einvoiceCancelled) {
            toast.success("Venta anulada. La factura electrónica quedó cancelada ante SIFEN.")
          } else if (einvoiceIssued) {
            toast.warning(
              "Venta anulada, pero no se canceló ningún documento electrónico. Revisá el estado de la factura.",
            )
          } else {
            toast.success("Venta anulada")
          }
          setConfirmOpen(false)
          onOpenChange(false)
        },
        onError: (err) => {
          setConfirmOpen(false)
          const code = err instanceof VoidSaleError ? err.errorCode : undefined
          if (onOfferReturn && (code === "HAS_RETURNS" || code === "VOID_WINDOW_EXPIRED")) {
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
              Anular venta{invoiceLabel ? ` #${invoiceLabel}` : ""}
            </ResponsiveDialogTitle>
            <ResponsiveDialogDescription>
              {dateLabel} · {formatAmount(total)}
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
              {onOfferReturn && (
                <Button variant="secondary" size="lg" onClick={onOfferReturn}>
                  Hacer devolución
                </Button>
              )}
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
                        {line.qty} × {formatAmount(line.unitPrice)}
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

              {/* El efecto fiscal, ANTES de confirmar. No es una banda de
                  estado: es parte de lo que la acción hace, y por eso vive
                  pegado al formulario de la acción. */}
              {einvoiceIssued && (
                <p className="text-sm text-muted-foreground">{EINVOICE_NOTICE}</p>
              )}

              <div className="flex flex-col gap-1.5">
                <Label htmlFor="void-reason">Motivo</Label>
                <Textarea
                  id="void-reason"
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="Por qué se anula esta venta"
                  rows={3}
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
                Anular venta
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
              El número{invoiceLabel ? ` #${invoiceLabel}` : ""} queda usado —no se libera— y esta
              venta deja de sumar al total vendido.
              {einvoiceIssued ? ` ${EINVOICE_NOTICE}` : ""} Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={voidSale.isPending}>Cancelar</AlertDialogCancel>
            {/* Button plano, no AlertDialogAction — mismo criterio que el
                "Anular" legacy de transactions-list.tsx: AlertDialogAction
                cierra el dialog al click, antes de que `isPending` alcance a
                bloquear un doble-click. */}
            <Button variant="destructive" disabled={voidSale.isPending} onClick={handleVoid}>
              {voidSale.isPending ? "Anulando…" : "Anular venta"}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}
