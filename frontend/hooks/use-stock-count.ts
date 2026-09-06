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
 * La MUTACIÓN no manda ni usa el stock teórico, ni siquiera en modo abierto: el
 * esperado con el que se calcula el ajuste lo resuelve el servidor al aplicar,
 * contra el ledger y en ese momento. El número que el cajero vio mientras
 * contaba es una AYUDA para contar, no la base del ajuste — si viajara en el
 * payload, un conteo que esperó en la cola ajustaría contra un saldo viejo.
 *
 * Ese número lo trae `useStockCountExpected()`, más abajo, y es una lectura
 * aparte a propósito.
 */

import { useMutation, useQuery } from "@tanstack/react-query"

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

/**
 * En qué modo arranca este conteo, y —si es abierto— el stock teórico de cada
 * artículo de la lista (context/63 F2).
 *
 * ── El modo lo dice el SERVIDOR, no esta pantalla ──────────────────────────
 *
 * `inventory.count.open` gobierna el panel y la caja, así que no lleva prefijo
 * `pos.` y `unlock-pin.php` no la baja al dispositivo: la caja NO conoce el
 * permiso del operador. Y está bien que sea así — el filtrado del esperado es
 * del servidor (cuando el modo es ciego, `expectedQty` no viaja), de modo que
 * preguntarle a la respuesta en qué modo estamos es leer la misma fuente que
 * decide. Un flag local que la pantalla tuviera que respetar se evade abriendo
 * las devtools; hasta la mig 169 el cierre de caja a ciegas era exactamente eso.
 *
 * ── Sin red se cuenta a ciegas, y se dice con esa palabra ──────────────────
 *
 * El teórico es ONLINE por decisión de la F2: un número viejo es peor que
 * ninguno (el operador ajusta contra algo que ya no es cierto y firma una
 * diferencia inventada), y el saldo se mueve con cada venta de cualquier caja.
 * Así que si la request no llega, el resultado no es un error: es
 * `{ mode: "blind", reason: "offline" }` — un modo válido con un motivo que la
 * pantalla puede explicar. El conteo ciego sigue siendo offline-nativo y no se
 * toca.
 *
 * ── El modo se decide UNA vez y no cambia bajo los pies ────────────────────
 *
 * `staleTime: Infinity` + sin reintentos ni refetch por foco o reconexión. Que
 * a mitad de un conteo aparezcan de golpe los teóricos —o desaparezcan— es
 * peor que cualquiera de los dos modos estables: lo ya contado se cargó bajo
 * otras reglas. La clave incluye el `listId`, así que cambiar de lista (que ya
 * descarta el borrador) vuelve a resolver.
 */
export type StockCountExpected =
  | { mode: "open"; expected: Record<string, number> }
  /** El servidor resolvió ciego: el comercio lo exige y esta persona no está habilitada. */
  | { mode: "blind"; reason: "policy" }
  /** No se pudo preguntar. Arranca ciego y hay que decirlo. */
  | { mode: "blind"; reason: "offline" }

export function useStockCountExpected(
  listId: string,
  itemIds: string[],
  enabled: boolean,
) {
  return useQuery<StockCountExpected>({
    queryKey: ["pos", "stock-count-expected", listId],
    enabled: enabled && listId !== "",
    staleTime: Infinity,
    gcTime: Infinity,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    queryFn: async (): Promise<StockCountExpected> => {
      // `navigator.onLine` no alcanza para afirmar que HAY red (miente con un
      // router sin salida), pero sí para afirmar que no la hay: ahorra una
      // request que va a fallar igual.
      if (typeof navigator !== "undefined" && !navigator.onLine) {
        return { mode: "blind", reason: "offline" }
      }

      try {
        const params = new URLSearchParams({ action: "expected", listId })
        if (itemIds.length > 0) params.set("itemIds", itemIds.join(","))

        const res = await posFetch(`/api/v1/inventory_count?${params.toString()}`)
        const json = (await res.json().catch(() => null)) as {
          ok?: boolean
          data?: { blind?: boolean; items?: Array<{ itemId?: string; expectedQty?: number }> }
          error?: { message?: string }
        } | null

        if (!res.ok || json?.ok === false) {
          throw new ApiError(res.status, json, json?.error?.message ?? `Error ${res.status}`)
        }
        if (json?.data?.blind !== false) {
          return { mode: "blind", reason: "policy" }
        }

        const expected: Record<string, number> = {}
        for (const it of json.data.items ?? []) {
          if (typeof it?.itemId === "string" && Number.isFinite(Number(it.expectedQty))) {
            expected[it.itemId] = Number(it.expectedQty)
          }
        }
        return { mode: "open", expected }
      } catch (err) {
        if (isUnreachable(err)) return { mode: "blind", reason: "offline" }
        // Un RECHAZO del servidor (403 sin `pos.stock.count`, 409 sin sucursal,
        // 422 con la lista borrada) tampoco puede romper la pantalla: el conteo
        // ciego es lo que este comercio ya hacía ayer y sigue funcionando sin
        // esta lectura. Se degrada a ciego por política, no se tira un error
        // que el cajero no puede resolver en el mostrador.
        return { mode: "blind", reason: "policy" }
      }
    },
  })
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
