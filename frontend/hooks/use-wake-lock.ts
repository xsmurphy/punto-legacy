"use client"

/**
 * Mantiene la pantalla encendida mientras el aparato está haciendo de reloj.
 *
 * Un celular viejo colgado en la entrada es el caso más común de reloj de
 * marcación (context/83 §9.2: se parea cualquier aparato). Con el bloqueo
 * automático del sistema, ese teléfono apaga la pantalla al minuto y la persona
 * que llega encuentra un rectángulo negro: tiene que tocarlo, esperar a que
 * despierte la cámara y recién ahí pararse a marcar. La marcación deja de ser
 * "pasar por delante" y pasa a ser un trámite.
 *
 * ── Fail-open, como todo el reloj ─────────────────────────────────────────
 *
 * La Screen Wake Lock API no existe en todos los navegadores (iOS la tiene
 * recién desde 16.4) y el sistema puede negarla o retirarla cuando quiera
 * —batería baja, modo de ahorro—. Nada de eso es un error que mostrar: sin
 * wake lock la pantalla se apaga como se apagaba antes y el reloj sigue
 * funcionando igual. Por eso acá no se tira, no se avisa y no se reintenta en
 * loop.
 *
 * ── Por qué hay que re-pedirlo ────────────────────────────────────────────
 *
 * El navegador SUELTA el lock solo cada vez que la pestaña deja de estar
 * visible (el usuario cambia de app, el sistema apaga la pantalla igual). Al
 * volver, el lock NO se restablece por su cuenta: hay que volver a pedirlo en
 * `visibilitychange`. Sin eso, el lock dura hasta la primera vez que alguien
 * mira otra cosa en el aparato y después nunca más, que es la clase de bug que
 * solo se ve al día siguiente.
 */

import * as React from "react"

interface WakeLockSentinelLike {
  released?: boolean
  release: () => Promise<void>
  addEventListener?: (type: "release", listener: () => void) => void
}

interface WakeLockCapableNavigator {
  wakeLock?: { request: (type: "screen") => Promise<WakeLockSentinelLike> }
}

export function useWakeLock(enabled: boolean = true): void {
  React.useEffect(() => {
    if (!enabled) return
    if (typeof navigator === "undefined") return

    const wakeLock = (navigator as Navigator & WakeLockCapableNavigator).wakeLock
    if (!wakeLock) return // navegador sin soporte: no pasa nada

    let cancelled = false
    let sentinel: WakeLockSentinelLike | null = null
    let requesting = false

    const acquire = async () => {
      // `request` tira si el documento no está visible: pedirlo con la pantalla
      // apagada es justamente lo que el navegador no permite.
      if (cancelled || requesting) return
      if (typeof document !== "undefined" && document.visibilityState !== "visible") return
      if (sentinel && sentinel.released === false) return

      requesting = true
      try {
        const next = await wakeLock.request("screen")
        if (cancelled) {
          void next.release().catch(() => undefined)
          return
        }
        sentinel = next
        // El sistema puede retirarlo por su cuenta (batería, ahorro de energía).
        // Se anota para no creer que sigue vivo; recuperarlo lo intenta el
        // próximo `visibilitychange`, no un reintento en bucle que le pelearía
        // al ahorro de energía del aparato.
        next.addEventListener?.("release", () => {
          if (sentinel === next) sentinel = null
        })
      } catch {
        /* denegado o no disponible — la pantalla se apaga como antes */
      } finally {
        requesting = false
      }
    }

    void acquire()

    const onVisible = () => {
      if (document.visibilityState === "visible") void acquire()
    }
    document.addEventListener("visibilitychange", onVisible)

    return () => {
      cancelled = true
      document.removeEventListener("visibilitychange", onVisible)
      // Soltarlo al desmontar no es cosmético: sin esto el aparato queda con la
      // pantalla forzada a encendida después de salir del reloj.
      void sentinel?.release().catch(() => undefined)
      sentinel = null
    }
  }, [enabled])
}
