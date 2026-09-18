"use client"

/**
 * El bucle de reconocimiento del quiosco (RRHH F2, context/83 D4 y D5).
 *
 * Mira la cámara varias veces por segundo, junta lecturas, espera un parpadeo y
 * —recién ahí— propone un nombre. Vive en un hook y no en la pantalla porque la
 * pantalla ya tiene su propio trabajo (el teclado, la confirmación, la cola) y
 * porque este bucle tiene estado propio con reglas que no son obvias.
 *
 * ── Tres condiciones para proponer a alguien, y las tres son necesarias ────
 *
 *   1. **Varias lecturas, no una.** Se promedian las últimas tomas. Un cuadro
 *      suelto puede agarrar a la persona girando la cabeza o a mitad de un
 *      pestañeo, y decidir con ese es decidir con ruido.
 *   2. **Un parpadeo** (`blink.ts`). Una foto impresa del compañero no parpadea.
 *      Es la versión barata del problema, y es la que pasa en un mostrador.
 *   3. **Distancia y margen** (`face-match.ts`). Cerca de una cara registrada, y
 *      lo bastante más cerca que de la segunda.
 *
 * ── Nada de esto bloquea a nadie (D4) ──────────────────────────────────────
 *
 * Si el modelo no carga, si no hay cámara, si nadie tiene rostro registrado o si
 * la persona no parpadea: no pasa NADA. La pantalla sigue con el código de
 * marcación, que es el camino de la F1 y siempre está. Lo que este hook agrega
 * es un atajo, no un portón.
 *
 * Por eso tampoco hay `throw` en ningún camino: cada fallo se convierte en un
 * estado que la pantalla puede mostrar sin drama.
 */

import * as React from "react"

import { BlinkDetector } from "@/lib/pos/face/blink"
import {
  averageEmbedding,
  pickBestMatch,
  type FaceCandidate,
} from "@/lib/pos/face/face-match"
import { loadFaceEngine, readEyeRatio, readFace, type FaceReading } from "@/lib/pos/face/face-engine"

/**
 * Cada cuánto se mira un cuadro.
 *
 * ~3 lecturas por segundo. Más seguido no mejora el reconocimiento —una cara no
 * cambia en 100 ms— y sí calienta la tablet y le come batería a un aparato que
 * está prendido todo el día. Menos seguido se siente lento justo cuando la
 * persona ya se paró enfrente a esperar.
 */
const TICK_MS = 320

/** Cuántas lecturas se promedian antes de decidir. */
const WINDOW = 3

/**
 * Cuánto se espera después de proponer a alguien, antes de volver a mirar.
 *
 * Sin esta pausa, la persona que acaba de marcar y sigue parada frente a la
 * tablet —guardando el celular, hablando con alguien— vuelve a ser reconocida y
 * la pantalla le propone marcar otra vez.
 */
const COOLDOWN_MS = 6000

/** Cuánto vale una cara vista, para decidir el motivo de revisión. */
const SIGHTING_TTL_MS = 15_000

export type FaceEngineStatus =
  /** Todavía no se pidió: sin cámara, o la pantalla no lo necesita. */
  | "off"
  /** Bajando el modelo. */
  | "loading"
  /** Mirando. */
  | "ready"
  /** No se pudo. La pantalla sigue con el código. */
  | "unavailable"

/** Lo último que se vio, para decidir si la marcación queda flageada. */
export interface FaceSighting {
  /** `performance.now()` del momento. */
  at: number
  /** A quién se pareció, o `null` si a nadie de los registrados. */
  employeeId: string | null
}

export interface UseFaceRecognitionOptions {
  videoRef: React.RefObject<HTMLVideoElement | null>
  /** Los rostros contra los que comparar. */
  candidates: FaceCandidate[]
  /** `false` apaga todo: sin cámara, o la pantalla está en otra cosa. */
  enabled: boolean
  /** Pausa el bucle sin descargar el modelo (pantalla de confirmación abierta). */
  paused?: boolean
  /** Se llama UNA vez cuando alguien queda identificado con prueba de vida. */
  onIdentified?: (employeeId: string) => void
}

