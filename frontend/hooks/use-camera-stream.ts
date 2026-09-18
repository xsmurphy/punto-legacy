"use client"

/**
 * La cámara de una pantalla que está prendida TODO EL DÍA (context/83 §9.5).
 *
 * El reloj de marcación no abre la cámara para sacar una foto y cerrarla: la
 * deja viva de la mañana a la noche, sin que nadie toque el aparato. Eso hace
 * que fallen cosas que en una sesión de cinco minutos no se ven nunca, y por eso
 * esto es un hook y no cuatro líneas en la pantalla.
 *
 * ── Qué se rompía, y por qué el arreglo no podía ser un `play()` más ───────
 *
 * La versión anterior pedía el stream en un `useEffect(..., [])` y lo enchufaba
 * a `videoRef.current` UNA vez. Con eso, el video se congelaba —y quedaba
 * congelado— por tres caminos distintos:
 *
 *   1. **El elemento se desmonta y vuelve.** La pantalla tiene estados que son
 *      otra pantalla (sin pareo, sin nadie cargado). Al volver, el `<video>` es
 *      un elemento NUEVO y vacío: el efecto no se re-ejecuta porque sus
 *      dependencias no cambiaron, así que nadie le vuelve a asignar el
 *      `srcObject`. Peor todavía en el arranque: mientras el pareo resuelve no
 *      hay `<video>` montado, y el stream que llega se enchufa a `null`.
 *   2. **El browser pausa el elemento.** La tablet apaga la pantalla, el turno
 *      manda la pestaña al fondo, iOS suspende la reproducción. Al volver, el
 *      elemento queda en pausa sobre el último cuadro — que es exactamente lo
 *      que se ve: video "congelado", no negro.
 *   3. **La pista muere.** Otra app se lleva la cámara, el SO la suspende, una
 *      webcam USB se re-enumera. El `MediaStreamTrack` pasa a `ended` y no
 *      vuelve solo: hay que volver a pedir `getUserMedia`. El vigía lo ve como
 *      cualquier otra forma de quedarse quieto, así que no hace falta además
 *      escuchar el evento de cada pista.
 *
 * Ninguno de los tres se arregla en la pantalla, porque los tres son del CICLO
 * DE VIDA del stream. Acá el stream tiene dueño: un `ref` de callback lo
 * re-enchufa cada vez que aparece un `<video>`, y un vigía lo revive cuando deja
 * de avanzar.
 *
 * ── Fail-open, como todo el reloj (D4) ────────────────────────────────────
 *
 * Nada de este archivo tira. Sin cámara, con el permiso denegado o con un fallo
 * raro, el estado lo dice y la pantalla sigue andando por el código numérico. Lo
 * único que no puede pasar es lo contrario: decir que la cámara está bien
 * mientras muestra un cuadro viejo, porque entonces la foto de evidencia de cada
 * marcación es la misma persona todo el día.
 */

import * as React from "react"

import { describeCameraError, type NoPhotoReason } from "@/lib/pos/attendance-photo"

/**
 * Cada cuánto se controla que el video siga vivo.
 *
 * Un segundo es demasiado seguido para algo que casi nunca cambia; diez es
 * demasiado tiempo mirando un cuadro muerto en una pantalla donde la gente se
 * para a fichar.
 */
const WATCHDOG_MS = 2000

/**
 * Cuántos controles seguidos sin que avance el cuadro se toleran antes de dar
 * el video por congelado.
 *
 * Dos (≈4 s) y no uno: una tablet ocupada puede perder un cuadro justo cuando
 * el vigía mira, y reabrir la cámara por eso apagaría y prendería el LED cada
 * tanto sin motivo.
 */
const STALL_TICKS = 2

/**
 * Cuánto se espera antes de volver a pedir una cámara que falló por algo que
 * puede pasar solo (estaba ocupada por otra app, el SO la tenía tomada).
 *
 * El permiso denegado y la ausencia de cámara NO entran acá: esos no se arreglan
 * esperando, y reintentar cada diez segundos para siempre sería trabajo inútil
 * en un aparato que va a estar prendido doce horas.
 */
const RETRY_MS = 15_000

/** `null` mientras se pide el permiso. */
export type CameraState = { ok: true } | { ok: false; reason: NoPhotoReason; message: string } | null

export interface UseCameraStream {
  state: CameraState
  /**
   * `ref` de callback para el `<video>`. Es de callback y no un `useRef` a
   * secas justamente por el punto 1 del docblock: así el stream se re-enchufa
   * solo cada vez que aparece un elemento, sin depender de que un efecto se
   * vuelva a ejecutar.
   */
  attach: (el: HTMLVideoElement | null) => void
  /** El elemento vivo, para `captureJpeg()` y para el motor de reconocimiento. */
  videoRef: React.RefObject<HTMLVideoElement | null>
}

/** Motivos que no se reintentan: esperar no los arregla. */
function isPermanent(reason: NoPhotoReason): boolean {
  return reason === "camera_denied" || reason === "no_camera"
}

