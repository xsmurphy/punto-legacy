"use client"

import * as React from "react"
import { useRouter } from "next/navigation"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { PurchaseFormSheet } from "@/components/purchases/purchase-form-sheet"

/**
 * `/purchase` (singular) — entrada al form de crear una compra/gasto desde
 * el item "Compras y Gastos" del menú user del sidebar.
 *
 * Renderea el PurchaseFormSheet con `open={true}`. Al cerrar (cancelar,
 * Esc, ✕) o tras un submit exitoso, navega a `/reports/purchases` (el
 * historial donde aparece la nueva compra). Mantiene el comportamiento
 * mental del legacy: "abro Compras → cargo factura → me deja viendo la
 * lista".
 *
 * NOTA: el sheet se renderea sobre un layout (panel) sin contenido de
 * fondo — si se quiere el patrón "modal sobre página previa" como en
 * /settings, habría que mover esto a @modal/(.)purchase. Por ahora la
 * página dedicada es suficiente.
 */
export default function NewPurchasePage() {
  const router = useRouter()
  const { data: bootstrap } = useBootstrap()

  const handleOpenChange = (next: boolean) => {
    if (!next) {
      // Pequeño delay para que la animación de salida del sheet alcance a
      // correr antes del unmount por la navegación.
      setTimeout(() => router.push("/reports/purchases"), 120)
    }
  }

  return (
    <PurchaseFormSheet
      open={true}
      onOpenChange={handleOpenChange}
      defaultOutletId={bootstrap?.activeOutletId ?? ""}
    />
  )
}
