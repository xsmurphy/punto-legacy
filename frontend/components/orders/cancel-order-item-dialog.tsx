"use client"

/**
 * Anular UN ítem de una comanda, con motivo obligatorio.
 *
 * Fuente ÚNICA de esta acción para las dos superficies que la ofrecen: el
 * diálogo de la mesa (`SpaceSessionDialog`, el caso real: cargan una mesa y
 * después sacan dos o tres productos) y el detalle de la orden
 * (`OrderDetailView`). Mismo criterio que `useOrderActions` para la
 * cancelación de la orden entera: el estado del motivo vive ACÁ y no suelto en
 * cada call-site, porque ese es justamente el hueco por el que se cuela una
 * pantalla que anula sin pedirlo.
 *
 * ── Por qué el error se explica en vez de solo mostrarse ────────────────────
 *
 * El copy de los rechazos NO vive acá: es `lib/orders/cancel-error.ts`, que lo
 * comparte con la cancelación de la orden entera y la de la mesa. Los tres
 * granos los gatea el MISMO `OrderCancelGate` del backend, con el mismo 422; si
 * el copy viviera en cada diálogo, cambiar la política obligaría a acordarse de
 * tres lugares.
 *
 * El error se pinta INLINE y no como toast: es la respuesta a lo que el
 * usuario acaba de hacer dentro de este diálogo, y el diálogo queda abierto
 * con el motivo tipeado intacto por si el encargado toma la posta en la misma
 * pantalla (context/14 §Toasts — los errores de validación no van a toast).
 */

import * as React from "react"
import { toast } from "sonner"

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
import { useCancelOrderItem, type OrderItem } from "@/hooks/use-orders"
import { cancelErrorMessage, minutes } from "@/lib/orders/cancel-error"

export function CancelOrderItemDialog({
  item,
  open,
  onOpenChange,
  onCancelled,
}: {
  /** El ítem a anular. `null` mientras el diálogo está cerrado. */
  item: OrderItem | null
  open: boolean
  onOpenChange: (open: boolean) => void
  /** La dispara el call-site para refrescar/cerrar lo suyo tras el éxito. */
  onCancelled?: () => void
}) {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const { data: registerConfig } = usePosRegisterConfig(activeRegisterId)
  const cancelItem = useCancelOrderItem()

  const [reason, setReason] = React.useState("")
  const [error, setError] = React.useState<string | null>(null)

  const windowMinutes =
    registerConfig?.config?.orderItemCancelWindowMinutes ??
    POS_REGISTER_CONFIG_DEFAULTS.orderItemCancelWindowMinutes

  // El diálogo arranca limpio cada vez que se abre: un motivo o un error de un
  // ítem anterior colgado acá sería peor que no mostrar nada.
  function handleOpenChange(next: boolean) {
    if (!next) {
      setReason("")
      setError(null)
    }
    onOpenChange(next)
  }

  function submit(event: React.MouseEvent) {
    // Sin esto Radix cierra el diálogo al click, y un rechazo del server
    // (permiso, ventana vencida) se perdería junto con el motivo ya tipeado.
    event.preventDefault()
    const trimmed = reason.trim()
    // El backend también lo rechaza; acá evitamos el round-trip.
    if (trimmed === "" || item === null) return
    setError(null)
    cancelItem.mutate(
      { orderItemId: item.id, reason: trimmed },
      {
        onSuccess: () => {
          toast.success("Ítem anulado")
          handleOpenChange(false)
          onCancelled?.()
        },
        onError: (err) => setError(cancelErrorMessage(err, "item")),
      },
    )
  }

  const reasonId = `cancel-item-reason-${item?.id ?? "none"}`

  return (
    <AlertDialog open={open} onOpenChange={handleOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>
            ¿Anular {item ? `${item.qty}× ${item.name}` : "el ítem"}?
          </AlertDialogTitle>
          <AlertDialogDescription>
            El ítem no se borra: queda anulado y deja de sumar al total. Sigue
            visible en la comanda digital —tachado— y el motivo queda registrado
            en el historial de la orden.
          </AlertDialogDescription>
        </AlertDialogHeader>

        {/* Motivo obligatorio — el backend rechaza la anulación sin él. */}
        <div className="flex flex-col gap-2">
          <Label htmlFor={reasonId}>Motivo de la anulación</Label>
          <Textarea
            id={reasonId}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Ej.: el cliente se arrepintió, se cargó de más, no había stock"
            rows={3}
          />
          {windowMinutes > 0 && (
            <p className="text-sm text-muted-foreground">
              Este comercio permite anular un ítem hasta {minutes(windowMinutes)}{" "}
              después de cargado. Pasado ese tiempo lo tiene que anular un
              encargado.
            </p>
          )}
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>

        <AlertDialogFooter>
          <AlertDialogCancel>Volver</AlertDialogCancel>
          <AlertDialogAction
            onClick={submit}
            disabled={reason.trim() === "" || cancelItem.isPending}
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
          >
            {cancelItem.isPending ? "Anulando..." : "Anular ítem"}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
