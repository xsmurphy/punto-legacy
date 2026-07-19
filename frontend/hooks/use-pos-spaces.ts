"use client"

/**
 * Hooks del módulo de Espacios para el POS operativo (context/15-espacios-module-plan.md
 * F2). Análogo a `use-orders.ts` — el device POS corre con Bearer del device
 * (realm `pos-app`), no la cookie `_jwt_panel` del panel, así que va por
 * `posFetch` contra los BFF `/api/pos/spaces` / `/api/pos/space-sessions` /
 * `/api/pos/space-sectors`, que proxean a `/v1/spaces.php` /
 * `/v1/space-sessions.php` / `/v1/space-sectors.php` reenviando el query
 * string completo.
 *
 * NO confundir con `hooks/use-spaces.ts` / `use-space-sectors.ts`
 * (config del panel, cookie `_jwt_panel`, usados por `/settings/espacios`) —
 * son dos superficies de auth distintas sobre las mismas entidades.
 *
 * Invalidación: `space` está mapeado en `ENTITY_TO_QUERY_KEYS`
 * (hooks/use-realtime-sync.ts) a los queryKeys de este archivo — cualquier
 * mutación server-side (open/request-bill/cancel/close/layout) publica
 * `realtimePublish('space', ...)` y el socket dispara la invalidación acá.
 * El canal `{companyId}:spaces:{outletId}` (wsPublish) es un segundo camino
 * best-effort para UIs dedicadas — el POS se apoya en la invalidación
 * genérica, no consume ese canal directo (mismo criterio que O1 con `order`).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"
import type { SpaceWithState, SpaceShape } from "@/hooks/use-spaces"

export interface PosSpaceSector {
  id: string
  companyId: string
  outletId: string
  name: string
  sort: number
  createdAt: string | null
}

export interface SpaceSession {
  id: string
  companyId: string
  outletId: string
  spaceId: string
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
export function usePosSpaceSectors() {
  return useQuery<{ sectors: PosSpaceSector[] }>({
    queryKey: ["pos-space-sectors"],
    queryFn: () => posJson<{ sectors: PosSpaceSector[] }>("/api/pos/space-sectors"),
    staleTime: 30 * 1000,
  })
}

/** Plano operativo — espacios + estado derivado + sesión activa. Vivo por realtime. */
export function usePosSpacesState() {
  return useQuery<{ spaces: SpaceWithState[] }>({
    queryKey: ["pos-spaces", "state"],
    queryFn: () => posJson<{ spaces: SpaceWithState[] }>("/api/pos/spaces?resource=state"),
    staleTime: 10 * 1000,
    refetchInterval: 20 * 1000,
  })
}

// ── Mutaciones ────────────────────────────────────────────────────────────────

export function useOpenSpaceSession() {
  const qc = useQueryClient()
  return useMutation<SpaceSession, Error, { tableId: string; guests?: number; waiterId?: string }>({
    mutationFn: (data) =>
      posJson<SpaceSession>("/api/pos/space-sessions", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(data),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["pos-spaces"] })
    },
  })
}

export function useRequestBill() {
  const qc = useQueryClient()
  return useMutation<SpaceSession, Error, string>({
    mutationFn: (sessionId) =>
      posJson<SpaceSession>(`/api/pos/space-sessions?id=${sessionId}&action=request-bill`, {
        method: "POST",
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["pos-spaces"] })
    },
  })
}

export function useCancelSpaceSession() {
  const qc = useQueryClient()
  return useMutation<SpaceSession, Error, string>({
    mutationFn: (sessionId) =>
      posJson<SpaceSession>(`/api/pos/space-sessions?id=${sessionId}&action=cancel`, {
        method: "POST",
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["pos-spaces"] })
    },
  })
}

/** Cierra la sesión — llamado al final del flujo de cobro con el transactionId resultante. */
export function useCloseSpaceSession() {
  const qc = useQueryClient()
  return useMutation<SpaceSession, Error, { sessionId: string; transactionId?: string }>({
    mutationFn: ({ sessionId, transactionId }) =>
      posJson<SpaceSession>(`/api/pos/space-sessions?id=${sessionId}&action=close`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ transactionId }),
      }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["pos-spaces"] })
    },
  })
}

export type { SpaceWithState, SpaceShape }
