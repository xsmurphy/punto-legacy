'use client'

import * as React from 'react'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { peekAll, discard, markFailed, markSynced } from '@/lib/pos/offline-queue'
import type { OfflineSaleRow } from '@/lib/pos/offline-queue'
import { useOfflineSyncStore } from '@/lib/pos/offline-sync-store'
import { api } from '@/lib/api-client'
import { formatMoney } from '@/lib/format-money'
import { useCatalogStore } from '@/lib/catalog/store'

interface SyncQueueDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
}

// Errores permanentes que no se pueden reintentar
const PERMANENT_ERROR_CODES = ['STOCK_OUT', 'NUMBER_TAKEN', 'INVALID_INPUT', 'LEASE_EXPIRED']

export function SyncQueueDialog({ open, onOpenChange }: SyncQueueDialogProps) {
  const [rows, setRows] = React.useState<OfflineSaleRow[]>([])
  const [syncing, setSyncing] = React.useState(false)
  const setPendingCount = useOfflineSyncStore((s) => s.setPendingCount)
  const config = useCatalogStore((s) => s.config)

  async function loadRows() {
    const all = await peekAll()
    setRows(all)
    setPendingCount(all.length)
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
            leasedInvoiceNo: row.leasedInvoiceNo,
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
          leasedInvoiceNo: r.leasedInvoiceNo,
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
      <DialogContent className="flex max-h-[85vh] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
        <DialogHeader className="shrink-0 px-5 pb-3 pt-5">
          <DialogTitle className="text-2xl font-semibold">
            Ventas pendientes de sincronizar
          </DialogTitle>
        </DialogHeader>

        <Separator />

        <div className="flex-1 overflow-y-auto">
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
                      {row.leasedInvoiceNo ?? '—'}
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
        </div>

        {hasFailed && (
          <>
            <Separator />
            <DialogFooter className="shrink-0 px-5 py-4">
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
