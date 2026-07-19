"use client"

import { ShieldX } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { PuntoLogo } from "@/components/layout/punto-logo"

/**
 * Pantalla full-page cuando un device no está conectado (Bearer token ausente
 * en localStorage, inválido, o device revocado). Usado tanto por el POS como
 * por la pantalla cliente — paridad visual obligatoria entre ambos.
 *
 * NO ofrece formulario de pairing — el flujo es invitation-based: el admin
 * genera el link desde /settings/devices y se lo manda al usuario del device.
 *
 * Reemplaza al viejo /pos-pair (form con contraseña admin, eliminado).
 */
export type DeviceKind = "pos" | "screen" | "kds" | "display"

const COPY: Record<DeviceKind, { title: string; subtitle: string }> = {
  pos: {
    title: "Dispositivo no conectado",
    subtitle:
      "Pedile al administrador que genere un link de conexión desde Configuración › Dispositivos del panel.",
  },
  screen: {
    title: "Pantalla no conectada",
    subtitle:
      "Pedile al administrador que genere un link de conexión desde Configuración › Dispositivos del panel.",
  },
  kds: {
    title: "KDS no conectado",
    subtitle:
      "Pedile al administrador que genere un link de conexión (módulo KDS Cocina) desde Configuración › Dispositivos del panel.",
  },
  display: {
    title: "Pantalla de mozos no conectada",
    subtitle:
      "Pedile al administrador que genere un link de conexión (módulo Pantalla de mozos) desde Configuración › Dispositivos del panel.",
  },
}

export function DeviceNotConnected({ kind = "pos" }: { kind?: DeviceKind }) {
  const { title, subtitle } = COPY[kind]
  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center p-6 bg-background">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-6 p-8 text-center">
          <PuntoLogo variant="mark" className="size-12" />
          <ShieldX className="size-12 text-muted-foreground" />
          <div className="space-y-2">
            <h1 className="text-2xl font-semibold">{title}</h1>
            <p className="text-sm text-muted-foreground">{subtitle}</p>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
