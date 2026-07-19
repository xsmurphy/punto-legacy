"use client"

/**
 * Dialog de detalle de un espacio ocupado/pagando (context/15-espacios-module-plan.md
 * F2). Lista las órdenes de la sesión (todas, cualquier status — historial
 * completo de rondas) y expone las acciones del espacio: Agregar orden, Pedir
 * cuenta, Cobrar, Cancelar sesión (solo si no hay órdenes activas).
 *
 * Modal centrado (no Sheet lateral) — convención transversal del owner:
 * Dialog es el default para paneles contextuales (ver context/14 §2.2). Botones
 * a ancho completo y size lg porque el POS se opera con el dedo en tablet.
 */

import * as React from "react"
import { Loader2, Plus, Receipt, Ban, CreditCard, ClipboardList } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { EmptyState } from "@/components/empty-state"
import { useOrdersBySession, ACTIVE_ORDER_STATUSES, type OrderStatus } from "@/hooks/use-orders"
import type { SpaceWithState } from "@/hooks/use-pos-spaces"

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
  table: SpaceWithState | null
  onOpenChange: (open: boolean) => void
  onAddOrder: () => void
  onRequestBill: () => void
  onCharge: () => void
  onCancelSession: () => void
  requestBillPending: boolean
  cancelPending: boolean
  chargePending: boolean
}

export function SpaceSessionDialog({
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
    <Dialog open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">{table?.name}</DialogTitle>
          <DialogDescription>
            {table?.session?.guests
              ? `${table.session.guests} comensales`
              : "Órdenes de la sesión activa"}
          </DialogDescription>
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

        <DialogFooter className="flex-col gap-2 sm:flex-col">
          {/* size lg + w-full: touch targets grandes para operar con el dedo. */}
          <Button size="lg" onClick={onAddOrder} className="w-full gap-1.5">
            <Plus className="size-4" />
            Agregar orden
          </Button>
          {table?.state !== "bill_requested" && (
            <Button
              size="lg"
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
            size="lg"
            variant="outline"
            onClick={onCharge}
            disabled={chargePending || orders.length === 0}
            className="w-full gap-1.5"
          >
            <CreditCard className="size-4" />
            {chargePending ? "Preparando cobro..." : "Cobrar"}
          </Button>
          <Button
            size="lg"
            variant="ghost"
            onClick={onCancelSession}
            disabled={!canCancel || cancelPending}
            title={!canCancel ? "No se puede cancelar: el espacio tiene órdenes activas" : undefined}
            className="w-full gap-1.5 text-destructive hover:text-destructive"
          >
            <Ban className="size-4" />
            {cancelPending ? "Cancelando..." : "Cancelar sesión"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
