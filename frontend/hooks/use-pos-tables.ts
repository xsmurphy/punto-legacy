"use client"

/**
 * Hooks del módulo de Mesas para el POS operativo (context/15-mesas-module-plan.md
 * F2). Análogo a `use-orders.ts` — el device POS corre con Bearer del device
 * (realm `pos-app`), no la cookie `_jwt_panel` del panel, así que va por
 * `posFetch` contra los BFF `/api/pos/tables` / `/api/pos/table-sessions` /
 * `/api/pos/table-sectors`, que proxean a `/v1/dining-tables.php` /
 * `/v1/table-sessions.php` / `/v1/table-sectors.php` reenviando el query
 * string completo.
 *
 * NO confundir con `hooks/use-dining-tables.ts` / `use-table-sectors.ts`
 * (config del panel, cookie `_jwt_panel`, usados por `/settings/tables`) —
 * son dos superficies de auth distintas sobre las mismas entidades.
 *
 * Invalidación: `table` está mapeado en `ENTITY_TO_QUERY_KEYS`
 * (hooks/use-realtime-sync.ts) a los queryKeys de este archivo — cualquier
 * mutación server-side (open/request-bill/cancel/close/layout) publica
 * `realtimePublish('table', ...)` y el socket dispara la invalidación acá.
 * El canal `{companyId}:tables:{outletId}` (wsPublish) es un segundo camino
 * best-effort para UIs dedicadas — el POS se apoya en la invalidación
 * genérica, no consume ese canal directo (mismo criterio que O1 con `order`).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"
import type { DiningTableWithState, TableShape } from "@/hooks/use-dining-tables"

export interface PosTableSector {
  id: string
  companyId: string
  outletId: string
  name: string
  sort: number
  createdAt: string | null
}

export interface TableSession {
  id: string
  companyId: string
  outletId: string
  tableId: string
  status: "open" | "bill_requested" | "closed" | "cancelled"
  guests: number | null
  waiterId: string | null
  openedAt: string | null
  closedAt: string | null
  saleTransactionId: string | null
  note: string | null
}

async function posJson<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await posFetch(url, init)
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new Error(json?.error?.message ?? `Error ${res.status}`)
  }
  return json.data as T
}

// ── Queries ───────────────────────────────────────────────────────────────────

/** Sectores del outlet del device — tabs de navegación del plano. */
export function usePosTableSectors() {
  return useQuery<{ sectors: PosTableSector[] }>({
    queryKey: ["pos-table-sectors"],
    queryFn: () => posJson<{ sectors: PosTableSector[] }>("/api/pos/table-sectors"),
    staleTime: 30 * 1000,
  })
}

/** Plano operativo — mesas + estado derivado + sesión activa. Vivo por realtime. */
export function usePosTablesState() {
  return useQuery<{ tables: DiningTableWithState[] }>({
    queryKey: ["pos-tables", "state"],
    queryFn: () => posJson<{ tables: DiningTableWithState[] }>("/api/pos/tables?resource=state"),
    staleTime: 10 * 1000,
    refetchInterval: 20 * 1000,
  })
}

// ── Mutaciones ────────────────────────────────────────────────────────────────

export function useOpenTableSession() {
  const qc = useQueryClient()
  return useMutation<TableSession, Error, { tableId: string; guests?: number; waiterId?: string }>({
    mutationFn: (data) =>
      posJson<TableSession>("/api/pos/table-sessions", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["pos-tables"] })
    },
  })
}

export function useRequestBill() {
  const qc = useQueryClient()
  return useMutation<TableSession, Error, string>({
    mutationFn: (sessionId) =>
      posJson<TableSession>(`/api/pos/table-sessions?id=${sessionId}&action=request-bill`, {
        method: "POST",
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["pos-tables"] })
    },
  })
}

export function useCancelTableSession() {
  const qc = useQueryClient()
  return useMutation<TableSession, Error, string>({
    mutationFn: (sessionId) =>
      posJson<TableSession>(`/api/pos/table-sessions?id=${sessionId}&action=cancel`, {
        method: "POST",
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["pos-tables"] })
    },
  })
}

/** Cierra la sesión — llamado al final del flujo de cobro con el transactionId resultante. */
export function useCloseTableSession() {
  const qc = useQueryClient()
  return useMutation<TableSession, Error, { sessionId: string; transactionId?: string }>({
    mutationFn: ({ sessionId, transactionId }) =>
      posJson<TableSession>(`/api/pos/table-sessions?id=${sessionId}&action=close`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ transactionId }),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["pos-tables"] })
    },
  })
}

export type { DiningTableWithState, TableShape }
