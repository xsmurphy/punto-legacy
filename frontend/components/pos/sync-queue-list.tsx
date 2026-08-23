'use client'

/**
 * Lista de lo que esta caja emitió o cambió y todavía no llegó al servidor,
 * con sus acciones (reintentar / descartar).
 *
 * Son DOS colas distintas mostradas en una sola pantalla:
 *   - las OPERACIONES de configuración y de caja (`lib/pos/pending-ops.ts`) —
 *     ajustes, hotkeys, impresoras, apertura y cierre;
 *   - las VENTAS emitidas (`lib/pos/offline-queue.ts`).
 *
 * Las operaciones van ARRIBA a propósito: ahí adentro puede haber un cierre de
 * caja, y eso es lo primero que alguien tiene que ver al entrar.
 *
 * Vive en Menú → Ventas pendientes (`pos-main-menu.tsx` → `SyncQueuePanel`), y
 * es el ÚNICO lugar donde se ven. Hasta 2026-08-23 la sección mostraba un
 * párrafo con el conteo y un botón "Ver el detalle" que abría un
 * `SyncQueueDialog` con esta misma tabla: dos pantallas para un solo listado,
 * y la que el cajero abría primero no listaba nada. El diálogo fue eliminado y
 * su contenido es este componente.
 *
 * Es un componente y no el cuerpo inline de la sección porque el indicador de
 * estado del carrito también manda acá (`openMenuSection('sync-queue')`) y
 * porque la lógica de reintento/descarte se testea sin el menú alrededor.
 */

import * as React from 'react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { CloudOff } from 'lucide-react'
import { EmptyState } from '@/components/empty-state'
import { peekAll, discard, markFailed, markSynced, getFailedCount } from '@/lib/pos/offline-queue'
import type { OfflineSaleRow } from '@/lib/pos/offline-queue'
import {
  discardOp,
  getFailedOpsCount,
  getOpsCount,
  peekAllOps,
  retryOp,
} from '@/lib/pos/pending-ops'
import type { PendingOpRow } from '@/lib/pos/pending-ops'
import { syncPendingOps } from '@/lib/pos/pending-ops-sync'
import { sendPendingOp } from '@/lib/pos/pending-ops-transport'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { posApi as api } from '@/lib/api/pos-client'
import { formatMoney } from '@/lib/format-money'
import { useCatalogStore } from '@/lib/catalog/store'
import { useTenancyStore } from '@/lib/pos/tenancy-store'

// Errores permanentes: reintentar el mismo payload vuelve a fallar siempre.
//
// `REGISTER_TAKEN` (otro dispositivo tiene la caja) NO está acá, aunque el
// viejo `REGISTER_NOT_HELD` sí lo estaba. La diferencia es real: mientras el
// otro device la tenga no se puede reintentar, pero eso CAMBIA en cuanto un
// admin la libera — y esa venta ya está impresa y cobrada. Marcarla permanente
// dejaba al cajero con un botón gris y ninguna salida salvo descartar un
// comprobante emitido. Ahora el reintento se habilita cuando este device
// recupera la tenencia (ver `canRetry`), y las causas que dejan la caja libre
// (`REGISTER_RELEASED`/`REGISTER_NEVER_HELD`) ni siquiera llegan acá: el loop
// de sync las revive solo (`revivePendingAfterTenancy`).
const PERMANENT_ERROR_CODES = ['STOCK_OUT', 'NUMBER_TAKEN', 'INVALID_INPUT']

