"use client"

import * as React from "react"
import { CheckCircle2, Loader2, ShieldX, Smartphone } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { PuntoLogo } from "@/components/layout/punto-logo"
import type { DeviceKind } from "@/lib/devices/connected-device"
import { parseInvitationId } from "@/lib/devices/invitation-link"
import { redeemErrorCopy } from "@/lib/devices/redeem-copy"
import { useInvitationRedeem } from "@/hooks/use-invitation-redeem"
import { useStandalone } from "@/hooks/use-standalone"

/**
 * Pantalla full-page cuando un device no está conectado (Bearer token ausente
 * en localStorage, inválido, o device revocado). La usan el POS y todas las
 * pantallas pareadas — paridad visual obligatoria entre ellas.
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
 * ── Por qué el pareo se hace ACÁ ADENTRO, y no navegando (2026-09-18) ───────
 *
 * Pegar el link ya se aceptaba, pero el formulario terminaba en un
 * `router.push("/connect/{id}")`. Eso arregla el caso de Android y NO arregla
 * el de iOS, que es el que motivó todo: una PWA instalada con `scope` propio
 * —el reloj de marcación es `scope: "/marcacion"` (context/83 §9.2)— que navega
 * fuera de su scope no abre la ruta adentro de la app, se la entrega a Safari.
 * Y Safari tiene otro `localStorage`. Entonces el link se canjeaba en el
 * navegador, el token quedaba allá, la app instalada seguía sin credencial y
 * volvía a pedir el link — ya quemado, porque el canje es de un solo uso. Tal
 * cual lo reportó el owner: "cierro la PWA y me vuelve a pedir el link".
 *
 * Por eso acá no se navega a ningún lado: se canja en el lugar, con el MISMO
 * flujo público de un solo uso que usa `/connect/{id}`
 * (`hooks/use-invitation-redeem.ts`). La ruta `/connect/{id}` sigue existiendo
 * y funcionando igual para quien abre el link en un navegador normal.
 *
 * Esto NO debilita el canje de un solo uso: no reutiliza una invitación vieja,
 * usa una invitación NUEVA que el admin generó. La app instalada es, a todos
 * los efectos, otro dispositivo — y como cada dispositivo tiene su propio
 * pareo, sigue valiendo un link, un dispositivo.
 *
 * ── Y el link tiene que ser de ESTA pantalla ───────────────────────────────
 *
 * Con el formulario aceptando cualquier link pegado, nada impedía meter el de
 * la caja en el reloj de la entrada: se pareaba, guardaba un token de otro
 * módulo que esta pantalla no lee, y quedaba "no conectada" para siempre con el
 * link ya consumido. El canje recibe el tipo de esta pantalla (`expect`) y
 * rechaza lo ajeno ANTES de consumir la invitación.
 */
export type { DeviceKind }

const KIND_LABEL: Record<DeviceKind, { noun: string; module: string | null }> = {
  pos:     { noun: "Este dispositivo",            module: null },
  screen:  { noun: "Esta pantalla",               module: "Pantalla de cliente" },
  kds:     { noun: "Este KDS",                    module: "KDS" },
  display: { noun: "Esta pantalla de despacho",   module: "Pantalla de despacho" },
  print:   { noun: "Esta estación de impresión",  module: "Estación de impresión" },
  clock:   { noun: "Este reloj de marcación",     module: "Reloj de marcación" },
}

const KIND_TITLE: Record<DeviceKind, string> = {
  pos:     "Dispositivo no conectado",
  screen:  "Pantalla no conectada",
  kds:     "KDS no conectado",
  display: "Pantalla de despacho no conectada",
  print:   "Estación de impresión no conectada",
  clock:   "Reloj de marcación no conectado",
}

export type DeviceNotConnectedReason = "unpaired" | "revoked" | "incomplete"

