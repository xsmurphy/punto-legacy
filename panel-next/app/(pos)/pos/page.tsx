"use client"

import { ProductArea } from "@/components/register/product-area"
import { CartPanel } from "@/components/register/cart-panel"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"

export default function PosPage() {
  useCatalogSeed()
  return (
    <div className="flex h-full w-full overflow-hidden">
      <div className="flex-[7] overflow-hidden">
        <ProductArea />
      </div>
      <div className="flex-[3] overflow-hidden">
        <CartPanel />
      </div>
    </div>
  )
}
