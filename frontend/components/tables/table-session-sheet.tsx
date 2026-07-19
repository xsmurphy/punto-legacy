"use client"

/**
 * Sheet de detalle de una mesa ocupada/pagando (context/15-mesas-module-plan.md
 * F2). Lista las órdenes de la sesión (todas, cualquier status — historial
 * completo de rondas) y expone las acciones de mesa: Agregar orden, Pedir
 * cuenta, Cobrar, Cancelar sesión (solo si no hay órdenes activas).
 */

import * as React from "react"
import { Loader2, Plus, Receipt, Ban, CreditCard } from "lucide-react"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetFooter,
} from "@/components/ui/sheet"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { useOrdersBySession, ACTIVE_ORDER_STATUSES, type OrderStatus } from "@/hooks/use-orders"
import type { DiningTableWithState } from "@/hooks/use-pos-tables"

const STATUS_LABEL: Record<OrderStatus, string> = {
  open: "Abierta",
  sent: "Enviada",
  in_progress: "En preparación",
  ready: "Lista",
  delivered: "Entregada",
  closed: "Cobrada",
  cancelled: "Cancelada",
}

interface Props {
  table: DiningTableWithState | null
  onOpenChange: (open: boolean) => void
  onAddOrder: () => void
  onRequestBill: () => void
  onCharge: () => void
  onCancelSession: () => void
  requestBillPending: boolean
  cancelPending: boolean
  chargePending: boolean
}

export function TableSessionSheet({
  table,
  onOpenChange,
  onAddOrder,
  onRequestBill,
  onCharge,
  onCancelSession,
  requestBillPending,
  cancelPending,
  chargePending,
}: Props) {
  const sessionId = table?.session?.id ?? null
  const { data, isLoading } = useOrdersBySession(sessionId)
  const orders = data?.orders ?? []
  const hasActiveOrders = orders.some((o) => ACTIVE_ORDER_STATUSES.includes(o.status))
  const canCancel = !hasActiveOrders

  return (
    <Sheet open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
      <SheetContent side="right" className="flex w-full flex-col sm:max-w-md">
        <SheetHeader>
          <SheetTitle>
            Mesa {table?.name}
            {table?.session?.guests ? ` · ${table.session.guests} comensales` : ""}
          </SheetTitle>
        </SheetHeader>

        <div className="flex-1 overflow-y-auto px-4">
          {isLoading ? (
            <div className="flex justify-center py-8">
              <Loader2 className="size-5 animate-spin text-muted-foreground" />
            </div>
          ) : orders.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Sin órdenes todavía.
            </p>
          ) : (
            <ul className="flex flex-col gap-2 py-2">
              {orders.map((o) => (
                <li
                  key={o.id}
                  className="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2"
                >
                  <span className="text-sm font-medium text-foreground">
                    Orden #{o.orderNumber ?? "—"}
                  </span>
                  <Badge variant="outline">{STATUS_LABEL[o.status]}</Badge>
                </li>
              ))}
            </ul>
          )}
        </div>

        <SheetFooter className="flex-col gap-2 sm:flex-col">
          <Button onClick={onAddOrder} className="w-full gap-1.5">
            <Plus className="size-4" />
            Agregar orden
          </Button>
          {table?.state !== "bill_requested" && (
            <Button
              variant="outline"
              onClick={onRequestBill}
              disabled={requestBillPending}
              className="w-full gap-1.5"
            >
              <Receipt className="size-4" />
              {requestBillPending ? "Pidiendo cuenta..." : "Pedir cuenta"}
            </Button>
          )}
          <Button
            variant="outline"
            onClick={onCharge}
            disabled={chargePending || orders.length === 0}
            className="w-full gap-1.5"
          >
            <CreditCard className="size-4" />
            {chargePending ? "Preparando cobro..." : "Cobrar"}
          </Button>
          <Button
            variant="ghost"
            onClick={onCancelSession}
            disabled={!canCancel || cancelPending}
            title={!canCancel ? "No se puede cancelar: la mesa tiene órdenes activas" : undefined}
            className="w-full gap-1.5 text-destructive hover:text-destructive"
          >
            <Ban className="size-4" />
            {cancelPending ? "Cancelando..." : "Cancelar sesión"}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
