"use client"

import * as React from "react"
import { useQueryClient } from "@tanstack/react-query"
import { subscribeRealtime, type InvalidateEvent } from "@/lib/realtime"

/**
 * Mapeo cerrado entity→queryKeys de TanStack Query. Cuando el server
 * publica `{ entity: "item", op: "update", ... }`, invalidamos los keys
 * listados — solo afecta queries ya cacheadas, no provoca fetch innecesarios.
 *
 * Si agregás un dominio nuevo a frontend, sumá su(s) queryKey(s) acá.
 */
const ENTITY_TO_QUERY_KEYS: Record<string, ReadonlyArray<readonly string[]>> = {
  item:              [["items"], ["item"], ["pos-bootstrap"]],
  contact:           [["contacts"], ["contact"], ["customers"], ["team-members"]],
  user:              [["team"]],
  outlet:            [["outlets"]],
  category:          [["categories"], ["taxonomies", "category"], ["pos-bootstrap"]],
  brand:             [["brands"], ["taxonomies", "brand"], ["pos-bootstrap"]],
  tag:               [["tags"], ["taxonomies", "tag"], ["pos-bootstrap"]],
  tax:               [["taxes"], ["taxonomies", "tax"]],
  location:          [["outlet-locations"]],
  transaction:       [["reports"], ["transactions"], ["pos-transactions"], ["dashboard"], ["dashboard-widget"]],
  drawer:            [["reports", "drawers"], ["dashboard"], ["dashboard-widget"]],
  expense:           [["reports", "expenses"], ["dashboard"], ["dashboard-widget"]],
  // setting también invalida pos-bootstrap porque lo usa el POS para leer config del tenant.
  setting:           [["settings"], ["modules"], ["bootstrap"], ["pos-bootstrap"]],
  screen:            [["screens"]],
  "price-list":      [["price-lists"], ["price-list-items"]],
  "parked-sale":     [["parked-sales"]],
  "inventory-count": [["inventory-counts"]],
  "stock-transfer": [["stock-transfers"]],
  "document-template": [["document-templates"]],
  purchase:          [["purchases"]],
  // register: invalida pos-hotkeys (layout de teclas) y pos-bootstrap (config de caja).
  // El PUT ?resource=hotkeys dispara este evento → refetch de pos-hotkeys es benigno
  // (el servidor ya escribió antes del emit, no hay race).
  register:          [["pos-hotkeys"], ["pos-bootstrap"], ["registers"]],
  // Módulo de Órdenes (O1, context/24-orders-module-plan.md). Invalidación
  // genérica de queryKeys — NO es el canal KDS ({companyId}:kds:{outletId},
  // scope O2), ese lo consumen pantallas de cocina/mozos dedicadas.
  order:             [["orders"]],
  // Módulo de Espacios (F2, context/15-espacios-module-plan.md). Invalida tanto
  // el plano operativo del POS (use-pos-spaces.ts) como la config del panel
  // (use-spaces.ts/use-space-sectors.ts, /settings/espacios) — ambos
  // consumen las mismas entidades bajo distintas auth. `space-settlement`
  // (F3, SpaceSettlementService::publishBalance) es prefix-match: invalida
  // TODOS los saldos cacheados, no solo el de la sesión que cambió — barato
  // (son queries livianas) y evita mapear sessionId→queryKey acá.
  space:             [["pos-spaces"], ["pos-space-sectors"], ["spaces"], ["space-sectors"], ["space-settlement"]],
  // pack, payment-method, giftcard, schedule: no hay hooks con queryKeys
  // propios en frontend aún — se agregan cuando existan sus hooks.
}

/**
 * `panel` recibe TODOS los eventos. `pos` ignora scope="dashboard" — el
 * cajero no quiere reinvalidar items en cada venta propia.
 */
export function useRealtimeSync(clientScope: "panel" | "pos" = "panel") {
  const qc = useQueryClient()
  React.useEffect(() => {
    return subscribeRealtime((ev: InvalidateEvent) => {
      if (clientScope === "pos" && ev.scope === "dashboard") return
      const keys = ENTITY_TO_QUERY_KEYS[ev.entity]
      if (!keys) return
      keys.forEach((k) => qc.invalidateQueries({ queryKey: [...k], refetchType: "active" }))
    })
  }, [qc, clientScope])
}
