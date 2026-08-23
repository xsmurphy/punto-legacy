'use client'

import * as React from 'react'
import { peekAll, markSynced, markFailed, markRetry, markSyncing, getCount, getFailedCount, revivePendingAfterTenancy, type OfflineSaleRow } from '@/lib/pos/offline-queue'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { posApi as api } from '@/lib/api/pos-client'
import { useCatalogStore } from '@/lib/catalog/store'
import { ensureTenancy } from '@/lib/pos/register-tenancy'

interface SyncResult {
  clientTempId: string
  ok: boolean
  transactionId?: string
  duplicated?: boolean
  error?: { code: string; message: string }
}

// Solo estos errores son TRANSITORIOS (se reintentan solos con backoff).
// Cualquier otro —validaciones de negocio, STOCK_OUT, NUMBER_TAKEN— es
// TERMINAL: reintentarlo nunca va a funcionar y generaba un bucle infinito de
// POSTs (auto-DDoS con N cajas). Los terminales quedan en 'failed' para
// revisión manual del operador.
const RETRYABLE_CODES = new Set(['NETWORK_ERROR', 'INTERRUPTED'])
const MAX_ATTEMPTS = 6
const BASE_BACKOFF_MS = 30_000
const MAX_BACKOFF_MS = 30 * 60_000

/** ¿Pasó el backoff exponencial (sobre attempts/lastAttemptAt) desde el último intento? */
function backoffElapsed(row: OfflineSaleRow): boolean {
  if (!row.lastAttemptAt) return true
  const delay = Math.min(MAX_BACKOFF_MS, BASE_BACKOFF_MS * 2 ** Math.max(0, row.attempts - 1))
  return Date.now() - new Date(row.lastAttemptAt).getTime() >= delay
}

/** Aplica el resultado de un error: reintento transitorio (con tope) o falla terminal. */
async function applyError(clientTempId: string, attempts: number, error: { code: string; message: string }): Promise<void> {
  if (RETRYABLE_CODES.has(error.code) && attempts + 1 < MAX_ATTEMPTS) {
    await markRetry(clientTempId, error)
  } else {
    await markFailed(clientTempId, error)
  }
}

