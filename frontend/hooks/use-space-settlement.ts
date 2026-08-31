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
  /**
   * Familia de modo ya comprometida en este espacio, o null si todavía no se
   * cobró nada. NO se pueden mezclar en una misma sesión: `items` marca los
   * ítems y descuenta su stock una vez; `amount`/`share` prorratean sobre los
   * ítems no saldados (para facturar mercadería real) pero NO los marcan, así
   * que mezclar descuenta stock dos veces del mismo ítem. La UI deshabilita
   * los modos de la otra familia; el enforcement real está en el backend.
   */
  lockedFamily: "items" | "amount" | null
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
 * "espacio completo" (markPaid + close, sin ledger) sobre un espacio que ya tenía
 * plata cobrada — doble cobro. La UI muestra el saldo cacheado; para cobrar,
 * relee.
 */
export function fetchSessionBalance(sessionId: string): Promise<SessionBalance> {
  return posJson<SessionBalance>(`/api/pos/space-settlements?sessionId=${sessionId}`)
}

/**
 * Preflight de un pago parcial: corre las mismas validaciones que el POST
 * real (`action=pay`) sin registrar nada — mismo motivo que
 * `fetchSessionBalance` para no cachear: es una pregunta imperativa previa a
 * decidir, no un estado que la UI muestre en pantalla.
 *
 * Existe porque hasta ahora el flujo de cobro parcial creaba la venta
 * PRIMERO y recién después intentaba `useRegisterSessionPayment` — si el
 * backend rechazaba el pago (espacio ya cobrado por otra familia, ítem ya
 * saldado, etc.), la plata ya había entrado a la caja pero el espacio seguía
 * debiendo lo mismo (descuadre; bug T1, 2026-08-03). El caller debe correr
 * esto ANTES de crear la venta y abortar el cobro si lanza — el mensaje de
 * `Error.message` es el motivo real del backend, apto para mostrarle al
 * cajero (no un genérico "avisá al soporte").
 *
 * No elimina la ventana de carrera entera (ver docblock de
 * `SpaceSettlementService::preflightPayment` en el backend) — reduce el caso
 * común al costo de un round-trip extra antes de comprometer la venta.
 */
export function validateSessionPayment(input: RegisterSessionPaymentInput): Promise<{ ok: true; amount: number; balance: number }> {
  const { sessionId, ...body } = input
  return posJson(`/api/pos/space-settlements?sessionId=${sessionId}&action=validate`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  })
}

// ── Queries ───────────────────────────────────────────────────────────────────

/**
 * Saldo pendiente de una sesión de espacio. `refetchInterval` corto porque
 * el saldo puede cambiar por pagos de OTRO dispositivo (dos mozos cobrando
 * el mismo espacio) — el WS (`space:settlement`, ver
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
