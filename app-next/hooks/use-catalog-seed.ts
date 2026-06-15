"use client"

/**
 * Hook que hidrata el `useCatalogStore` con fixtures de desarrollo.
 *
 * Se usa en el register page durante Slice A (dev seed).
 * En producción, la hidratación viene del BFF `/api/pos/bootstrap`
 * via `usePosBootstrap` + `useCatalogStore().hydrate()`.
 *
 * Solo hidrata si el store está en estado "idle" (nunca sobrescribe
 * datos reales del BFF).
 */

import * as React from "react"
import { useCatalogStore } from "@/lib/catalog/store"
import { fixtureBootstrap } from "@/lib/catalog/fixtures"

export function useCatalogSeed() {
  const status = useCatalogStore((s) => s.status)
  const hydrate = useCatalogStore((s) => s.hydrate)

  React.useEffect(() => {
    if (status === "idle") {
      hydrate({
        items: fixtureBootstrap.items,
        customers: fixtureBootstrap.customers,
        config: fixtureBootstrap.config,
        registers: fixtureBootstrap.registers,
      })
    }
  }, [status, hydrate])
}