export function DeviceNotConnected({
  kind = "pos",
  reason = "unpaired",
}: {
  kind?: DeviceKind
  reason?: DeviceNotConnectedReason
}) {
  const standalone = useStandalone()
  const [link, setLink] = React.useState("")
  const [linkError, setLinkError] = React.useState<string | null>(null)

  const { phase, start, reset } = useInvitationRedeem({
    expect: kind,
    // Con la credencial guardada, esta pantalla tiene que volver a arrancar con
    // ella: la recarga es lo que hace que el bootstrap del módulo (contexto,
    // roster, suscripciones) corra de nuevo, ahora autenticado. Recargar la
    // MISMA url no sale del scope de la app instalada — que es todo el punto.
    onRedeemed: () => {
      setTimeout(() => window.location.reload(), 800)
    },
  })

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
    start(id)
  }

  /** Vuelve al formulario para pegar otro link. */
  function tryAnother() {
    setLink("")
    setLinkError(null)
    reset()
  }

  const busy = phase.kind === "opening" || phase.kind === "waiting"

  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center p-6 bg-background overflow-y-auto">
      <Card className="max-w-md w-full">
        <CardContent className="flex flex-col items-center gap-6 p-8 text-center">
          <PuntoLogo variant="mark" className="size-12" />

          {phase.kind === "done" ? (
            <>
              <CheckCircle2 className="size-12 text-green-600" />
              <div className="space-y-2">
                <h1 className="text-2xl font-semibold">Listo</h1>
                <p className="text-sm text-muted-foreground">
                  {noun} ya está conectado. Un momento...
                </p>
              </div>
            </>
          ) : phase.kind === "waiting" ? (
            <>
              <div className="space-y-2">
                <h1 className="text-2xl font-semibold">Falta que lo aprueben</h1>
                <p className="text-sm text-muted-foreground">
                  Mostrale este código al administrador para aprobar
                </p>
              </div>

              <div className="bg-muted px-6 py-4 rounded-lg w-full">
                <span className="font-mono text-4xl font-bold tracking-widest tabular-nums">
                  {phase.userCode}
                </span>
              </div>

              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" />
                <span>Esperando aprobación...</span>
              </div>

              <Button variant="ghost" className="w-full" onClick={tryAnother}>
                Usar otro link
              </Button>
            </>
          ) : (
            <>
              {standalone && reason === "unpaired" ? (
                <Smartphone className="size-12 text-muted-foreground" />
              ) : (
                <ShieldX className="size-12 text-muted-foreground" />
              )}

              {phase.kind === "error" || phase.kind === "closed" ? (
                // El intento anterior no salió. Se dice qué pasó y el formulario
                // queda abajo, listo para el link nuevo: es la única acción que
                // esta persona puede tomar.
                <div className="space-y-2">
                  <h1 className="text-2xl font-semibold">{failureCopy(phase).title}</h1>
                  <p className="text-sm text-muted-foreground">{failureCopy(phase).detail}</p>
                </div>
              ) : (
                <div className="space-y-2">
                  <h1 className="text-2xl font-semibold">{title}</h1>
                  <p className="text-sm text-muted-foreground">{subtitle}</p>
                </div>
              )}

              <form onSubmit={submit} className="w-full space-y-2 text-left">
                <Input
                  value={link}
                  onChange={(e) => {
                    setLink(e.target.value)
                    setLinkError(null)
                    if (phase.kind === "error" || phase.kind === "closed") reset()
                  }}
                  placeholder="Pegá el link de conexión"
                  autoComplete="off"
                  autoCapitalize="off"
                  spellCheck={false}
                  aria-label="Link de conexión"
                  aria-invalid={linkError !== null}
                  disabled={busy}
                />
                {linkError ? (
                  <p className="text-sm text-destructive">{linkError}</p>
                ) : null}
                <Button type="submit" className="w-full" disabled={link.trim() === "" || busy}>
                  {phase.kind === "opening" ? (
                    <>
                      <Loader2 className="size-4 animate-spin" />
                      Conectando
                    </>
                  ) : (
                    "Vincular dispositivo"
                  )}
                </Button>
              </form>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

/**
 * Copy del intento fallido. Los motivos codificados y los mensajes del backend
 * salen del diccionario compartido con `/connect/{id}`; los estados terminales
 * sin token (rechazada, vencida, usada por otro) se mapean a los códigos que ese
 * diccionario ya conoce.
 */
function failureCopy(phase: { kind: "error"; reason: string } | { kind: "closed"; status: string }) {
  if (phase.kind === "error") return redeemErrorCopy(phase.reason)
  if (phase.status === "consumed") return redeemErrorCopy("in-use")
  if (phase.status === "expired") return redeemErrorCopy("expired")
  return {
    title: "No se aprobó la conexión",
    detail: "El administrador rechazó este dispositivo. Pedí un link nuevo si fue un error.",
  }
}
