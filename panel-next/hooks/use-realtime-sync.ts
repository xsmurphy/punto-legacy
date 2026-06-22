"use client"

import * as React from "react"
import { useQueryClient } from "@tanstack/react-query"
import { subscribeRealtime, type InvalidateEvent } from "@/lib/realtime"

/**
 * Mapeo cerrado entity→queryKeys de TanStack Query. Cuando el server
 * publica `{ entity: "item", op: "update", ... }`, invalidamos los keys
 * listados — solo afecta queries ya cacheadas, no provoca fetch innecesarios.
 *
 * Si agregás un dominio nuevo a panel-next, sumá su(s) queryKey(s) acá.
 */
const ENTITY_TO_QUERY_KEYS: Record<string, ReadonlyArray<readonly string[]>> = {
  item:              [["items"], ["item"]],
  contact:           [["contacts"], ["contact"], ["customers"], ["team-members"]],
  user:              [["team"]],
  outlet:            [["outlets"]],
  category:          [["categories"], ["taxonomies", "category"]],
  brand:             [["brands"], ["taxonomies", "brand"]],
  tag:               [["tags"], ["taxonomies", "tag"]],
  tax:               [["taxes"], ["taxonomies", "tax"]],
  location:          [["outlet-locations"]],
  transaction:       [["reports"], ["transactions"], ["dashboard"], ["dashboard-widget"]],
  drawer:            [["reports", "drawers"], ["dashboard"], ["dashboard-widget"]],
  expense:           [["reports", "expenses"], ["dashboard"], ["dashboard-widget"]],
  // setting también invalida pos-bootstrap porque lo usa el POS para leer config del tenant.
  setting:           [["settings"], ["modules"], ["bootstrap"], ["pos-bootstrap"]],
  screen:            [["screens"]],
  "price-list":      [["price-lists"], ["price-list-items"]],
  "parked-sale":     [["parked-sales"]],
  "inventory-count": [["inventory-counts"]],
  "document-template": [["document-templates"]],
  purchase:          [["purchases"]],
  // register: invalida pos-hotkeys (layout de teclas) y pos-bootstrap (config de caja).
  // El PUT ?resource=hotkeys dispara este evento → refetch de pos-hotkeys es benigno
  // (el servidor ya escribió antes del emit, no hay race).
  register:          [["pos-hotkeys"], ["pos-bootstrap"]],
  // pack, payment-method, giftcard, table, schedule: no hay hooks con queryKeys
  // propios en panel-next aún — se agregan cuando existan sus hooks.
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
      keys.forEach((k) => qc.invalidateQueries({ queryKey: [...k] }))
    })
  }, [qc, clientScope])
}
