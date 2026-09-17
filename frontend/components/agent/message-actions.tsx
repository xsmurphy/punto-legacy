"use client"

import * as React from "react"
import { Copy, Check, Volume2, Square, Loader2 } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { api, ApiError } from "@/lib/api-client"
import { mapWithConcurrency, splitTextForTts, textForSpeech } from "@/lib/ai/tts-chunk"

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
 * EL TEXTO SE PIDE TROCEADO (`lib/ai/tts-chunk.ts`): la generación del
 * proveedor escala con el largo y no streamea (una respuesta larga tardaba
 * ~48s en empezar a sonar). Los pedazos se piden en paralelo —con tope— y se
 * reproducen en secuencia: el primero es corto y define la espera; los demás
 * llegan mientras suena. El gate y el débito corren por pedazo en el BFF, y la
 * suma de caracteres es la del mensaje entero — el costo no cambia.
 *
 * El audio se retiene por MENSAJE en un ref (D6): volver a tocar play sobre el
 * mismo mensaje reproduce los blobs que ya están en memoria, sin pedirlos de
 * nuevo y sin debitar de nuevo. Los object URLs se revocan al desmontar.
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

  /**
   * Object URLs del audio ya generado para ESTE mensaje, uno por pedazo y en
   * orden de lectura. Null = todavía no se pidió; solo se guarda la SECUENCIA
   * COMPLETA (una re-escucha con huecos leería el mensaje salteado).
   */
  const audioUrlsRef = React.useRef<string[] | null>(null)
  /** Todo object URL creado, completo o no — lo que hay que revocar al desmontar. */
  const createdUrlsRef = React.useRef<string[]>([])
  const audioRef = React.useRef<HTMLAudioElement | null>(null)
  /** Evita setState después de desmontar (el fetch del audio dura segundos). */
  const aliveRef = React.useRef(true)

  React.useEffect(() => {
    aliveRef.current = true
    return () => {
      aliveRef.current = false
      // Si el user navega o el componente se desmonta mid-speech, cortar.
      audioRef.current?.pause()
      for (const url of createdUrlsRef.current) URL.revokeObjectURL(url)
      createdUrlsRef.current = []
      audioUrlsRef.current = null
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

    // La voz nativa lee el markdown literal igual que el TTS pago — misma limpieza.
    const u = new SpeechSynthesisUtterance(textForSpeech(text))
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

  /**
   * Reproduce un object URL HASTA EL FINAL: resuelve cuando terminó, lanza si
   * el browser rechaza el play, y devuelve `false` si lo frenaron. Es el
   * eslabón de la secuencia — quien la recorre decide si sigue con el próximo.
   */
  function playAudioUrlToEnd(url: string): Promise<boolean> {
    return new Promise<boolean>((resolve, reject) => {
      const audio = audioRef.current ?? new Audio()
      audioRef.current = audio
      audio.src = url
      audio.onended = () => resolve(true)
      audio.onerror = () => resolve(true) // un pedazo ilegible no traba el resto
      stopCurrentPlayback = () => {
        audio.pause()
        audio.currentTime = 0
        if (aliveRef.current) setSpeakState("idle")
        resolve(false)
      }
      audio.play().then(
        () => {
          if (aliveRef.current) setSpeakState("playing")
        },
        (e) => reject(e),
      )
    })
  }

  /**
   * Recorre la secuencia en orden; corta limpio si la frenan o se desmonta.
   * `progress.played` queda con cuántos pedazos SONARON aunque después algo
   * lance: el que maneja el error necesita saber si el usuario ya escuchó
   * parte del mensaje (releerlo entero con la voz nativa sería peor que
   * cortar).
   */
  async function playSequence(
    urls: (string | Promise<string>)[],
    progress: { played: number } = { played: 0 },
  ) {
    for (const pending of urls) {
      const url = await pending
      if (!aliveRef.current) return
      const finished = await playAudioUrlToEnd(url)
      if (!finished || !aliveRef.current) return
      progress.played++
    }
    if (aliveRef.current) setSpeakState("idle")
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

    // Re-escucha: la secuencia completa de este mensaje ya está en memoria.
    // Ni pedidos ni débitos nuevos (D6).
    if (audioUrlsRef.current) {
      try {
        await playSequence(audioUrlsRef.current)
        return
      } catch {
        // Los blobs quedaron inservibles (revocados, o el browser rechazó el
        // play): se descartan y sigue el camino normal, que los vuelve a pedir.
        audioUrlsRef.current = null
      }
    }

    setSpeakState("loading")
    try {
      // El texto va TROCEADO y en paralelo (ver el docblock de arriba): la
      // espera del usuario es la del primer pedazo, no la del mensaje entero.
      // Por el api-client del panel y no por `fetch` crudo: es el único que
      // adjunta el Bearer del realm (`realm-token-separation.test.ts` lo
      // verifica en CI).
      // Markdown → texto hablable ANTES de trocear: "**Lote**" se leía como
      // "asterisco asterisco lote asterisco asterisco".
      const chunks = splitTextForTts(textForSpeech(text))
      if (chunks.length === 0) return
      const urlPromises = mapWithConcurrency(chunks, 3, async (chunk) => {
        const blob = await api.postBlob("/agent/tts", { text: chunk })
        const url = URL.createObjectURL(blob)
        createdUrlsRef.current.push(url)
        return url
      })
      // Marca de "manejada" para cada promesa: mientras suena el pedazo N, un
      // fallo del N+2 sería un unhandled rejection aunque el for de la
      // secuencia lo vaya a ver después.
      for (const p of urlPromises) p.catch(() => {})

      const progress = { played: 0 }
      try {
        await playSequence(urlPromises, progress)
      } catch (e) {
        // Con parte del mensaje YA ESCUCHADA, caer a la voz nativa releería
        // todo desde el principio: se corta con aviso y listo. El fallback
        // nativo queda para cuando no sonó nada (el catch de afuera).
        if (progress.played > 0) {
          toast("No se pudo completar la lectura")
          if (aliveRef.current) setSpeakState("idle")
          return
        }
        throw e
      }

      // La secuencia se cachea solo COMPLETA — con todas las promesas ya
      // resueltas. Si la frenaron a mitad, las que faltaban pueden seguir en
      // vuelo: se resuelven igual (quedan registradas para revocar) y la
      // próxima escucha las vuelve a pedir.
      const settled = await Promise.allSettled(urlPromises)
      if (settled.every((s) => s.status === "fulfilled")) {
        audioUrlsRef.current = settled.map((s) => (s as PromiseFulfilledResult<string>).value)
      }
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
