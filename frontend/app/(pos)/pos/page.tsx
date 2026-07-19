/**
 * Vista por defecto de la caja — grilla de hotkeys (categorías → productos).
 *
 * Es el bloque izquierdo del workspace (ver layout.tsx). El carrito vive en
 * el layout y se mantiene; el sidebar del POS intercambia este bloque por
 * los otros módulos (Espacios / Órdenes / Calendario). El icono "Hotkeys" del
 * sidebar vuelve acá.
 */

import { ProductArea } from "@/components/register/product-area"

export default function PosPage() {
  return <ProductArea />
}
