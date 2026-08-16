"use client"

/**
 * Sync incremental del POS (context/43-sync-incremental.md) — reemplaza,
 * al RECONECTAR el WS (`lib/realtime.ts`), el `qc.invalidateQueries()` a
 * ciegas de antes (recarga `pos-bootstrap` entero: 5000+ productos o
 * 10000+ clientes en CADA reconexión) por "pedí solo lo que cambió desde
 * la última marca de agua".
 *
 * Tres secciones (`api/lib/Sync/SyncService.php`):
 *   - `items`/`customers`: delta por fila + lápidas de borrado
 *     (`deleted_row`, mig 138) — se mergean en `useCatalogStore` con
 *     `patchItems`/`removeItems` (mismo mecanismo que el sync realtime
 *     quirúrgico de `lib/catalog/realtime-catalog-sync.ts`, no uno nuevo).
 *   - `settings`: bundle chico (outlet/register/tax/category/brand/tag/
 *     payment-method/printer_binding/user) — sin delta por fila, cuando
 *     está stale simplemente se re-pide `pos-bootstrap` completo (barato,
 *     cambia poco).
 *
 * Alcance de ESTA sesión: cubre la reconexión (el store ya está caliente en
 * memoria — no hace falta persistencia para tener algo sobre lo cual
 * aplicar el delta). El arranque en frío ("al arrancar el POS") sigue
 * usando `usePosBootstrap()` completo sin cambios — safeer con esta
 * primera versión, PRIMA la marca de agua al terminar (ver
 * `useCatalogSeed`) para que la PRIMERA reconexión después de un arranque
 * ya sea delta. Activar delta también en frío requiere persistir el
 * catálogo local (IndexedDB) — deliberadamente fuera de esta sesión, ver
 * context/43-sync-incremental.md §Qué quedó afuera.
 */

import type { QueryClient } from "@tanstack/react-query"
import { useCatalogStore } from "@/lib/catalog/store"
import { posApi } from "@/lib/api/pos-client"
import { loadWatermarks, saveWatermarks } from "@/lib/catalog/sync-watermarks"
import type { PosItem, PosCustomer } from "@/lib/types/pos-bootstrap"

interface WatermarksResponse {
  items: string | null
  customers: string | null
  settings: string | null
  serverTime: string
}

interface ItemsDeltaResponse {
  items: PosItem[]
  deletedIds: string[]
  full: boolean
  serverTime: string
}

interface CustomersDeltaResponse {
  customers: PosCustomer[]
  deletedIds: string[]
  full: boolean
  serverTime: string
}

export type DeltaSyncOutcome = "delta" | "full" | "skipped" | "error"

/** `server` más nuevo que `local`. `null` de cualquiera de los dos se trata como "sin baseline". */
function isNewer(server: string | null, local: string | null): boolean {
  if (server === null) return false
  if (local === null) return true
  return server > local
}

/**
 * Trae los watermarks del server (barato, 1 query indexada por sección) y
 * los persiste — usado tanto por `runDeltaSync` como, en frío, por
 * `useCatalogSeed` para "primar" la marca de agua justo después de un
 * bootstrap completo (así la PRIMERA reconexión ya es delta).
 */
export async function primeWatermarks(companyId: string): Promise<void> {
  try {
    const wm = await posApi.get<WatermarksResponse>("/pos/sync?resource=watermarks")
    saveWatermarks(companyId, { items: wm.items, customers: wm.customers, settings: wm.settings })
  } catch {
    // Best-effort: si falla, el próximo reconnect simplemente no encuentra
    // watermark y cae a `qc.invalidateQueries()` completo (fallback seguro).
  }
}

/**
 * Punto de entrada único del sync incremental. Llamado desde
 * `use-realtime-sync.ts` en cada reconexión del WS (`clientScope==="pos"`).
 * Nunca lanza — cualquier fallo de red cae al fallback ya establecido por
 * el resto del sync quirúrgico (invalidar `pos-bootstrap`, ver
 * `realtime-catalog-sync.ts`), nunca rompe la reconexión.
 */
