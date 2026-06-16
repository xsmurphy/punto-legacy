"use client"

/**
 * Modal de configuración inicial del dispositivo POS (2 pasos).
 *
 * Se muestra cuando el guard del layout detecta que no hay caja activa
 * Y no hay un default guardado en localStorage para este dispositivo.
 *
 * Paso 1 — Sucursal:
 *   - Si `outlets.length <= 1` → se salta (la única sucursal ya es la activa).
 *   - Si `outlets.length > 1` → picker de sucursales. Al elegir, llama
 *     `useSetActiveOutlet` y espera a que el bootstrap se refetchee con las
 *     cajas de esa sucursal.
 *
 * Paso 2 — Caja:
 *   - Si `registers.length === 1` → auto-selección (no se muestra picker).
 *   - Si `registers.length > 1` → picker con botones grandes (misma UI que
 *     RegisterSelectorDialog).
 *   - Si `registers.length === 0` → mensaje "no hay cajas configuradas".
 *
 * Al completar ambos pasos → llama `setDeviceDefault({ outletId, registerId })`
 * y el guard cierra el modal al detectar `activeRegisterId !== ''`.
 *
 * No-dismissable: sin ESC, sin click-outside, sin botón de cierre.
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Loader2 } from "lucide-react"
import { useCatalogStore } from "@/lib/catalog/store"
import { useSetActiveRegister } from "@/hooks/use-active-register"
import { useSetActiveOutlet } from "@/hooks/use-bootstrap"
import { setDeviceDefault } from "@/lib/pos/device"

type SetupStep = "outlet" | "register"

export function DeviceSetupDialog() {
  const outlets = useCatalogStore((s) => s.outlets)
  const registers = useCatalogStore((s) => s.registers)
  const outlet = useCatalogStore((s) => s.outlet)

  const {
    mutate: setOutlet,
    isPending: isPendingOutlet,
    variables: outletVariables,
  } = useSetActiveOutlet()

  const {
    mutate: setRegister,
    isPending: isPendingRegister,
    variables: registerVariables,
  } = useSetActiveRegister()

  // Determinamos el paso inicial: si hay más de 1 sucursal, empezamos por
  // la elección de sucursal; si no, pasamos directo a la caja.
  const initialStep: SetupStep = outlets.length > 1 ? "outlet" : "register"
  const [step, setStep] = React.useState<SetupStep>(initialStep)

  // ID de la sucursal elegida en el paso 1 (puede ser la activa si no se mostró).
  const [chosenOutletId, setChosenOutletId] = React.useState<string>(
    outlet?.id ?? "",
  )

  // Cuando el bootstrap se refetchea tras elegir sucursal, avanzamos al paso 2.
  // Detectamos esto mirando si el step sigue siendo "outlet" pero outlets ya
  // cargó — en realidad lo que indica el avance es que la mutation terminó y
  // el query fue invalidado. Usamos un ref para no disparar el efecto dos veces.
  const outletAppliedRef = React.useRef(false)

  // Manejador: el usuario eligió una sucursal.
  const handleOutletSelect = (outletId: string) => {
    if (isPendingOutlet || outletAppliedRef.current) return
    outletAppliedRef.current = true
    setChosenOutletId(outletId)
    setOutlet(outletId, {
      onSuccess: () => {
        // El bootstrap se invalida en onSuccess de useSetActiveOutlet.
        // Avanzamos al paso 2 (las registers llegan del refetch).
        setStep("register")
      },
    })
  }

  // Auto-selección si hay exactamente 1 caja en el paso 2.
  const autoRegisterFiredRef = React.useRef(false)

  React.useEffect(() => {
    if (step !== "register") return
    // Esperar a que el refetch tras el cambio de sucursal haya settleado: el
    // store solo refleja la sucursal elegida (y SUS cajas) cuando outlet.id
    // coincide con chosenOutletId. Sin este gate, registers podría ser todavía
    // la lista vieja de la sucursal anterior → auto-select de una caja ajena.
    if (outlet?.id !== chosenOutletId) return
    if (registers.length !== 1) return
    if (autoRegisterFiredRef.current) return
    autoRegisterFiredRef.current = true
    const regId = registers[0].id
    setRegister(regId, {
      onSuccess: () => {
        setDeviceDefault({ outletId: chosenOutletId, registerId: regId })
      },
    })
  }, [step, registers, setRegister, chosenOutletId, outlet])

  // Manejador: el usuario eligió una caja manualmente.
  const handleRegisterSelect = (registerId: string) => {
    if (isPendingRegister) return
    setRegister(registerId, {
      onSuccess: () => {
        setDeviceDefault({ outletId: chosenOutletId, registerId })
      },
    })
  }

  // ── Contenido dinámico según el paso ──────────────────────────────────────

  let title: string
  let description: string
  let content: React.ReactNode

  if (step === "outlet") {
    title = "Seleccioná la sucursal"
    description = "Elegí desde qué sucursal vas a operar en este dispositivo."
    content = (
      <div className="mt-4 flex flex-col gap-3">
        {outlets.map((o) => {
          const isThis = outletVariables === o.id && isPendingOutlet
          return (
            <Button
              key={o.id}
              variant="outline"
              size="lg"
              className="h-auto justify-start gap-4 px-5 py-4 text-left"
              disabled={isPendingOutlet}
              onClick={() => handleOutletSelect(o.id)}
            >
              {isThis ? (
                <Loader2 className="size-5 shrink-0 animate-spin text-muted-foreground" />
              ) : (
                <span className="flex size-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-sm font-semibold">
                  {o.name.slice(0, 2).toUpperCase()}
                </span>
              )}
              <span className="flex-1 truncate text-base font-medium">
                {o.name}
              </span>
            </Button>
          )
        })}
      </div>
    )
  } else {
    title = "Seleccioná la caja"
    description = "Elegí la caja desde la que vas a operar en esta sesión."
    // Tras elegir sucursal, el bootstrap se refetchea de forma asíncrona. Hasta
    // que el store refleje la sucursal elegida, la lista de cajas es la vieja:
    // mostramos un loader en vez de cajas ajenas a la sucursal recién elegida.
    const isResolvingOutlet = outlet?.id !== chosenOutletId
    content = (
      <div className="mt-4 flex flex-col gap-3">
        {isResolvingOutlet ? (
          <div className="flex items-center justify-center py-6">
            <Loader2 className="size-6 animate-spin text-muted-foreground" />
          </div>
        ) : registers.length === 0 ? (
          <p className="rounded-lg border border-border bg-muted/40 px-4 py-5 text-center text-sm text-muted-foreground">
            No hay cajas configuradas en esta sucursal.{" "}
            <span className="font-medium text-foreground">
              Configurá una en el panel.
            </span>
          </p>
        ) : (
          registers.map((reg) => {
            const isThis = registerVariables === reg.id && isPendingRegister
            return (
              <Button
                key={reg.id}
                variant="outline"
                size="lg"
                className="h-auto justify-start gap-4 px-5 py-4 text-left"
                disabled={isPendingRegister}
                onClick={() => handleRegisterSelect(reg.id)}
              >
                {isThis ? (
                  <Loader2 className="size-5 shrink-0 animate-spin text-muted-foreground" />
                ) : (
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-md border border-border bg-muted text-sm font-semibold">
                    {reg.name.slice(0, 2).toUpperCase()}
                  </span>
                )}
                <span className="flex-1 truncate text-base font-medium">
                  {reg.name}
                </span>
              </Button>
            )
          })
        )}
      </div>
    )
  }

  return (
    <Dialog open modal>
      {/* Sin DialogClose ni click-outside → no hay forma de cerrar sin elegir */}
      <DialogContent
        className="sm:max-w-md"
        onEscapeKeyDown={(e) => e.preventDefault()}
        onPointerDownOutside={(e) => e.preventDefault()}
        onInteractOutside={(e) => e.preventDefault()}
        showCloseButton={false}
      >
        <DialogHeader>
          <DialogTitle className="text-xl">{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        {/* Indicador de paso (solo si hay selección de sucursal) */}
        {outlets.length > 1 && (
          <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span
              className={
                step === "outlet" ? "font-semibold text-foreground" : ""
              }
            >
              Sucursal
            </span>
            <span>→</span>
            <span
              className={
                step === "register" ? "font-semibold text-foreground" : ""
              }
            >
              Caja
            </span>
          </div>
        )}

        {content}
      </DialogContent>
    </Dialog>
  )
}
