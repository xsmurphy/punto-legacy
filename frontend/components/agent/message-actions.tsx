"use client"

import * as React from "react"
import { Copy, Check, Volume2, Square, Loader2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { api, ApiError } from "@/lib/api-client"

/**
 * Acciones inline debajo de cada mensaje del assistant: copiar al clipboard +
 * leer en voz alta.
 *
 * ── La voz (context/80) ─────────────────────────────────────────────────────
 * Hasta 2026-09-17 la lectura era `window.speechSynthesis` (la voz del
 * navegador, gratis y mala). Ahora el panel pide el audio a
 * `/api/agent/tts`, que sintetiza con un modelo TTS de OpenRouter y lo cobra
 * del crédito IA del tenant. La voz nativa NO se borró: quedó como FALLBACK
 * (D4) — sin créditos o con el proveedor caído, el usuario pidió escuchar y
 * escuchar algo feo le gana a un botón muerto.
 *
 * El audio se retiene por MENSAJE en un ref (D6): volver a tocar play sobre el
 * mismo mensaje reproduce el blob que ya está en memoria, sin pedirlo de nuevo
 * y sin debitar de nuevo. El object URL se revoca al desmontar.
 *
 * ── Por qué `remoteVoice` es una prop y no se resuelve acá ──────────────────
 * Este componente lo montan las DOS superficies (el thread es uno solo, ver el
 * docblock de `agent-chat-content.tsx`). `/api/agent/tts` es del realm PANEL y
 * se autentica con el Bearer del panel, que en la caja no existe: el POS es
 * token-only por mandato (`feedback_pos_token_only_no_realms`). Así que la
 * caja se queda con la voz nativa (D5 — v1 solo panel) apagando este
 * interruptor, mismo criterio que el resto de los interruptores del chat:
 * default `true` = comportamiento del panel.
 */

/**
 * Corta la reproducción que esté sonando, sea de este mensaje o de otro.
 *
 * `speechSynthesis` es global (una sola cola para toda la página), pero cada
 * mensaje tiene su propio `<audio>`: sin este registro a nivel de módulo, dar
 * play en un segundo mensaje dejaba los dos sonando encima. Lo que se registra
 * es el "cómo frenar" del que está sonando, y quien va a reproducir lo llama
 * primero.
 */
let stopCurrentPlayback: (() => void) | null = null

function stopAnyPlayback() {
  const stop = stopCurrentPlayback
  stopCurrentPlayback = null
  if (stop) stop()
  if (typeof window !== "undefined" && window.speechSynthesis) {
    window.speechSynthesis.cancel()
  }
}

type SpeakState = "idle" | "loading" | "playing"

export function MessageActions({
  text,
  showSpeak = true,
  // Default FALSE (hallazgo del review): un consumidor nuevo que no declare
  // la prop no debe intentar el endpoint del realm panel con una credencial
  // que quizás no tiene — la voz remota se ENCIENDE explícitamente donde
  // corresponde, nunca por omisión.
  remoteVoice = false,
}: {
  text: string
  /** Mensajes del user solo muestran "Copiar" — leerse a sí mismo no aporta. */
  showSpeak?: boolean
  /**
   * `false` = leer con la voz del navegador, sin pasar por el BFF ni cobrar.
   * Lo apaga la caja: su credencial no abre un endpoint del realm panel.
   */
  remoteVoice?: boolean
}) {
  const [copied, setCopied] = React.useState(false)
  const [speakState, setSpeakState] = React.useState<SpeakState>("idle")

  /** Object URL del audio ya generado para ESTE mensaje. Null = todavía no se pidió. */
  const audioUrlRef = React.useRef<string | null>(null)
  const audioRef = React.useRef<HTMLAudioElement | null>(null)
  /** Evita setState después de desmontar (el fetch del audio dura segundos). */
  const aliveRef = React.useRef(true)

  React.useEffect(() => {
    aliveRef.current = true
    return () => {
      aliveRef.current = false
      // Si el user navega o el componente se desmonta mid-speech, cortar.
      audioRef.current?.pause()
      if (audioUrlRef.current) {
        URL.revokeObjectURL(audioUrlRef.current)
        audioUrlRef.current = null
      }
      if (typeof window !== "undefined" && window.speechSynthesis) {
        window.speechSynthesis.cancel()
      }
    }
  }, [])

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch {
      // ignore — algunos browsers requieren https; en localhost suele andar
    }
  }

  /**
   * Voz del navegador. Es el camino único de la caja y el fallback del panel
   * (D4): no se borra ni se deja morir, es la red de contención de todo lo que
   * puede fallar del lado del TTS pago.
   */
  function speakNative() {
    if (typeof window === "undefined" || !window.speechSynthesis) {
      if (aliveRef.current) setSpeakState("idle")
      return
    }
    const synth = window.speechSynthesis
    synth.cancel()

    const u = new SpeechSynthesisUtterance(text)
    u.lang = "es-ES"
    u.rate = 1
    u.onend = () => {
      if (aliveRef.current) setSpeakState("idle")
    }
    u.onerror = () => {
      if (aliveRef.current) setSpeakState("idle")
    }
    stopCurrentPlayback = () => {
      synth.cancel()
      if (aliveRef.current) setSpeakState("idle")
    }
    synth.speak(u)
    setSpeakState("playing")
  }

  /** Reproduce un object URL ya generado. Lanza si el browser rechaza el play. */
  async function playAudioUrl(url: string) {
    const audio = audioRef.current ?? new Audio()
    audioRef.current = audio
    audio.src = url
    audio.onended = () => {
      if (aliveRef.current) setSpeakState("idle")
    }
    audio.onerror = () => {
      if (aliveRef.current) setSpeakState("idle")
    }
    stopCurrentPlayback = () => {
      audio.pause()
      audio.currentTime = 0
      if (aliveRef.current) setSpeakState("idle")
    }
    await audio.play()
    if (aliveRef.current) setSpeakState("playing")
  }

  async function handleToggleSpeak() {
    // Mientras genera, el botón no hace nada: cancelar a mitad dejaría el
    // débito hecho y el audio tirado.
    if (speakState === "loading") return

    if (speakState === "playing") {
      stopAnyPlayback()
      setSpeakState("idle")
      return
    }

    // Cortar lo que esté sonando de otro mensaje antes de arrancar.
    stopAnyPlayback()

    if (!remoteVoice) {
      speakNative()
      return
    }

    // Re-escucha: el audio de este mensaje ya está en memoria. Ni pedido ni
    // débito nuevos (D6).
    if (audioUrlRef.current) {
      try {
        await playAudioUrl(audioUrlRef.current)
        return
      } catch {
        // El blob quedó inservible (revocado, o el browser rechazó el play):
        // se descarta y sigue por el camino normal, que lo vuelve a pedir.
        audioUrlRef.current = null
      }
    }

    setSpeakState("loading")
    try {
      // Por el api-client del panel y no por `fetch` crudo: es el único que
      // adjunta el Bearer del realm (`realm-token-separation.test.ts` lo
      // verifica en CI).
      const blob = await api.postBlob("/agent/tts", { text })
      const url = URL.createObjectURL(blob)
      if (!aliveRef.current) {
        // Se desmontó mientras generaba: no dejar el object URL colgado.
        URL.revokeObjectURL(url)
        return
      }
      audioUrlRef.current = url
      await playAudioUrl(url)
    } catch (e) {
      const status = e instanceof ApiError ? e.status : 0
      toast(
        status === 402
          ? "Sin créditos — se usa la voz del navegador"
          : "No se pudo generar la voz — se usa la voz del navegador",
      )
      speakNative()
    }
  }

  const isBusy = speakState === "loading"
  const isPlaying = speakState === "playing"

  return (
    <div className="mt-1 flex items-center gap-0.5">
      <Tooltip>
        <TooltipTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            onClick={handleCopy}
            className="size-7 rounded-md text-muted-foreground/70 hover:text-foreground"
            aria-label="Copiar mensaje"
          >
            {copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
          </Button>
        </TooltipTrigger>
        <TooltipContent>{copied ? "¡Copiado!" : "Copiar"}</TooltipContent>
      </Tooltip>
      {showSpeak && (
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              onClick={handleToggleSpeak}
              disabled={isBusy}
              className="size-7 rounded-md text-muted-foreground/70 hover:text-foreground"
              aria-label={isPlaying ? "Detener lectura" : "Leer en voz alta"}
            >
              {isBusy ? (
                <Loader2 className="size-3.5 animate-spin" />
              ) : isPlaying ? (
                <Square className="size-3.5" />
              ) : (
                <Volume2 className="size-3.5" />
              )}
            </Button>
          </TooltipTrigger>
          <TooltipContent>{isBusy ? "Generando" : isPlaying ? "Detener" : "Leer"}</TooltipContent>
        </Tooltip>
      )}
    </div>
  )
}
