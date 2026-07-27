"use client"

import * as React from "react"
import { History, Undo2 } from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import type { Order } from "@/hooks/use-orders"
import { doneAtIso, recallBlockReason, RECALL_LIMIT, screenItems } from "@/lib/kds/board"
import { KDS_ITEM_VISUALS, kdsTint } from "@/lib/kds/kds-visuals"
import { formatTime } from "@/lib/format-date"
import { orderDestination, statusLabelFor } from "@/lib/orders/order-display"

/**
 * Panel de comandas que ya SALIERON del board (recall).
 *
 * El board muestra lo que falta hacer; acá se llega a lo que ya salió. Dos usos
 * reales: quien despacha y pregunta "¿y la 42?" y la comanda que vuelve del salón
 * porque salió mal — para esa está "Devolver", que des-bumpea los
 * ítems `ready` de esta pantalla y hace que la comanda REAPAREZCA en el board,
 * en su posición por tiempo (el orden es por `sentAt`, que no cambia).
 *
 * Modal (Dialog centrado), nunca Sheet/Drawer — convención del proyecto.
 *
 * Lista acotada a las últimas `RECALL_LIMIT` (ver la justificación en
 * `lib/kds/board.ts`): una noche movida no puede producir una lista infinita en
 * una pantalla sin búsqueda ni filtros. Para el historial completo está
 * /pos/ordenes.
 *
 * Lo que NO vuelve se explica: una comanda entregada, cobrada o cancelada se VE
 * pero muestra el motivo en vez de un botón muerto (`recallBlockReason`).
 */

interface RecallDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Ya ordenadas (más reciente primero) y acotadas por la pantalla. */
  orders: Order[]
  stationIds: string[]
  /** Ids con un POST en vuelo — evita doble "Devolver" sobre la misma comanda. */
  busyIds: Set<string>
  onRecall: (order: Order) => void
}

export function KdsRecallDialog({
  open,
  onOpenChange,
  orders,
  stationIds,
  busyIds,
  onRecall,
}: RecallDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogTrigger asChild>
        <Button variant="ghost" className="h-11 gap-2" aria-label="Comandas que ya salieron">
          <History className="size-6" />
          <span className="hidden sm:inline">Recall</span>
        </Button>
      </DialogTrigger>
      <DialogContent className="max-h-[85vh] gap-0 overflow-hidden p-0 sm:max-w-2xl">
        <DialogHeader className="border-b p-6 pb-4">
          <DialogTitle>Comandas que ya salieron</DialogTitle>
          <DialogDescription>
            Las últimas {RECALL_LIMIT}, la más reciente primero. Devolverla a preparación la hace
            reaparecer en pantalla, en su lugar por hora.
          </DialogDescription>
        </DialogHeader>

        <div className="max-h-[60vh] overflow-y-auto p-6">
          {orders.length === 0 ? (
            <p className="py-8 text-center text-muted-foreground">
              Todavía no salió ninguna comanda.
            </p>
          ) : (
            <ul className="flex flex-col gap-3">
              {orders.map((order) => {
                const items = screenItems(order, stationIds)
                const reason = recallBlockReason(order, stationIds)
                const doneIso = doneAtIso(order, stationIds)
                const busy = busyIds.has(order.id)
                const destination = orderDestination(order)
                return (
                  <li key={order.id} className="rounded-lg border p-3">
                    <div className="flex items-start gap-3">
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-lg font-bold tabular-nums">
                            #{order.orderNumber ?? "—"}
                          </span>
                          <Badge variant="secondary">{statusLabelFor(order)}</Badge>
                          <span className="text-sm text-muted-foreground tabular-nums">
                            {doneIso ? formatTime(doneIso) : "—"}
                          </span>
                          <Badge variant="outline" className="gap-1">
                            <destination.icon className="size-3.5" />
                            {destination.label}
                          </Badge>
                          {order.customerName && (
                            <span className="truncate text-sm text-muted-foreground">
                              {order.customerName}
                            </span>
                          )}
                        </div>

                        <ul className="mt-2 flex flex-col gap-1">
                          {items.map((item) => {
                            const visual = KDS_ITEM_VISUALS[item.status]
                            return (
                              <li
                                key={item.id}
                                className={`flex items-baseline gap-2 rounded px-2 py-1 text-sm ${
                                  item.status === "cancelled" ? "line-through opacity-50" : ""
                                }`}
                                style={{
                                  backgroundColor: visual.accent
                                    ? kdsTint(visual.accent, "soft")
                                    : undefined,
                                }}
                              >
                                <span className="font-bold tabular-nums">{item.qty}×</span>
                                <span className="min-w-0 flex-1 truncate">{item.name}</span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                  {visual.label}
                                </span>
                              </li>
                            )
                          })}
                          {items.length === 0 && (
                            <li className="px-2 py-1 text-sm text-muted-foreground">
                              Sin ítems para esta estación.
                            </li>
                          )}
                        </ul>
                      </div>

                      <div className="flex w-32 shrink-0 justify-end">
                        {reason === null ? (
                          <Button
                            type="button"
                            variant="outline"
                            className="h-11 w-full gap-2"
                            disabled={busy}
                            onClick={() => onRecall(order)}
                          >
                            <Undo2 className="size-5" />
                            Devolver
                          </Button>
                        ) : (
                          // Motivo en vez de botón deshabilitado: quien mira la
                          // pantalla entiende por qué no vuelve sin preguntar.
                          <span className="text-right text-sm text-muted-foreground">{reason}</span>
                        )}
                      </div>
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
