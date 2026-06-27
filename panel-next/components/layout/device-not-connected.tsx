"use client"

import { ShieldX } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"

/**
 * Pantalla full-page cuando un device intenta operar el POS sin estar conectado
 * (Bearer token ausente en localStorage, inválido, o device revocado).
 *
 * NO ofrece formulario de pairing — el flujo nuevo es invitation-based: el admin
 * genera el link desde /settings/devices y se lo manda al usuario del device.
 *
 * Reemplaza al viejo /pos-pair (form con contraseña admin, eliminado).
 */
export function DeviceNotConnected() {
  return (
    <div className="min-h-svh flex flex-col items-center justify-center p-6 bg-background">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-6 p-8 text-center">
          <PuntoLogo variant="mark" className="size-12" />
          <ShieldX className="size-12 text-muted-foreground" />
          <div className="space-y-2">
            <h1 className="text-2xl font-semibold">Dispositivo no conectado</h1>
            <p className="text-sm text-muted-foreground">
              Pedile al administrador que genere un link de conexión desde el panel
              y volvé a abrirlo en este dispositivo.
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
