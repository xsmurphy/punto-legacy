/**
 * El motor: cargar el modelo y mirar la cámara (RRHH F2, context/83 D5).
 *
 * Todo lo sucio vive acá — la librería, los modelos, el video, TensorFlow. La
 * parte que DECIDE (a quién se parece una cara, si parpadeó) está aparte, en
 * `face-match.ts` y `blink.ts`, y por eso se puede probar sin una webcam.
 *
 * ── Nada de esto está en el bundle común del POS ───────────────────────────
 *
 * La librería son 1,3 MB y los modelos 6,7 MB. Este archivo se importa SIEMPRE
 * con `await import()`, desde la pantalla de marcación y solo cuando ya hay una
 * cámara viva: una caja que nunca abre esa pantalla no descarga un byte de esto.
 * Por eso tampoco hay un `import` de la librería arriba de todo — un import
 * estático la metería en el chunk de quien importe este módulo, que es
 * exactamente lo que se quiere evitar.
 *
 * ── Y nada sale del dispositivo ────────────────────────────────────────────
 *
 * Los modelos se sirven del mismo deploy (`/models/face`, ver
 * `scripts/copy-face-models.mjs`) y el cálculo corre en el navegador. Cero
 * requests a terceros: es lo que hace que la marcación funcione sin internet y
 * que la cara de un empleado no viaje a ningún servicio ajeno (D5).
 *
 * ── Fail-open, como todo el resto de esta pantalla ─────────────────────────
 *
 * Ninguna función de este archivo tira. Si los modelos no bajan, si el
 * navegador no puede con ellos, si un cuadro sale mal: se devuelve `null` o
 * `ready: false` y la pantalla sigue con el código de marcación, que es el
 * camino de la F1 y siempre está.
 */

import type * as FaceApi from "@vladmandic/face-api"

import { blinkRatio, eyesFromLandmarks, type FacePoint } from "@/lib/pos/face/blink"

/** De dónde salen los modelos. Del propio deploy, nunca de un CDN. */
const MODEL_URL = "/models/face"

/**
 * Tamaño al que el detector reduce el cuadro antes de buscar caras.
 *
 * 320 y no más: en la tablet barata de un mostrador, 416 o 608 bajan el ritmo a
 * menos de dos cuadros por segundo y la pantalla se siente trabada justo cuando
 * la persona está esperando. A 320 una cara a medio metro de la cámara —que es
 * la distancia real de un quiosco— se detecta de sobra.
 */
const DETECTOR_INPUT_SIZE = 320

/** Confianza mínima para decir "acá hay una cara". */
const DETECTOR_SCORE = 0.5

type FaceApiModule = typeof FaceApi

let modulePromise: Promise<FaceApiModule | null> | null = null

/**
 * Carga la librería y los tres modelos. Una sola vez por pestaña.
 *
 * La promesa se guarda, no el resultado: dos llamadas simultáneas —la pantalla
 * montándose dos veces en desarrollo, por ejemplo— comparten la misma carga en
 * vez de bajar 8 MB dos veces.
 *
 * Devuelve `null` si algo falló. Un fallo acá no es excepcional: un navegador
 * viejo sin WebGL, un deploy sin los modelos copiados, una tablet sin memoria.
 * En todos esos casos la respuesta correcta es que el quiosco funcione por
 * código, no que la pantalla se rompa.
 */
export async function loadFaceEngine(): Promise<FaceApiModule | null> {
  if (typeof window === "undefined") return null
  if (modulePromise) return modulePromise

  modulePromise = (async () => {
    try {
      // Import del archivo del `dist` y no del paquete: ver el docblock de
      // `face-api-esm.d.ts`. El `main` del paquete es el build de Node y pide
      // un binario nativo que no existe acá.
      const faceapi = (await import(
        "@vladmandic/face-api/dist/face-api.esm.js"
      )) as unknown as FaceApiModule

      // El motor de cálculo (WebGL, o CPU si no hay) se inicializa solo en la
      // primera operación, que es la carga de pesos de acá abajo. No se lo
      // fuerza a mano: los tipos que publica el paquete para esa capa son
      // parciales, y un `as any` para llamar a algo que ya pasa solo sería ruido
      // con riesgo. Si el navegador no puede con ninguno de los dos, la carga
      // falla y esta función devuelve `null`, que es el camino previsto.
      //
      // Los tres en paralelo: son tres descargas independientes y en serie
      // suman la latencia de todas.
      await Promise.all([
        faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
        faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
        faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
      ])

      return faceapi
    } catch {
      // Se limpia la promesa fallida para que un reintento posterior —volver a
      // entrar a la pantalla con mejor conexión— pueda intentar de nuevo en vez
      // de heredar el fracaso para siempre.
      modulePromise = null
      return null
    }
  })()

  return modulePromise
}

