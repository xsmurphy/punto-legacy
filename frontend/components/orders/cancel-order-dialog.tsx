"use client"

/**
 * Cancelar la ORDEN ENTERA, con motivo obligatorio.
 *
 * Fuente ÚNICA de este diálogo para las dos superficies que lo ofrecen:
 * `OrderCard` (vista Cuadros de /pos/ordenes) y `OrderDetailView` (diálogo de
 * detalle de Lista/Mapa). Estaba duplicado byte por byte en los dos: solo el
 * ESTADO se compartía, vía `useOrderActions`. El JSX no, y por eso este cambio
 * —agregar el error inline y el aviso de la ventana— habría que haberlo hecho
 * dos veces, con una de las dos pantallas quedándose sin él tarde o temprano.
 * Es el mismo movimiento que ya hizo `CancelOrderItemDialog` para el grano
 * ítem.
 *
 * ── Por qué el error va INLINE y el diálogo no se cierra ───────────────────
 *
 * Desde 2026-09-06 cancelar una orden entera pasa por `OrderCancelGate`: puede
 * rebotar con 403 (falta `pos.order.item.cancel`) o 422 (fuera de la ventana
 * del comercio, sin `.late`). Antes no rebotaba nunca, así que `confirmCancel`
 * cerraba el diálogo de una y mandaba cualquier error a un toast — con el
 * motivo ya tipeado perdido y el cajero sin saber a quién llamar.
 *
 * Ahora el diálogo queda abierto con el motivo intacto y el error explicado
 * abajo, para que el encargado tome la posta en la misma pantalla sin volver a
 * escribir nada (context/14 §Toasts — los errores de validación no van a
 * toast). Mismo comportamiento que el diálogo del ítem, porque para el cajero
 * son la misma situación.
 */

import * as React from "react"

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
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { useCatalogStore } from "@/lib/catalog/store"
import {
  POS_REGISTER_CONFIG_DEFAULTS,
  usePosRegisterConfig,
} from "@/hooks/use-pos-config"
import { minutes } from "@/lib/orders/cancel-error"
import type { OrderActions } from "@/hooks/use-order-actions"

export function CancelOrderDialog({
  orderId,
  orderNumber,
  actions,
}: {
  orderId: string
  orderNumber: number | null
  /**
   * El objeto que devuelve `useOrderActions` — el estado del diálogo (abierto,
   * motivo, error, pendiente) vive ahí y no acá porque los call-sites también
   * disparan la apertura desde su propio botón.
   */
  actions: OrderActions
}) {
  const {
    cancelOpen,
    setCancelOpen,
    cancelReason,
    setCancelReason,
    cancelError,
    cancelPending,
    confirmCancel,
  } = actions

  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: registerConfig } = usePosRegisterConfig(activeRegisterId)
  // La MISMA ventana que la del ítem: un solo ajuste del comercio gobierna los
  // dos granos, igual que un solo gate los valida server-side.
  const windowMinutes =
    registerConfig?.config?.orderItemCancelWindowMinutes ??
    POS_REGISTER_CONFIG_DEFAULTS.orderItemCancelWindowMinutes

  const reasonId = `cancel-reason-${orderId}`

  function submit(event: React.MouseEvent) {
    // Sin esto Radix cierra el diálogo al click, y un rechazo del server
    // (permiso, ventana vencida) se perdería junto con el motivo ya tipeado.
    event.preventDefault()
    confirmCancel()
  }

  return (
    <AlertDialog
      open={cancelOpen}
      onOpenChange={(v) => {
        setCancelOpen(v)
        if (!v) setCancelReason("")
      }}
    >
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            ¿Cancelar la orden {orderNumber !== null ? `#${orderNumber}` : ""}?
          </AlertDialogTitle>
          <AlertDialogDescription>
            La orden no se elimina: queda cancelada y sale de las pantallas
            operativas. El motivo queda registrado en su historial y la
            anulación figura en el cierre de caja del turno.
          </AlertDialogDescription>
        </AlertDialogHeader>
        {/* Motivo obligatorio — el backend rechaza la cancelación sin él. */}
        <div className="flex flex-col gap-2">
          <Label htmlFor={reasonId}>Motivo de la cancelación</Label>
          <Textarea
            id={reasonId}
            value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)}
            placeholder="Ej.: el cliente se retiró, error de carga, faltó stock"
            rows={3}
          />
          {windowMinutes > 0 && (
            <p className="text-sm text-muted-foreground">
              Este comercio permite cancelar una orden hasta{" "}
              {minutes(windowMinutes)} después de abierta. Pasado ese tiempo la
              tiene que cancelar un encargado.
            </p>
          )}
          {cancelError && <p className="text-sm text-destructive">{cancelError}</p>}
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel>Volver</AlertDialogCancel>
          <AlertDialogAction
            onClick={submit}
            disabled={cancelReason.trim() === "" || cancelPending}
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
          >
            {cancelPending ? "Cancelando..." : "Cancelar orden"}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
