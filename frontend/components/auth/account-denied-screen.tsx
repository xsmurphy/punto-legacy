"use client"

import * as React from "react"
import { CircleSlash, Lock, MessageCircle, ShieldAlert } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"
import { SOPORTE_TELEFONO, SOPORTE_WHATSAPP_URL } from "@/lib/site/contacto"
import type { AccountDenialReason } from "@/lib/auth/account-denial"

/**
 * Pantalla full-page cuando la CUENTA del comercio no puede operar.
 *
 * Reemplaza al panel entero, no a una página: el 403 lo devuelve el embudo de
 * auth de la API, así que ninguna pantalla del panel tiene datos que mostrar.
 * Antes de esto el usuario veía el layout con listados vacíos y 403s
 * silenciosos en consola — un panel muerto sin explicación.
 *
 * Lo que esta pantalla tiene que lograr, en orden:
 *   1. que el usuario sepa QUÉ pasó (su cuenta, no el sistema);
 *   2. que sepa que sus DATOS están intactos — es el miedo inmediato;
 *   3. que tenga UNA acción concreta: hablar con soporte.
 *
 * No ofrece pagar acá: hoy no existe checkout en el producto, y un botón
 * "Pagar" que lleva a la nada es peor que no tenerlo. Cuando exista, este es
 * el lugar donde entra.
 *
 * La sesión NO se cierra sola. El usuario puede salir con el botón secundario,
 * pero desloguearlo automáticamente le sacaría la única pantalla que le explica
 * qué está pasando y lo dejaría en un login que tampoco le dice nada.
 *
 * Paridad visual con `components/layout/device-not-connected.tsx`: es el otro
 * estado full-page del producto y ambos son el mismo tipo de cartel.
 */

type Copy = {
  icon: React.ComponentType<{ className?: string }>
  title: string
  body: string[]
  /** Qué dice el botón que vuelve a consultar el estado de la cuenta. */
  retryLabel: string
}

const COPY: Record<AccountDenialReason, Copy> = {
  account_blocked: {
    icon: Lock,
    title: "Cuenta bloqueada por falta de pago",
    body: [
      "El acceso al panel y a la caja está pausado hasta que se regularice el pago.",
      "Tu información está intacta: ventas, artículos, clientes y documentos siguen guardados tal como los dejaste. Apenas se registre el pago, la cuenta se reactiva y todo vuelve a estar disponible.",
    ],
    retryLabel: "Ya regularicé el pago — reintentar",
  },
  account_suspended: {
    icon: ShieldAlert,
    title: "Cuenta suspendida",
    body: [
      "Un administrador de Punto suspendió el acceso a esta cuenta.",
      "Tu información está intacta. Para saber el motivo y reactivarla, contactá con soporte.",
    ],
    retryLabel: "Volver a consultar el estado",
  },
  account_inactive: {
    icon: CircleSlash,
    title: "Cuenta inactiva",
    body: [
      "Esta cuenta no está activa, así que no se puede operar con ella.",
      "Tu información está intacta. Escribinos y vemos cómo reactivarla.",
    ],
    retryLabel: "Volver a consultar el estado",
  },
}

export function AccountDeniedScreen({
  reason,
  onLogout,
  onRetry,
}: {
  reason: AccountDenialReason
  onLogout: () => void
  /** Vuelve a consultar el estado — útil cuando el pago ya se registró. */
  onRetry?: () => void
}) {
  const { icon: Icon, title, body, retryLabel } = COPY[reason]

  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center overflow-y-auto bg-background p-6">
      <Card className="w-full max-w-md">
        <CardContent className="flex flex-col items-center gap-6 p-8 text-center">
          <PuntoLogo variant="mark" className="size-12" />
          <Icon className="size-12 text-muted-foreground" />
          <div className="space-y-2">
            <h1 className="text-2xl font-semibold">{title}</h1>
            {body.map((line) => (
              <p key={line} className="text-sm text-muted-foreground">
                {line}
              </p>
            ))}
          </div>

          <div className="flex w-full flex-col gap-2">
            <Button asChild className="w-full">
              {/* El contacto sale del mercado activo (`lib/site/markets.ts`) —
                  el panel no tiene un teléfono propio hardcodeado. */}
              <a href={SOPORTE_WHATSAPP_URL} target="_blank" rel="noopener noreferrer">
                <MessageCircle className="size-4" />
                Contactar a soporte
              </a>
            </Button>
            {onRetry ? (
              <Button variant="outline" className="w-full" onClick={onRetry}>
                {retryLabel}
              </Button>
            ) : null}
            <Button variant="ghost" className="w-full" onClick={onLogout}>
              Cerrar sesión
            </Button>
          </div>

          <p className="text-sm text-muted-foreground">
            También podés llamarnos al {SOPORTE_TELEFONO}.
          </p>
        </CardContent>
      </Card>
    </div>
  )
}
