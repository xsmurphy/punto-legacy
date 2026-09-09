"use client"

/**
 * Hooks de anulación de ventas (F6, context/40-anulacion-y-nota-credito.md).
 *
 * Endpoint único: `api/v1/sales-void.php`, autenticado con
 * `apiAuthTenant(['panel', 'pos-app'])` y gateado por `pos.sale.void`. O sea
 * que los DOS realms hablan con el mismo backend — lo que cambia es la
 * credencial y, por eso, el transporte.
 *
 * ── Por qué el hook se parametriza y NO se duplica ──────────────────────────
 * **Un cliente HTTP = un realm** (invariante de `lib/api-client.ts`,
 * memoria `project_client_per_realm_no_cross_credentials`). El POS manda el
 * Bearer del device por `posFetch`/`posApi`; el panel manda el Bearer del
 * panel por `api`. El panel NO puede usar `posFetch` — eso es exactamente el
 * cruce de realms que ya costó tres incidentes.
 *
 * La salida NO es una segunda copia del hook: es inyectar el cliente y su
 * ruta, igual que `usePrinterBindings(registerId, { client: posApi })`. Dos
 * copias del flujo de anulación es cómo una se arregla y la otra no.
 *
 *   - panel (default) → `api`    + `/v1/sales-void.php`
 *   - POS             → `posApi` + `/pos/sales-void` (BFF con `requireBearer`)
 *
 * Ninguno de los dos transportes vive acá: este módulo importa SOLO `api`.
 * Exportar un `POS_SALE_VOID` con `posApi` adentro arrastraría
 * `lib/auth/device-token` al bundle del panel, que es justo lo que el guard
 * `lib/auth/__tests__/realm-token-separation.test.ts` protege. El call-site
 * del POS arma su transporte con el cliente que ya tiene importado.
 *
 * `useVoidOptions` — GET: estado de anulabilidad (D4, ventana 48h) + por
 *   cada línea vendida qué es POSIBLE reponer al stock (D2 — el sistema
 *   decide qué es posible, el cajero decide dentro de eso).
 * `useVoidSale`    — POST: anula la venta. `errorCode` en `VoidSaleError`
 *   distingue `VOID_WINDOW_EXPIRED` | `HAS_RETURNS` | `HAS_PAYMENTS` |
 *   `ALREADY_VOIDED` para que la UI ofrezca "Hacer devolución" cuando aplica.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api, ApiError, type HttpClient } from "@/lib/api-client"

// ── Tipos ────────────────────────────────────────────────────────────────────

export interface VoidLine {
  itemSoldId: string
  itemId: string
  name: string
  qty: number
  unitPrice: number
  unitCogs: number
  /** 'ownStock' (repone el ítem) | 'ingredientReversal' (repone insumos, solo si nunca se preparó) | 'service' (nada que reponer) | 'compoundChild' (hija de combo fijo: nunca descontó stock, jamás repone — context/52 G4). */
  kind: "ownStock" | "ingredientReversal" | "service" | "compoundChild"
  canRestock: boolean
  defaultRestock: boolean
  hadStockImpact: boolean
}

export interface CanVoid {
  allowed: boolean
  reason: string | null
  expiresAt: string | null
}

export interface VoidOptions {
  canVoid: CanVoid
  lines: VoidLine[]
}

export interface VoidSaleInput {
  id: string
  reason: string
  lines?: Array<{ itemSoldId: string; restock: boolean }>
}

export interface VoidSaleResult {
  id: string
  voidedAt: string
  restocked: number
  wasted: number
  /**
   * ¿La anulación canceló TAMBIÉN el documento electrónico ante SIFEN?
   *
   * `SaleVoidService` lo hace en cascada dentro de la MISMA transacción que la
   * anulación (paso 5): si SIFEN rechaza, revierte todo. Pero es `false`
   * cuando no había documento vigente que cancelar (nunca se emitió, ya estaba
   * cancelado, o quedó `superseded_by` por un rechazo previo — mig 201). La UI
   * informa lo que este flag dice, NO lo que supuso antes de mandar el POST.
   */
  einvoiceCancelled: boolean
}

/** `errorCode` viene en `error.details.errorCode` del envelope (api/lib/response.php). */
export class VoidSaleError extends Error {
  errorCode?: string
  constructor(message: string, errorCode?: string) {
    super(message)
    this.name = "VoidSaleError"
    this.errorCode = errorCode
  }
}

/**
 * Transporte del endpoint. Mismo patrón que `usePrinterBindings`: el call-site
 * inyecta SU cliente (y por lo tanto SU realm) y la ruta que ese cliente
 * entiende. Omitido = panel.
 */
