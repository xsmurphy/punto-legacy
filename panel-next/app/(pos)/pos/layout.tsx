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
 * Guard de caja activa (Slice A7):
 *   - Sin caja → muestra RegisterSelectorDialog (bloquea el POS).
 *   - 1 sola caja → auto-selecciona con useSetActiveRegister una vez.
 *   - 0 cajas → selector con mensaje "no hay cajas configuradas".
 *   - Caja activa → render normal.
 *
 * Responsive: en mobile el bloque izquierdo se oculta (solo carrito).
 */

import * as React from "react"
import { CartPanel } from "@/components/register/cart-panel"
import { RegisterSelectorDialog } from "@/components/register/register-selector-dialog"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"
import { useCatalogStore } from "@/lib/catalog/store"
import { useSetActiveRegister } from "@/hooks/use-active-register"

function RegisterGuard({ children }: { children: React.ReactNode }) {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const registers = useCatalogStore((s) => s.registers)
  const status = useCatalogStore((s) => s.status)
  const { mutate, isPending } = useSetActiveRegister()

  // Guard de auto-selección para cuando hay exactamente 1 caja.
  // El ref evita que se llame más de una vez aunque el componente re-renderice.
  const autoSelectFiredRef = React.useRef(false)

  React.useEffect(() => {
    if (
      status === "ready" &&
      activeRegisterId === "" &&
      registers.length === 1 &&
      !isPending &&
      !autoSelectFiredRef.current
    ) {
      autoSelectFiredRef.current = true
      mutate(registers[0].id)
    }
  }, [status, activeRegisterId, registers, isPending, mutate])

  // Mientras el catálogo no está hidratado, no renderizar el guard
  // (evita flicker del selector mientras llega el bootstrap).
  if (status !== "ready") return <>{children}</>

  // Sin caja activa → mostrar selector (bloquea el POS).
  if (activeRegisterId === "") {
    return (
      <>
        {children}
        <RegisterSelectorDialog />
      </>
    )
  }

  // Caja activa → render normal.
  return <>{children}</>
}

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
      <div className="hidden flex-[7] overflow-hidden md:block">
        <RegisterGuard>{children}</RegisterGuard>
      </div>

      {/* Carrito (persistente) — full-width en mobile, 3/10 en desktop. */}
      <div className="flex-1 overflow-hidden md:flex-[3]">
        <CartPanel />
      </div>
    </div>
  )
}
