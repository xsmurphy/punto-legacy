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
 * Guard de caja activa (Slice A7 + setup de dispositivo):
 *   - Sin caja activa + default en localStorage → aplica en silencio (sin modal).
 *     Si el outletId del default difiere de la sucursal activa → useSetActiveOutlet
 *     primero; luego useSetActiveRegister.
 *   - Sin caja activa + sin default → DeviceSetupDialog (2 pasos: sucursal → caja).
 *   - Caja activa → render normal.
 *
 * Responsive: en mobile el bloque izquierdo se oculta (solo carrito).
 */

import * as React from "react"
import { CartPanel } from "@/components/register/cart-panel"
import { DeviceSetupDialog } from "@/components/register/device-setup-dialog"
import { LockScreen } from "@/components/register/lock-screen"
import { PosLoadingScreen } from "@/components/register/pos-loading-screen"
import { useCatalogSeed } from "@/hooks/use-catalog-seed"
import { useHotkeys } from "@/hooks/use-hotkeys"
import { usePosHotkeys } from "@/hooks/use-pos-hotkeys"
import { useCatalogStore } from "@/lib/catalog/store"
import { useSetActiveRegister } from "@/hooks/use-active-register"
import { useBootstrap, useSetActiveOutlet } from "@/hooks/use-bootstrap"
import { getDeviceDefault, clearDeviceDefault } from "@/lib/pos/device"
import { useLockStore } from "@/lib/pos/lock-store"
import { useCartStore } from "@/lib/cart/store"

function RegisterGuard({ children }: { children: React.ReactNode }) {
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const registers = useCatalogStore((s) => s.registers)
  const outlet = useCatalogStore((s) => s.outlet)
  const status = useCatalogStore((s) => s.status)

  const { mutate: setRegister, isPending: isPendingRegister } =
    useSetActiveRegister()
  const { mutate: setOutlet, isPending: isPendingOutlet } = useSetActiveOutlet()

  // Ref para que los efectos de auto-aplicación no se disparen más de una vez.
  const autoApplyFiredRef = React.useRef(false)

  // Intentar aplicar el default del dispositivo en silencio al montar.
  // Solo cuando status === "ready" y aún no hay caja activa.
  React.useEffect(() => {
    if (status !== "ready") return
    if (activeRegisterId !== "") return
    if (autoApplyFiredRef.current) return

    const deviceDefault = getDeviceDefault()
    if (!deviceDefault) return

    // Marcar disparado para no volver a ejecutar en re-renders.
    autoApplyFiredRef.current = true

    const { outletId, registerId } = deviceDefault

    // ¿La sucursal del default ya es la activa?
    if (outlet?.id === outletId) {
      // Misma sucursal → solo seleccionar la caja.
      setRegister(registerId, {
        onError: () => {
          // Si la caja ya no existe, limpiar el default para que muestre el modal.
          clearDeviceDefault()
        },
      })
    } else {
      // Distinta sucursal → cambiar sucursal primero, luego seleccionar caja.
      setOutlet(outletId, {
        onSuccess: () => {
          setRegister(registerId, {
            onError: () => {
              clearDeviceDefault()
            },
          })
        },
        onError: () => {
          // La sucursal ya no existe → limpiar default y dejar que muestre el modal.
          clearDeviceDefault()
        },
      })
    }
  }, [status, activeRegisterId, outlet, setRegister, setOutlet])

  // Mientras el catálogo no está hidratado, no renderizar el guard
  // (evita flicker del selector mientras llega el bootstrap).
  if (status !== "ready") return <>{children}</>

  // Mientras se está aplicando el default en silencio, no mostrar el modal.
  const isApplyingDefault =
    (isPendingRegister || isPendingOutlet) && autoApplyFiredRef.current

  // Sin caja activa → mostrar setup de dispositivo (2 pasos).
  if (activeRegisterId === "" && !isApplyingDefault) {
    return (
      <>
        {children}
        <DeviceSetupDialog />
      </>
    )
  }

  // Caja activa (o aplicando default en silencio) → render normal.
  return <>{children}</>
}

function BeforeUnloadGuard() {
  const lineCount = useCartStore((s) => s.lines.length)

  React.useEffect(() => {
    if (lineCount === 0) return
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault()
      e.returnValue = ""
    }
    window.addEventListener("beforeunload", handler)
    return () => window.removeEventListener("beforeunload", handler)
  }, [lineCount])

  return null
}

export default function PosWorkspaceLayout({
  children,
}: {
  children: React.ReactNode
}) {
  // Hidrata el catálogo una vez; persiste mientras se navega entre vistas.
  useCatalogSeed()
  // Carga los hotkeys de la caja activa desde el backend y los hidrata en el store.
  useHotkeys()
  // Atajos de teclado globales (Q/W/E/R/Enter) — operación rápida sin mouse.
  usePosHotkeys()

  // Flujo de arranque:
  //   1. Por default arrancamos con <PosLoadingScreen /> mientras bootstrap
  //      no llegó. Esto evita el "flash" donde se ve el contenido del POS
  //      por un tick antes de que el efecto del auto-lock decida bloquear.
  //   2. Cuando llega bootstrap, decidimos SÍNCRONAMENTE (no en useEffect):
  //      userCount > 1 → aplicamos lock antes del primer render del contenido.
  //      userCount <= 1 (o undefined) → no bloqueamos.
  //      Hacer esto sincrónicamente con un ref hace que el primer paint del
  //      contenido ya respete la decisión: nunca se ve el POS desbloqueado
  //      antes del LockScreen.
  const { data: bootstrap } = useBootstrap()
  const autoLockApplied = React.useRef(false)
  if (!autoLockApplied.current && bootstrap) {
    autoLockApplied.current = true
    const userCount = bootstrap.userCount
    if (typeof userCount === "number" && userCount > 1) {
      useLockStore.getState().lock()
    }
  }

  // Loading screen mientras no llega bootstrap. Una vez llega, el render
  // del POS ya nace con el lock aplicado (o sin lock) según userCount.
  if (!bootstrap) {
    return <PosLoadingScreen />
  }

  return (
    <div className="relative flex h-full w-full overflow-hidden">
      <BeforeUnloadGuard />
      {/* Bloque izquierdo (intercambiable por ruta) — oculto en mobile. */}
      <div className="hidden flex-[7] overflow-hidden md:block">
        <RegisterGuard>{children}</RegisterGuard>
      </div>

      {/* Carrito (persistente) — full-width en mobile, 3/10 en desktop. */}
      <div className="flex-1 overflow-hidden md:flex-[3]">
        <CartPanel />
      </div>

      {/* Lock screen — overlay fullscreen activado desde el menú de usuario. */}
      <LockScreen />
    </div>
  )
}
