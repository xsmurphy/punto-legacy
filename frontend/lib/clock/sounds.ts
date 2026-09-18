/**
 * Los dos avisos sonoros del reloj de marcación (context/83 §9.5).
 *
 * El reloj está colgado en la entrada y la persona que marca casi nunca mira la
 * pantalla: se para, la cámara la reconoce y sigue caminando. El sonido es la
 * confirmación que el saludo en pantalla no alcanza a dar, y por eso son dos y
 * bien distintos entre sí:
 *
 *   - **Listo** — campanita corta que sube. Suena junto al "Bienvenido/Adiós",
 *     o sea cuando la marcación YA quedó registrada (o repetida dentro de la
 *     ventana, que para la persona es lo mismo: su marca está).
 *   - **No te reconozco** — un tono grave y corto. Suena cuando hubo una cara
 *     sostenida delante de la cámara y no se pareció a nadie de los
 *     registrados. No es un error de la persona ni una acusación: es la señal
 *     de que ahí parado no va a pasar nada y conviene usar el código.
 *
 * ── El grave no puede sonar en loop ────────────────────────────────────────
 *
 * El bucle de reconocimiento mira ~3 veces por segundo, así que alguien sin
 * rostro registrado parado frente a la tablet dispara "no matcheó" una y otra
 * vez. Sin freno, el reloj le ladraría sin parar mientras busca su código —y al
 * resto del local también. De ahí `canPlayUnknown()`: el MISMO estímulo
 * repetido suena una vez cada `UNKNOWN_COOLDOWN_MS`.
 *
 * El aviso de éxito NO tiene freno propio: quien acaba de marcar ya está
 * protegido por la ventana de repetición de `session-marks.ts` (la misma
 * persona no vuelve a generar una marcación en 60 s), y dos personas seguidas
 * SÍ tienen que escuchar su confirmación cada una.
 *
 * La decisión de sonar es una función PURA con el reloj inyectado —no lee
 * `Date.now()` por su cuenta— porque es la única parte de esto que se puede
 * verificar sin un navegador, y es justo la que tiene la regla.
 */

import { playTones, unlockToneAudio, type Tone } from "@/lib/audio/tones"

/**
 * Cuánto callar el aviso de "no te reconozco" después de darlo.
 *
 * Diez segundos: lo que tarda alguien en leer la pantalla y decidir que va a
 * usar el código. Menos vuelve a ser un loop; más deja sin aviso a la persona
 * que llegó después de la que se fue sin marcar.
 */
export const UNKNOWN_COOLDOWN_MS = 10_000

/** Campanita corta que sube: la marcación quedó. */
const SUCCESS: Tone[] = [
  { freq: 880, at: 0, duration: 0.12, gain: 0.22 },
  { freq: 1318, at: 0.11, duration: 0.18, gain: 0.22 },
]

/**
 * Tono grave y corto. Una sola nota baja, más bajita de volumen que el éxito:
 * avisa sin retar, y sin llamar la atención de todo el local.
 */
const UNKNOWN: Tone[] = [{ freq: 196, at: 0, duration: 0.22, gain: 0.18 }]

/**
 * ¿Corresponde sonar el aviso de "no te reconozco"?
 *
 * `lastPlayedAt` es `null` cuando todavía no sonó nunca en esta sesión. Pura y
 * con el reloj por parámetro: la pantalla le pasa `Date.now()`.
 */
export function canPlayUnknown(lastPlayedAt: number | null, now: number): boolean {
  if (lastPlayedAt === null) return true
  // Un `lastPlayedAt` en el futuro (el reloj del aparato se corrigió hacia
  // atrás, que en una tablet que sincroniza hora pasa) dejaría el aviso mudo
  // hasta alcanzarlo. Se trata como "hace mucho": el lado seguro es sonar.
  if (now < lastPlayedAt) return true
  return now - lastPlayedAt >= UNKNOWN_COOLDOWN_MS
}

/**
 * Desbloquea el audio. SOLO sirve llamado dentro de un gesto (un toque en la
 * pantalla, una tecla). Sin gesto nunca hubo, el audio queda mudo y no pasa
 * nada más.
 */
export function unlockClockSound(): Promise<boolean> {
  return unlockToneAudio()
}

/** La marcación quedó registrada. No-op si el audio nunca se desbloqueó. */
export function playClockSuccess(): void {
  playTones(SUCCESS)
}

/** Hay alguien delante y no se pareció a nadie. Ver `canPlayUnknown()`. */
export function playClockUnknown(): void {
  playTones(UNKNOWN)
}
