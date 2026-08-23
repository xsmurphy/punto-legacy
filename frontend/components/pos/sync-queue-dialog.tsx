'use client'

import * as React from 'react'
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
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

interface SyncQueueDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

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

export function SyncQueueDialog({ open, onOpenChange }: SyncQueueDialogProps) {
  const [rows, setRows] = React.useState<OfflineSaleRow[]>([])
  const [ops, setOps] = React.useState<PendingOpRow[]>([])
  const [syncing, setSyncing] = React.useState(false)
  const setPendingCount = useOfflineSyncStore((s) => s.setPendingCount)
  const setFailedCount = useOfflineSyncStore((s) => s.setFailedCount)
  const setPendingOpsCount = useOfflineSyncStore((s) => s.setPendingOpsCount)
  const setFailedOpsCount = useOfflineSyncStore((s) => s.setFailedOpsCount)
  const config = useCatalogStore((s) => s.config)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  // Tenencia vigente de este device — habilita el reintento de las ventas que
  // el servidor rechazó por caja tomada, en cuanto la caja vuelve a ser suya.
  const tenancyOk = useTenancyStore((s) => s.verdict?.canIssue === true)

  async function loadRows() {
    const all = await peekAll()
    setRows(all)
    setPendingCount(all.length)
    setFailedCount(await getFailedCount())

    const allOps = await peekAllOps()
    setOps(allOps)
    setPendingOpsCount(await getOpsCount())
    setFailedOpsCount(await getFailedOpsCount())
  }

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

  React.useEffect(() => {
    if (open) void loadRows()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

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

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent sectioned className="max-h-[85vh] sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">
            Pendientes de sincronizar
          </DialogTitle>
        </DialogHeader>

        <Separator />

        {/* `flush`: el separador de cada fila cruza de lado a lado, pero la
            primera/última celda respetan el gutter de 24px del header. */}
        <DialogBody flush>
          {/* Operaciones de configuración y de caja. Van ARRIBA de las ventas
              a propósito: acá adentro puede haber un cierre de caja, y eso es
              lo primero que alguien tiene que ver al abrir este diálogo. */}
          {ops.length > 0 && (
            <div className="mb-2">
              <p className="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Cambios y operaciones de caja
              </p>
              <div className="divide-y divide-border border-y border-border">
                {ops.map((op) => (
                  <div
                    key={op.opId}
                    className="flex items-center justify-between gap-3 px-4 py-2.5"
                  >
                    <div className="min-w-0">
                      <p className="truncate text-sm">{op.label}</p>
                      <p className="text-xs text-muted-foreground">
                        {formatDate(op.createdAt)}
                        {op.status === 'failed' && op.error ? ` · ${op.error.message}` : ''}
                      </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-1.5">
                      {op.status === 'pending' && <Badge variant="secondary">En cola</Badge>}
                      {op.status === 'syncing' && (
                        <Badge variant="secondary">Sincronizando...</Badge>
                      )}
                      {op.status === 'failed' && (
                        <>
                          <Badge variant="destructive">Error</Badge>
                          <Button
                            size="sm"
                            variant="outline"
                            className="h-7 px-2 text-xs"
                            disabled={syncing}
                            onClick={() => void handleRetryOp(op.opId)}
                          >
                            Reintentar
                          </Button>
                          <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 px-2 text-xs text-destructive hover:text-destructive"
                            disabled={syncing}
                            onClick={() => void handleDiscardOp(op.opId)}
                          >
                            Descartar
                          </Button>
                        </>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {rows.length === 0 && ops.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 py-16 text-center text-muted-foreground">
              <p className="text-sm">No hay nada pendiente de sincronizar</p>
            </div>
          ) : rows.length === 0 ? null : (
            <>
            <p className="px-4 py-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Ventas emitidas
            </p>
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs text-muted-foreground">
                  <th className="px-4 py-2 font-medium">Fecha</th>
                  <th className="px-4 py-2 font-medium">N</th>
                  <th className="px-4 py-2 font-medium">Total</th>
                  <th className="px-4 py-2 font-medium">Estado</th>
                  <th className="px-4 py-2 font-medium">Acciones</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {rows.map((row) => (
                  <tr key={row.clientTempId} className="hover:bg-muted/30">
                    <td className="whitespace-nowrap px-4 py-2 text-xs text-muted-foreground">
                      {formatDate(row.createdAt)}
                    </td>
                    <td className="px-4 py-2 tabular-nums text-xs">
                      {row.invoiceNo ?? '—'}
                    </td>
                    <td className="px-4 py-2 tabular-nums font-medium">
                      {formatMoney(getTotal(row), config)}
                    </td>
                    <td className="px-4 py-2">
                      {row.status === 'pending' && <Badge variant="secondary">En cola</Badge>}
                      {row.status === 'syncing' && (
                        <Badge variant="secondary">Sincronizando...</Badge>
                      )}
                      {row.status === 'failed' && (
                        <div className="flex flex-col gap-0.5">
                          <Badge variant="destructive">Error</Badge>
                          {row.error && (
                            <span className="text-[10px] text-muted-foreground">
                              {row.error.message}
                            </span>
                          )}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex gap-1.5">
                        {canRetry(row) && (
                          <Button
                            size="sm"
                            variant="outline"
                            className="h-7 px-2 text-xs"
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
                            className="h-7 px-2 text-xs text-destructive hover:text-destructive"
                            disabled={syncing}
                            onClick={() => void handleDiscard(row.clientTempId)}
                          >
                            Descartar
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            </>
          )}
        </DialogBody>

        {hasFailed && (
          <>
            <Separator />
            <DialogFooter>
              <Button
                variant="outline"
                disabled={syncing}
                onClick={() => void handleRetryAllFailed()}
              >
                Reintentar todas las fallidas
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}