export interface UseFaceRecognition {
  status: FaceEngineStatus
  /** Hay una cara delante de la cámara AHORA. */
  facePresent: boolean
  /** Se vio una cara y todavía falta el parpadeo. */
  awaitingBlink: boolean
  /**
   * Qué se vio hace poco. Se lee al CONFIRMAR una marcación por código, para
   * saber si hay que flagearla — por eso es una ref y no estado: no tiene que
   * volver a renderizar nada, solo estar disponible en ese instante.
   */
  lastSighting: React.RefObject<FaceSighting | null>
  /** Lee un cuadro a pedido. Lo usa el enrolamiento, que captura cuando le dicen. */
  readOnce: () => Promise<FaceReading | null>
  /** Olvida lo visto. Se llama al cerrar una confirmación. */
  reset: () => void
}

export function useFaceRecognition({
  videoRef,
  candidates,
  enabled,
  paused = false,
  onIdentified,
}: UseFaceRecognitionOptions): UseFaceRecognition {
  const [status, setStatus] = React.useState<FaceEngineStatus>("off")
  const [facePresent, setFacePresent] = React.useState(false)
  const [awaitingBlink, setAwaitingBlink] = React.useState(false)

  // El módulo cargado. En una ref y no en estado: cambiarlo no tiene que
  // redibujar nada, y guardarlo en estado dispararía un render con un objeto de
  // varios MB adentro.
  const engineRef = React.useRef<Awaited<ReturnType<typeof loadFaceEngine>>>(null)
  const blinkRef = React.useRef(new BlinkDetector())
  const windowRef = React.useRef<number[][]>([])
  const cooldownUntilRef = React.useRef(0)
  const lastSighting = React.useRef<FaceSighting | null>(null)

  // Los candidatos y el callback en refs: el bucle los lee en cada tick y no
  // puede re-armarse cada vez que el padre re-renderiza (perdería el estado del
  // parpadeo a mitad de camino, que es justo lo que hace falta conservar).
  const candidatesRef = React.useRef(candidates)
  candidatesRef.current = candidates
  const onIdentifiedRef = React.useRef(onIdentified)
  onIdentifiedRef.current = onIdentified
  const pausedRef = React.useRef(paused)
  pausedRef.current = paused

  const reset = React.useCallback(() => {
    blinkRef.current.reset()
    windowRef.current = []
    setAwaitingBlink(false)
  }, [])

  // ── Carga del modelo ──────────────────────────────────────────────────────
  //
  // Solo cuando la pantalla lo pide. Son 8 MB entre librería y modelos: una caja
  // que nunca abre la marcación no descarga nada de esto, y un comercio que no
  // usa el rostro tampoco (sin candidatos no se carga).
  React.useEffect(() => {
    if (!enabled) {
      setStatus("off")
      return
    }
    let cancelled = false
    setStatus("loading")

    void loadFaceEngine().then((mod) => {
      if (cancelled) return
      engineRef.current = mod
      // `unavailable` no es un error que haya que mostrar con alarma: es un
      // quiosco que va a funcionar por código, como funcionaba antes de esta
      // fase.
      setStatus(mod ? "ready" : "unavailable")
    })

    return () => {
      cancelled = true
    }
  }, [enabled])

  // ── El bucle ──────────────────────────────────────────────────────────────
  React.useEffect(() => {
    if (status !== "ready") return

    let cancelled = false
    let timer: ReturnType<typeof setTimeout> | null = null
    // `running` evita que dos lecturas se pisen: en una tablet lenta un tick
    // puede tardar más que el intervalo, y encimarlas la hunde del todo. Por eso
    // se re-agenda al TERMINAR cada lectura y no con un `setInterval`.
    let running = false

    const tick = async () => {
      if (cancelled || running) return
      running = true
      try {
        if (pausedRef.current) return

        const engine = engineRef.current
        if (!engine) return

        const reading = await readFace(engine, videoRef.current)
        if (cancelled) return

        if (!reading) {
          // Nadie delante de la cámara. La ventana se vacía —promediar lecturas
          // separadas por una ausencia sería promediar dos momentos distintos—
          // pero el parpadeo NO se reinicia: la persona pudo haber salido del
          // cuadro un instante.
          windowRef.current = []
          setFacePresent(false)
          setAwaitingBlink(false)
          return
        }

        setFacePresent(true)
        const blinked = blinkRef.current.push(reading.eyeRatio, performance.now())

        const buf = windowRef.current
        buf.push(reading.embedding)
        if (buf.length > WINDOW) buf.shift()

        if (buf.length < WINDOW) {
          setAwaitingBlink(false)
          return
        }

        const probe = averageEmbedding(buf)
        if (!probe) return

        const result = pickBestMatch(probe, candidatesRef.current)

        // Se registra lo visto SIEMPRE, con parpadeo o sin él: sirve para el
        // motivo de revisión de una marcación hecha por código, y ahí lo que
        // importa es si HABÍA una cara y de quién era — no si probó estar viva.
        lastSighting.current = {
          at: performance.now(),
          employeeId: result.matched ? result.match.employeeId : null,
        }

        if (!result.matched) {
          setAwaitingBlink(false)
          return
        }

        // Identificada, pero todavía sin prueba de vida: la pantalla lo dice y
        // la persona parpadea (o tipea su código, que también sirve).
        if (!blinkRef.current.alive && !blinked) {
          setAwaitingBlink(true)
          return
        }

        if (performance.now() < cooldownUntilRef.current) return

        cooldownUntilRef.current = performance.now() + COOLDOWN_MS
        setAwaitingBlink(false)
        blinkRef.current.reset()
        windowRef.current = []
        onIdentifiedRef.current?.(result.match.employeeId)
      } finally {
        running = false
        if (!cancelled) timer = setTimeout(tick, TICK_MS)
      }
    }

    timer = setTimeout(tick, TICK_MS)
    return () => {
      cancelled = true
      if (timer) clearTimeout(timer)
    }
  }, [status, videoRef])

  // ── Bucle rápido del parpadeo ─────────────────────────────────────────────
  //
  // Solo mientras la pantalla espera la prueba de vida. El bucle principal
  // sigue a su ritmo (identificar es caro); este alimenta el detector de
  // parpadeo con lecturas livianas a ~12 Hz — ver `readEyeRatio`. Cuando el
  // parpadeo se completa, `blinkRef.alive` queda en true y el próximo tick del
  // bucle principal termina la marcación.
  React.useEffect(() => {
    if (!awaitingBlink || status !== "ready") return

    let cancelled = false
    let running = false
    const fastTick = async () => {
      if (cancelled || running) return
      running = true
      try {
        const engine = engineRef.current
        if (!engine || pausedRef.current) return
        const ratio = await readEyeRatio(engine, videoRef.current)
        if (cancelled) return
        blinkRef.current.push(ratio, performance.now())
      } finally {
        running = false
      }
    }
    const timer = setInterval(() => void fastTick(), 80)
    return () => {
      cancelled = true
      clearInterval(timer)
    }
  }, [awaitingBlink, status, videoRef])

  const readOnce = React.useCallback(async (): Promise<FaceReading | null> => {
    const engine = engineRef.current
    if (!engine) return null
    return readFace(engine, videoRef.current)
  }, [videoRef])

  return { status, facePresent, awaitingBlink, lastSighting, readOnce, reset }
}

