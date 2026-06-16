"use client"

/**
 * Lock screen del POS — el cajero "bloquea" la caja y debe ingresar su PIN
 * (4 dígitos) para reanudar. No hay input visible: captura las teclas a
 * nivel window y muestra 4 círculos que se llenan a medida que se tipea.
 *
 * Backend: por ahora stub (STUB_PIN = "1234"). F2 lo cambia por POST al
 * backend que valida el PIN del usuario y re-emite el JWT.
 *
 * UX:
 *   - Logo Punto centrado.
 *   - 4 círculos: vacío (solo borde) → relleno (pop animado al pintar).
 *   - Tipeás un dígito → pinta un círculo + animate-pin-pop.
 *   - Backspace borra el último.
 *   - Llega a 4 → valida. OK → unlock(). Falla → shake + reset + mensaje sutil.
 *   - ESC no desbloquea (es lock real).
 */

import * as React from "react"
import { cn } from "@/lib/utils"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { STUB_PIN, useLockStore } from "@/lib/pos/lock-store"

const PIN_LENGTH = 4

export function LockScreen() {
  const locked = useLockStore((s) => s.locked)
  const unlock = useLockStore((s) => s.unlock)

  const [pin, setPin] = React.useState("")
  const [shake, setShake] = React.useState(false)
  const [error, setError] = React.useState(false)
  // Versión del slot que recién se "pintó", para retrigger del bounce sin
  // re-animar los anteriores cuando React reusa el mismo DOM.
  const [poppedIndex, setPoppedIndex] = React.useState(-1)

  // Resetear estado cada vez que entra en locked (evita PIN colgado).
  React.useEffect(() => {
    if (locked) {
      setPin("")
      setShake(false)
      setError(false)
      setPoppedIndex(-1)
    }
  }, [locked])

  // Captura de teclas mientras está locked.
  React.useEffect(() => {
    if (!locked) return
    const onKey = (e: KeyboardEvent) => {
      // Sin modificadores — no atrapamos atajos del sistema.
      if (e.metaKey || e.ctrlKey || e.altKey) return

      if (e.key === "Backspace") {
        e.preventDefault()
        setError(false)
        setPin((prev) => prev.slice(0, -1))
        return
      }

      // Dígitos 0-9 (pad o número de fila).
      if (/^[0-9]$/.test(e.key)) {
        e.preventDefault()
        setError(false)
        setPin((prev) => {
          if (prev.length >= PIN_LENGTH) return prev
          const next = prev + e.key
          setPoppedIndex(next.length - 1)
          return next
        })
      }
    }
    window.addEventListener("keydown", onKey, true)
    return () => window.removeEventListener("keydown", onKey, true)
  }, [locked])

  // Validar al llegar a 4 dígitos.
  React.useEffect(() => {
    if (pin.length !== PIN_LENGTH) return
    // Mini-pausa para que el último pop sea visible antes de evaluar.
    const id = setTimeout(() => {
      if (pin === STUB_PIN) {
        unlock()
      } else {
        // Shake + limpiar + mostrar error sutil.
        setShake(true)
        setError(true)
        setTimeout(() => {
          setShake(false)
          setPin("")
          setPoppedIndex(-1)
        }, 420)
      }
    }, 160)
    return () => clearTimeout(id)
  }, [pin, unlock])

  if (!locked) return null

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Pantalla bloqueada"
      className="fixed inset-0 z-[200] flex flex-col items-center justify-center gap-10 bg-background"
    >
      {/* Logo */}
      <div className={cn(shake && "animate-pin-shake")}>
        <PuntoLogo variant="mark" className="size-20" />
      </div>

      {/* PIN dots */}
      <div className={cn("flex items-center gap-5", shake && "animate-pin-shake")}>
        {Array.from({ length: PIN_LENGTH }).map((_, i) => {
          const filled = i < pin.length
          const justPopped = i === poppedIndex && filled
          return (
            <span
              key={i}
              className={cn(
                "block size-4 rounded-full border-2 transition-colors duration-150",
                filled
                  ? "border-foreground bg-foreground"
                  : "border-muted-foreground/50 bg-transparent",
                justPopped && "animate-pin-pop",
              )}
            />
          )
        })}
      </div>

      {/* Hint / error */}
      <p
        className={cn(
          "h-5 text-sm transition-colors",
          error ? "font-semibold text-destructive" : "text-muted-foreground",
        )}
      >
        {error ? "Código incorrecto" : "Ingresá tu PIN para reanudar"}
      </p>
    </div>
  )
}
