"use client"

/**
 * Layout del workspace de la caja.
 *
 * El bloque DERECHO (carrito / venta) es persistente — vive en este layout,
 * así que se mantiene montado (y conserva su estado) mientras el bloque
 * IZQUIERDO cambia según la ruta:
 *   /pos            → grilla de hotkeys (ProductArea)
 *   /pos/mesas      → módulo Mesas
 *   /pos/ordenes    → módulo Órdenes
 *   /pos/calendario → módulo Calendario
 *
 * Los items del sidebar del POS navegan entre estas rutas: solo cambia el
 * bloque izquierdo, el carrito de la derecha no se desmonta.
 *
 * Responsive: en mobile el bloque izquierdo se oculta (solo carrito).
 */

import { CartPanel } from "@/components/register/cart-panel"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"

export default function PosWorkspaceLayout({
  children,
}: {
  children: React.ReactNode
}) {
  // Hidrata el catálogo una vez; persiste mientras se navega entre vistas.
  useCatalogSeed()

  return (
    <div className="flex h-full w-full overflow-hidden">
      {/* Bloque izquierdo (intercambiable por ruta) — oculto en mobile. */}
      <div className="hidden flex-[7] overflow-hidden md:block">{children}</div>

      {/* Carrito (persistente) — full-width en mobile, 3/10 en desktop. */}
      <div className="flex-1 overflow-hidden md:flex-[3]">
        <CartPanel />
      </div>
    </div>
  )
}
