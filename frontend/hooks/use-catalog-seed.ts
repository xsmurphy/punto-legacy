"use client"

/**
 * Hidrata el `useCatalogStore` desde el BFF `/api/pos/bootstrap`.
 *
 * Fuente de verdad: lo que devuelve `usePosBootstrap()` se vuelca al store.
 * La UI del POS (búsqueda, grilla de productos, modales) lee SIEMPRE del
 * store — nunca llama directo al BFF para consultar el catálogo.
 *
 * Fallback dev: si `NEXT_PUBLIC_USE_FIXTURES=1`, se hidrata con
 * `fixtureBootstrap` en lugar del BFF real. Útil para diseñar UI sin
 * tener JWT pos-app válido contra el backend real. NO usar en producción.
 *
 * Solo hidrata cuando el store está en estado "idle" — nunca pisa datos
 * ya cargados (evita race conditions con `patchCustomer` / `patchItem`).
 */

import * as React from "react"
import { useCatalogStore } from "@/lib/catalog/store"
import { fixtureBootstrap, fixtureHotkeys } from "@/lib/catalog/fixtures"
import { usePosBootstrap } from "@/hooks/use-pos-bootstrap"
import { useHotkeysStore } from "@/lib/hotkeys/store"

const USE_FIXTURES = process.env.NEXT_PUBLIC_USE_FIXTURES === "1"

export function useCatalogSeed() {
  const status = useCatalogStore((s) => s.status)
  const hydrate = useCatalogStore((s) => s.hydrate)
  const { data: bootstrap } = usePosBootstrap()

  React.useEffect(() => {
    // Fixtures: hidratar solo la primera vez (no re-pegar al fixture).
    if (USE_FIXTURES) {
      if (status !== "idle") return
      hydrate({
        items: fixtureBootstrap.items,
        customers: fixtureBootstrap.customers,
        config: fixtureBootstrap.config,
        outlet: fixtureBootstrap.outlet,
        outlets: fixtureBootstrap.outlets,
        registers: fixtureBootstrap.registers,
        paymentMethods: fixtureBootstrap.paymentMethods,
        users: [],
        // En fixtures el guard no debe bloquear: auto-seleccionamos la primera caja.
        activeRegisterId: fixtureBootstrap.registers[0]?.id ?? "",
        taxes: fixtureBootstrap.taxes,
        outletTaxIncluded: fixtureBootstrap.outletTaxIncluded,
      })
      // Seed de hotkeys de ejemplo (solo si no hay config persistida).
      if (useHotkeysStore.getState().hotkeys.length === 0) {
        useHotkeysStore.getState().hydrate(fixtureHotkeys)
      }
      return
    }

    // Bootstrap real: re-hidratar cada vez que el bootstrap cambia
    // (no solo en idle). Esto es lo que permite que al cambiar de caja
    // (POST /v1/active-register → invalidate → refetch), el catalog store
    // refleje el nuevo activeRegisterId sin necesidad de refrescar la página.
    if (bootstrap) {
      hydrate({
        items: bootstrap.items,
        customers: bootstrap.customers,
        config: bootstrap.config,
        outlet: bootstrap.outlet,
        outlets: bootstrap.outlets,
        registers: bootstrap.registers,
        paymentMethods: bootstrap.paymentMethods,
        users: bootstrap.users ?? [],
        activeRegisterId: bootstrap.activeRegisterId,
        taxes: bootstrap.taxes,
        outletTaxIncluded: bootstrap.outletTaxIncluded,
      })
    }
  }, [status, hydrate, bootstrap])
}
