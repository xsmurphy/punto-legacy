"use client"

/**
 * Sync realtime quirúrgico del catálogo POS — context/15-realtime-sync-plan.md
 * §Modelo quirúrgico (2026-08-16).
 *
 * Reemplaza, para `item`/`contact`, el "invalidar `pos-bootstrap` entero"
 * (re-descarga de 5000+ productos o 10000+ clientes en CADA cambio) por
 * "pedí solo los ids tocados y mergealos en el store". Invocado desde
 * `hooks/use-realtime-sync.ts` cuando `clientScope === "pos"`.
 *
 * Reglas (todas del brief del owner, ver context/15):
 *
 *   - `delete` NUNCA pide nada al server — sería un 404 garantizado (así
 *     funcionaba el legacy: `{channel,action:"delete",id}` borraba local
 *     sin request). Borrado sincrónico, inmediato.
 *   - `create`/`update` encolan el id (o los ids, para el evento de stock
 *     batcheado — ver `Inventory::flushRealtimeStockEvents()`) y se
 *     resuelven con debounce: una ráfaga de N eventos en la ventana de
 *     debounce colapsa a UNA request por entity, no N. Criterio de la
 *     ventana: 400ms de silencio (trailing debounce) con un techo duro de
 *     1500ms — una ráfaga continua (ej. un admin tipeando precio por precio
 *     en una grilla) no puede diferir el sync indefinidamente.
 *   - Un id pedido que NO vuelve en la respuesta del batch (borrado de
 *     verdad, o —solo para items— desactivado/no-vendible) se saca del
 *     store — nunca queda fantasma.
 *   - Si el request de sync falla (red, 5xx, parseo), fallback a invalidar
 *     `["pos-bootstrap"]` completo — mejor la re-descarga cara que quedarse
 *     con datos viejos en silencio.
 */

import type { QueryClient } from "@tanstack/react-query"
import { useCatalogStore } from "@/lib/catalog/store"
import { posApi } from "@/lib/api/pos-client"
import type { PosItem, PosCustomer } from "@/lib/types/pos-bootstrap"

type CatalogKind = "item" | "contact"
type CatalogOp = "create" | "update" | "delete"

const DEBOUNCE_MS = 400
const MAX_WAIT_MS = 1500

const pendingItemIds = new Set<string>()
const pendingCustomerIds = new Set<string>()
let flushTimer: ReturnType<typeof setTimeout> | null = null
let windowStartedAt: number | null = null

/**
 * Punto de entrada único desde `use-realtime-sync.ts`. `ids` son los ids
 * afectados por el evento — para `item` puede venir de `ev.ids` (batch de
 * stock) o `[ev.id]` (mutación puntual de catálogo); para `contact` siempre
 * `[ev.id]` hoy (no hay batching de contactos todavía).
 */
export function queueCatalogSync(kind: CatalogKind, op: CatalogOp, ids: string[], qc: QueryClient): void {
  if (ids.length === 0) return

  if (op === "delete") {
    // Legacy exacto: delete borra local, sin pedir nada (404 garantizado).
    if (kind === "item") useCatalogStore.getState().removeItems(ids)
    else useCatalogStore.getState().removeCustomers(ids)
    const pending = kind === "item" ? pendingItemIds : pendingCustomerIds
    for (const id of ids) pending.delete(id)
    return
  }

  const pending = kind === "item" ? pendingItemIds : pendingCustomerIds
  for (const id of ids) pending.add(id)
  scheduleFlush(qc)
}

function scheduleFlush(qc: QueryClient): void {
  if (windowStartedAt === null) windowStartedAt = Date.now()
  if (flushTimer) clearTimeout(flushTimer)
  const elapsed = Date.now() - windowStartedAt
  const wait = Math.min(DEBOUNCE_MS, Math.max(0, MAX_WAIT_MS - elapsed))
  flushTimer = setTimeout(() => {
    void flush(qc)
  }, wait)
}

async function flush(qc: QueryClient): Promise<void> {
  flushTimer = null
  windowStartedAt = null

  const itemIds = Array.from(pendingItemIds)
  const customerIds = Array.from(pendingCustomerIds)
  pendingItemIds.clear()
  pendingCustomerIds.clear()

  await Promise.allSettled([
    itemIds.length > 0 ? syncItems(itemIds, qc) : Promise.resolve(),
    customerIds.length > 0 ? syncCustomers(customerIds, qc) : Promise.resolve(),
  ])
}

async function syncItems(ids: string[], qc: QueryClient): Promise<void> {
  try {
    const data = await posApi.post<{ items: PosItem[] }>("/pos/items-batch", { ids })
    const found = new Set(data.items.map((i) => i.id))
    const missing = ids.filter((id) => !found.has(id))
    if (data.items.length > 0) useCatalogStore.getState().patchItems(data.items)
    // Ids pedidos que no volvieron: borrados de verdad, o desactivados/no
    // vendibles (el BFF ya filtró por `isSellableItemRow`) — mismo destino
    // en ambos casos, no dejar fantasma.
    if (missing.length > 0) useCatalogStore.getState().removeItems(missing)
  } catch {
    void qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
  }
}

async function syncCustomers(ids: string[], qc: QueryClient): Promise<void> {
  try {
    const data = await posApi.post<{ customers: PosCustomer[] }>("/pos/customers-batch", { ids })
    const found = new Set(data.customers.map((c) => c.id))
    const missing = ids.filter((id) => !found.has(id))
    if (data.customers.length > 0) useCatalogStore.getState().patchCustomers(data.customers)
    if (missing.length > 0) useCatalogStore.getState().removeCustomers(missing)
  } catch {
    void qc.invalidateQueries({ queryKey: ["pos-bootstrap"] })
  }
}