export function useCameraStream(): UseCameraStream {
  const [state, setState] = React.useState<CameraState>(null)

  const videoRef = React.useRef<HTMLVideoElement | null>(null)
  const streamRef = React.useRef<MediaStream | null>(null)
  // Evita dos `getUserMedia` encimados: el vigía y el evento de una pista
  // muerta pueden pedir la cámara en el mismo instante, y dos streams vivos
  // dejan uno huérfano con el LED prendido.
  const acquiringRef = React.useRef(false)
  const mountedRef = React.useRef(true)
  /** `performance.now()` a partir del cual se puede reintentar. */
  const retryAtRef = React.useRef(0)
  const permanentRef = React.useRef(false)

  /** Enchufa el stream vivo al elemento vivo y lo hace reproducir. */
  const bind = React.useCallback(() => {
    const el = videoRef.current
    const stream = streamRef.current
    if (!el || !stream) return
    if (el.srcObject !== stream) el.srcObject = stream
    // `play()` rechaza si el elemento no está en el documento todavía o si el
    // browser lo considera un gesto no permitido. Con `muted` + `playsInline`
    // no debería pasar, y si pasa el vigía vuelve a intentar.
    void el.play().catch(() => undefined)
  }, [])

  const release = React.useCallback(() => {
    const stream = streamRef.current
    streamRef.current = null
    // Soltar las pistas NO es opcional: sin esto la luz de la cámara queda
    // prendida con la pantalla cerrada, que para quien mira la tablet es un
    // aparato filmando el local.
    stream?.getTracks().forEach((t) => t.stop())
    if (videoRef.current) videoRef.current.srcObject = null
  }, [])

  const acquire = React.useCallback(async () => {
    if (acquiringRef.current || permanentRef.current) return
    if (typeof navigator === "undefined" || !navigator.mediaDevices?.getUserMedia) {
      permanentRef.current = true
      setState({
        ok: false,
        reason: "no_camera",
        message: "Este dispositivo no tiene cámara. Se puede marcar con el código.",
      })
      return
    }

    acquiringRef.current = true
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        // Cámara frontal: la persona mira la pantalla mientras marca.
        video: { facingMode: "user", width: { ideal: 640 } },
        audio: false,
      })

      if (!mountedRef.current) {
        stream.getTracks().forEach((t) => t.stop())
        return
      }

      release()
      streamRef.current = stream
      retryAtRef.current = 0
      bind()
      setState({ ok: true })
    } catch (err) {
      if (!mountedRef.current) return
      const failure = describeCameraError(err)
      permanentRef.current = isPermanent(failure.reason)
      retryAtRef.current = performance.now() + RETRY_MS
      setState({ ok: false, ...failure })
    } finally {
      acquiringRef.current = false
    }
  }, [bind, release])

  const attach = React.useCallback(
    (el: HTMLVideoElement | null) => {
      videoRef.current = el
      // Acá está el arreglo del punto 1: cada vez que aparece un elemento
      // —montaje inicial, vuelta desde otro estado de la pantalla— recibe el
      // stream que ya existe.
      if (el) bind()
    },
    [bind],
  )

  // ── Apertura y cierre ─────────────────────────────────────────────────────
  React.useEffect(() => {
    mountedRef.current = true
    void acquire()
    return () => {
      mountedRef.current = false
      release()
    }
  }, [acquire, release])

  // ── El vigía ──────────────────────────────────────────────────────────────
  //
  // Un reloj colgado en la pared no tiene a nadie que lo recargue cuando el
  // video se queda quieto: si no se revive solo, se queda congelado hasta que
  // alguien se da cuenta — que es justamente el bug que esto cierra.
  React.useEffect(() => {
    let stalledTicks = 0
    let lastTime = -1

    const revive = () => {
      if (typeof document !== "undefined" && document.hidden) return
      if (permanentRef.current) return

      const stream = streamRef.current
      const live = stream?.getTracks().some((t) => t.readyState === "live") ?? false

      // Sin pistas vivas no hay nada que reproducir: se vuelve a pedir.
      if (!live) {
        if (performance.now() >= retryAtRef.current) void acquire()
        return
      }

      const el = videoRef.current
      if (!el) return

      if (el.srcObject !== stream || el.paused || el.ended) {
        stalledTicks = 0
        lastTime = -1
        bind()
        return
      }

      // El elemento dice que está reproduciendo, pero el cuadro no cambia. Es
      // el caso silencioso: `paused` es `false` y la imagen está muerta.
      if (el.currentTime === lastTime) {
        stalledTicks += 1
        if (stalledTicks === STALL_TICKS) {
          bind()
        } else if (stalledTicks > STALL_TICKS) {
          stalledTicks = 0
          lastTime = -1
          retryAtRef.current = 0
          void acquire()
        }
        return
      }

      stalledTicks = 0
      lastTime = el.currentTime
    }

    const timer = setInterval(revive, WATCHDOG_MS)
    // Al volver del fondo no se espera al próximo control: la persona ya se
    // paró enfrente.
    const onVisible = () => {
      if (typeof document !== "undefined" && !document.hidden) revive()
    }
    document.addEventListener("visibilitychange", onVisible)
    window.addEventListener("focus", onVisible)

    return () => {
      clearInterval(timer)
      document.removeEventListener("visibilitychange", onVisible)
      window.removeEventListener("focus", onVisible)
    }
  }, [acquire, bind])

  return { state, attach, videoRef }
}
