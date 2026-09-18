"use client"

/**
 * Registro del rostro en el quiosco (RRHH F2, context/83 D5).
 *
 * ── Esta pantalla NO elige a quién registra ────────────────────────────────
 *
 * El nombre llega del servidor: alguien con permiso sobre el legajo lo habilitó
 * desde el panel, para esa persona y por unos minutos. El quiosco solo captura.
 *
 * Es toda la seguridad de la función: si desde acá se pudiera elegir a quién
 * registrar, quien sabe un código podría dejar SU cara bajo el nombre de otro, y
 * a partir de ahí la cara lo confirmaría todos los días. Por eso no hay un
 * buscador de empleados en esta pantalla y no debe haberlo nunca.
 *
 * ── Y no es fail-open, al revés que marcar ────────────────────────────────
 *
 * Marcar entra siempre, pase lo que pase: alguien trabajó y tiene que poder
 * registrarlo. Registrar un rostro no tiene esa urgencia —el código sigue
 * funcionando— y en cambio un registro malo reconoce mal todos los días hasta
 * que alguien se da cuenta. Acá, si algo sale raro, se corta y se vuelve a
 * intentar.
 */

import * as React from "react"
import { Check, ScanFace, X } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { captureJpeg } from "@/lib/pos/attendance-photo"
import type { FaceReading } from "@/lib/pos/face/face-engine"
import type { PendingEnrollment } from "@/hooks/use-attendance-faces"

/**
 * Las tomas y qué se le pide a la persona en cada una.
 *
 * Cuatro y no una: una sola toma congela un instante —una sombra, la cabeza
 * girada, los ojos a medio cerrar— y después el reconocimiento falla justo
 * cuando la persona se para distinto. Con varias, el promedio tolera lo que
 * cambia todos los días.
 *
 * Las indicaciones piden ángulos apenas distintos a propósito. El servidor
 * verifica que las tomas se parezcan ENTRE SÍ, así que tampoco pueden ser muy
 * distintas: son variaciones de la misma pose, no poses diferentes.
 */
/**
 * Cuatro tomas, todas de frente y seguidas (owner 2026-09-18: sin coreografía
 * de poses ni prueba de vida — "solo registrar la cara para matchear"). Las
 * tomas espaciadas unos cientos de ms capturan la variación natural (micro
 * movimientos, luz) que hace robusto el promedio; el servidor sigue
 * verificando que se parezcan entre sí.
 */
const STEPS = ["Mirá a la cámara", "Quedate así", "Quedate así", "Listo"] as const

/** Espaciado entre tomas: variación natural sin hacer esperar a nadie. */
const STEP_DELAY_MS = 400

/** Cuántos intentos se hacen por toma antes de rendirse con esa. */
const MAX_TRIES_PER_STEP = 12

export interface FaceEnrollmentProps {
  enrollment: PendingEnrollment
  videoRef: React.RefObject<HTMLVideoElement | null>
  /** Lee un cuadro de la cámara. Lo provee `useFaceRecognition`. */
  readOnce: () => Promise<FaceReading | null>
  /** ¿El modelo está listo? Sin él no hay nada que capturar. */
  ready: boolean
  submitting: boolean
  onSubmit: (samples: number[][], photo: Blob | null) => Promise<void>
  onCancel: () => void
}

type Phase = "idle" | "capturing" | "done"

export function FaceEnrollment({
  enrollment,
  videoRef,
  readOnce,
  ready,
  submitting,
  onSubmit,
  onCancel,
}: FaceEnrollmentProps) {
  const [phase, setPhase] = React.useState<Phase>("idle")
  const [step, setStep] = React.useState(0)

  // Corta el bucle si la pantalla se desmonta a mitad de la captura: seguir
  // leyendo la cámara de un componente que ya no está es trabajo para nadie.
  const aliveRef = React.useRef(true)
  React.useEffect(() => {
    aliveRef.current = true
    return () => {
      aliveRef.current = false
    }
  }, [])

  async function run() {
    setPhase("capturing")
    setStep(0)
    const samples: number[][] = []

    for (let i = 0; i < STEPS.length; i++) {
      if (!aliveRef.current) return
      setStep(i)

      // Se espera ANTES de capturar, incluso en la primera: la persona acaba de
      // leer la indicación y necesita un momento para acomodarse.
      await sleep(STEP_DELAY_MS)

      let reading: FaceReading | null = null
      for (let t = 0; t < MAX_TRIES_PER_STEP && aliveRef.current; t++) {
        reading = await readOnce()
        if (reading) break
        await sleep(200)
      }
      if (!aliveRef.current) return

      if (!reading) {
        setPhase("idle")
        toast.error("No se ve la cara", {
          description: "Acercate un poco y que haya luz de frente.",
        })
        return
      }
      samples.push(reading.embedding)
    }

    // La foto del último cuadro: es lo que el dueño va a ver en el legajo
    // cuando revise una marcación que no reconoció a nadie.
    const photo = await captureJpeg(videoRef.current)
    if (!aliveRef.current) return

    setPhase("done")
    await onSubmit(samples, photo)
  }

  const busy = phase === "capturing" || submitting

  return (
    <div className="flex w-full max-w-sm flex-col items-center gap-5">
      <div className="text-center">
        <p className="text-sm text-muted-foreground">Registro de rostro</p>
        <p className="text-2xl font-semibold">{enrollment.name}</p>
        <p className="text-sm text-muted-foreground">
          {enrollment.jobTitle || "Sin puesto cargado"}
        </p>
      </div>

      {/* Altura fija: la indicación cambia de texto pero no mueve los botones.
          Quien captura mira la cámara, no la pantalla — si el botón se corre,
          lo busca. */}
      <p
        className={cn(
          "flex h-12 items-center text-center text-base",
          busy ? "font-semibold text-foreground" : "text-muted-foreground",
        )}
      >
        {!ready
          ? "Preparando la cámara"
          : phase === "capturing"
            ? STEPS[step]
            : phase === "done"
              ? "Guardando"
              : "Cuando estés listo, tocá Empezar"}
      </p>

      {/* Una marca por toma. Es lo único que dice cuánto falta, y no hace falta
          más: son cuatro y duran seis segundos. */}
      <div className="flex items-center gap-3">
        {STEPS.map((_, i) => (
          <span
            key={i}
            className={cn(
              "block size-3 rounded-full border-2 transition-colors",
              phase !== "idle" && i <= step
                ? "border-foreground bg-foreground"
                : "border-foreground/40 bg-transparent",
            )}
          />
        ))}
      </div>

      <div className="grid w-full grid-cols-2 gap-3">
        <Button
          size="lg"
          variant="outline"
          className="h-16 flex-col gap-1"
          disabled={busy}
          onClick={onCancel}
        >
          <X className="size-5" />
          Ahora no
        </Button>
        <Button
          size="lg"
          className="h-16 flex-col gap-1"
          disabled={busy || !ready}
          onClick={() => void run()}
        >
          {phase === "done" ? <Check className="size-5" /> : <ScanFace className="size-5" />}
          {phase === "idle" ? "Empezar" : "Registrando"}
        </Button>
      </div>
    </div>
  )
}

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}
