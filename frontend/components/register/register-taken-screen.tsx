"use client"

/**
 * Pantalla bloqueante de TODO el workspace del POS cuando este dispositivo
 * no pudo tomar la tenencia de su caja (`useRegisterClaim`,
 * `api/v1/register/claim.php` — F2 de
 * context/29-numeracion-y-exclusividad-de-caja.md §4).
 *
 * Antes de esto, la única señal de "caja tomada" aparecía recién al intentar
 * cobrar (`PayDialog` → `RegisterTakenPhase`, dialog acotado al carrito) —
 * el operador podía navegar todo el POS sin saber que ninguna venta iba a
 * poder confirmarse. Acá se corta ANTES de entrar, con el mismo mensaje
 * (`lib/pos/register-conflict.ts`, compartido con `PayDialog`).
 *
 * Mismo patrón visual que `PosLoadingScreen`/`LockScreen` — overlay
 * full-screen fijo, no un dialog, porque bloquea TODO el workspace, no solo
 * el cobro.
 */

import { Lock } from "lucide-react"
import { Button } from "@/components/ui/button"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { formatDateTime } from "@/lib/format-date"
import { registerConflictMessage, type RegisterConflictInfo } from "@/lib/pos/register-conflict"

interface RegisterTakenScreenProps {
  info: RegisterConflictInfo | null
  onRetry: () => void
}

export function RegisterTakenScreen({ info, onRetry }: RegisterTakenScreenProps) {
  const expiresLabel = info?.expiresAt ? formatDateTime(info.expiresAt) : null
  const { title, body } = registerConflictMessage(info, expiresLabel)

  return (
    <div
      role="alertdialog"
      aria-label={title}
      className="fixed inset-0 z-[100] flex flex-col items-center justify-center gap-8 bg-background px-6 text-center"
    >
      <PuntoLogo variant="mark" className="size-[35px]" />
      <div className="flex flex-col items-center gap-5">
        <Lock className="size-16 text-destructive" strokeWidth={1.5} />
        <div className="flex flex-col items-center gap-2">
          <h1 className="text-xl font-bold text-foreground">{title}</h1>
          <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
        <Button className="font-bold" onClick={onRetry}>
          Reintentar
        </Button>
      </div>
    </div>
  )
}
