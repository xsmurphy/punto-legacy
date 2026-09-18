/**
 * Avisos sonoros sintetizados con Web Audio, compartidos por las pantallas del
 * comercio (KDS, reloj de marcación).
 *
 * Se sintetizan tonos en vez de reproducir archivos: no hay asset que servir,
 * no depende de la red (la cocina y la entrada pueden quedarse sin internet y
 * las pantallas siguen andando) y no hay CDN externo.
 *
 * ── Por qué esto es un módulo propio y no dos ──────────────────────────────
 *
 * El KDS ya tenía su chime con todo este andamiaje adentro
 * (`lib/kds/sound.ts`). Cuando el reloj necesitó los suyos, copiar el archivo
 * habría dejado DOS `AudioContext` singleton en el mismo bundle: el navegador
 * limita cuántos se pueden crear por página, y "cada pantalla con el suyo" es
 * la clase de duplicación que se paga recién cuando alguien monta las dos
 * juntas. El ciclo de vida del contexto vive una sola vez, acá; lo que cada
 * pantalla define es SU tono, que es lo único propio.
 *
 * ── Política de autoplay: el contexto nace mudo ────────────────────────────
 *
 * TODOS los navegadores crean el `AudioContext` en estado `suspended` hasta que
 * hay un gesto del usuario. Una pantalla colgada de la pared que nadie tocó
 * NUNCA tuvo ese gesto, así que el sonido simplemente no suena. Por eso el
 * contrato es explícito y nada de esto tira:
 *
 *   - `toneAudioState()` dice si está listo, bloqueado o no soportado.
 *   - `unlockToneAudio()` SOLO sirve dentro de un handler de gesto (click,
 *     pointerdown, keydown). Fuera de uno, el navegador lo deja `suspended` y
 *     devuelve `false`.
 *   - `playTones()` degrada EN SILENCIO si no está desbloqueado — nunca tira,
 *     nunca encola para después.
 *
 * Que el aviso no suene es aceptable; que la pantalla se rompa por un aviso,
 * no.
 */

export type ToneAudioState = "unsupported" | "blocked" | "ready"

/** Una nota del aviso. */
export interface Tone {
  /** Hz. */
  freq: number
  /** Cuándo empieza, en segundos desde el inicio del aviso. */
  at: number
  /** Cuánto dura, en segundos. */
  duration: number
  /** Volumen pico (0-1). */
  gain?: number
  /** Forma de onda. `sine` es la más suave y la default. */
  type?: OscillatorType
}

type AudioCtor = typeof AudioContext

let ctx: AudioContext | null = null

function audioCtor(): AudioCtor | null {
  if (typeof window === "undefined") return null
  const w = window as unknown as { AudioContext?: AudioCtor; webkitAudioContext?: AudioCtor }
  return w.AudioContext ?? w.webkitAudioContext ?? null
}

export function toneAudioState(): ToneAudioState {
  if (!audioCtor()) return "unsupported"
  return ctx && ctx.state === "running" ? "ready" : "blocked"
}

/**
 * Crea/reanuda el `AudioContext`. DEBE invocarse dentro de un gesto del
 * usuario. Devuelve `true` si quedó reproducible.
 */
export async function unlockToneAudio(): Promise<boolean> {
  const Ctor = audioCtor()
  if (!Ctor) return false
  try {
    ctx ??= new Ctor()
    if (ctx.state !== "running") await ctx.resume()
    return ctx.state === "running"
  } catch {
    return false
  }
}

/**
 * Reproduce una secuencia de notas. No-op si el audio no está desbloqueado.
 *
 * La envolvente sube y baja con rampas exponenciales en vez de arrancar y
 * cortar de golpe: un oscilador que empieza en volumen pleno hace un "click"
 * audible que suena a falla del parlante, no a aviso.
 */
export function playTones(tones: Tone[]): void {
  if (!ctx || ctx.state !== "running") return
  try {
    const now = ctx.currentTime
    for (const tone of tones) {
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      const at = now + tone.at
      const peak = tone.gain ?? 0.25
      osc.type = tone.type ?? "sine"
      osc.frequency.value = tone.freq
      // `exponentialRampToValueAtTime` no acepta 0 como destino ni como origen:
      // de ahí el 0.0001 en las puntas, que es silencio a efectos prácticos.
      gain.gain.setValueAtTime(0.0001, at)
      gain.gain.exponentialRampToValueAtTime(peak, at + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, at + tone.duration)
      osc.connect(gain).connect(ctx.destination)
      osc.start(at)
      osc.stop(at + tone.duration + 0.02)
    }
  } catch {
    /* degradar en silencio — un aviso sonoro nunca puede tumbar la pantalla */
  }
}

/**
 * Libera el contexto al desmontar la pantalla. Estas pantallas quedan abiertas
 * días: un `AudioContext` colgado por cada remount (HMR, navegación) agota el
 * límite de contextos del navegador.
 */
export function closeToneAudio(): void {
  if (!ctx) return
  const dying = ctx
  ctx = null
  void dying.close().catch(() => {})
}
