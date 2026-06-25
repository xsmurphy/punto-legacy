"use client"

/**
 * Lock screen del POS — el cajero "bloquea" la caja y debe ingresar su PIN
 * (4 dígitos) para reanudar. No hay input visible: captura las teclas a
 * nivel window y muestra 4 círculos que se llenan a medida que se tipea.
 *
 * PIN: validado localmente con bcrypt.compare() contra los hashes del catalog store.
 * Decision del owner (2026-06-25): match local-first para funcionar offline.
 * POST best-effort a /api/pos/audit-unlock solo para logging — si falla (offline),
 * el unlock ya ocurrio de todas formas.
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
import { Lock } from "lucide-react"
import { toast } from "sonner"
import { cn } from "@/lib/utils"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { useLockStore } from "@/lib/pos/lock-store"
import { useCatalogStore } from "@/lib/catalog/store"

const PIN_LENGTH = 4

export function LockScreen() {
  const locked = useLockStore((s) => s.locked)
  const unlock = useLockStore((s) => s.unlock)
  const setActiveUser = useLockStore((s) => s.setActiveUser)
  const outletName = useCatalogStore((s) => s.outlet?.name)
  const users = useCatalogStore((s) => s.users)

  const [pin, setPin] = React.useState("")
  const [shake, setShake] = React.useState(false)
  const [error, setError] = React.useState(false)
  // Versión del slot que recién se "pintó", para retrigger del bounce sin
  // re-animar los anteriores cuando React reusa el mismo DOM.
  const [poppedIndex, setPoppedIndex] = React.useState(-1)

  // Ref al input invisible — necesario para abrir el teclado virtual en mobile.
  const hiddenInputRef = React.useRef<HTMLInputElement>(null)

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

  // Validar al llegar a 4 dígitos — match local con bcrypt contra los hashes del store.
  // Best-effort POST a /api/pos/audit-unlock para logging (no bloquea el unlock si falla).
  React.useEffect(() => {
    if (pin.length !== PIN_LENGTH) return
    const id = setTimeout(async () => {
      const { default: bcrypt } = await import("bcryptjs")
      let matched: { id: string; name: string } | null = null
      for (const u of users) {
        if (!u.lockpasshash) continue
        const ok = await bcrypt.compare(pin, u.lockpasshash)
        if (ok) {
          matched = { id: u.id, name: u.name }
          break
        }
      }
      if (matched) {
        // Audit best-effort — no bloquear si falla (offline).
        fetch("/api/pos/audit-unlock", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ contactId: matched.id }),
        }).catch(() => undefined)
        setActiveUser({ id: matched.id, name: matched.name })
        toast.success(`Bienvenido, ${matched.name}`)
        unlock()
      } else {
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
  }, [pin, unlock, users])

  // Escape hatch (P1): si ningún user tiene lockpasshash configurado, el lock screen
  // se convierte en un deadlock permanente (no hay PIN que comparar).
  // Esto ocurre cuando: (a) el store degradó a users=[] por un 5xx upstream,
  // (b) el tenant nunca configuró PINs. En ese caso, desbloqueamos sin PIN
  // para no dejar la caja inutilizable offline.
  const noPinsConfigured = users.length === 0 || users.every((u) => !u.lockpasshash)

  if (!locked) return null

  // Si no hay hashes: mostrar aviso + botón de recarga en vez del lock real.
  if (noPinsConfigured) {
    return (
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Pantalla bloqueada"
        className="fixed inset-0 z-[100] flex flex-col items-center justify-center gap-4 bg-background"
      >
        <PuntoLogo variant="mark" className="size-[35px]" />
        <p className="text-sm text-muted-foreground">
          No hay PINs configurados para este dispositivo.
        </p>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="text-xs underline text-muted-foreground hover:text-foreground"
        >
          Recargar
        </button>
      </div>
    )
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Pantalla bloqueada"
      className="fixed inset-0 z-[100] flex flex-col items-center justify-center bg-background"
    >
      {/*
       * Input invisible — captura el teclado virtual en mobile cuando el
       * usuario toca la zona de los círculos. En desktop el listener global
       * de keydown sigue funcionando igual sin importar el foco.
       *
       * Usamos sr-only en lugar de display:none/visibility:hidden para que el
       * browser permita el focus y abra el teclado del OS en mobile.
       * inputMode="numeric" + pattern="[0-9]*" dan el teclado numérico en iOS/Android.
       */}
      <input
        ref={hiddenInputRef}
        className="sr-only"
        type="text"
        inputMode="numeric"
        pattern="[0-9]*"
        aria-hidden="false"
        aria-label="Ingrese su código de acceso"
        autoComplete="off"
        autoCorrect="off"
        autoCapitalize="off"
        spellCheck={false}
        readOnly={false}
        onChange={(e) => {
          // Los teclados virtuales disparan onChange (no siempre keydown).
          // Tomamos solo el último carácter ingresado y lo procesamos.
          const last = e.target.value.slice(-1)
          if (/^[0-9]$/.test(last)) {
            setError(false)
            setPin((prev) => {
              if (prev.length >= PIN_LENGTH) return prev
              const next = prev + last
              setPoppedIndex(next.length - 1)
              return next
            })
          }
          // Vaciamos el input para que el próximo dígito se capture igual.
          e.target.value = ""
        }}
        onKeyDown={(e) => {
          // Backspace desde teclado virtual → borrar último dígito.
          if (e.key === "Backspace") {
            e.preventDefault()
            setError(false)
            setPin((prev) => prev.slice(0, -1))
          }
        }}
      />

      {/* Logo — max 35px de alto, junto a los círculos. */}
      <div className={cn("mb-6", shake && "animate-pin-shake")}>
        <PuntoLogo variant="mark" className="size-[35px]" />
      </div>

      {/* PIN dots: 30px de diámetro, 52px de separación.
          Al tocar esta zona en mobile se abre el teclado virtual. */}
      <div
        role="button"
        tabIndex={-1}
        aria-label="Toca para ingresar tu código"
        onClick={() => hiddenInputRef.current?.focus()}
        className={cn(
          "flex items-center cursor-pointer",
          shake && "animate-pin-shake",
        )}
        style={{ gap: 52 }}
      >
        {Array.from({ length: PIN_LENGTH }).map((_, i) => {
          const filled = i < pin.length
          const justPopped = i === poppedIndex && filled
          return (
            <span
              key={i}
              className={cn(
                "block rounded-full border-2 transition-colors duration-150",
                filled
                  ? "border-foreground bg-foreground"
                  : "border-foreground/40 bg-transparent",
                justPopped && "animate-pin-pop",
              )}
              style={{ width: 30, height: 30 }}
            />
          )
        })}
      </div>

      {/* Ícono de candado + texto + nombre de la empresa. */}
      <div className="mt-8 flex flex-col items-center gap-1">
        <Lock
          aria-hidden
          className={cn(
            "size-4 transition-colors",
            error ? "text-destructive" : "text-muted-foreground",
          )}
        />
        <p
          className={cn(
            "text-xs transition-colors",
            error ? "font-semibold text-destructive" : "text-muted-foreground",
          )}
        >
          {error ? "Código incorrecto" : "Ingrese su código de usuario"}
        </p>
        {!error && outletName && (
          <p className="text-sm font-bold text-foreground">{outletName}</p>
        )}
      </div>
    </div>
  )
}
