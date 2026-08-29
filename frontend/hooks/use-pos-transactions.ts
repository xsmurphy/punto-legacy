"use client"

/**
 * Hooks para el modal de transacciones del POS (T1).
 *
 * usePosTransactionsList — lista paginada con q + date + offset manual
 * (el proyecto no usa useInfiniteQuery — implementamos pages con useState).
 *
 * usePosTransactionDetail — detalle de una transacción por ID enc.
 * Reutiliza el BFF /api/pos/transactions/[id] y el hook useTransaction
 * del módulo existente.
 */

import * as React from "react"
import { posApi as api } from "@/lib/api/pos-client"
import type { PosTransactionListItem, PosTransactionsListResponse } from "@/lib/types/pos-transactions"
import { useTransaction } from "@/hooks/use-transactions"

const PAGE_SIZE = 30

// ── Lista paginada ─────────────────────────────────────────────────────────────

interface UsePosTransactionsListOpts {
  q?: string
  date?: string
  limit?: number
  type?: number | null
}

interface UsePosTransactionsListResult {
  flat: PosTransactionListItem[]
  isFetching: boolean
  hasMore: boolean
  fetchNextPage: () => void
  reset: () => void
  error: Error | null
}

export function usePosTransactionsList({
  q = "",
  date = "",
  limit = PAGE_SIZE,
  type = null,
}: UsePosTransactionsListOpts = {}): UsePosTransactionsListResult {
  const [pages, setPages] = React.useState<PosTransactionListItem[][]>([])
  const [offset, setOffset] = React.useState(0)
  const [hasMore, setHasMore] = React.useState(false)
  const [isFetching, setIsFetching] = React.useState(false)
  const [error, setError] = React.useState<Error | null>(null)

  const resetRef = React.useRef<number>(0)

  const reset = React.useCallback(() => {
    resetRef.current += 1
    setPages([])
    setOffset(0)
    setHasMore(false)
    setError(null)
  }, [])

  React.useEffect(() => {
    reset()
  }, [q, date, type, reset])

  React.useEffect(() => {
    let cancelled = false
    const generation = resetRef.current

    async function load() {
      setIsFetching(true)
      setError(null)
      try {
        const qs = new URLSearchParams({ limit: String(limit), offset: String(offset) })
        if (q) qs.set("q", q)
        if (date) qs.set("date", date)
        if (type != null) qs.set("type", String(type))
        // posApi ya prepende `/api` en el browser — pasar `/pos/transactions` directo.
        // Con doble prefix daba GET /api/api/pos/transactions → 404. Este endpoint
        // ya es un BFF `/api/pos/*` dedicado con requireBearer — posApi manda el
        // Bearer del device explícitamente (antes dependía del fallback removido
        // de api-client, ver invariante en lib/api-client.ts).
        const data = await api.get<PosTransactionsListResponse>(`/pos/transactions?${qs.toString()}`)

        if (cancelled || resetRef.current !== generation) return

        const items = data.transactionsList ?? []
        setPages((prev) => offset === 0 ? [items] : [...prev, items])
        setHasMore(data.hasMore ?? false)
      } catch (err) {
        if (!cancelled && resetRef.current === generation) {
          setError(err instanceof Error ? err : new Error(String(err)))
        }
      } finally {
        if (!cancelled && resetRef.current === generation) {
          setIsFetching(false)
        }
      }
    }

    load()
    return () => { cancelled = true }
  }, [q, date, limit, offset, type])

  const fetchNextPage = React.useCallback(() => {
    if (isFetching || !hasMore) return
    setOffset((prev) => prev + limit)
  }, [isFetching, hasMore, limit])

  const flat = React.useMemo(() => pages.flat(), [pages])

  return { flat, isFetching, hasMore, fetchNextPage, reset, error }
}

// ── Detalle ────────────────────────────────────────────────────────────────────

/**
 * Delega en `useTransaction` en vez de traer su propio fetcher — que es lo que
 * el docblock de arriba decía desde el principio y el código no hacía.
 *
 * Los dos cacheaban bajo la MISMA queryKey `["pos-transaction", encId]` pero
 * con fetchers distintos: éste desenvolvía el envelope vía `posApi`, y el de
 * use-transactions.ts casteaba el `{ ok, data }` crudo. Ganaba el último en
 * resolver, así que el detalle salía vacío ("Tipo NaN", "Items (0)") hasta que
 * una recarga hacía correr el otro (reporte del tester, 2026-08-28).
 *
 * Dos hooks pueden compartir una queryKey; lo que no pueden es tener cada uno
 * su propio fetcher para ella. Un solo dueño de la clave y el problema no
 * puede volver.
 */
export function usePosTransactionDetail(encId: string | null) {
  return useTransaction(encId)
}
