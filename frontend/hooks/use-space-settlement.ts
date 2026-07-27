"use client"

/**
 * Hooks del split de cuenta (F3a+F3b+F3c, context/15-espacios-module-plan.md
 * §F3). Backend: `SpaceSettlementService` (api/lib/Spaces/), endpoint
 * `/v1/space-settlements.php` vía el BFF `/api/pos/space-settlements`.
 *
 * Mismo patrón que `use-orders.ts`: device POS → `posFetch` (Bearer, realm
 * `pos-app`), sin cookie de panel.
 *
 * Alcance de este commit: solo los hooks (lectura de saldo + registro de
 * pago). La UI del split (diálogo de modos, selección de ítems) llega en un
 * commit siguiente — nada acá asume todavía cómo se invoca desde la pantalla.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { posFetch } from "@/lib/api/pos-fetch"

// ── Tipos ─────────────────────────────────────────────────────────────────────

export interface SettlementItem {
  id: string
  orderId: string
  name: string
  qty: number
  price: number
  /** qty × price, ya redondeado a 2 decimales. */
  total: number
  /** true si ya fue cobrado (kind='items') — no seleccionable de nuevo. */
  settled: boolean
  settledPaymentId: string | null
}

export interface SessionBalance {
  sessionId: string
  /** Σ ítems no cancelados de órdenes activas de la sesión. */
  total: number
  /** Σ pagos ya registrados en el ledger. */
  paid: number
  /** total − paid. La sesión solo puede cerrarse cuando llega a ≤ 0. */
  balance: number
  items: SettlementItem[]
}

export type SettlementKind = "items" | "amount" | "share"

/**
 * Payload de un pago parcial. `transactionId` viene de un `SaleService`
 * previo (fuera de este hook, ver `use-orders.ts::useMarkOrderPaid` para el
 * mismo contrato) — cada cobro parcial es su propio comprobante.
 *
 *   - kind='items':  orderItemIds requerido, amount se calcula server-side
 *                     (cualquier amount mandado acá se ignora).
 *   - kind='amount': amount requerido (monto libre / adelanto).
 *   - kind='share':  shareCount + shareIndex (1-based) requeridos — el monto
 *                     de cada parte lo calcula el backend (redondeo: la
 *                     última parte absorbe el resto).
 */
export type RegisterSessionPaymentInput =
  | { sessionId: string; transactionId: string; kind: "items"; orderItemIds: string[] }
  | { sessionId: string; transactionId: string; kind: "amount"; amount: number }
  | { sessionId: string; transactionId: string; kind: "share"; shareCount: number; shareIndex: number }

async function posJson<T>(url: string, init?: RequestInit): Promise<T> {
  const res = await posFetch(url, init)
  const json = await res.json().catch(() => null)
  if (!res.ok || !json?.ok) {
    throw new Error(json?.error?.message ?? `Error ${res.status}`)
  }
  return json.data as T
}

// ── Fetchers imperativos ──────────────────────────────────────────────────────

/**
 * Saldo de la sesión leído AHORA, sin caché (mismo patrón que
 * `fetchOrdersBySession` en `use-orders.ts`).
 *
 * Existe porque el saldo cacheado no sirve para DECIDIR un cobro: entre que
 * el diálogo lo mostró y el cajero toca "Cobrar" puede haber entrado un pago
 * parcial de otro dispositivo, y con un `paid` viejo se tomaría el camino de
 * "mesa completa" (markPaid + close, sin ledger) sobre una mesa que ya tenía
 * plata cobrada — doble cobro. La UI muestra el saldo cacheado; para cobrar,
 * relee.
 */
export function fetchSessionBalance(sessionId: string): Promise<SessionBalance> {
  return posJson<SessionBalance>(`/api/pos/space-settlements?sessionId=${sessionId}`)
}

// ── Queries ───────────────────────────────────────────────────────────────────

/**
 * Saldo pendiente de una sesión de espacio. `refetchInterval` corto porque
 * el saldo puede cambiar por pagos de OTRO dispositivo (dos mozos cobrando
 * la misma mesa) — el WS (`space:settlement`, ver
 * `SpaceSettlementService::publishBalance`) invalida vía
 * `use-realtime-sync.ts` cuando eso pasa, esto es solo la red de contención.
 */
export function useSessionBalance(sessionId: string | null) {
  return useQuery<SessionBalance>({
    queryKey: ["space-settlement", "balance", sessionId],
    queryFn: () => posJson<SessionBalance>(`/api/pos/space-settlements?sessionId=${sessionId}`),
    enabled: !!sessionId,
    staleTime: 5 * 1000,
    refetchInterval: 15 * 1000,
  })
}

// ── Mutaciones ────────────────────────────────────────────────────────────────

/**
 * Registra un pago parcial. Si el pago cubre el saldo restante, el backend
 * liquida la sesión en la misma operación (markPaid de las órdenes + close)
 * — no hay un segundo paso a llamar desde acá.
 *
 * Invalida tanto el saldo de la sesión como `["orders"]` (las órdenes de la
 * sesión pueden pasar a `closed` si este pago liquida) y `["spaces"]` (el
 * espacio vuelve a `free` si la sesión se cerró).
 */
export function useRegisterSessionPayment() {
  const qc = useQueryClient()
  return useMutation<SessionBalance, Error, RegisterSessionPaymentInput>({
    mutationFn: ({ sessionId, ...body }) =>
      posJson<SessionBalance>(`/api/pos/space-settlements?sessionId=${sessionId}&action=pay`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      }),
    onSuccess: (_data, variables) => {
      void qc.invalidateQueries({ queryKey: ["space-settlement", "balance", variables.sessionId] })
      void qc.invalidateQueries({ queryKey: ["orders"] })
      void qc.invalidateQueries({ queryKey: ["spaces"] })
    },
  })
}
