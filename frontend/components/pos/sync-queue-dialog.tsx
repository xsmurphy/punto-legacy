'use client'

import * as React from 'react'
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { peekAll, discard, markFailed, markSynced, getFailedCount } from '@/lib/pos/offline-queue'
import type { OfflineSaleRow } from '@/lib/pos/offline-queue'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { posApi as api } from '@/lib/api/pos-client'
import { formatMoney } from '@/lib/format-money'
import { useCatalogStore } from '@/lib/catalog/store'

interface SyncQueueDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

// Errores permanentes que no se pueden reintentar. REGISTER_NOT_HELD
// (api/v1/offline-sync.php — context/29-numeracion-y-exclusividad-de-caja.md
// §4): la caja se liberó, la tomó otro dispositivo, o se cerró mientras esta
// venta esperaba conexión — no hay forma de reintentar CON el mismo número
// sin arriesgar un duplicado. El mensaje real (`row.error.message`, ya se
// renderiza abajo) le dice al cajero qué pasó.
const PERMANENT_ERROR_CODES = ['STOCK_OUT', 'NUMBER_TAKEN', 'INVALID_INPUT', 'REGISTER_NOT_HELD']

export function SyncQueueDialog({ open, onOpenChange }: SyncQueueDialogProps) {
  const [rows, setRows] = React.useState<OfflineSaleRow[]>([])
  const [syncing, setSyncing] = React.useState(false)
  const setPendingCount = useOfflineSyncStore((s) => s.setPendingCount)
  const setFailedCount = useOfflineSyncStore((s) => s.setFailedCount)
  const config = useCatalogStore((s) => s.config)

  async function loadRows() {
    const all = await peekAll()
    setRows(all)
    setPendingCount(all.length)
    setFailedCount(await getFailedCount())
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
    return !PERMANENT_ERROR_CODES.includes(row.error.code)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent sectioned className="max-h-[85vh] sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">
            Ventas pendientes de sincronizar
          </DialogTitle>
        </DialogHeader>

        <Separator />

        {/* `flush`: el separador de cada fila cruza de lado a lado, pero la
            primera/última celda respetan el gutter de 24px del header. */}
        <DialogBody flush>
          {rows.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-2 py-16 text-center text-muted-foreground">
              <p className="text-sm">No hay ventas pendientes de sincronizar</p>
            </div>
          ) : (
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
