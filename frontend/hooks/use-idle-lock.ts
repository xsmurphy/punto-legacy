"use client"

/**
 * Bloqueo de la caja por inactividad.
 *
 * Arma un timer que, tras `lockAfterSeconds` sin actividad del cajero, manda el
 * POS al lock screen (`useLockStore.lock()`). Y nada más: no cierra el turno,
 * no limpia el carrito y no toca la tenencia de la caja. El carrito vive en
 * memoria y `lock()` solo tira `operatorToken` / `operatorPermissions`, así que
 * volver a tipear el PIN devuelve la venta en curso intacta.
 *
 * ── Por qué NO se pausa nunca ─────────────────────────────────────────────
 *
 * Ni por venta en curso, ni por diálogo abierto, ni por impresión. Una
 * heurística de "está ocupado" cubre mal justo el caso que importa: el cajero
 * que se fue del mostrador dejando el carrito lleno es el motivo del bloqueo,
 * no una excepción a él. Predecible le gana a inteligente.
 *
 * Con la pestaña oculta el timer sigue corriendo, y es lo correcto: que la caja
 * no esté en foco es una razón más para bloquear, no menos.
 *
 * El valor sale de la config por caja (`lockAfterSeconds` en
 * `PosRegisterConfig`), así que hereda gratis el debounce del panel de ajustes,
 * la caché offline y la cola de patches del resto de los ajustes del mostrador.
 */

import * as React from "react"
import { useLockStore } from "@/lib/pos/lock-store"

/**
 * Qué cuenta como "el cajero sigue acá".
 *
 * Se escuchan en fase de CAPTURA para que un `stopPropagation()` de cualquier
 * componente de la caja (los hay, en numpads y diálogos) no impida reiniciar el
 * timer — eso se leería como "se bloquea mientras lo estoy usando". Y pasivos
 * porque solo observan: nunca llaman a `preventDefault()`.
 */
const ACTIVITY_EVENTS = ["pointerdown", "keydown", "touchstart", "wheel"] as const

/**
 * Piso entre reprogramaciones del timer. Los eventos elegidos son de baja
 * frecuencia salvo `wheel`, que en un scroll largo dispara decenas de veces por
 * segundo: sin esto cada tick tiraría y recrearía el `setTimeout`. El costo es
 * que el vencimiento puede correrse hasta un segundo, que a esta escala no es
 * nada.
 */
const RESCHEDULE_THROTTLE_MS = 1000

export function useIdleLock(lockAfterSeconds: number): void {
  React.useEffect(() => {
    // 0 —o cualquier cosa que no sea un número positivo— es "desactivado".
    if (!Number.isFinite(lockAfterSeconds) || lockAfterSeconds <= 0) return
    if (typeof window === "undefined") return

    const idleMs = lockAfterSeconds * 1000
    let timer: ReturnType<typeof setTimeout> | null = null
    let armedAt = 0

    const fire = () => {
      timer = null
      // El estado se lee ACÁ y no como dependencia del efecto a propósito: con
      // `locked` en las deps, cada bloqueo o desbloqueo remontaría los
      // listeners y reiniciaría el timer por algo que no es actividad del
      // cajero.
      const store = useLockStore.getState()
      if (store.locked) return
      store.lock()
    }

    const arm = () => {
      if (timer) clearTimeout(timer)
      armedAt = Date.now()
      timer = setTimeout(fire, idleMs)
    }

    // Con la pantalla bloqueada la actividad igual reprograma —tipear el PIN
    // cuenta— y por eso al desbloquear ya hay un timer corriendo sin necesidad
    // de suscribirse al store. El que no dispara es `fire`, que chequea
    // `locked` en el momento del vencimiento.
    const onActivity = () => {
      if (Date.now() - armedAt < RESCHEDULE_THROTTLE_MS) return
      arm()
    }

    // Se arma en el montaje: una caja abierta que nadie toca tiene que
    // bloquearse igual, sin esperar un primer evento.
    arm()
    for (const evt of ACTIVITY_EVENTS) {
      window.addEventListener(evt, onActivity, { passive: true, capture: true })
    }

    return () => {
      for (const evt of ACTIVITY_EVENTS) {
        window.removeEventListener(evt, onActivity, { capture: true })
      }
      if (timer) clearTimeout(timer)
      timer = null
    }
  }, [lockAfterSeconds])
}
