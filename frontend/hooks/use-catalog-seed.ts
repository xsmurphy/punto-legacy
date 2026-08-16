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
 * Solo hidrata cuando el store está en estado "idle", o cuando el bootstrap
 * recién llegado es MÁS NUEVO que el último patch quirúrgico aplicado —
 * evita que una re-hidratación en vuelo pise un `patchItem`/`patchCustomer`
 * más reciente que llegó por WS mientras el fetch del bootstrap estaba en
 * curso (ver `lastPatchedAt` en `lib/catalog/store.ts` y context/15 §Modelo
 * quirúrgico — la re-hidratación, a su vez, SIEMPRE gana si es más nueva:
 * es la fuente de verdad completa, un patch viejo no debe sobrevivirla).
 */

import * as React from "react"
import { useCatalogStore } from "@/lib/catalog/store"
import { fixtureBootstrap, fixtureHotkeys } from "@/lib/catalog/fixtures"
import { usePosBootstrap } from "@/hooks/use-pos-bootstrap"
import { useHotkeysStore } from "@/lib/hotkeys/store"
import { primeWatermarks } from "@/lib/catalog/delta-sync"

const USE_FIXTURES = process.env.NEXT_PUBLIC_USE_FIXTURES === "1"

export function useCatalogSeed() {
  const status = useCatalogStore((s) => s.status)
  const hydrate = useCatalogStore((s) => s.hydrate)
  const { data: bootstrap, dataUpdatedAt } = usePosBootstrap()

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
        categories: fixtureBootstrap.categories,
        brands: fixtureBootstrap.brands,
        printTemplates: fixtureBootstrap.printTemplates ?? [],
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
    //
    // Guarda de frescura: si el store ya tiene un patch quirúrgico MÁS NUEVO
    // que este fetch (`dataUpdatedAt` es el momento en que la respuesta
    // llegó — TanStack lo pisa en cada refetch resuelto), no hidratar —
    // sería pisar hacia atrás un cambio que ya se aplicó con datos más
    // frescos. La invalidación de `pos-bootstrap` ahora es rara (la mayoría
    // de los cambios de catálogo se resuelven quirúrgico, sin tocar esta
    // query) así que esta carrera es angosta, pero no imposible.
    if (bootstrap) {
      const lastPatchedAt = useCatalogStore.getState().lastPatchedAt
      if (status === "idle" || dataUpdatedAt >= lastPatchedAt) {
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
          categories: bootstrap.categories ?? [],
          brands: bootstrap.brands ?? [],
          printTemplates: bootstrap.printTemplates ?? [],
        })
        // Sync incremental (context/43-sync-incremental.md): un bootstrap
        // completo YA sincronizó las 3 secciones — primar la marca de agua
        // acá (server time, no el reloj del dispositivo) deja el próximo
        // RECONNECT del WS listo para pedir delta desde el arranque, en vez
        // de necesitar un ciclo completo primero. Best-effort, no bloquea
        // la hidratación si falla.
        const companyId = bootstrap.config?.companyId
        if (companyId) void primeWatermarks(String(companyId))
      }
    }
  }, [status, hydrate, bootstrap, dataUpdatedAt])
}
