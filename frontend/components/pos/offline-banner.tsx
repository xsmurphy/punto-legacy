'use client'

import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { useOnlineStatus } from '@/hooks/use-online-status'

export function OfflineBanner() {
  // Estado de red: wrapper compartido (hooks/use-online-status), el mismo que
  // usa el gate de los medios de pago de pasarela en el cobro.
  const isOnline = useOnlineStatus()
  const pendingCount = useOfflineSyncStore((s) => s.pendingCount)
  const isSyncing = useOfflineSyncStore((s) => s.isSyncing)
  const failedCount = useOfflineSyncStore((s) => s.failedCount)
  const setQueueDialogOpen = useOfflineSyncStore((s) => s.setQueueDialogOpen)

  // failedCount > 0 es TERMINAL (context/08 §53): no se resuelve solo al
  // volver la conexión, así que este banner se muestra SIEMPRE que haya
  // fallidas — incluso online y sin sync en curso — para que no quede
  // escondida detrás del indicador chico del carrito. Es clickeable: abre
  // el mismo `SyncQueueDialog` que el indicador del carrito.
  if (failedCount > 0) {
    return (
      <button
        onClick={() => setQueueDialogOpen(true)}
        className="shrink-0 border-b border-destructive/30 bg-destructive/10 px-3 py-2 text-center text-xs font-medium text-destructive transition-colors hover:bg-destructive/20"
      >
        {failedCount} venta{failedCount !== 1 ? 's' : ''} no se pudo sincronizar — tocá para revisar y reintentar
      </button>
    )
  }

  // Numeración (context/29): ya no hay "arriendo bajo/agotado" que avisar
  // con anticipación — el device suma +1 a su propio último correlativo
  // localmente, así que quedarse sin número es un caso rarísimo (un device
  // que JAMÁS tuvo bootstrap exitoso en esta caja), no un umbral que se
  // acerca durante el turno. Si pasa, `getNextInvoiceNo()` tira
  // `NO_INVOICE_NUMBER` y `pay-dialog.tsx` lo muestra como error puntual de
  // esa venta — no hace falta un banner permanente para esto.

  if (isOnline && !(isSyncing && pendingCount > 0)) return null

  return (
    <div className="shrink-0 border-b border-amber-500/20 bg-amber-500/10 px-3 py-2 text-center text-xs font-medium text-amber-900 dark:text-amber-100">
      {!isOnline
        ? 'Sin conexion — las ventas se guardaran y enviaran al volver online'
        : `Conectado · sincronizando ${pendingCount} venta${pendingCount !== 1 ? 's' : ''}`}
    </div>
  )
}
