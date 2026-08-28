"use client"

import * as React from "react"
import { Eye, EyeOff } from "lucide-react"

import {
  InputGroup,
  InputGroupAddon,
  InputGroupButton,
  InputGroupInput,
} from "@/components/ui/input-group"
import { cn } from "@/lib/utils"

/**
 * Campo de clave con el ojo de mostrar/ocultar (pedido del owner 2026-08-28).
 *
 * Un componente y no el toggle repetido en cada formulario: la contraseña de
 * usuario y el PIN del POS lo necesitan hoy, y cualquier campo de clave nuevo lo
 * va a necesitar mañana. Compuesto con `InputGroup` de shadcn, no con un
 * `<input>` + `<button>` a mano (regla `feedback_shadcn_mandatory`).
 *
 * El estado arranca SIEMPRE oculto y no se persiste: mostrar es una acción
 * deliberada por campo y por vez. `type` queda fuera de las props a propósito —
 * este componente es el campo de clave; si hace falta otro tipo, es un `Input`.
 */
export function PasswordInput({
  className,
  showLabel = "Mostrar",
  hideLabel = "Ocultar",
  ...props
}: Omit<React.ComponentProps<typeof InputGroupInput>, "type"> & {
  /** Texto accesible del botón cuando la clave está oculta. */
  showLabel?: string
  /** Texto accesible del botón cuando la clave está visible. */
  hideLabel?: string
}) {
  const [visible, setVisible] = React.useState(false)

  return (
    <InputGroup className={cn("h-8 rounded-2xl", className)}>
      <InputGroupInput
        // `type` alterna, pero el resto del contrato del campo no: el
        // autocompletado del navegador lo decide quien lo usa vía props.
        type={visible ? "text" : "password"}
        {...props}
      />
      <InputGroupAddon align="inline-end" className="pr-1">
        <InputGroupButton
          size="icon-xs"
          aria-label={visible ? hideLabel : showLabel}
          aria-pressed={visible}
          // `tabIndex={-1}`: tabulando desde el campo se va al siguiente campo
          // del formulario, no al ojo. Se llega igual con el mouse y con
          // navegación por landmarks; interponerlo en el tab order de un
          // formulario de alta molesta más de lo que ayuda.
          tabIndex={-1}
          onClick={() => setVisible((v) => !v)}
        >
          {visible ? <EyeOff /> : <Eye />}
        </InputGroupButton>
      </InputGroupAddon>
    </InputGroup>
  )
}
