"use client"

import * as React from "react"
import { Copy, Check, Volume2, Square } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"

/**
 * Acciones inline debajo de cada mensaje del assistant: copiar al clipboard +
 * leer en voz alta vía Web Speech API del navegador (sin costo de tokens).
 *
 * El estado "playing" se computa contra el SpeechSynthesisUtterance actual
 * — `speechSynthesis.speaking` es global (compartido entre mensajes), así que
 * cuando el user toca play en otro mensaje, el primero refleja "stopped"
 * automáticamente al perder el utterance activo.
 */
export function MessageActions({
  text,
  showSpeak = true,
}: {
  text: string
  /** Mensajes del user solo muestran "Copiar" — leerse a sí mismo no aporta. */
  showSpeak?: boolean
}) {
  const [copied, setCopied] = React.useState(false)
  const [playing, setPlaying] = React.useState(false)
  const utteranceRef = React.useRef<SpeechSynthesisUtterance | null>(null)

  // Si el user navega o el componente se desmonta mid-speech, cortar.
  React.useEffect(() => {
    return () => {
      if (utteranceRef.current && typeof window !== "undefined" && window.speechSynthesis) {
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

  function handleToggleSpeak() {
    if (typeof window === "undefined" || !window.speechSynthesis) return
    const synth = window.speechSynthesis

    if (playing) {
      synth.cancel()
      setPlaying(false)
      return
    }

    // Si hay otra reproducción en curso (de otro mensaje), cortarla primero.
    synth.cancel()

    const u = new SpeechSynthesisUtterance(text)
    u.lang = "es-ES"
    u.rate = 1
    u.onend = () => setPlaying(false)
    u.onerror = () => setPlaying(false)
    utteranceRef.current = u
    synth.speak(u)
    setPlaying(true)
  }

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
              className="size-7 rounded-md text-muted-foreground/70 hover:text-foreground"
              aria-label={playing ? "Detener lectura" : "Leer en voz alta"}
            >
              {playing ? <Square className="size-3.5" /> : <Volume2 className="size-3.5" />}
            </Button>
          </TooltipTrigger>
          <TooltipContent>{playing ? "Detener" : "Leer"}</TooltipContent>
        </Tooltip>
      )}
    </div>
  )
}
