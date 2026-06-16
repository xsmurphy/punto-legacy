"use client"

import { ProductArea } from "@/components/register/product-area"
import { CartPanel } from "@/components/register/cart-panel"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"

export default function PosPage() {
  useCatalogSeed()
  return (
    <div className="flex h-full w-full overflow-hidden">
      {/* Bloque izquierdo (grilla de productos / hotkeys): oculto en mobile.
          En pantallas chicas el POS muestra solo el carrito; los productos se
          agregan desde la lupa de búsqueda del toolbar de caja. */}
      <div className="hidden flex-[7] overflow-hidden md:block">
        <ProductArea />
      </div>
      {/* Carrito: full-width en mobile, 3/10 en desktop. */}
      <div className="flex-1 overflow-hidden md:flex-[3]">
        <CartPanel />
      </div>
    </div>
  )
}
