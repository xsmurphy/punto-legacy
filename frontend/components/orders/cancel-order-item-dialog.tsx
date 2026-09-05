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
 * En una caja, "no se pudo" sin decir qué falta es una llamada al encargado.
 * Los tres rechazos del backend se traducen a copy accionable: falta permiso,
 * motivo vacío, y —el que importa— ventana de anulación vencida, que dice
 * cuántos minutos pasaron, cuántos permite el comercio y que a partir de ahí
 * lo tiene que hacer un encargado. Mismo criterio que `recallBlockReason`
 * (`lib/kds/board.ts`).
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
import {
  OrderApiError,
  useCancelOrderItem,
  type OrderItem,
} from "@/hooks/use-orders"

/** "1 minuto" / "12 minutos" — el plural del copy, sin helper de formato. */
function minutes(n: number): string {
  const v = Math.max(0, Math.round(n))
  return v === 1 ? "1 minuto" : `${v} minutos`
}

/**
 * Traduce el rechazo del backend a algo que el cajero pueda usar.
 *
 * Exportada para poder probarla sin montar el diálogo, y porque es la única
 * definición del copy de estos tres errores: si mañana otra superficie ofrece
 * la anulación, reusa esto en vez de reinventar los mensajes.
 */
export function cancelItemErrorMessage(err: unknown): string {
  if (err instanceof OrderApiError) {
    const details = err.details
    if (err.status === 403) {
      return `${err.message} Esta anulación la tiene que hacer un encargado con su usuario.`
    }
    if (details?.code === "cancel_window_expired") {
      const windowMinutes = details.windowMinutes
      const elapsedMinutes = details.elapsedMinutes
      if (typeof windowMinutes === "number" && typeof elapsedMinutes === "number") {
        return (
          `Pasaron ${minutes(elapsedMinutes)} desde que se cargó el ítem y el comercio ` +
          `permite anularlo hasta ${minutes(windowMinutes)}. ` +
          `A partir de ahí lo tiene que anular un encargado con su usuario.`
        )
      }
      return `${err.message} Lo tiene que anular un encargado con su usuario.`
    }
    return err.message
  }
  return err instanceof Error ? err.message : "No se pudo anular el ítem."
}

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
        onError: (err) => setError(cancelItemErrorMessage(err)),
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
