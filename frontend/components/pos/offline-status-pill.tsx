"use client"

/**
 * EL indicador de ESTADO de la caja. Uno solo, y vive arriba de la toolbar
 * del carrito (montado en `cart-panel.tsx`).
 *
 * Cubre tres estados, con prioridad explícita:
 *   1. pendientes FALLIDOS (destructivo) — terminal, no se resuelve al volver
 *      la conexión y exige que alguien lo mire (context/08 §53). Cuenta tanto
 *      ventas como operaciones de configuración y de caja: un cierre de caja
 *      que el servidor rechazó es plata contada que quedó en el aire, y no hay
 *      motivo para que grite menos fuerte que una venta;
 *   2. sin conexión (ámbar), con la cola pendiente si la hay;
 *   3. sincronizando.
 *
 * Lo que NO va acá: los IMPEDIMENTOS. La tenencia de caja se avisó un rato en
 * este pill ("Caja tomada por X — no se puede facturar") y estuvo mal por dos
 * razones (owner, 2026-08-23): un impedimento se informa en el control que
 * impide —el botón de cobrar, ver `CartBottom` en `cart-panel.tsx`—, no en un
 * cartel arriba del carrito; y como el bloqueo tenía prioridad sobre todo lo
 * demás, tapaba el aviso de "sin conexión" justo en el escenario donde los dos
 * pasan juntos. Estado y impedimento son cosas distintas y viven en lugares
 * distintos; ninguna de las dos se muestra dos veces.
 *
 * Las ventas EN COLA no se avisan con una banda: no requieren atención (se
 * sincronizan solas). Su señal es el punto en el icono del menú del POS
 * (`pos-main-menu.tsx`), y el detalle vive en Menú → Ventas pendientes —
 * adonde lleva este pill cuando hay algo que revisar.
 *
 * Regla del POS que gobierna todo esto: las señales de estado no pueden mover
 * de lugar lo que ya está en pantalla — la memoria muscular del cajero es
 * parte de la interfaz.
 */
import * as React from "react"
import { CloudOff, RefreshCw } from "lucide-react"
import { cn } from "@/lib/utils"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"
import { usePosUIStore } from "@/lib/ui/store"

/**
 * `savedAt` es un instante real en UTC (`new Date().toISOString()`), no un
 * timestamp naive de Postgres — los helpers de `lib/format-date` stripean la
 * `Z` a propósito para los segundos, así que acá corresponde el formateo del
 * locale del device.
 */
function formatSnapshotAge(iso: string): string | null {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleString(undefined, {
    day: "numeric",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  })
}

/**
 * `navigator.onLine` como external store. `useSyncExternalStore` en vez de
 * `useState` + `useEffect`: el valor VIVE afuera de React, así que suscribirse
 * es la primitiva correcta — y además da el valor real en el primer paint
 * (cliente), sin el frame intermedio en "online" que tenía la versión con
 * estado local. El snapshot del server es `true`: en SSR no hay navigator y
 * asumir "hay conexión" evita pintar el aviso en el HTML inicial.
 */
function subscribeOnline(onChange: () => void): () => void {
  window.addEventListener("online", onChange)
  window.addEventListener("offline", onChange)
  return () => {
    window.removeEventListener("online", onChange)
    window.removeEventListener("offline", onChange)
  }
}

