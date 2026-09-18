"use client"

/**
 * El canje de una invitación de dispositivo, como MÁQUINA DE ESTADOS reusable:
 * abrir, esperar la aprobación del admin, guardar la credencial.
 *
 * ── Por qué esto salió de `connect-view.tsx` (2026-09-18) ──────────────────
 *
 * El canje vivía entero adentro de la pantalla `/connect/{id}`, así que la
 * ÚNICA forma de parear era navegar a esa ruta. Eso rompe en el caso que más
 * importa, y rompe de una manera que no se ve leyendo el código:
 *
 * El reloj de marcación se instala como app propia con `scope: "/marcacion"`
 * (context/83 §9.2). Una PWA instalada en iOS que navega FUERA de su scope no
 * abre la ruta adentro de la app: la entrega a Safari. Y en iOS la app
 * instalada y Safari NO comparten `localStorage`. Resultado: el link se canjea
 * en Safari, el token de device queda guardado ahí, la app instalada sigue sin
 * credencial y vuelve a pedir el link — que además ya está quemado, porque el
 * canje es de un solo uso. Es literalmente el reporte del owner: "cierro la PWA
 * y me vuelve a pedir el link".
 *
 * Pegar el link ya estaba resuelto a medias (`parseInvitationId`), pero el
 * formulario terminaba en un `router.push("/connect/…")`, o sea en la misma
 * navegación fuera de scope. Lo que faltaba no era parsear: era CANJEAR SIN
 * MOVERSE. Por eso el flujo se extrajo acá y `/connect/{id}` pasó a ser un
 * consumidor más — no se duplicó nada, y el camino público de un solo uso
 * sigue siendo uno solo (`lib/devices/redeem-invitation.ts`).
 *
 * ── El token se entrega UNA vez ───────────────────────────────────────────
 *
 * En el poll que encuentra la invitación aprobada, el backend la mueve al
 * estado terminal `consumed` con un CAS. Por eso hay tres guardas: `startedRef`
 * (no abrir dos veces la misma invitación — StrictMode monta los efectos dos
 * veces en dev), `inFlightRef` (no solapar polls) y `redeemedRef` (ignorar
 * respuestas tardías después de canjear). Sin ellas, dos requests del MISMO
 * navegador pisándose hacen que la segunda vea `consumed` y pinte un error
 * sobre un pareo que salió bien.
 */

import * as React from "react"

import type { DeviceModule } from "@/lib/auth/device-token"
import {
  isForeignInvitation,
  openInvitation,
  persistRedeemedDevice,
  pollInvitation,
  type InvitationStatus,
  type RedeemedDevice,
} from "@/lib/devices/redeem-invitation"

const POLL_INTERVAL_MS = 3000
const MAX_POLL_MS = 30 * 60 * 1000 // 30 minutos

/**
 * Motivo codificado de que el link pegado sea de otro tipo de pantalla. Se
 * distingue de los motivos del backend porque el copy lo resuelve quien
 * muestra, y acá no hay nada que traducir.
 */
export const FOREIGN_INVITATION = "foreign-module"

export type RedeemPhase =
  /** Todavía no se empezó. */
  | { kind: "idle" }
  /** Abriendo la invitación. */
  | { kind: "opening" }
  /** Abierta: hay que mostrar el código y esperar a que el admin apruebe. */
  | { kind: "waiting"; userCode: string; module: string }
  /** Canjeada. La credencial ya está guardada. */
  | { kind: "done"; device: RedeemedDevice }
  /** Terminal sin token: rechazada, vencida o usada por otro dispositivo. */
  | { kind: "closed"; status: Extract<InvitationStatus, "denied" | "expired" | "consumed"> }
  /** Terminal: el link no sirve. `reason` es el motivo codificado o el del backend. */
  | { kind: "error"; reason: string }

export interface UseInvitationRedeemOptions {
  /**
   * Tipo de pantalla que está canjeando. Con esto puesto, una invitación de
   * otro módulo se rechaza ANTES de consumirla. Ausente (el caso de
   * `/connect/{id}`, que rutea por módulo) no se exige nada.
   */
  expect?: DeviceModule | null
  /** Se llama UNA vez, con la credencial ya persistida. */
  onRedeemed?: (device: RedeemedDevice) => void
}

