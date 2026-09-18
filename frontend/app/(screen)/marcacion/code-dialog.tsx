"use client"

/**
 * El código numérico del reloj: el RESPALDO, no el camino (context/83 §9.5).
 *
 * Hasta el rediseño el teclado era la pantalla y la cámara un círculo al lado.
 * El owner lo dio vuelta: se marca poniéndose adelante de la cámara, y el código
 * queda para quien no tiene el rostro registrado o para el día en que la cámara
 * no está. Por eso vive detrás de una acción secundaria y no ocupa la pantalla.
 *
 * Lo que NO cambia al entrar por acá: el tipo de marcación se sigue infiriendo
 * solo y el saludo es el mismo. El código reemplaza al reconocimiento, no al
 * automatismo — nadie elige entrada o salida en ningún camino.
 *
 * El PIN se valida LOCALMENTE contra los hashes que bajaron con el roster,
 * igual que el lock screen de la caja: sin red, marcar con código sigue
 * andando (D7).
 */

import * as React from "react"
import { Delete } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { cn } from "@/lib/utils"
import { sha256Hex } from "@/lib/pos/pin-hash"
import type { ClockEmployee } from "@/lib/types/clock"

const PIN_LENGTH = 4

export interface CodeDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  employees: ClockEmployee[]
  /** Dígito con el que se abrió (tablet con teclado físico). */
  seed?: string
  /** Se llama con la persona identificada. El resto lo hace la pantalla. */
  onIdentified: (employee: ClockEmployee) => void
}

export function CodeDialog({
  open,
  onOpenChange,
  employees,
  seed,
  onIdentified,
}: CodeDialogProps) {
  const [pin, setPin] = React.useState("")
  const [error, setError] = React.useState(false)

  // El aviso en una ref: la pantalla lo redefine en cada render (tiene un reloj
  // que avanza cada segundo) y sin esto el efecto de abajo se re-armaría con él,
  // reiniciando el temporizador que valida el código.
  const onIdentifiedRef = React.useRef(onIdentified)
  React.useEffect(() => {
    onIdentifiedRef.current = onIdentified
  })

  // Cada apertura arranca limpia. El dígito con el que se abrió entra acá y no
  // en el estado inicial: el componente no se desmonta entre aperturas.
  React.useEffect(() => {
    if (open) {
      setPin(seed ?? "")
      setError(false)
    }
  }, [open, seed])

  // ── Identificación ────────────────────────────────────────────────────────
  React.useEffect(() => {
    if (!open || pin.length !== PIN_LENGTH) return
    let cancelled = false

    const timer = setTimeout(async () => {
      const hash = await sha256Hex(pin)
      if (cancelled) return

      // `pinHash` nulo = esa persona se identifica por el rostro y no tiene
      // código (§9.3). Se saltea en vez de compararse: sin esta guarda, un
      // `undefined === hash` nunca matchea pero tampoco se lee como intencional.
      const found = employees.find((e) => e.pinHash !== null && e.pinHash === hash) ?? null
      if (!found) {
        setError(true)
        setPin("")
        return
      }
      setPin("")
      setError(false)
      onIdentifiedRef.current(found)
    }, 80)

    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [pin, open, employees])

  // Teclado físico: el comercio que tiene la tablet con teclado marca sin tocar
  // la pantalla.
  React.useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.metaKey || e.ctrlKey || e.altKey) return
      if (e.key === "Backspace") {
        e.preventDefault()
        setError(false)
        setPin((prev) => prev.slice(0, -1))
        return
      }
      if (/^[0-9]$/.test(e.key)) {
        e.preventDefault()
        setError(false)
        setPin((prev) => (prev.length >= PIN_LENGTH ? prev : prev + e.key))
      }
    }
    window.addEventListener("keydown", onKey, true)
    return () => window.removeEventListener("keydown", onKey, true)
  }, [open])

  function press(digit: string) {
    setError(false)
    setPin((prev) => (prev.length >= PIN_LENGTH ? prev : prev + digit))
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      {/* `dark`: el diálogo se monta en un portal al final del body, fuera del
          wrapper oscuro de la pantalla. Sin esto, sobre la cámara en penumbra
          se abriría un panel blanco. */}
      <DialogContent className="dark sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>Marcá con tu código</DialogTitle>
          <DialogDescription className="sr-only">
            Ingresá tu código de cuatro dígitos para registrar la marcación.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col items-center gap-6 pb-2">
          {/* Cuatro círculos, igual que el lock screen: es el mismo gesto y no
              hay razón para que se vea distinto. */}
          <div className="flex items-center gap-8">
            {Array.from({ length: PIN_LENGTH }).map((_, i) => (
              <span
                key={i}
                className={cn(
                  "block size-4 rounded-full border-2 transition-colors",
                  i < pin.length
                    ? "border-foreground bg-foreground"
                    : "border-foreground/40 bg-transparent",
                )}
              />
            ))}
          </div>

          {/* Altura fija: el mensaje de error no puede empujar el teclado. */}
          <p
            className={cn(
              "h-5 text-sm",
              error ? "font-semibold text-destructive" : "text-muted-foreground",
            )}
          >
            {error ? "Ese código no es de nadie" : "Cuatro dígitos"}
          </p>

          {/* Botones de 72px: se tocan con el dedo en una tablet colgada de la
              pared (§2 de context/14 habilita el override con razón documentada). */}
          <div className="grid w-full grid-cols-3 gap-3">
            {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((d) => (
              <Button
                key={d}
                variant="outline"
                className="h-[72px] text-2xl font-medium"
                onClick={() => press(d)}
              >
                {d}
              </Button>
            ))}
            {/* La celda vacía mantiene al 0 centrado y al borrar a la derecha,
                que es donde está en cualquier teclado numérico. */}
            <span aria-hidden />
            <Button
              variant="outline"
              className="h-[72px] text-2xl font-medium"
              onClick={() => press("0")}
            >
              0
            </Button>
            <Button
              variant="outline"
              aria-label="Borrar"
              className="h-[72px]"
              onClick={() => {
                setError(false)
                setPin((prev) => prev.slice(0, -1))
              }}
            >
              <Delete className="size-6" />
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