export function SyncQueueList() {
  const [rows, setRows] = React.useState<OfflineSaleRow[]>([])
  const [ops, setOps] = React.useState<PendingOpRow[]>([])
  const [syncing, setSyncing] = React.useState(false)
  const setPendingCount = useOfflineSyncStore((s) => s.setPendingCount)
  const setFailedCount = useOfflineSyncStore((s) => s.setFailedCount)
  const setPendingOpsCount = useOfflineSyncStore((s) => s.setPendingOpsCount)
  const setFailedOpsCount = useOfflineSyncStore((s) => s.setFailedOpsCount)
  // Conteos del store: la sección se abre mientras el loop de sync corre en
  // segundo plano, así que una venta puede salir de la cola sin que nadie
  // toque un botón. Releer al cambiar el conteo mantiene la tabla viva.
  const pendingCount = useOfflineSyncStore((s) => s.pendingCount)
  const failedCount = useOfflineSyncStore((s) => s.failedCount)
  const pendingOpsCount = useOfflineSyncStore((s) => s.pendingOpsCount)
  const failedOpsCount = useOfflineSyncStore((s) => s.failedOpsCount)
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  // Tenencia vigente de este device — habilita el reintento de las ventas que
  // el servidor rechazó por caja tomada, en cuanto la caja vuelve a ser suya.
  const tenancyOk = useTenancyStore((s) => s.verdict?.canIssue === true)
  // Ventas que todavía pueden sincronizar. Es la condición exacta que frena el
  // cierre de caja en `canSendPendingOp` — las terminales no lo frenan, así que
  // tampoco cuentan acá.
  const unsentSalesCount = rows.filter((r) => r.status !== 'failed').length

  const loadRows = React.useCallback(async () => {
    const all = await peekAll()
    const failed = await getFailedCount()
    setRows(all)
    setPendingCount(all.length)
    setFailedCount(failed)

    const allOps = await peekAllOps()
    setOps(allOps)
    setPendingOpsCount(await getOpsCount())
    setFailedOpsCount(await getFailedOpsCount())
  }, [setPendingCount, setFailedCount, setPendingOpsCount, setFailedOpsCount])

  React.useEffect(() => {
    // `alive`: la lectura de IndexedDB puede resolver después de que el cajero
    // cerró el menú. Los conteos del store como dependencia y no un intervalo —
    // el loop de sync ya actualiza el store cuando algo cambia, así que esto
    // relee exactamente cuando hay algo nuevo que mostrar.
    let alive = true
    void (async () => {
      const all = await peekAll()
      const failed = await getFailedCount()
      const allOps = await peekAllOps()
      const opsTotal = await getOpsCount()
      const opsFailed = await getFailedOpsCount()
      if (!alive) return
      setRows(all)
      setPendingCount(all.length)
      setFailedCount(failed)
      setOps(allOps)
      setPendingOpsCount(opsTotal)
      setFailedOpsCount(opsFailed)
    })()
    return () => {
      alive = false
    }
  }, [
    setPendingCount,
    setFailedCount,
    setPendingOpsCount,
    setFailedOpsCount,
    pendingCount,
    failedCount,
    pendingOpsCount,
    failedOpsCount,
  ])

  /**
   * Reintento manual de una operación. Vuelve a `pending` con el contador de
   * intentos en cero y dispara una pasada del motor — que respeta el orden del
   * canal, así que reintentar el cierre de caja también destraba lo que quedó
   * detrás de él.
   */
  async function handleRetryOp(opId: string) {
    setSyncing(true)
    try {
      await retryOp(opId)
      await syncPendingOps({ send: sendPendingOp, activeRegisterId })
    } finally {
      setSyncing(false)
      await loadRows()
    }
  }

  async function handleDiscardOp(opId: string) {
    await discardOp(opId)
    await loadRows()
  }

  async function handleDiscard(clientTempId: string) {
    await discard(clientTempId)
    await loadRows()
  }

  async function handleRetryOne(row: OfflineSaleRow) {
    setSyncing(true)
    try {
      const response = await api.post<{
        results: Array<{
          clientTempId: string
          ok: boolean
          transactionId?: string
          error?: { code: string; message: string }
        }>
      }>('/v1/offline-sync', {
        sales: [
          {
            clientTempId: row.clientTempId,
            invoiceNo: row.invoiceNo,
            sale: row.sale,
          },
        ],
      })
      const result = response?.results?.[0]
      if (result?.ok) {
        await markSynced(row.clientTempId)
      } else if (result?.error) {
        await markFailed(row.clientTempId, result.error)
      }
    } catch {
      await markFailed(row.clientTempId, { code: 'NETWORK_ERROR', message: 'Error de red' })
    } finally {
      setSyncing(false)
      await loadRows()
    }
  }

  async function handleRetryAllFailed() {
    setSyncing(true)
    const failed = rows.filter((r) => r.status === 'failed')
    try {
      const response = await api.post<{
        results: Array<{
          clientTempId: string
          ok: boolean
          error?: { code: string; message: string }
        }>
      }>('/v1/offline-sync', {
        sales: failed.map((r) => ({
          clientTempId: r.clientTempId,
          invoiceNo: r.invoiceNo,
          sale: r.sale,
        })),
      })
      const results = response?.results ?? []
      await Promise.all(
        results.map(async (res) => {
          if (res.ok) {
            await markSynced(res.clientTempId)
          } else if (res.error) {
            await markFailed(res.clientTempId, res.error)
          }
        }),
      )
    } catch {
      await Promise.all(
        failed.map((r) =>
          markFailed(r.clientTempId, { code: 'NETWORK_ERROR', message: 'Error de red' }),
        ),
      )
    } finally {
      setSyncing(false)
      await loadRows()
    }
  }

  const hasFailed = rows.some((r) => r.status === 'failed')

  function formatDate(iso: string) {
    return new Date(iso).toLocaleString('es-PY', {
      hour: '2-digit',
      minute: '2-digit',
      day: '2-digit',
      month: '2-digit',
    })
  }

  function getTotal(row: OfflineSaleRow) {
    // payment es SalePaymentMethod[] (tipado en CreateSalePayload) — sin cast
    return row.sale.payment.reduce((s, p) => s + (p.total ?? 0), 0)
  }

  function canRetry(row: OfflineSaleRow) {
    if (row.status !== 'failed') return false
    if (!row.error) return true
    if (PERMANENT_ERROR_CODES.includes(row.error.code)) return false
    // Rechazo por tenencia: reintentar solo tiene sentido si este device
    // recuperó la caja. Si no, el botón quedaría disponible para fallar otra
    // vez con el mismo mensaje. El texto del error ya dice qué hacer (pedir
    // que la liberen), y el botón se habilita solo cuando eso pasó.
    if (row.error.code === 'REGISTER_TAKEN') return tenancyOk
    return true
  }

  /**
   * Un cierre "En cola" que no sale puede leerse como un cierre trabado. No lo
   * está: espera a que las ventas del turno lleguen primero, porque si no el
   * servidor cerraría el arqueo sin ellas. Se dice, en vez de dejar al
   * operador adivinando.
   */
  function opWaitingNote(op: PendingOpRow): string | null {
    if (op.kind !== 'drawerClose' || op.status !== 'pending' || unsentSalesCount === 0) {
      return null
    }
    return `Se envía cuando terminen de enviarse ${unsentSalesCount} venta${
      unsentSalesCount !== 1 ? 's' : ''
    } del turno`
  }

  if (rows.length === 0 && ops.length === 0) {
    return (
      <EmptyState
        icon={CloudOff}
        title="No hay nada pendiente"
        description="Todo lo emitido y lo configurado en esta caja ya llegó al servidor."
      />
    )
  }

  return (
    <div className="flex flex-col gap-6">
      {/* Operaciones de configuración y de caja. Van ARRIBA de las ventas a
          propósito: acá adentro puede haber un cierre de caja, y eso es lo
          primero que alguien tiene que ver. */}
      {ops.length > 0 && (
        <div className="flex flex-col gap-2">
          <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Cambios y operaciones de caja
          </p>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Operación</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {ops.map((op) => {
                const waiting = opWaitingNote(op)
                return (
                  <TableRow key={op.opId}>
                    <TableCell>
                      <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="truncate">{op.label}</span>
                        <span className="text-xs text-muted-foreground">
                          {formatDate(op.createdAt)}
                          {waiting ? ` · ${waiting}` : ''}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell>
                      {op.status === 'pending' && <Badge variant="secondary">En cola</Badge>}
                      {op.status === 'syncing' && <Badge variant="secondary">Sincronizando</Badge>}
                      {op.status === 'failed' && (
                        <div className="flex flex-col items-start gap-1">
                          <Badge variant="destructive">Error</Badge>
                          {op.error && (
                            <span className="text-xs text-muted-foreground">
                              {op.error.message}
                            </span>
                          )}
                        </div>
                      )}
                    </TableCell>
                    <TableCell>
                      <div className="flex justify-end gap-2">
                        {op.status === 'failed' && (
                          <>
                            <Button
                              size="sm"
                              variant="outline"
                              disabled={syncing}
                              onClick={() => void handleRetryOp(op.opId)}
                            >
                              Reintentar
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              className="text-destructive hover:text-destructive"
                              disabled={syncing}
                              onClick={() => void handleDiscardOp(op.opId)}
                            >
                              Descartar
                            </Button>
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </div>
      )}

      {rows.length > 0 && (
        <div className="flex flex-col gap-2">
          {/* El título de la sección solo aparece cuando hay las dos colas: con
              ventas nada más, la pantalla ya se llama "Ventas pendientes". */}
          {ops.length > 0 && (
            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Ventas emitidas
            </p>
          )}
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Fecha</TableHead>
                <TableHead>Número</TableHead>
                <TableHead>Total</TableHead>
                <TableHead>Estado</TableHead>
                <TableHead className="text-right">Acciones</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.clientTempId}>
                  <TableCell className="whitespace-nowrap text-muted-foreground">
                    {formatDate(row.createdAt)}
                  </TableCell>
                  <TableCell className="tabular-nums">{row.invoiceNo ?? '—'}</TableCell>
                  <TableCell className="tabular-nums font-medium">
                    {formatMoney(getTotal(row), config)}
                  </TableCell>
                  <TableCell>
                    {row.status === 'pending' && <Badge variant="secondary">En cola</Badge>}
                    {row.status === 'syncing' && <Badge variant="secondary">Sincronizando</Badge>}
                    {row.status === 'failed' && (
                      <div className="flex flex-col items-start gap-1">
                        <Badge variant="destructive">Error</Badge>
                        {row.error && (
                          <span className="text-xs text-muted-foreground">{row.error.message}</span>
                        )}
                      </div>
                    )}
                  </TableCell>
                  <TableCell>
                    <div className="flex justify-end gap-2">
                      {canRetry(row) && (
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={syncing}
                          onClick={() => void handleRetryOne(row)}
                        >
                          Reintentar
                        </Button>
                      )}
                      {row.status === 'failed' && (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-destructive hover:text-destructive"
                          disabled={syncing}
                          onClick={() => void handleDiscard(row.clientTempId)}
                        >
                          Descartar
                        </Button>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>

          {hasFailed && (
            <Button
              variant="outline"
              className="self-start"
              disabled={syncing}
              onClick={() => void handleRetryAllFailed()}
            >
              Reintentar todas las fallidas
            </Button>
          )}
        </div>
      )}
    </div>
  )
}