export interface UseInvitationRedeem {
  phase: RedeemPhase
  /** Arranca el canje. Llamarla de nuevo con la misma invitación no hace nada. */
  start: (invitationId: string) => void
  /** Vuelve a `idle` para que se pueda pegar otro link. */
  reset: () => void
}

export function useInvitationRedeem({
  expect = null,
  onRedeemed,
}: UseInvitationRedeemOptions = {}): UseInvitationRedeem {
  const [phase, setPhase] = React.useState<RedeemPhase>({ kind: "idle" })
  const [pollingId, setPollingId] = React.useState<string | null>(null)

  const startedRef = React.useRef<string | null>(null)
  const redeemedRef = React.useRef(false)
  const inFlightRef = React.useRef(false)
  const startedAtRef = React.useRef(0)

  // El callback en una ref: el bucle de polling se arma una sola vez por
  // invitación y no puede re-armarse cada vez que el padre re-renderiza —
  // reiniciaría el reloj de los 30 minutos en cada render.
  const onRedeemedRef = React.useRef(onRedeemed)
  onRedeemedRef.current = onRedeemed

  /** Guarda la credencial y avisa. El único lugar que persiste algo. */
  const finish = React.useCallback(
    (invitationId: string, device: RedeemedDevice) => {
      // Un token de otro módulo NO se guarda: dejaría a esta pantalla con una
      // credencial que no lee, "no conectada" para siempre, y con el link ya
      // quemado. Ver `isForeignInvitation()`.
      if (isForeignInvitation(device.module, expect)) {
        setPhase({ kind: "error", reason: FOREIGN_INVITATION })
        return
      }
      redeemedRef.current = true
      persistRedeemedDevice(invitationId, device)
      setPhase({ kind: "done", device })
      onRedeemedRef.current?.(device)
    },
    [expect],
  )

  const reset = React.useCallback(() => {
    startedRef.current = null
    redeemedRef.current = false
    inFlightRef.current = false
    setPollingId(null)
    setPhase({ kind: "idle" })
  }, [])

  const start = React.useCallback(
    (invitationId: string) => {
      if (startedRef.current === invitationId) return
      startedRef.current = invitationId
      redeemedRef.current = false
      inFlightRef.current = false
      startedAtRef.current = Date.now()
      setPollingId(null)
      setPhase({ kind: "opening" })

      void (async () => {
        const result = await openInvitation(invitationId)
        if (startedRef.current !== invitationId) return // se reseteó mientras tanto

        if (result.kind === "error") {
          setPhase({ kind: "error", reason: result.reason })
          return
        }

        if (result.kind === "token") {
          // Reconexión / pareo auto-aprobado: el token llega en la apertura, sin
          // código ni espera.
          finish(invitationId, result.device)
          return
        }

        // El módulo se conoce ACÁ, con la invitación abierta pero todavía SIN
        // consumir: rechazar en este punto deja el link entero para que lo use
        // la pantalla correcta.
        if (isForeignInvitation(result.module, expect)) {
          setPhase({ kind: "error", reason: FOREIGN_INVITATION })
          return
        }

        setPhase({ kind: "waiting", userCode: result.userCode, module: result.module })
        setPollingId(invitationId)
      })()
    },
    [expect, finish],
  )

  // ── La espera de la aprobación ────────────────────────────────────────────
  React.useEffect(() => {
    if (!pollingId) return

    const invitationId = pollingId
    const intervalId = setInterval(async () => {
      if (redeemedRef.current) {
        clearInterval(intervalId)
        return
      }
      if (inFlightRef.current) return // el poll anterior sigue abierto
      if (Date.now() - startedAtRef.current > MAX_POLL_MS) {
        clearInterval(intervalId)
        setPhase({ kind: "closed", status: "expired" })
        return
      }

      inFlightRef.current = true
      try {
        const result = await pollInvitation(invitationId)
        if (startedRef.current !== invitationId) return
        if (result.kind === "retry" || result.kind === "pending") return
        if (redeemedRef.current) {
          clearInterval(intervalId)
          return
        }

        clearInterval(intervalId)
        if (result.kind === "approved") {
          finish(invitationId, result.device)
        } else if (result.kind === "closed") {
          setPhase({ kind: "closed", status: result.status })
        } else {
          setPhase({ kind: "error", reason: result.reason })
        }
      } finally {
        inFlightRef.current = false
      }
    }, POLL_INTERVAL_MS)

    return () => clearInterval(intervalId)
  }, [pollingId, finish])

  return { phase, start, reset }
}
