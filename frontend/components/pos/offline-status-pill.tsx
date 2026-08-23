"use client"

/**
 * Indicador de "la caja está operando sin conexión" — y cuántas ventas hay
 * esperando para subir.
 *
 * Por qué es un pill flotante y no una banda
 * ──────────────────────────────────────────
 * Es un elemento que aparece y desaparece SOLO (se corta el wifi, vuelve, un
 * ciclo de sync arranca y termina). Como banda en el flujo del layout empujaba
 * todo el workspace hacia abajo cada vez — botones incluidos — y el docblock
 * del layout ya registraba que "parpadeaba en cada ciclo de sync". Eso rompe
 * la memoria muscular del cajero, que es una regla dura del POS: las señales
 * de estado se pintan SOBRE elementos fijos, nunca insertando bloques que
 * muevan lo que ya está en pantalla.
 *
 * Va `absolute` sobre el workspace, en la esquina inferior izquierda: no ocupa
 * lugar en el flujo, así que su aparición no mueve nada, y en esa esquina no
 * tapa ni la grilla de hotkeys ni el carrito ni el CTA de cobro.
 *
 * NO cubre el caso terminal ("N ventas no se pudo sincronizar"): ese sigue
 * siendo `OfflineBanner`, a propósito. Es un estado que no se resuelve solo y
 * el owner pidió explícitamente que no quedara escondido en un indicador
 * chico (context/08 §53) — ahí el desplazamiento del layout es el precio
 * correcto, y además es un estado raro y persistente, no un parpadeo.
 */

import * as React from "react"
import { CloudOff, RefreshCw } from "lucide-react"
import { cn } from "@/lib/utils"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"

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
  const isSyncing = useOfflineSyncStore((s) => s.isSyncing)
  const fromCache = useOfflineSyncStore((s) => s.catalogFromCache)
  const cachedAt = useOfflineSyncStore((s) => s.catalogCachedAt)
  const setQueueDialogOpen = useOfflineSyncStore((s) => s.setQueueDialogOpen)

  // `fromCache` además de `!isOnline`: `navigator.onLine` miente seguido (dice
  // `true` con un cable conectado a un router sin salida, o con el server
  // caído). Que el catálogo haya salido del snapshot es la señal DURA de que
  // esta caja no está hablando con el servidor.
  const degraded = !isOnline || fromCache
  const syncing = isSyncing && pendingCount > 0

  // El caso terminal es del banner, no de acá — si además hay fallidas, el
  // pill se calla para no duplicar el mensaje.
  if (failedCount > 0) return null
  if (!degraded && !syncing) return null

  const queueLabel =
    pendingCount > 0
      ? ` · ${pendingCount} venta${pendingCount !== 1 ? "s" : ""} en cola`
      : ""
  const snapshotAge = degraded && cachedAt ? formatSnapshotAge(cachedAt) : null

  const content = (
    <>
      {degraded ? (
        <CloudOff className="size-3.5 shrink-0" />
      ) : (
        <RefreshCw className="size-3.5 shrink-0 animate-spin" />
      )}
      <span className="truncate">
        {degraded ? `Sin conexión${queueLabel}` : `Sincronizando ${pendingCount}`}
      </span>
    </>
  )

  const className = cn(
    "flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium shadow-sm",
    degraded
      ? "border-amber-500/30 bg-amber-500/15 text-amber-900 backdrop-blur dark:text-amber-100"
      : "border-border bg-background/90 text-muted-foreground backdrop-blur",
  )

  return (
    // Vive DENTRO del carrito, arriba de la toolbar (montado en
    // `cart-panel.tsx`). Ya no flota en absolute sobre el workspace: el owner
    // pidió un único aviso y en ese lugar. `shrink-0` para que la columna del
    // carrito no lo comprima cuando la lista de ítems crece.
    <div className="flex shrink-0 justify-center px-2 pt-2">
      {pendingCount > 0 ? (
        <button
          type="button"
          onClick={() => setQueueDialogOpen(true)}
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