/**
 * Qué informar sobre la cámara al registrar una marcación.
 *
 * Se resuelve en el momento de confirmar y no antes, porque depende de QUIÉN
 * terminó marcando:
 *
 *   - Marcó con la cara            → 'matched'.
 *   - Marcó con su código y recién
 *     se vio SU cara               → 'matched'. No hay nada raro que mirar.
 *   - Marcó con su código y recién
 *     se vio OTRA cara, o una que
 *     no reconocimos               → 'mismatch'. Este es el caso que existe.
 *   - No se vio ninguna cara       → 'none'.
 *
 * El tercero es el que el dueño quiere poder revisar: alguien se paró frente a
 * la cámara y el código que se tipeó no era el suyo. La marcación entra igual
 * (D4) — lo único que cambia es que queda marcada para mirar.
 */
export function resolveFaceOutcome(
  method: "pin" | "face",
  sighting: FaceSighting | null,
  employeeId: string,
): "none" | "matched" | "mismatch" {
  if (method === "face") return "matched"
  if (!sighting) return "none"
  // Una cara vista hace un minuto no dice nada sobre esta marcación.
  if (performance.now() - sighting.at > SIGHTING_TTL_MS) return "none"
  return sighting.employeeId === employeeId ? "matched" : "mismatch"
}
