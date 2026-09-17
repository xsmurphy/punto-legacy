/**
 * Prueba de vida por parpadeo (RRHH F2, context/83 D4).
 *
 * Pura, como `face-match.ts`: entran los puntos de los ojos, sale si la persona
 * parpadeó. Sin cámara y sin DOM, para que se pueda probar.
 *
 * ── Qué problema resuelve, y cuál NO ───────────────────────────────────────
 *
 * Un reconocedor que mira una imagen no distingue una cara de una FOTO de esa
 * cara. Sin ninguna comprobación, el buddy punching vuelve en su forma más
 * barata: una foto impresa del compañero delante de la tablet.
 *
 * Pedir un parpadeo lo arregla en su versión barata —una foto impresa no
 * parpadea— y no en su versión cara: un video en un celular sí. La D4 lo dice
 * con todas las letras y vale repetirlo acá: esto es control de ASISTENCIA de
 * una pyme, no control de acceso. La evidencia real de la marcación sigue siendo
 * la foto guardada, que convierte el intento en algo con autor.
 *
 * ── Y no bloquea a nadie ───────────────────────────────────────────────────
 *
 * Que no haya parpadeo no impide marcar: impide el ATAJO de marcar con la cara.
 * La persona tipea su código y sigue su día, exactamente como en la F1. La
 * distinción importa: la D4 dice que la cara nunca bloquea, y acá no bloquea —
 * lo que hace es no ofrecerse.
 */

/** Un punto de la cara, como los devuelve el modelo de 68 puntos. */
export interface FacePoint {
  x: number
  y: number
}

/**
 * Relación de apertura del ojo (EAR, por su nombre en la literatura).
 *
 * Toma los 6 puntos del contorno de un ojo y divide su ALTO promedio por su
 * ANCHO. Es una proporción, no una medida: por eso funciona igual con la persona
 * cerca o lejos de la cámara, que es justamente lo que se necesita cuando nadie
 * controla a qué distancia se para.
 *
 * Ojo abierto ≈ 0,3. Cerrado ≈ 0,1. Devuelve `null` si los puntos no sirven.
 *
 *   p0 ─────── p3     (esquinas: el ancho)
 *      p1   p2        (párpado de arriba)
 *      p5   p4        (párpado de abajo)
 */
export function eyeAspectRatio(points: readonly FacePoint[]): number | null {
  if (points.length !== 6) return null
  for (const p of points) {
    if (!Number.isFinite(p?.x) || !Number.isFinite(p?.y)) return null
  }
  const dist = (a: FacePoint, b: FacePoint) => Math.hypot(a.x - b.x, a.y - b.y)

  const width = dist(points[0], points[3])
  // Un ancho de cero es un ojo que el modelo no vio: dividir daría infinito y
  // el infinito pasaría por "ojo muy abierto".
  if (width <= 1e-6) return null

  const height = (dist(points[1], points[5]) + dist(points[2], points[4])) / 2
  return height / width
}

/**
 * Promedio de los dos ojos, ignorando el que no se pudo medir.
 *
 * Se promedian porque la cara casi nunca está perfectamente de frente y el ojo
 * más escorzado da lecturas bajas sin que nadie haya parpadeado. Con uno solo
 * medible se usa ese: es peor que dos y mucho mejor que nada.
 */
export function blinkRatio(left: readonly FacePoint[], right: readonly FacePoint[]): number | null {
  const a = eyeAspectRatio(left)
  const b = eyeAspectRatio(right)
  if (a === null && b === null) return null
  if (a === null) return b
  if (b === null) return a
  return (a + b) / 2
}

/** Por debajo de esto el ojo se considera cerrado. */
export const EAR_CLOSED = 0.19

/**
 * Y por encima de esto, abierto.
 *
 * Los dos umbrales NO son el mismo número, y esa separación es el punto: con un
 * solo umbral, una lectura que oscila alrededor del límite —lo normal con una
 * cámara barata— cuenta diez parpadeos por segundo. Con una banda muerta en el
 * medio hay que cruzarla entera para cambiar de estado.
 */
export const EAR_OPEN = 0.24