export interface SaleVoidTransport {
  /** Cliente HTTP del realm. Default `api` (panel). El POS pasa `posApi`. */
  client?: HttpClient
  /** Ruta del endpoint EN ESE cliente. Default `/v1/sales-void.php`. El POS pasa `/pos/sales-void`. */
  path?: string
}

const PANEL_PATH = "/v1/sales-void.php"

function resolveTransport(t: SaleVoidTransport = {}) {
  const client = t.client ?? api
  return {
    client,
    path: t.path ?? PANEL_PATH,
    // Discriminador del queryKey: dos realms pueden tener la misma pantalla
    // abierta en el mismo browser (panel + /pos conviven), y la respuesta se
    // resuelve con credenciales distintas. Misma clave sería cache compartido
    // entre realms — el bug que este invariante evita, pero en el cache.
    realm: client === api ? "panel" : "pos",
  }
}

/**
 * Los dos clientes (`api` y `posApi`) tiran `ApiError` con el envelope crudo
 * en `payload`, pero ninguno lee `details.errorCode` — es específico de este
 * endpoint. Se extrae acá, una sola vez, para los dos realms.
 */
function toVoidSaleError(err: unknown, fallback: string): VoidSaleError {
  if (err instanceof ApiError) {
    const envelope = err.payload as
      | { error?: { message?: string; details?: { errorCode?: string } } }
      | null
    return new VoidSaleError(
      envelope?.error?.message ?? err.message ?? fallback,
      envelope?.error?.details?.errorCode,
    )
  }
  if (err instanceof VoidSaleError) return err
  return new VoidSaleError(err instanceof Error ? err.message : fallback)
}

// ── Hooks ────────────────────────────────────────────────────────────────────

export function useVoidOptions(
  transactionId: string | null,
  enabled = true,
  transport: SaleVoidTransport = {},
) {
  const { client, path, realm } = resolveTransport(transport)
  return useQuery<VoidOptions, VoidSaleError>({
    queryKey: ["sale-void-options", realm, transactionId],
    queryFn: async (): Promise<VoidOptions> => {
      try {
        return await client.get<VoidOptions>(
          `${path}?id=${encodeURIComponent(transactionId!)}`,
        )
      } catch (err) {
        throw toVoidSaleError(err, "No se pudo consultar la anulación")
      }
    },
    enabled: enabled && Boolean(transactionId),
    // La ventana de 48h y el estado de devoluciones/recibos vigentes pueden
    // cambiar entre aperturas del dialog — siempre fresh, sin cache.
    staleTime: 0,
    retry: false,
  })
}

export function useVoidSale(transport: SaleVoidTransport = {}) {
  const queryClient = useQueryClient()
  const { client, path } = resolveTransport(transport)
  return useMutation<VoidSaleResult, VoidSaleError, VoidSaleInput>({
    mutationFn: async (input): Promise<VoidSaleResult> => {
      try {
        return await client.post<VoidSaleResult>(path, input as unknown as Record<string, unknown>)
      } catch (err) {
        throw toVoidSaleError(err, "No se pudo anular la venta")
      }
    },
    onSuccess: () => {
      // Mismo set que useCreateReturn (hooks/use-returns.ts) — la anulación
      // reversa stock y movimiento financiero igual que una devolución.
      //
      // `["pos-transaction"]` SIN el id: `vars.id` es el UUID crudo que pide
      // sales-void.php, pero la query del detalle abierto está cacheada con
      // `encId` (`enc($transactionId)`, TransactionService::mainList) — son
      // strings distintos. invalidateQueries matchea por prefijo, así que
      // el key parcial invalida CUALQUIER detalle abierto sin necesitar el
      // enc — mismo criterio que el fix en use-realtime-sync.ts (entity
      // `transaction`).
      queryClient.invalidateQueries({ queryKey: ["pos-transactions"] })
      queryClient.invalidateQueries({ queryKey: ["pos-transaction"] })
      queryClient.invalidateQueries({ queryKey: ["transactions"] })
      // El detalle del PANEL (`useTransactionDetail`, hooks/use-reports.ts)
      // vive bajo su propia clave: sin esto la pantalla desde la que se anula
      // se queda mostrando la venta viva hasta que expire su staleTime de 30s.
      queryClient.invalidateQueries({ queryKey: ["transaction-detail"] })
      // Y el estado de anulabilidad de ESTA venta, en los dos realms: tras
      // anular, `canVoid.allowed` pasa a false con motivo ALREADY_VOIDED.
      queryClient.invalidateQueries({ queryKey: ["sale-void-options"] })
      queryClient.invalidateQueries({ queryKey: ["stock"] })
      queryClient.invalidateQueries({ queryKey: ["reports"] })
      queryClient.invalidateQueries({ queryKey: ["dashboard"] })
    },
  })
}
