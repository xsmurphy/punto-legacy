"use client"

/**
 * Pantalla de caja principal — Slice A1.
 *
 * Layout 2 columnas full-screen (fidelidad §6.1):
 *   ┌─────────────────────────────────┬──────────────────────┐
 *   │  ProductArea (~70%)             │  CartPanel (~30%)    │
 *   │  - Grid de tiles (cat/producto) │  - Toolbar iconos    │
 *   │  - CategoryBar inferior         │  - Lista de líneas   │
 *   │  - FAB "+" + info de sesión     │  - Controles activos │
 *   │                                 │  - Total verde       │
 *   └─────────────────────────────────┴──────────────────────┘
 *
 * Datos: fixture seed (dev) — hidratados en `useCatalogSeed()`.
 * En producción el store se hidrata desde `/api/pos/bootstrap`.
 *
 * Ver context/16-app-next-rewrite.md §6.1 y §7 Slice A.
 */

import { ProductArea } from "@/components/register/product-area"
import { CartPanel } from "@/components/register/cart-panel"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"

export default function RegisterPage() {
  // Hidrata el catálogo con fixtures (dev seed).
  // TODO (Slice A backend): reemplazar con hydrate() desde usePosBootstrap().
  useCatalogSeed()

  return (
    <div className="flex h-full w-full overflow-hidden">
      {/* Izquierda: grid de productos (~70%) */}
      <div className="flex-[7] overflow-hidden">
        <ProductArea />
      </div>

      {/* Derecha: panel de carrito (~30%) */}
      <div className="flex-[3] overflow-hidden">
        <CartPanel />
      </div>
    </div>
  )
}