export function OfflineStatusPill() {
  const isOnline = React.useSyncExternalStore(
    subscribeOnline,
    () => navigator.onLine,
    () => true,
  )
  const pendingCount = useOfflineSyncStore((s) => s.pendingCount)
  const failedCount = useOfflineSyncStore((s) => s.failedCount)
  // Operaciones de configuración y de caja (`lib/pos/pending-ops.ts`). Entran
  // en ESTE indicador y no en uno propio: el owner pidió un solo aviso de
  // estado en la caja (2026-08-23) y un cartel más repetiría el error que se
  // acaba de arreglar.
  const pendingOpsCount = useOfflineSyncStore((s) => s.pendingOpsCount)
  const failedOpsCount = useOfflineSyncStore((s) => s.failedOpsCount)
  const isSyncing = useOfflineSyncStore((s) => s.isSyncing)
  const fromCache = useOfflineSyncStore((s) => s.catalogFromCache)
  const cachedAt = useOfflineSyncStore((s) => s.catalogCachedAt)
  const openMenuSection = usePosUIStore((s) => s.openMenuSection)

  // `fromCache` además de `!isOnline`: `navigator.onLine` miente seguido (dice
  // `true` con un cable conectado a un router sin salida, o con el server
  // caído). Que el catálogo haya salido del snapshot es la señal DURA de que
  // esta caja no está hablando con el servidor.
  const degraded = !isOnline || fromCache
  const syncing = isSyncing && pendingCount > 0

  // UN solo aviso de estado en toda la caja, con prioridad explícita
  // (2026-08-23): las ventas fallidas ganan sobre sin-conexión, y sin-conexión
  // sobre sincronizando. Antes esto se repartía entre este pill y una banda
  // `OfflineBanner` montada en otro punto del carrito, así que con dos estados
  // simultáneos se apilaban dos franjas y empujaban la toolbar hacia abajo.
  if (failedCount === 0 && failedOpsCount === 0 && !degraded && !syncing) return null

  // Con conexión y sin nada roto, las operaciones en cola NO se avisan acá: se
  // sincronizan solas en segundos. Sin conexión sí, porque ahí el cajero
  // necesita saber que su cierre de caja todavía no salió del aparato.
  const queueParts: string[] = []
  if (pendingCount > 0) {
    queueParts.push(`${pendingCount} venta${pendingCount !== 1 ? "s" : ""}`)
  }
  if (pendingOpsCount > 0) {
    queueParts.push(`${pendingOpsCount} cambio${pendingOpsCount !== 1 ? "s" : ""}`)
  }
  const queueLabel = queueParts.length > 0 ? ` · ${queueParts.join(" y ")} en cola` : ""
  const snapshotAge = degraded && cachedAt ? formatSnapshotAge(cachedAt) : null

  // Terminal: no se resuelve al volver la conexión, exige que alguien la mire
  // (context/08 §53). Por eso es el único estado que se pinta en destructivo.
  //
  // Una OPERACIÓN fallida escala igual que una venta fallida, y con más razón
  // cuando lo que falló es un cierre de caja: eso es plata contada esperando a
  // que alguien la mire. Un ajuste rechazado usa el mismo canal porque no hay
  // forma de saber cuál de los dos es desde acá, y errar hacia "avisar de más"
  // es el lado correcto.
  const failed = failedCount > 0 || failedOpsCount > 0
  const failedTotal = failedCount + failedOpsCount

  const content = (
    <>
      {failed || degraded ? (
        <CloudOff className="size-3.5 shrink-0" />
      ) : (
        <RefreshCw className="size-3.5 shrink-0 animate-spin" />
      )}
      <span className="truncate">
        {failed
          ? `${failedTotal} pendiente${failedTotal !== 1 ? "s" : ""} con error — tocá para revisar`
          : degraded
            ? `Sin conexión${queueLabel}`
            : `Sincronizando ${pendingCount}`}
      </span>
    </>
  )

  const className = cn(
    "flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium shadow-sm",
    failed
      ? "border-destructive/30 bg-destructive/10 text-destructive"
      : degraded
        ? "border-amber-500/30 bg-amber-500/15 text-amber-900 backdrop-blur dark:text-amber-100"
        : "border-border bg-background/90 text-muted-foreground backdrop-blur",
  )

  return (
    // Vive DENTRO del carrito, arriba de la toolbar (montado en
    // `cart-panel.tsx`). Ya no flota en absolute sobre el workspace: el owner
    // pidió un único aviso y en ese lugar. `shrink-0` para que la columna del
    // carrito no lo comprima cuando la lista de ítems crece.
    <div className="flex shrink-0 justify-center px-2 pt-2">
      {pendingCount > 0 || pendingOpsCount > 0 || failed ? (
        <button
          type="button"
          onClick={() => openMenuSection("sync-queue")}
          className={cn(className, "transition-colors hover:brightness-95")}
          title={snapshotAge ? `Catálogo local del ${snapshotAge}` : undefined}
        >
          {content}
        </button>
      ) : (
        <div
          className={className}
          title={snapshotAge ? `Catálogo local del ${snapshotAge}` : undefined}
        >
          {content}
        </div>
      )}
    </div>
  )
}