export async function runDeltaSync(companyId: string, qc: QueryClient): Promise<DeltaSyncOutcome> {
  let watermarksResp: WatermarksResponse
  try {
    watermarksResp = await posApi.get<WatermarksResponse>("/pos/sync?resource=watermarks")
  } catch {
    return "error"
  }

  const local = loadWatermarks(companyId)

  // "settings" es de cardinalidad baja (outlet/register/tax/category/
  // brand/tag/payment-method/printer_binding/user — decenas, no miles) —
  // sin delta por fila: cuando cambió, re-pedir `pos-bootstrap` completo YA
  // trae items/customers frescos también, así que no hace falta pedir su
  // delta por separado en esta pasada.
  if (isNewer(watermarksResp.settings, local.settings)) {
    await qc.invalidateQueries({ queryKey: ["pos-bootstrap"], refetchType: "active" })
    saveWatermarks(companyId, {
      items: watermarksResp.items,
      customers: watermarksResp.customers,
      settings: watermarksResp.settings,
    })
    return "full"
  }

  const jobs: Array<Promise<DeltaSyncOutcome>> = []
  if (isNewer(watermarksResp.items, local.items)) {
    jobs.push(syncItemsDelta(companyId, local.items, qc))
  }
  if (isNewer(watermarksResp.customers, local.customers)) {
    jobs.push(syncCustomersDelta(companyId, local.customers, qc))
  }
  if (jobs.length === 0) return "skipped"

  const settled = await Promise.allSettled(jobs)
  const outcomes = settled.map((r) => (r.status === "fulfilled" ? r.value : "error"))
  if (outcomes.includes("error")) return "error"
  if (outcomes.includes("full")) return "full"
  return "delta"
}

async function syncItemsDelta(companyId: string, since: string | null, qc: QueryClient): Promise<DeltaSyncOutcome> {
  try {
    const data = await posApi.post<ItemsDeltaResponse>("/pos/sync", { section: "items", since })
    if (data.full) {
      await qc.invalidateQueries({ queryKey: ["pos-bootstrap"], refetchType: "active" })
      saveWatermarks(companyId, { items: data.serverTime })
      return "full"
    }
    if (data.items.length > 0) useCatalogStore.getState().patchItems(data.items)
    if (data.deletedIds.length > 0) useCatalogStore.getState().removeItems(data.deletedIds)
    saveWatermarks(companyId, { items: data.serverTime })
    return "delta"
  } catch {
    // Mismo fallback que el resto del sync quirúrgico (ver
    // realtime-catalog-sync.ts): mejor la recarga cara que quedarse con
    // datos viejos en silencio. NO tocamos el watermark — si el próximo
    // intento tiene éxito, el `since` sigue siendo válido.
    await qc.invalidateQueries({ queryKey: ["pos-bootstrap"], refetchType: "active" })
    return "error"
  }
}

async function syncCustomersDelta(companyId: string, since: string | null, qc: QueryClient): Promise<DeltaSyncOutcome> {
  try {
    const data = await posApi.post<CustomersDeltaResponse>("/pos/sync", { section: "customers", since })
    if (data.full) {
      await qc.invalidateQueries({ queryKey: ["pos-bootstrap"], refetchType: "active" })
      saveWatermarks(companyId, { customers: data.serverTime })
      return "full"
    }
    if (data.customers.length > 0) useCatalogStore.getState().patchCustomers(data.customers)
    if (data.deletedIds.length > 0) useCatalogStore.getState().removeCustomers(data.deletedIds)
    saveWatermarks(companyId, { customers: data.serverTime })
    return "delta"
  } catch {
    await qc.invalidateQueries({ queryKey: ["pos-bootstrap"], refetchType: "active" })
    return "error"
  }
}
