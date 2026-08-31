"use client"

/**
 * Dialog de detalle de un espacio ocupado/pagando (context/15-espacios-module-plan.md
 * F2). Lista las órdenes de la sesión (todas, cualquier status — historial
 * completo de rondas) CON sus ítems (includeItems=1, base del split F3: el
 * cajero necesita ver los ítems para poder seleccionarlos) y expone las
 * acciones del espacio: Agregar orden, Pedir cuenta, Cobrar, Cancelar sesión.
 *
 * Modal centrado (no Sheet lateral) — convención transversal del owner:
 * Dialog es el default para paneles contextuales (ver context/14 §2.2).
 * Botones size lg (touch targets); en desktop los tres principales van en
 * una fila (sm:grid-cols-3) — decisión owner 2026-07-19, el modal tiene
 * ancho de sobra para un botón por línea.
 *
 * "Cancelar sesión" libera el espacio SIN cobro y cancela EN CASCADA las
 * órdenes activas (server-side, SpaceSessionService::cancel) — siempre
 * habilitado con sesión abierta, gated por AlertDialog de confirmación.
 */

import * as React from "react"
import {
  Loader2,
  Plus,
  Receipt,
  Ban,
  CreditCard,
  ClipboardList,
  Pencil,
  ArrowRightLeft,
  Merge,
} from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { EmptyState } from "@/components/empty-state"
import { cn } from "@/lib/utils"
import { formatAmount, formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import {
  useOrdersBySession,
  ACTIVE_ORDER_STATUSES,
  type Order,
} from "@/hooks/use-orders"
import { statusLabelFor } from "@/lib/orders/order-display"
import { cancelSessionDescription, countActiveOrders } from "@/lib/spaces/cancel-session-copy"
import type { SpaceWithState } from "@/hooks/use-pos-spaces"

/** Total de una orden = suma de ítems no cancelados (qty × price). */
function orderTotal(order: Order): number {
  return (order.items ?? [])
    .filter((i) => i.status !== "cancelled")
    .reduce((s, i) => s + i.qty * (i.price ?? 0), 0)
}

interface Props {
  table: SpaceWithState | null
  onOpenChange: (open: boolean) => void
  onAddOrder: () => void
  onRequestBill: () => void
  onCharge: () => void
  onCancelSession: () => void
  onEdit: () => void
  onMove: () => void
  onMerge: () => void
  requestBillPending: boolean
  cancelPending: boolean
}

export function SpaceSessionDialog({
  table,
  onOpenChange,
  onAddOrder,
  onRequestBill,
  onCharge,
  onCancelSession,
  onEdit,
  onMove,
  onMerge,
  requestBillPending,
  cancelPending,
}: Props) {
  const config = useCatalogStore((s) => s.config)
  const users = useCatalogStore((s) => s.users)
  const sessionId = table?.session?.id ?? null
  const { data, isLoading } = useOrdersBySession(sessionId)
  const orders = data?.orders ?? []
  const activeOrderCount = countActiveOrders(orders, ACTIVE_ORDER_STATUSES)
  const [confirmCancel, setConfirmCancel] = React.useState(false)

  // Total de la sesión: órdenes no canceladas (las cobradas siguen sumando —
  // es el consumo total del espacio, referencia para el cobro/split).
  const sessionTotal = orders
    .filter((o) => o.status !== "cancelled")
    .reduce((s, o) => s + orderTotal(o), 0)

  // Subtítulo: comensales y mozo, lo que haya. El mozo se resuelve contra los
  // usuarios ya precacheados en el bootstrap — sin request extra, y funciona
  // igual con el catálogo en frío.
  const waiterName = React.useMemo(() => {
    const id = table?.session?.waiterId
    if (!id) return null
    return users.find((u) => u.id === id)?.name ?? null
  }, [table, users])

  const sessionSummary = React.useMemo(() => {
    const parts: string[] = []
    if (table?.session?.guests) parts.push(`${table.session.guests} comensales`)
    if (waiterName) parts.push(`Mozo: ${waiterName}`)
    return parts.length > 0 ? parts.join(" · ") : "Órdenes de la sesión activa"
  }, [table, waiterName])

  return (
    <Dialog open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">
            {/* El alias, cuando existe, ES cómo el mozo llama a este espacio —
                va primero y el nombre fijo del espacio queda de referencia al
                lado. Sin alias, el título es el de siempre. */}
            {table?.session?.alias ? (
              <span className="flex flex-wrap items-baseline gap-2">
                <span>{table.session.alias}</span>
                <span className="text-base font-medium text-muted-foreground">{table.name}</span>
              </span>
            ) : (
              table?.name
            )}
          </DialogTitle>
          <DialogDescription>{sessionSummary}</DialogDescription>
        </DialogHeader>

        <div className="max-h-[45vh] flex-1 overflow-y-auto">
          {isLoading ? (
            <div className="flex justify-center py-8">
              <Loader2 className="size-5 animate-spin text-muted-foreground" />
            </div>
          ) : orders.length === 0 ? (
            <EmptyState
              icon={ClipboardList}
              title="Sin órdenes todavía"
              className="py-8"
            />
          ) : (
            <div className="flex flex-col gap-2 py-2">
              {orders.map((o) => {
                const cancelled = o.status === "cancelled"
                return (
                  <div key={o.id} className="rounded-lg border border-border px-3 py-2">
                    <div className="flex items-center justify-between gap-2">
                      <span
                        className={cn(
                          "text-sm font-medium text-foreground",
                          cancelled && "text-muted-foreground line-through",
                        )}
                      >
                        Orden #{o.orderNumber ?? "—"}
                      </span>
                      <Badge variant="outline">{statusLabelFor(o)}</Badge>
                    </div>
                    {(o.items?.length ?? 0) > 0 && (
                      <ul className="mt-1.5 flex flex-col gap-0.5 border-t border-border/60 pt-1.5">
                        {o.items!.map((it) => {
                          const itemCancelled = cancelled || it.status === "cancelled"
                          return (
                            <li key={it.id} className="flex items-baseline justify-between gap-2 text-sm">
                              <div className="min-w-0">
                                <span
                                  className={cn(
                                    itemCancelled && "text-muted-foreground line-through",
                                  )}
                                >
                                  <span className="tabular-nums">{it.qty}×</span> {it.name}
                                </span>
                                {it.note && (
                                  <p className="truncate text-xs text-muted-foreground">{it.note}</p>
                                )}
                              </div>
                              <span
                                className={cn(
                                  "shrink-0 tabular-nums",
                                  itemCancelled
                                    ? "text-muted-foreground line-through"
                                    : "text-foreground",
                                )}
                              >
                                {formatAmount(it.qty * (it.price ?? 0), config)}
                              </span>
                            </li>
                          )
                        })}
                      </ul>
                    )}
                  </div>
                )
              })}
              <div className="flex items-center justify-between border-t border-border pt-2 text-sm">
                <span className="text-muted-foreground">Total de la sesión</span>
                <span className="font-semibold tabular-nums">{formatMoney(sessionTotal, config)}</span>
              </div>
            </div>
          )}
        </div>

        <DialogFooter className="flex-col gap-2 sm:flex-col">
          {/* size lg: touch targets grandes. En sm+ los tres principales van
              en una fila — el modal tiene ancho de sobra (decisión owner). */}
          <div className="grid w-full gap-2 sm:grid-cols-3">
            <Button size="lg" onClick={onAddOrder} className="w-full gap-1.5">
              <Plus className="size-4" />
              Agregar orden
            </Button>
            <Button
              size="lg"
              variant="outline"
              onClick={onRequestBill}
              disabled={requestBillPending || table?.state === "bill_requested"}
              className="w-full gap-1.5"
            >
              <Receipt className="size-4" />
              {requestBillPending ? "Pidiendo cuenta..." : "Pedir cuenta"}
            </Button>
            <Button
              size="lg"
              variant="outline"
              onClick={onCharge}
              disabled={orders.length === 0}
              className="w-full gap-1.5"
            >
              <CreditCard className="size-4" />
              Cobrar
            </Button>
          </div>
          {/* Segunda fila — gestión del espacio (owner 2026-08-23). Separada de
              la de arriba a propósito: esas tres son el flujo de servicio que
              el mozo usa en cada ronda y no se mueven de lugar (Regla #10);
              estas son ocasionales. La fila EXISTE SIEMPRE, con los tres
              botones, aunque no haya destinos válidos — el backend rechaza lo
              que no corresponda y los diálogos muestran su propio vacío.
              Ocultarlos condicionalmente movería "Cancelar sesión" de lugar
              según el estado del salón. */}
          <div className="grid w-full gap-2 sm:grid-cols-3">
            <Button size="lg" variant="outline" onClick={onEdit} className="w-full gap-1.5">
              <Pencil className="size-4" />
              Editar
            </Button>
            <Button size="lg" variant="outline" onClick={onMove} className="w-full gap-1.5">
              <ArrowRightLeft className="size-4" />
              Mover
            </Button>
            <Button size="lg" variant="outline" onClick={onMerge} className="w-full gap-1.5">
              <Merge className="size-4" />
              Unir
            </Button>
          </div>
          <Button
            size="lg"
            variant="ghost"
            onClick={() => setConfirmCancel(true)}
            disabled={cancelPending}
            className="w-full gap-1.5 text-destructive hover:text-destructive"
          >
            <Ban className="size-4" />
            {cancelPending ? "Cancelando..." : "Cancelar sesión"}
          </Button>
        </DialogFooter>

        <AlertDialog open={confirmCancel} onOpenChange={setConfirmCancel}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>¿Cancelar la sesión?</AlertDialogTitle>
              <AlertDialogDescription>
                {cancelSessionDescription(activeOrderCount)}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Volver</AlertDialogCancel>
              <AlertDialogAction
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                onClick={() => {
                  setConfirmCancel(false)
                  onCancelSession()
                }}
              >
                Cancelar sesión
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </DialogContent>
    </Dialog>
  )
}