/** Lo que devuelve mirar un cuadro. */
export interface FaceReading {
  /** El vector de 128 números de la cara que se vio. */
  embedding: number[]
  /** Relación de apertura de los ojos, para la prueba de vida. `null` si no se midió. */
  eyeRatio: number | null
  /** Confianza de la detección. */
  score: number
}

/**
 * Mira UN cuadro del video y devuelve lo que encontró.
 *
 * `null` = no había cara, o no se pudo leer. Los dos casos son lo mismo para
 * quien llama: no hay a quién comparar todavía.
 *
 * Se piden landmarks Y descriptor en la misma pasada porque el descriptor ya
 * necesita los landmarks para alinear el recorte: pedirlos por separado sería
 * calcular dos veces lo mismo, y acá se corre varias veces por segundo.
 */
/**
 * Lectura LIVIANA: solo detección + landmarks, sin embedding.
 *
 * Existe por el parpadeo. Un parpadeo dura ~150-250 ms y la lectura completa
 * (con descriptor) corre cada ~320 ms más su propio costo: el ojo cerrado cae
 * ENTRE dos lecturas y el detector no lo ve nunca — "Parpadeá" quedaba
 * esperando para siempre. Sin el descriptor la inferencia es varias veces más
 * barata y se puede muestrear a ~10-12 Hz mientras se espera la prueba de
 * vida, que es la frecuencia que un parpadeo real necesita.
 */
export async function readEyeRatio(
  faceapi: FaceApiModule,
  video: HTMLVideoElement | null,
): Promise<number | null> {
  if (!video || !video.videoWidth || !video.videoHeight) return null
  try {
    const result = await faceapi
      .detectSingleFace(
        video,
        new faceapi.TinyFaceDetectorOptions({
          inputSize: DETECTOR_INPUT_SIZE,
          scoreThreshold: DETECTOR_SCORE,
        }),
      )
      .withFaceLandmarks()
    const positions = (result?.landmarks?.positions ?? []) as unknown as FacePoint[]
    const eyes = eyesFromLandmarks(positions)
    return eyes ? blinkRatio(eyes.left, eyes.right) : null
  } catch {
    return null
  }
}

export async function readFace(
  faceapi: FaceApiModule,
  video: HTMLVideoElement | null,
): Promise<FaceReading | null> {
  // `videoWidth` es 0 hasta que llega el primer cuadro. Leer ahí procesaría un
  // lienzo vacío: costo completo, resultado nulo.
  if (!video || !video.videoWidth || !video.videoHeight) return null

  try {
    const result = await faceapi
      .detectSingleFace(
        video,
        new faceapi.TinyFaceDetectorOptions({
          inputSize: DETECTOR_INPUT_SIZE,
          scoreThreshold: DETECTOR_SCORE,
        }),
      )
      .withFaceLandmarks()
      .withFaceDescriptor()

    if (!result?.descriptor) return null

    const positions = (result.landmarks?.positions ?? []) as unknown as FacePoint[]
    const eyes = eyesFromLandmarks(positions)

    return {
      embedding: Array.from(result.descriptor),
      eyeRatio: eyes ? blinkRatio(eyes.left, eyes.right) : null,
      score: result.detection?.score ?? 0,
    }
  } catch {
    // Un cuadro que falla no es nada: viene otro en 300 ms.
    return null
  }
}

/**
 * ¿Están los modelos ya cargados en esta pestaña?
 *
 * Sirve para no volver a mostrar "preparando la cámara" cuando la persona entra
 * y sale de la pantalla dentro de la misma sesión.
 */
export function isFaceEngineLoaded(): boolean {
  return modulePromise !== null
}
