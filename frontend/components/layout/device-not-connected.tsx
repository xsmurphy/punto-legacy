"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { ShieldX, Smartphone } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { PuntoLogo } from "@/components/layout/punto-logo"
import type { DeviceKind } from "@/lib/devices/connected-device"
import { parseInvitationId } from "@/lib/devices/invitation-link"

/**
 * Pantalla full-page cuando un device no está conectado (Bearer token ausente
 * en localStorage, inválido, o device revocado). Usado tanto por el POS como
 * por la pantalla cliente — paridad visual obligatoria entre ambos.
 *
 * ── El caso PWA instalada (iOS), 2026-08-25 ─────────────────────────────────
 *
 * El owner pareó el POS en Safari y después guardó `/pos` en el homescreen: la
 * app instalada abrió con "Dispositivo no conectado". No es un bug de Punto —
 * en iOS una PWA en modo standalone tiene su PROPIO almacén, separado del de
 * Safari, así que arranca con `localStorage` vacío y el Bearer del device
 * sencillamente no está ahí. El guard tenía razón; el que mentía era el
 * cartel, que le hacía pensar al usuario que el pareo había fallado o que
 * había perdido la caja.
 *
 * En Android el síntoma normalmente NO aparece: Chrome instala la PWA como
 * WebAPK dentro del mismo perfil y comparte el storage del origen con el
 * navegador, así que el token pareado en Chrome sigue estando en la app
 * instalada. Por eso el copy específico se muestra sólo cuando detectamos modo
 * standalone y no hay token: describe la situación real sin afirmar nada sobre
 * la plataforma.
 *
 * ── Por qué acá hay un formulario de vinculación ────────────────────────────
 *
 * Antes esta pantalla era deliberadamente un cartel muerto ("el flujo es
 * invitation-based, pedile el link al admin"). Con la app instalada eso se
 * vuelve un callejón: en iOS, tocar el link de conexión desde WhatsApp abre
 * SAFARI, no la app instalada, así que el pareo se hace en el navegador
 * equivocado una y otra vez. La única forma de meter el link DENTRO de la app
 * instalada es pegarlo acá, y navegar internamente a `/connect/{id}`.
 *
 * Esto NO debilita el canje de un solo uso: no reutiliza una invitación vieja,
 * usa una invitación NUEVA que el admin generó. La app instalada es, a todos
 * los efectos, otro dispositivo — y como cada dispositivo tiene su propio
 * pareo, sigue valiendo un link, un dispositivo. La convención del proyecto
 * pide que el impedimento se explique en el control de la acción y ofrezca
 * salida, no que se quede en un cartel.
 */
export type { DeviceKind }

const KIND_LABEL: Record<DeviceKind, { noun: string; module: string | null }> = {
  pos:     { noun: "Este dispositivo",            module: null },
  screen:  { noun: "Esta pantalla",               module: "Pantalla de cliente" },
  kds:     { noun: "Este KDS",                    module: "KDS" },
  display: { noun: "Esta pantalla de despacho",   module: "Pantalla de despacho" },
  print:   { noun: "Esta estación de impresión",  module: "Estación de impresión" },
}

const KIND_TITLE: Record<DeviceKind, string> = {
  pos:     "Dispositivo no conectado",
  screen:  "Pantalla no conectada",
  kds:     "KDS no conectado",
  display: "Pantalla de despacho no conectada",
  print:   "Estación de impresión no conectada",
}

export type DeviceNotConnectedReason = "unpaired" | "revoked" | "incomplete"

/** ¿La app corre instalada (standalone), no dentro del navegador? */
function useStandalone(): boolean {
  const [standalone, setStandalone] = React.useState(false)
  React.useEffect(() => {
    const iosStandalone = (window.navigator as Navigator & { standalone?: boolean }).standalone === true
    const displayMode =
      typeof window.matchMedia === "function" &&
      (window.matchMedia("(display-mode: standalone)").matches ||
        window.matchMedia("(display-mode: fullscreen)").matches)
    setStandalone(iosStandalone || displayMode)
  }, [])
  return standalone
}

export function DeviceNotConnected({
  kind = "pos",
  reason = "unpaired",
}: {
  kind?: DeviceKind
  reason?: DeviceNotConnectedReason
}) {
  const router = useRouter()
  const standalone = useStandalone()
  const [link, setLink] = React.useState("")
  const [linkError, setLinkError] = React.useState<string | null>(null)

  const { noun, module } = KIND_LABEL[kind]
  const moduleHint = module ? ` (módulo ${module})` : ""

  const { title, subtitle } =
    reason === "revoked"
      ? {
          title: "Dispositivo desconectado por un administrador",
          subtitle:
            "Para volver a usarlo hace falta un link de conexión nuevo, generado desde Configuración › Dispositivos del panel.",
        }
      : reason === "incomplete"
      ? {
          title: "Este dispositivo no tiene caja asignada",
          subtitle:
            "El pareo quedó incompleto — falta la sucursal o la caja. Hace falta un link de conexión nuevo desde Configuración › Dispositivos del panel.",
        }
      : standalone
      ? {
          // El caso que motivó el cambio: la app instalada NO hereda el pareo
          // del navegador. Se dice lo que pasó, no "no estás conectado".
          title: "Falta vincular esta app instalada",
          subtitle:
            `La app instalada guarda sus datos aparte del navegador, así que no hereda el pareo que hiciste ahí. ` +
            `${noun} necesita su propio link de conexión${moduleHint}: pedilo desde Configuración › Dispositivos del panel y pegalo acá abajo.`,
        }
      : {
          title: KIND_TITLE[kind],
          subtitle:
            `${noun} todavía no está vinculado a una caja. Pedí un link de conexión${moduleHint} desde ` +
            `Configuración › Dispositivos del panel y pegalo acá abajo, o abrilo directamente en este dispositivo.`,
        }

  function submit(e: React.FormEvent) {
    e.preventDefault()
    const id = parseInvitationId(link)
    if (!id) {
      setLinkError("No encontramos un link de conexión válido en lo que pegaste. Copiá el link entero.")
      return
    }
    setLinkError(null)
    router.push(`/connect/${id}`)
  }

  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center p-6 bg-background overflow-y-auto">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-6 p-8 text-center">
          <PuntoLogo variant="mark" className="size-12" />
          {standalone && reason === "unpaired" ? (
            <Smartphone className="size-12 text-muted-foreground" />
          ) : (
            <ShieldX className="size-12 text-muted-foreground" />
          )}
          <div className="space-y-2">
            <h1 className="text-2xl font-semibold">{title}</h1>
            <p className="text-sm text-muted-foreground">{subtitle}</p>
          </div>

          <form onSubmit={submit} className="w-full space-y-2 text-left">
            <Input
              value={link}
              onChange={(e) => { setLink(e.target.value); setLinkError(null) }}
              placeholder="Pegá el link de conexión"
              autoComplete="off"
              autoCapitalize="off"
              spellCheck={false}
              aria-label="Link de conexión"
              aria-invalid={linkError !== null}
            />
            {linkError ? (
              <p className="text-sm text-destructive">{linkError}</p>
            ) : null}
            <Button type="submit" className="w-full" disabled={link.trim() === ""}>
              Vincular dispositivo
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  )
}
