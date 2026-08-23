"use client"

/**
 * "Eliminar dispositivo del comercio" — desvinculación EXPLÍCITA del device
 * desde Ajustes del POS.
 *
 * Dos responsabilidades que antes no tenía, cuando esto era un `AlertDialog`
 * inline en `pos-main-menu.tsx`:
 *
 * 1. **Purga total de los datos locales.** El device guarda en IndexedDB un
 *    snapshot del bootstrap para poder arrancar sin red, y ahí viaja la lista
 *    de clientes del comercio (PII). Desvincular el device tiene que llevarse
 *    eso puesto — junto con los caches HTTP de `/api/pos/*` del Service
 *    Worker. Ver `purgeAllOfflineData()` en `lib/pos/offline-db.ts`.
 *
 * 2. **Avisar por las ventas sin sincronizar.** La purga total incluye la cola
 *    offline, y ahí puede haber ventas YA EMITIDAS E IMPRESAS que el backend
 *    todavía no recibió. Son documentos fiscales que existen en papel y en
 *    ningún otro lado: el operador tiene que enterarse ANTES, no descubrirlo
 *    después. Con la cola vacía (el caso normal) el copy es el de siempre.
 *
 * El orden importa: primero se revoca server-side, y recién con el OK del
 * server se borra lo local. Al revés, un fallo de red dejaría el device sin
 * datos y todavía pareado.
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
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { Button } from "@/components/ui/button"
import { toast } from "sonner"
import { posFetch } from "@/lib/api/pos-fetch"
import { getDeviceClaims } from "@/lib/auth/device-claims"
import { getCount } from "@/lib/pos/offline-queue"
import { purgeAllOfflineData } from "@/lib/pos/offline-db"

export function RemoveDeviceDialog() {
  const [pendingSales, setPendingSales] = React.useState(0)
  const [busy, setBusy] = React.useState(false)

  // Se relee al ABRIR y no al montar: el menú de ajustes vive montado durante
  // todo el turno y la cola cambia debajo.
  async function handleOpenChange(open: boolean) {
    if (open) setPendingSales(await getCount())
  }

  async function handleRemove() {
    setBusy(true)
    try {
      const deviceId = getDeviceClaims("pos")?.deviceId ?? null
      const res = await posFetch("/api/pos/revoke-this-device", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ deviceId }),
      })
      if (!res.ok) {
        const data = await res.json().catch(() => ({}))
        toast.error(
          (data as { error?: { message?: string } }).error?.message ??
            "Error al eliminar el dispositivo",
        )
        return
      }
      // Recién ahora: el device ya no existe server-side, así que nada de lo
      // local puede volver a usarse. Se espera el borrado antes de recargar —
      // un `location.href` inmediato mataría el tab a mitad de la transacción
      // de IndexedDB y dejaría la PII a medio borrar.
      await purgeAllOfflineData()
      // El device fue revocado server-side; recargar /pos hace que
      // PosAuthGuard re-evalúe y muestre DeviceNotConnected.
      window.location.href = "/pos"
    } catch {
      toast.error("Error al eliminar el dispositivo")
    } finally {
      setBusy(false)
    }
  }

  return (
    <AlertDialog onOpenChange={handleOpenChange}>
      <AlertDialogTrigger asChild>
        <Button variant="ghost" className="text-destructive hover:text-destructive">
          Eliminar dispositivo del comercio
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Eliminar dispositivo del comercio</AlertDialogTitle>
          <AlertDialogDescription>
            {pendingSales > 0 ? (
              <>
                Hay {pendingSales} venta{pendingSales !== 1 ? "s" : ""} emitida
                {pendingSales !== 1 ? "s" : ""} en este dispositivo que todavía no
                llegó al servidor. Si lo eliminás ahora, {pendingSales !== 1 ? "esas ventas se pierden" : "esa venta se pierde"}.
                Conectate a internet y esperá a que se sincronicen antes de continuar.
              </>
            ) : (
              <>
                Esta acción desvinculará este dispositivo de la caja y borrará los datos
                del comercio guardados en él. Tendrás que volver a parearlo para usar el POS.
              </>
            )}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancelar</AlertDialogCancel>
          <AlertDialogAction
            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            disabled={busy}
            onClick={(e) => {
              // El AlertDialogAction cierra el diálogo al click; sin esto el
              // componente se desmonta a mitad del await y el `finally` corre
              // sobre un árbol muerto.
              e.preventDefault()
              void handleRemove()
            }}
          >
            {pendingSales > 0 ? "Eliminar igual" : "Eliminar"}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
