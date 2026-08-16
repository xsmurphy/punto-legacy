"use client"

/**
 * Resuelve nombre de categoría/marca a partir del id, contra las listas del
 * bundle `settings` (`useCatalogStore.categories`/`.brands`) — mismo patrón
 * que el carrito resuelve la tasa de impuesto por `PosItem.taxId` contra
 * `PosBootstrap.taxes` (ver `lib/types/pos-bootstrap.ts`, comentario de
 * `PosTaxRate`). El ítem NUNCA vuelve a llevar el nombre copiado
 * (context/45-satelites-item-contact-sync.md §Decisión: el VÍNCULO es
 * satélite, la ENTIDAD no) — renombrar una categoría no debe re-bajar los
 * miles de ítems que la referencian.
 *
 * `useCategoryBrandMaps` memoiza un `Map` por lista (no un `find()` por
 * ítem): el buscador y la grilla de productos recorren miles de ítems por
 * render — un `.find()` por ítem convertiría un recorrido lineal en
 * cuadrático (`components/register/product-area.tsx`,
 * `product-search-dialog.tsx`).
 */

import * as React from "react"
import { useCatalogStore } from "@/lib/catalog/store"

/** Fallback explícito para una categoría referenciada por id que ya no existe (renombrada/borrada). */
export const FALLBACK_CATEGORY_NAME = "Categoría"

export function useCategoryBrandMaps() {
  const categories = useCatalogStore((s) => s.categories)
  const brands = useCatalogStore((s) => s.brands)

  const categoryMap = React.useMemo(
    () => new Map(categories.map((c) => [c.id, c.name])),
    [categories],
  )
  const brandMap = React.useMemo(
    () => new Map(brands.map((b) => [b.id, b.name])),
    [brands],
  )

  return { categoryMap, brandMap }
}

/**
 * `categoryId === null` → el ítem no tiene categoría, `null` (se omite del
 * render). `categoryId` sin match en `categoryMap` → categoría borrada o
 * renombrada fuera de este snapshot: fallback explícito, nunca "undefined".
 */
export function resolveCategoryName(
  categoryId: string | null,
  categoryMap: Map<string, string>,
): string | null {
  if (categoryId === null) return null
  return categoryMap.get(categoryId) ?? FALLBACK_CATEGORY_NAME
}

/**
 * La marca es metadata opcional del ítem (a diferencia de la categoría, que
 * agrupa la grilla) — sin match, se omite del render en vez de mostrar un
 * fallback textual.
 */
export function resolveBrandName(
  brandId: string | null,
  brandMap: Map<string, string>,
): string | null {
  if (brandId === null) return null
  return brandMap.get(brandId) ?? null
}
