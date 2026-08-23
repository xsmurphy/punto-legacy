/**
 * Wrapper compartido para agregar un `PosItem` del catálogo al carrito.
 *
 * Único punto de entrada usado por los 3 call-sites del POS (grid de
 * categorías/hotkeys en `product-area.tsx`, búsqueda en
 * `product-search-dialog.tsx`, scanner de barras en `cart-panel.tsx`) —
 * evita triplicar el chequeo de `kind === "descuento"` y su feedback de UI.
 *
 * `useCartStore.getState().addItem` ya contiene la decisión de negocio
 * (¿entra como línea o como descuento de venta?) y devuelve un
 * discriminador; acá solo se traduce ese resultado a un toast.
 *
 * kind === "giftcard" (F2 giftcard-issue-flow): NO agrega la línea directo —
 * abre `GiftcardIssueDialog` (vía giftcard-issue-store) para capturar monto/
 * código/beneficiario/vencimiento. La línea se agrega recién al confirmar
 * ese dialog.
 *
 * hasAddons (F4, context/41): mismo mecanismo — abre `AddonPickerDialog` para
 * elegir los add-ons y la línea entra recién al confirmar. El chequeo va acá y
 * no en el call-site del grid: la búsqueda de productos y el scanner de barras
 * agregan por esta misma puerta y tienen que preguntar igual, o venderían el
 * producto con el precio base y sin lo que va a cocina.
 */

import { toast } from "sonner"
import { useCartStore } from "@/lib/cart/store"
import { useGiftcardIssueStore } from "@/lib/cart/giftcard-issue-store"
import { useAddonPickerStore } from "@/lib/cart/addon-picker-store"
import type { PosItem } from "@/lib/types/pos-bootstrap"

export function addCatalogItem(item: PosItem): void {
  if (item.kind === "giftcard") {
    useGiftcardIssueStore.getState().open(item)
    return
  }

  // Producto SIN grupos → sigue de largo y se agrega directo, exactamente como
  // antes: cero fricción para el comercio que no usa la feature.
  // `kind === "descuento"` queda afuera aunque tuviera grupos: no entra como
  // línea de carrito (aplica un % de descuento de venta), así que un modal de
  // add-ons ahí no tendría dónde guardar la selección.
  if (item.hasAddons && item.kind !== "descuento") {
    useAddonPickerStore.getState().open(item)
    return
  }

  // Leído ANTES de addItem: si ya había un saleDiscount activo (manual desde
  // sale-options-drawer.tsx, u otro item "descuento" agregado antes), se
  // sobreescribe — el cajero debe enterarse explícitamente, no perderlo en
  // silencio.
  const previousDiscount = useCartStore.getState().saleDiscount

  const result = useCartStore.getState().addItem({
    id: item.id,
    name: item.name,
    price: item.price,
    kind: item.kind,
    discountPercent: item.discountPercent,
    // F2b (context/38): impuesto real del ítem. `lib/cart/line-tax.ts` lo
    // resuelve desde acá tanto para el IVA que muestra el carrito como para
    // el neteo de "quitar IVA" — ya no hay ninguna tasa hardcodeada.
    taxId: item.taxId,
    taxIncluded: item.taxIncluded,
  })

  if (result === "discount-applied") {
    if (previousDiscount) {
      const prevLabel =
        previousDiscount.mode === "percent" ? `${previousDiscount.value}%` : previousDiscount.value
      toast.success(`Descuento de venta reemplazado: ${prevLabel} → ${item.discountPercent}%`)
    } else {
      toast.success(`Descuento de venta aplicado: ${item.discountPercent}%`)
    }
  } else if (result === "discount-missing") {
    toast.error(`"${item.name}" no tiene % de descuento configurado en el catálogo`)
  }
}
