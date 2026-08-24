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
 * Fuente offline: `usePosBootstrap()` sirve el snapshot de IndexedDB cuando
 * la red no responde (ver `lib/pos/bootstrap-cache.ts`), así que este hook
 * hidrata igual sin conexión y no necesita saber de dónde vino el dato —
 * salvo para no primar marcas de agua de sync con datos cacheados.
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
import { primeInvoiceNumbering, primeInvoiceRange } from "@/lib/pos/invoice-numbering"
import { useOfflineSyncStore } from "@/lib/pos/offline-sync-store"

const USE_FIXTURES = process.env.NEXT_PUBLIC_USE_FIXTURES === "1"

export function useCatalogSeed() {
  const status = useCatalogStore((s) => s.status)
  const hydrate = useCatalogStore((s) => s.hydrate)
  const { data: bootstrap, dataUpdatedAt } = usePosBootstrap()
  // ¿Este bootstrap salió de la red o del snapshot de IndexedDB? Cambia qué
  // efectos secundarios corresponde disparar (ver `primeWatermarks` abajo).
  // La hidratación en sí es idéntica en los dos casos: catálogo viejo se
  // muestra, no se esconde.
  const fromCache = useOfflineSyncStore((s) => s.catalogFromCache)

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
      // Numeración de fixtures: arranca en 1 si el device nunca vendió en
      // esta caja fixture — sin esto, vender bajo NEXT_PUBLIC_USE_FIXTURES=1
      // explota con NO_INVOICE_NUMBER (ver lib/pos/invoice-numbering.ts).
      const fixtureRegisterId = fixtureBootstrap.registers[0]?.id ?? ""
      if (fixtureRegisterId) primeInvoiceNumbering(fixtureRegisterId, 1)
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
      // Del SNAPSHOT: hidratar una sola vez, nunca re-hidratar.
      //
      // Offline, la query se re-resuelve sola cada 60s (poll de sesión) y cada
      // vez devuelve el MISMO snapshot con un `dataUpdatedAt` nuevo. Sin este
      // corte, la guarda de frescura de abajo lo tomaría por "más fresco que
      // el último patch" y volvería a volcar el snapshot sobre el store —
      // borrando cada minuto los patches locales acumulados durante el corte:
      // los descuentos de stock optimistas de cada venta offline, y cualquier
      // cliente/ítem tocado desde la caja. Datos viejos pisando datos nuevos.
      //
      // Re-hidratar solo tiene sentido con datos de RED, que sí traen algo que
      // el store todavía no sabe. Ver `catalogFromCache` en offline-sync-store.
      if (fromCache && status !== "idle") return

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
          // mig 158 — formato del correlativo impreso, no el número.
          invoicePadWidth: bootstrap.invoicePadWidth ?? null,
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
        //
        // SOLO con datos de red: la marca de agua afirma "ya tengo todo lo
        // que el server tenía hasta este instante". Primarla con un snapshot
        // de IndexedDB sería mentir sobre una sincronización que no ocurrió
        // — el device se saltearía para siempre todos los cambios ocurridos
        // entre el guardado del snapshot y la reconexión.
        const companyId = bootstrap.config?.companyId
        if (companyId && !fromCache) void primeWatermarks(String(companyId))
        // Numeración del POS (context/29): siembra/corrige el contador local
        // de "último correlativo + 1" de la caja activa con lo que el
        // servidor reporta — ver docblock de primeInvoiceNumbering(). Se
        // llama en CADA hidratación (no solo la primera), no solo al montar,
        // para que un cambio de caja o una corrección server-side se reflejen.
        if (bootstrap.activeRegisterId) {
          primeInvoiceNumbering(bootstrap.activeRegisterId, bootstrap.nextInvoiceNo)
          // Techo del timbrado (D5, context/37) — se persiste junto al
          // contador para que el preaviso "quedan N números" funcione
          // offline. `?? null` y no un skip: si el panel QUITÓ el rango,
          // hay que borrar el techo guardado, no dejar el viejo avisando.
          primeInvoiceRange(bootstrap.activeRegisterId, bootstrap.invoiceRangeTo ?? null)
        }
      }
    }
  }, [status, hydrate, bootstrap, dataUpdatedAt, fromCache])
}
