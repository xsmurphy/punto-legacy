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
  item:        [["items"], ["item"]],
  contact:     [["contacts"], ["contact"], ["customers"], ["team-members"]],
  outlet:      [["outlets"]],
  category:    [["categories"], ["taxonomies", "category"]],
  brand:       [["brands"], ["taxonomies", "brand"]],
  tag:         [["tags"], ["taxonomies", "tag"]],
  tax:         [["taxes"], ["taxonomies", "tax"]],
  transaction: [["reports"], ["transactions"], ["dashboard"]],
  drawer:      [["reports", "drawers"], ["dashboard"]],
  expense:     [["reports", "expenses"], ["dashboard"]],
  setting:     [["settings"], ["modules"], ["bootstrap"]],
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
