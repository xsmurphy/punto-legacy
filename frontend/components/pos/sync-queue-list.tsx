'use client'

/**
 * Lista de ventas emitidas por esta caja que todavía no llegaron al servidor,
 * con sus acciones (reintentar / descartar).
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
  const [syncing, setSyncing] = React.useState(false)
  const setPendingCount = useOfflineSyncStore((s) => s.setPendingCount)
  const setFailedCount = useOfflineSyncStore((s) => s.setFailedCount)
  // Conteos del store: la sección se abre mientras el loop de sync corre en
  // segundo plano, así que una venta puede salir de la cola sin que nadie
  // toque un botón. Releer al cambiar el conteo mantiene la tabla viva.
  const pendingCount = useOfflineSyncStore((s) => s.pendingCount)
  const failedCount = useOfflineSyncStore((s) => s.failedCount)
  const config = useCatalogStore((s) => s.config)
  // Tenencia vigente de este device — habilita el reintento de las ventas que
  // el servidor rechazó por caja tomada, en cuanto la caja vuelve a ser suya.
  const tenancyOk = useTenancyStore((s) => s.verdict?.canIssue === true)

  const loadRows = React.useCallback(async () => {
    const all = await peekAll()
    const failed = await getFailedCount()
    setRows(all)
    setPendingCount(all.length)
    setFailedCount(failed)
  }, [setPendingCount, setFailedCount])

  React.useEffect(() => {
    // `alive`: la lectura de IndexedDB puede resolver después de que el cajero
    // cerró el menú. `pendingCount`/`failedCount` como dependencia y no un
    // intervalo — el loop de sync ya actualiza el store cuando algo cambia,
    // así que esto relee exactamente cuando hay algo nuevo que mostrar.
    let alive = true
    void (async () => {
      const all = await peekAll()
      const failed = await getFailedCount()
      if (!alive) return
      setRows(all)
      setPendingCount(all.length)
      setFailedCount(failed)
    })()
    return () => {
      alive = false
    }
  }, [setPendingCount, setFailedCount, pendingCount, failedCount])

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

  if (rows.length === 0) {
    return (
      <EmptyState
        icon={CloudOff}
        title="No hay ventas pendientes"
        description="Todo lo emitido en esta caja ya llegó al servidor."
      />
    )
  }

  return (
    <div className="flex flex-col gap-4">
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
  )
}
