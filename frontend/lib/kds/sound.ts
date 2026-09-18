/**
 * Aviso sonoro del KDS al entrar una comanda nueva.
 *
 * El andamiaje (crear el `AudioContext`, desbloquearlo con un gesto, sintetizar
 * las notas, cerrarlo al desmontar) NO vive acá: vive en `lib/audio/tones.ts`,
 * compartido con el reloj de marcación. Acá queda lo único propio del KDS, que
 * es QUÉ suena. La razón está en el docblock del módulo compartido —dos copias
 * del ciclo de vida significan dos `AudioContext` singleton en el mismo bundle.
 *
 * El contrato que la pantalla ve no cambió: `kdsSoundState()` para saber si
 * está listo, `unlockKdsSound()` SOLO desde un handler de click (el botón
 * "Activar sonido" de la barra inferior o "Probar" en la config), y
 * `playKdsChime()` que degrada en silencio si nadie desbloqueó nada.
 */

import {
  closeToneAudio,
  playTones,
  toneAudioState,
  unlockToneAudio,
  type Tone,
  type ToneAudioState,
} from "@/lib/audio/tones"

export type KdsSoundState = ToneAudioState

/** Dos senoidales cortas que suben: "entró algo". */
const CHIME: Tone[] = [
  { freq: 880, at: 0, duration: 0.14 },
  { freq: 1175, at: 0.16, duration: 0.14 },
]

export function kdsSoundState(): KdsSoundState {
  return toneAudioState()
}

/**
 * Crea/reanuda el AudioContext. DEBE invocarse dentro de un gesto del usuario.
 * Devuelve `true` si quedó reproducible.
 */
export function unlockKdsSound(): Promise<boolean> {
  return unlockToneAudio()
}

/** Beep corto de dos notas. No-op si el audio no está desbloqueado. */
export function playKdsChime(): void {
  playTones(CHIME)
}

/**
 * Libera el contexto al desmontar la pantalla. El KDS queda abierto días: un
 * AudioContext colgado por cada remount (HMR, navegación) agota el límite de
 * contextos del navegador.
 */
export function closeKdsSound(): void {
  closeToneAudio()
}
