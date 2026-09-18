/**
 * La foto de evidencia de una marcación (context/83 F1, D4).
 *
 * ── Por qué comprimirla, y por qué a ESTE tamaño ───────────────────────────
 *
 * Un frame crudo de una cámara de tablet pesa varios MB. La marcación es
 * offline-nativa, así que esa foto puede quedarse en el IndexedDB del
 * dispositivo durante horas —y no una: todas las de un turno sin red—, y
 * después subir por la misma conexión que recién vuelve. Un turno de veinte
 * marcaciones sin comprimir son decenas de MB peleando con la cola de ventas.
 *
 * El objetivo es ~100 KB: alcanza de sobra para reconocer a una persona en
 * pantalla, que es todo lo que esta foto tiene que permitir. No es material
 * forense ni entra a ningún reconocedor — cuando llegue el facial (F2), lo que
 * se compara es un EMBEDDING calculado en el dispositivo sobre el frame en
 * vivo, no este JPEG.
 *
 * ── Fail-open, también acá ─────────────────────────────────────────────────
 *
 * Nada de este archivo tira. Si no hay cámara, si el permiso está denegado, si
 * el frame todavía no llegó o si el canvas no produce un blob, se devuelve
 * `null` con un MOTIVO, y la marcación entra igual y queda flageada. Una
 * excepción acá arriba sería una persona que trabajó y no pudo registrarlo.
 */

/** Ancho máximo del JPEG guardado. Suficiente para reconocer una cara. */
const MAX_WIDTH = 480

/** Techo de tamaño. Por encima se vuelve a comprimir con menos calidad. */
const TARGET_BYTES = 120 * 1024

/** Calidades a probar, de mejor a peor. La última se acepta pese a todo. */
const QUALITY_STEPS = [0.7, 0.55, 0.4]

/**
 * Por qué no hay foto. Son los mismos códigos que el backend guarda en
 * `attendance_mark.reviewreason` — el front no traduce acá, solo elige.
 */
export type NoPhotoReason = "no_camera" | "camera_denied" | "photo_failed"

export interface CameraFailure {
  reason: NoPhotoReason
  /** Para mostrarle a la persona, en una línea, qué pasó. */
  message: string
}

/**
 * Traduce el fallo de `getUserMedia` a un motivo del catálogo.
 *
 * Distinguir "denegado" de "no hay cámara" importa porque son dos problemas de
 * dos personas distintas: el permiso lo arregla quien opera la tablet, y la
 * ausencia de cámara la arregla quien la compró. Un mensaje único mandaría a
 * todos a buscar en el lugar equivocado.
 */
export function describeCameraError(err: unknown): CameraFailure {
  const name = err instanceof Error ? err.name : ""
  if (name === "NotAllowedError" || name === "SecurityError") {
    return {
      reason: "camera_denied",
      message: "La cámara está bloqueada en este dispositivo. Se puede marcar con el código.",
    }
  }
  if (name === "NotFoundError" || name === "OverconstrainedError") {
    return {
      reason: "no_camera",
      message: "Este dispositivo no tiene cámara. Se puede marcar con el código.",
    }
  }
  return {
    reason: "photo_failed",
    message: "No se pudo usar la cámara. Se puede marcar con el código.",
  }
}

/**
 * Toma el frame actual del video y lo devuelve como JPEG comprimido.
 *
 * Devuelve `null` —nunca tira— cuando no hay frame que capturar: el video
 * todavía no cargó dimensiones, el canvas no está disponible, o el browser no
 * produjo el blob. El llamador lo trata como "sin foto" y sigue.
 */
export async function captureJpeg(video: HTMLVideoElement | null): Promise<Blob | null> {
  if (!video) return null

  // `videoWidth` es 0 hasta que llega el primer frame. Dibujar ahí produce un
  // canvas vacío, o sea una foto negra que pasa por buena — peor que no tener
  // foto, porque no queda flageada.
  const sourceWidth = video.videoWidth
  const sourceHeight = video.videoHeight
  if (!sourceWidth || !sourceHeight) return null

  const scale = Math.min(1, MAX_WIDTH / sourceWidth)
  const canvas = document.createElement("canvas")
  canvas.width = Math.round(sourceWidth * scale)
  canvas.height = Math.round(sourceHeight * scale)

  const ctx = canvas.getContext("2d")
  if (!ctx) return null
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

  for (const quality of QUALITY_STEPS) {
    const blob = await toBlob(canvas, quality)
    if (!blob) continue
    // La última calidad se acepta aunque siga pasada: una foto grande sirve y
    // una foto que no existe, no. El backend tiene su propio techo (3 MB) muy
    // por encima de cualquier resultado de esta escala.
    if (blob.size <= TARGET_BYTES || quality === QUALITY_STEPS[QUALITY_STEPS.length - 1]) {
      return blob
    }
  }
  return null
}

function toBlob(canvas: HTMLCanvasElement, quality: number): Promise<Blob | null> {
  return new Promise((resolve) => {
    try {
      canvas.toBlob((blob) => resolve(blob), "image/jpeg", quality)
    } catch {
      resolve(null)
    }
  })
}