export function useOfflineSync() {
  const setPendingCount = useOfflineSyncStore((s) => s.setPendingCount)
  const setFailedCount = useOfflineSyncStore((s) => s.setFailedCount)
  const setIsSyncing = useOfflineSyncStore((s) => s.setIsSyncing)
  const setLastSyncAt = useOfflineSyncStore((s) => s.setLastSyncAt)

  const syncRef = React.useRef(false)

  const refreshCounts = React.useCallback(async () => {
    const [count, failed] = await Promise.all([getCount(), getFailedCount()])
    setPendingCount(count)
    setFailedCount(failed)
  }, [setPendingCount, setFailedCount])

  const runSync = React.useCallback(async () => {
    if (syncRef.current) return
    if (!navigator.onLine) return

    // El mutex se toma ANTES del primer `await`, no después. El chequeo de
    // tenencia y el revive de abajo son asíncronos, así que dos disparos
    // simultáneos —el tick del intervalo y el evento `online`, que en una
    // reconexión caen casi juntos, justo el escenario que este arreglo
    // ataca— pasaban los dos el guard de arriba y armaban el MISMO lote dos
    // veces. La deduplicación por uid del backend lo atajaba, pero
    // depender de eso es pedirle al servidor que arregle una carrera del
    // cliente.
    syncRef.current = true
    // Declarado FUERA del try porque el `catch` de abajo lo necesita para
    // marcar el reintento de cada venta del lote.
    let toSync: OfflineSaleRow[] = []
    try {
      // Tenencia ANTES de postear (incidente 2026-08-23). Sin esto había una
      // carrera real: al volver la red, el evento `online` disparaba este sync
      // en paralelo con el claim del arranque, así que el lote llegaba a
      // `offline-sync.php` cuando `register_lease` todavía estaba vacía y las
      // ventas se rechazaban por "caja sin tenencia" con la caja LIBRE — y el
      // rechazo era terminal. Confirmar primero convierte esa carrera en una
      // secuencia.
      //
      // `ensureTenancy()` es barato cuando el veredicto ya es `ok` (no toca la
      // red), así que se puede llamar en cada ciclo. Si NO se puede confirmar,
      // no se postea nada: las ventas quedan 'pending' y se reintentan en el
      // próximo ciclo. Nunca se marcan 'failed' por esto — no hubo respuesta
      // del servidor que lo justifique.
      const registerId = useCatalogStore.getState().activeRegisterId
      if (registerId) {
        const verdict = await ensureTenancy(registerId)
        if (!verdict.canIssue) return
        // Con la tenencia recuperada, las ventas que habían fallado por una
        // tenencia que ya no aplica vuelven a la cola: es el caso inevitable
        // del diseño (le sacaron la caja al device mientras estaba offline) y
        // así se resuelve solo, sin que el operador tenga que abrir el
        // diálogo. Lo que NO vuelve es lo que falló por otra causa (stock,
        // número tomado, payload inválido) ni por `REGISTER_TAKEN`.
        await revivePendingAfterTenancy()
      }

      const pending = await peekAll()
      // Solo reintentamos 'pending' cuyo backoff ya venció. 'failed' es
      // TERMINAL (no se reintenta) → así los rechazos de negocio no generan el
      // bucle infinito.
      toSync = pending.filter((r) => r.status === 'pending' && backoffElapsed(r))
      if (toSync.length === 0) return

      setIsSyncing(true)
      await Promise.all(toSync.map((r) => markSyncing(r.clientTempId)))

      const body = {
        sales: toSync.map((r) => ({
          clientTempId: r.clientTempId,
          invoiceNo: r.invoiceNo,
          sale: r.sale,
        })),
      }

      const response = await api.post<{ results: SyncResult[] }>('/v1/offline-sync', body)
      const results: SyncResult[] = response?.results ?? []

      for (const res of results) {
        if (res.ok || res.duplicated) {
          await markSynced(res.clientTempId)
        } else if (res.error) {
          const attempts = toSync.find((r) => r.clientTempId === res.clientTempId)?.attempts ?? 0
          await applyError(res.clientTempId, attempts, res.error)
        }
      }

      setLastSyncAt(new Date().toISOString())
    } catch {
      // Falla de toda la request (red/servidor) → transitorio: reintento con tope.
      for (const r of toSync) {
        await applyError(r.clientTempId, r.attempts, { code: 'NETWORK_ERROR', message: 'Error de red al sincronizar' })
      }
    } finally {
      syncRef.current = false
      setIsSyncing(false)
      await refreshCounts()
    }
  }, [refreshCounts, setIsSyncing, setLastSyncAt])

  // Boot-time cleanup (P1 code-review): si el proceso crasheó mid-flight
  // (tab cerrado, error JS durante el sync), los items quedan en 'syncing'.
  // Al montar los volvemos a 'pending' (vía markRetry, transitorio) para que el
  // próximo ciclo los reintente — respetando el tope de attempts/backoff.
  React.useEffect(() => {
    async function bootCleanup() {
      const all = await peekAll()
      const stuck = all.filter((r) => r.status === 'syncing')
      await Promise.all(
        stuck.map((r) =>
          applyError(r.clientTempId, r.attempts, {
            code: 'INTERRUPTED',
            message: 'Sync interrumpido — reintentando',
          }),
        ),
      )
      await refreshCounts()
    }
    void bootCleanup()
  }, [refreshCounts])

  React.useEffect(() => {
    const interval = setInterval(() => { void runSync() }, 30_000)
    return () => clearInterval(interval)
  }, [runSync])

  React.useEffect(() => {
    const handler = () => { void runSync() }
    window.addEventListener('online', handler)
    return () => window.removeEventListener('online', handler)
  }, [runSync])
}
