/**
 * Aviso sonoro del KDS al entrar una comanda nueva.
 *
 * Se sintetiza con Web Audio (dos senoidales cortas) en vez de reproducir un
 * archivo: no hay asset que servir, no depende de la red (la cocina puede
 * quedarse sin internet y el KDS sigue con el WS local), y no hay CDN externo.
 *
 * ⚠ Política de autoplay: TODOS los navegadores crean el `AudioContext` en
 * estado `suspended` hasta que hay un gesto del usuario. Una pantalla de cocina
 * que se abre y queda desatendida NUNCA tuvo ese gesto, así que el sonido
 * simplemente no sonaría — y peor: lo haría en silencio, sin avisar. Por eso el
 * contrato de este módulo es explícito:
 *
 *   - `kdsSoundState()` dice si el audio está listo, bloqueado o no soportado.
 *   - `unlockKdsSound()` SOLO se llama desde un handler de click (el botón
 *     "Activar sonido" de la barra inferior o "Probar" en la config).
 *   - `playKdsChime()` degrada en silencio si no está desbloqueado — nunca
 *     tira ni encola.
 *
 * La UI muestra el botón de activación mientras el estado no sea "ready", así
 * el operador ve que el sonido está pedido pero todavía no habilitado.
 */

export type KdsSoundState = "unsupported" | "blocked" | "ready"

type AudioCtor = typeof AudioContext

let ctx: AudioContext | null = null

function audioCtor(): AudioCtor | null {
  if (typeof window === "undefined") return null
  const w = window as unknown as { AudioContext?: AudioCtor; webkitAudioContext?: AudioCtor }
  return w.AudioContext ?? w.webkitAudioContext ?? null
}

export function kdsSoundState(): KdsSoundState {
  if (!audioCtor()) return "unsupported"
  return ctx && ctx.state === "running" ? "ready" : "blocked"
}

/**
 * Crea/reanuda el AudioContext. DEBE invocarse dentro de un gesto del usuario.
 * Devuelve `true` si quedó reproducible.
 */
export async function unlockKdsSound(): Promise<boolean> {
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

/** Beep corto de dos notas. No-op si el audio no está desbloqueado. */
export function playKdsChime(): void {
  if (!ctx || ctx.state !== "running") return
  try {
    const now = ctx.currentTime
    for (const [i, freq] of [880, 1175].entries()) {
      const osc = ctx.createOscillator()
      const gain = ctx.createGain()
      const at = now + i * 0.16
      osc.type = "sine"
      osc.frequency.value = freq
      gain.gain.setValueAtTime(0.0001, at)
      gain.gain.exponentialRampToValueAtTime(0.25, at + 0.02)
      gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.14)
      osc.connect(gain).connect(ctx.destination)
      osc.start(at)
      osc.stop(at + 0.16)
    }
  } catch {
    /* degradar en silencio — el aviso sonoro nunca puede tumbar la pantalla */
  }
}

/**
 * Libera el contexto al desmontar la pantalla. El KDS queda abierto días: un
 * AudioContext colgado por cada remount (HMR, navegación) agota el límite de
 * contextos del navegador.
 */
export function closeKdsSound(): void {
  if (!ctx) return
  const dying = ctx
  ctx = null
  void dying.close().catch(() => {})
}
