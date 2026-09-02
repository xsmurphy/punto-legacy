"use client"

/**
 * Conteo de stock desde la caja (context/63 F1).
 *
 * Una sola mutación, porque un conteo es UN hecho: el cajero recorre el
 * mostrador, carga las cantidades y confirma. Adentro el servidor crea la
 * sesión, congela el esperado, carga las líneas y finaliza — todo atómico,
 * atado al `opId` que este hook genera antes de intentar nada.
 *
 * ── Por qué el `opId` nace ACÁ y no en la cola ─────────────────────────────
 *
 * Porque hay dos caminos y tienen que compartir identidad. Con red se manda
 * directo; si la request no vuelve (timeout, red que se corta a mitad), la
 * operación se encola para reintentar. El servidor pudo haberla aplicado, así
 * que el reintento DEBE llevar el mismo id: es lo único que le permite
 * reconocer "esto ya lo hice" en vez de crear un segundo conteo con un segundo
 * ajuste de stock.
 *
 * Si el `opId` lo generara la cola, el reintento nacería con uno nuevo y el
 * mostrador terminaría con el doble de la diferencia ajustada.
 *
 * ── Qué NO hace ────────────────────────────────────────────────────────────
 *
 * No lee el stock teórico ni lo muestra: el conteo de la caja es CIEGO (D2), y
 * el POS no tiene ese dato de todos modos. El esperado lo resuelve el servidor
 * al aplicar, contra el ledger y en ese momento — que es el saldo correcto
 * para derivar el ajuste, no el que había cuando el cajero empezó.
 */

import { useMutation } from "@tanstack/react-query"

import { posFetch } from "@/lib/api/pos-fetch"
import { ApiError } from "@/lib/api-client"
import { enqueueOp, newOpId } from "@/lib/pos/pending-ops"
import type { StockCountPayload } from "@/lib/pos/local-register-state"

export interface StockCountResult {
  /** `true` si la operación quedó en la cola y todavía no llegó al servidor. */
  queued: boolean
  /** Líneas con diferencia. `null` cuando quedó en cola: todavía no se sabe. */
  adjustmentsCount: number | null
  /** ¿Se movió el stock? `false` en modo registro (D9). `null` si quedó en cola. */
  applied: boolean | null
}

/**
 * ¿El fallo fue "no se pudo hablar con el servidor"?
 *
 * Mismo criterio que `use-pos-config.ts`: `navigator.onLine` no alcanza (dice
 * `true` con un router sin salida), así que lo que decide es que la request no
 * haya obtenido respuesta. Un rechazo del servidor —403 sin permiso, 422 con
 * la lista borrada— es una respuesta y NO se encola: reintentarlo sería
 * martillar con el mismo payload, y el cajero tiene que enterarse.
 */
function isUnreachable(err: unknown): boolean {
  if (typeof navigator !== "undefined" && !navigator.onLine) return true
  return err instanceof TypeError
}

export function useSubmitStockCount() {
  return useMutation<StockCountResult, Error, StockCountPayload>({
    mutationFn: async (payload) => {
      const opId = newOpId()

      const enqueueOffline = async (): Promise<StockCountResult> => {
        await enqueueOp({
          opId,
          kind: "stockCount",
          // Canal propio: los conteos son independientes entre sí y del turno,
          // así que no tienen por qué esperar detrás de un cierre de caja
          // rechazado (ni frenarlo).
          stream: "stock-count",
          registerId: payload.registerId ?? "",
          payload,
          label: `Conteo de stock — ${payload.listName}`,
          // Sin `mergePayload`: dos conteos del mismo mostrador son DOS hechos,
          // no una corrección del anterior. El owner fue explícito en que cada
          // conteo es un evento autónomo.
        })
        return { queued: true, adjustmentsCount: null, applied: null }
      }

      if (typeof navigator !== "undefined" && !navigator.onLine) {
        return enqueueOffline()
      }

      try {
        const res = await posFetch("/api/v1/inventory_count", {
          method: "POST",
          headers: { "Content-Type": "application/json", "X-Punto-Op-Id": opId },
          body: JSON.stringify({
            action: "registerCount",
            listId: payload.listId,
            listName: payload.listName,
            itemIds: payload.itemIds,
            rows: payload.rows,
            countedAt: payload.countedAt,
            note: payload.note ?? null,
          }),
        })

        const json = (await res.json().catch(() => null)) as {
          ok?: boolean
          data?: { adjustmentsCount?: number; applied?: boolean }
          error?: { message?: string }
        } | null

        if (!res.ok || json?.ok === false) {
          throw new ApiError(res.status, json, json?.error?.message ?? `Error ${res.status}`)
        }

        return {
          queued: false,
          adjustmentsCount: Number(json?.data?.adjustmentsCount ?? 0),
          applied: json?.data?.applied === true,
        }
      } catch (err) {
        if (isUnreachable(err)) return enqueueOffline()
        throw err
      }
    },
  })
}
