"use client"

import * as React from "react"
import { Eye, EyeOff } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from "@/components/ui/input-otp"
import { cn } from "@/lib/utils"

/**
 * Campo de PIN numérico con el ojo de mostrar/ocultar (pedido del owner
 * 2026-08-28).
 *
 * Arranca OCULTO: el PIN es la credencial con la que se desbloquea la caja, y
 * la ficha del usuario se edita con gente alrededor. Antes se pintaba en claro
 * y no había forma de taparlo.
 *
 * Compuesto con el `InputOTP` de shadcn —el mismo que ya usaba el formulario—
 * para no cambiar la interacción de tipeo, solo lo que se ve.
 */
export function PinInput({
  value,
  onChange,
  length = 4,
  className,
  disabled,
  showLabel = "Mostrar código",
  hideLabel = "Ocultar código",
}: {
  value: string
  onChange: (value: string) => void
  /** Cantidad de dígitos. El PIN del POS son 4 (`UsersService`). */
  length?: number
  className?: string
  disabled?: boolean
  showLabel?: string
  hideLabel?: string
}) {
  const [visible, setVisible] = React.useState(false)

  return (
    <div className={cn("flex items-center gap-2", className)}>
      <InputOTP
        maxLength={length}
        value={value}
        onChange={onChange}
        inputMode="numeric"
        pattern="^[0-9]*$"
        disabled={disabled}
      >
        <InputOTPGroup>
          {Array.from({ length }, (_, i) => (
            <InputOTPSlot key={i} index={i} masked={!visible} />
          ))}
        </InputOTPGroup>
      </InputOTP>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="size-8"
        aria-label={visible ? hideLabel : showLabel}
        aria-pressed={visible}
        // Fuera del tab order, igual que el ojo de `PasswordInput`: tabulando
        // desde el PIN se va al siguiente campo, no al botón.
        tabIndex={-1}
        disabled={disabled}
        onClick={() => setVisible((v) => !v)}
      >
        {visible ? <EyeOff /> : <Eye />}
      </Button>
    </div>
  )
}
