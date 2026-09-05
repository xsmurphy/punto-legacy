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
import { peekAll, markFailed, markSynced, markWaiting, getFailedCount } from '@/lib/pos/offline-queue'
import { ACCOUNT_BLOCKED_NOTE, isAccountBlocked } from '@/lib/pos/account-block'
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
import { resolveDateLocale } from '@/lib/tenant-locale'
import { useCatalogStore } from '@/lib/catalog/store'
import { useTenancyStore } from '@/lib/pos/tenancy-store'

/**
 * Mensaje de error de la cola, en la celda de Estado.
 *
 * El tope de ancho NO es cosmético: una celda de tabla se ensancha con su
 * contenido, y el `<Table>` de shadcn scrollea horizontal. Un mensaje largo
 * ("La caja fue liberada, tomada por otro dispositivo, o cerrada mientras esta
 * venta esperaba conexión") empujaba la columna Acciones fuera del viewport y
 * dejaba "Reintentar" invisible — el cajero veía el problema pero no el
 * remedio, justo lo contrario de la convención del POS: la acción y su
 * impedimento van juntos, nunca separados por un scroll.
 *
 * `max-w` va sobre el `<span>` y no sobre la celda porque un `max-width` en un
 * `<td>` lo ignora el algoritmo de tabla automático; el elemento de adentro sí
 * lo respeta y deja de empujar. `break-words` corta URLs o ids que no tengan
 * espacios.
 */
const ERROR_MESSAGE_CLASS =
  'max-w-[38ch] whitespace-normal break-words text-xs text-muted-foreground'

/**
 * Celda de acciones: nunca se parte en dos renglones ni cede ancho. `w-0` con
 * `whitespace-nowrap` es el idiom para "ocupá exactamente lo que miden los
 * botones" — el resto del ancho se lo reparten las columnas de contenido.
 */
const ACTIONS_CELL_CLASS = 'w-0 whitespace-nowrap'

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
  // El servidor está rechazando todo porque la cuenta del comercio no está al
  // día (D8, `lib/pos/account-block.ts`). No es un error de lo que hay en la
  // cola —está intacto y sale solo cuando se regularice el pago—, así que se
  // dice como ESPERA. Decirle "Error" al cajero es empujarlo al botón de
  // descartar una venta real.
  const accountBlocked = useOfflineSyncStore((s) => s.accountBlocked)
  const setAccountBlocked = useOfflineSyncStore((s) => s.setAccountBlocked)
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
    } catch (err) {
      // Cuenta impaga: la venta sale de 'failed' y vuelve a la cola en espera
      // (D8). El reintento manual acá es lo mejor que le puede pasar a esa
      // venta — deja de estar marcada como error y sincroniza sola en cuanto se
      // regularice el pago, sin depender de que alguien vuelva a esta pantalla.
      if (isAccountBlocked(err)) {
        setAccountBlocked('sales', true)
        await markWaiting(row.clientTempId)
        return
      }
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
    } catch (err) {
      // Mismo criterio que el reintento de a una: con la cuenta impaga, estas
      // ventas no fallaron — están esperando. Ver `lib/pos/account-block.ts`.
      if (isAccountBlocked(err)) {
        setAccountBlocked('sales', true)
        await Promise.all(failed.map((r) => markWaiting(r.clientTempId)))
        return
      }
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
    return new Date(iso).toLocaleString(resolveDateLocale(config), {
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
    if (op.status !== 'pending') return null
    // La cuenta impaga gana sobre cualquier otra espera: mientras esté, NADA
    // sale, así que explicar el orden interno de la cola sería contarle al
    // cajero el motivo equivocado.
    if (accountBlocked) return ACCOUNT_BLOCKED_NOTE
    if (op.kind !== 'drawerClose' || unsentSalesCount === 0) return null
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
                      {/* Mismo motivo que ERROR_MESSAGE_CLASS: `truncate` no
                          alcanza dentro de un `<td>` —la celda se ensancha con
                          el texto antes de que el overflow entre en juego— así
                          que el tope va acá, en el bloque de adentro. */}
                      <div className="flex min-w-0 max-w-[40ch] flex-col gap-0.5">
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
                            <span className={ERROR_MESSAGE_CLASS}>{op.error.message}</span>
                          )}
                        </div>
                      )}
                    </TableCell>
                    <TableCell className={ACTIONS_CELL_CLASS}>
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
                    {row.status === 'pending' && (
                      <div className="flex flex-col items-start gap-1">
                        <Badge variant="secondary">En cola</Badge>
                        {/* La venta está intacta: lo que falta es que el
                            comercio se ponga al día. Decirlo acá, en la fila,
                            es lo que evita que alguien la descarte creyendo
                            que se rompió. */}
                        {accountBlocked && (
                          <span className="text-xs text-muted-foreground">
                            {ACCOUNT_BLOCKED_NOTE}
                          </span>
                        )}
                      </div>
                    )}
                    {row.status === 'syncing' && <Badge variant="secondary">Sincronizando</Badge>}
                    {row.status === 'failed' && (
                      <div className="flex flex-col items-start gap-1">
                        <Badge variant="destructive">Error</Badge>
                        {row.error && (
                          <span className={ERROR_MESSAGE_CLASS}>{row.error.message}</span>
                        )}
                      </div>
                    )}
                  </TableCell>
                  <TableCell className={ACTIONS_CELL_CLASS}>
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
                      {/* SIN "Descartar" para una VENTA — mandato del owner
                          (2026-09-05): "una venta no debe ser eliminada".
                          Acá había un botón que llamaba a `discard()`, o sea
                          `db.delete('pendingSales', …)`: borrado permanente,
                          un click, sin confirmación, de un comprobante YA
                          cobrado e impreso al cliente. Esa venta no llegaba
                          nunca a los libros y no quedaba rastro de que
                          existió.

                          No hay reemplazo ni diálogo de confirmación: una
                          venta que no se puede sincronizar es un caso de
                          soporte, no una decisión para tomar parado atrás de
                          la caja en hora pico. Se queda en la lista, con su
                          error a la vista y el botón de reintentar.

                          Sacarlo NO traba la cola: el ciclo de sync saltea
                          las `failed` y sigue con las `pending` (ver
                          `use-offline-sync.ts`), así que una venta atascada
                          no frena a las que vienen atrás.

                          El botón de las OPERACIONES (arriba) se conserva:
                          son cambios de configuración, no plata cobrada. */}
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