/**
 * Cuánto vale un parpadeo, en milisegundos.
 *
 * Un parpadeo humano dura entre 100 y 400 ms. El piso descarta el ruido de un
 * cuadro suelto mal medido; el techo descarta a alguien con los ojos cerrados un
 * rato, que no es un parpadeo sino una persona esperando.
 */
export const BLINK_MIN_MS = 60
export const BLINK_MAX_MS = 800

/**
 * Detector de parpadeo: una máquina de estados sobre las lecturas sucesivas.
 *
 * Un parpadeo no es "el ojo está cerrado" —eso es una foto de alguien con los
 * ojos cerrados— sino la SECUENCIA abierto → cerrado → abierto dentro de un
 * tiempo razonable. Por eso hay estado y no una función suelta.
 *
 * Arranca en `unknown` y no en `open`: si empezara asumiendo el ojo abierto, la
 * primera lectura de alguien que ya estaba con los ojos entrecerrados
 * completaría medio parpadeo que nunca ocurrió.
 */
export class BlinkDetector {
  private state: "unknown" | "open" | "closed" = "unknown"
  private closedAt = 0
  private blinks = 0

  /**
   * Suma una lectura. Devuelve `true` SOLO en el cuadro en que se completa un
   * parpadeo — nunca después, para que el llamador pueda reaccionar una vez.
   *
   * `ratio` nulo (no se vieron los ojos) no rompe el estado ni lo avanza: la
   * cara pudo haber salido del cuadro un instante, y eso no es ni un parpadeo ni
   * una razón para olvidar lo que veníamos viendo.
   */
  push(ratio: number | null, nowMs: number): boolean {
    if (ratio === null || !Number.isFinite(ratio)) return false

    if (ratio <= EAR_CLOSED) {
      if (this.state === "open") {
        this.state = "closed"
        this.closedAt = nowMs
      } else if (this.state === "unknown") {
        // Entramos viendo el ojo ya cerrado: no sabemos si se acaba de cerrar.
        // Se espera a verlo abierto para empezar a contar de verdad.
        this.state = "unknown"
      }
      return false
    }

    if (ratio >= EAR_OPEN) {
      if (this.state === "closed") {
        const elapsed = nowMs - this.closedAt
        this.state = "open"
        if (elapsed >= BLINK_MIN_MS && elapsed <= BLINK_MAX_MS) {
          this.blinks++
          return true
        }
        return false
      }
      this.state = "open"
      return false
    }

    // Zona intermedia: ni abierto ni cerrado. No se toca el estado — es
    // exactamente el ruido que la banda muerta existe para absorber.
    return false
  }

  /** Cuántos parpadeos se vieron desde el último `reset()`. */
  get count(): number {
    return this.blinks
  }

  /** ¿Ya alcanza para dar la prueba de vida por cumplida? */
  get alive(): boolean {
    return this.blinks > 0
  }

  /**
   * Vuelve a cero. Se llama cuando la cara que se está mirando cambia (o
   * desaparece un rato): el parpadeo de una persona no puede acreditar la prueba
   * de vida de la que viene después.
   */
  reset(): void {
    this.state = "unknown"
    this.closedAt = 0
    this.blinks = 0
  }
}

/**
 * Los índices de los puntos de cada ojo en el modelo de 68 puntos.
 *
 * Es el estándar del conjunto de datos con el que se entrenó el modelo, y por
 * eso son números literales y no un cálculo: no los elegimos nosotros.
 */
export const LEFT_EYE_POINTS = [36, 37, 38, 39, 40, 41] as const
export const RIGHT_EYE_POINTS = [42, 43, 44, 45, 46, 47] as const

/**
 * Saca los dos ojos de la lista completa de puntos de la cara.
 *
 * Devuelve `null` si la lista no tiene el largo esperado: preferimos no medir a
 * medir sobre índices que apuntan a otra cosa.
 */
export function eyesFromLandmarks(
  landmarks: readonly FacePoint[],
): { left: FacePoint[]; right: FacePoint[] } | null {
  if (landmarks.length !== 68) return null
  return {
    left: LEFT_EYE_POINTS.map((i) => landmarks[i]),
    right: RIGHT_EYE_POINTS.map((i) => landmarks[i]),
  }
}
