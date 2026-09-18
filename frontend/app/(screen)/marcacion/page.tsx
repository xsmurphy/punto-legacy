"use client"

/**
 * El RELOJ DE MARCACIÓN (context/83 §9.2 y §9.5).
 *
 * La persona se para frente a la tablet colgada en la entrada. La cámara la
 * reconoce, el sistema deduce solo si entra o sale, lo registra y la saluda por
 * su nombre. No hay nada que tocar.
 *
 * ── La cámara ES la pantalla (§9.5) ────────────────────────────────────────
 *
 * Hasta el rediseño esto era un teclado numérico con un circulito de cámara al
 * costado: la pantalla pedía un código y ofrecía el rostro como adorno. El owner
 * lo dio vuelta. Ahora el video ocupa todo, el estado se pinta ENCIMA (nunca al
 * lado, nunca empujando) y el código numérico es una acción secundaria para
 * quien no tiene el rostro registrado.
 *
 * ── El código puede estar apagado (context/83) ────────────────────────────
 *
 * El comercio decide en Ajustes si se puede marcar con código. Apagado, esta
 * pantalla no ofrece "Usar código" y el teclado físico deja de abrirlo. Lo que
 * IMPIDE marcar por código no es esto —una pantalla es un cliente y un cliente
 * se edita— sino el servidor, que rechaza la marcación en `mark()`. Acá solo se
 * evita ofrecer lo que del otro lado va a ser rechazado.
 *
 * El flag baja en el contexto del device y vuelve a bajar solo cuando cambian
 * los Ajustes (`usePairedScreen` repregunta con la entidad 'setting'). Ausente
 * —contexto cacheado de una versión anterior— significa el default: disponible.
 *
 * ── Automático de punta a punta, y eso prohíbe cosas ──────────────────────
 *
 * Nadie elige entrada o salida: se infiere de la última marcación conocida
 * (`attendance-kind.ts` + `session-marks.ts`). Nadie confirma nada: el
 * reconocimiento registra. Y no se le dice a la persona que se la reconoció —se
 * la saluda, que es lo que un humano haría en la puerta.
 *
 * Una inferencia equivocada (quedó una salida sin marcar ayer) se corrige en la
 * revisión del panel. Preguntarle al que llega sería trasladarle a él un
 * problema que no puede resolver parado en la puerta con el abrigo puesto.
 *
 * ── La misma persona no marca dos veces ───────────────────────────────────
 *
 * Quien acaba de fichar sigue parada ahí y la cámara la vuelve a ver. Dentro de
 * la ventana de repetición no se registra nada y se le repite el saludo — el
 * porqué completo, en `lib/clock/session-marks.ts`.
 *
 * ── Sin sesión de operador, a propósito ───────────────────────────────────
 *
 * El reloj no le pregunta nada a nadie antes de dejar marcar. Es del COMERCIO y
 * atiende a gente que en su mayoría no opera el sistema (cocina, limpieza):
 * pedir el PIN de un operador para que un cocinero fiche sería hacer que otra
 * persona lo habilite a trabajar.
 *
 * ── Offline-nativo y fail-open (D4, D7) ───────────────────────────────────
 *
 * El código se valida contra los hashes que bajaron con el roster, la marcación
 * y su foto se encolan y suben solas. La foto se intenta SIEMPRE y no bloquea
 * nunca: sin cámara, con el permiso denegado o con la captura fallada, la
 * marcación entra igual y queda flageada para que el dueño la revise.
 *
 * ── Posiciones estables (§10 de context/14) ───────────────────────────────
 *
 * Los tres controles del reloj —código, pantalla completa y, cuando el panel lo
 * habilita, el registro de rostro— viven siempre en las mismas coordenadas. El
 * bloque central cambia de contenido (hora, saludo, aviso) pero no de lugar, y
 * el anillo de estado se pinta sobre el borde que ya existe.
 *
 * ── También es un celular colgado en la entrada ───────────────────────────
 *
 * El reloj se parea en cualquier aparato, y muchos comercios cuelgan un
 * teléfono viejo. Eso cambia tres cosas, todas acá:
 *
 *   - **Alto real, no `100vh`.** En un navegador móvil `h-screen` cuenta la
 *     barra de direcciones que se esconde al scrollear, así que el pie queda
 *     debajo del borde. `h-dvh` es el alto que de verdad se ve.
 *   - **La pantalla no se puede dormir.** Con el bloqueo del sistema, quien
 *     llega encuentra un rectángulo negro y tiene que despertar el aparato
 *     antes de marcar (`hooks/use-wake-lock.ts`).
 *   - **Los tamaños de texto bajan en pantalla angosta.** Un nombre largo a
 *     `text-6xl` en 390 px se desarma. Los saltos son por ancho de viewport y
 *     NO por estado, así que ningún elemento se mueve según lo que esté
 *     pasando (§10 sigue en pie).
 *
 * El video es `object-cover`: en retrato recorta los costados del cuadro de la
 * cámara en vez de deformarlo o dejar bandas.
 *
 * ── Suena (context/83 §9.5: la persona no mira la pantalla) ───────────────
 *
 * Quien marca pasa caminando. El saludo en pantalla no alcanza, así que hay dos
 * avisos —"listo" y "no te reconozco"— en `lib/clock/sounds.ts`. El audio
 * arranca MUDO por la política de autoplay de los navegadores y se desbloquea
 * con el primer gesto que reciba este aparato; si nunca recibe ninguno, no
 * suena nada y no pasa nada más.
 */

import * as React from "react"
import { CameraOff, KeyRound, ScanFace, UserCheck } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { ScreenFullscreenToggle } from "@/components/screens/screen-fullscreen-toggle"
import { FaceEnrollment } from "@/components/pos/face-enrollment"
import { cn } from "@/lib/utils"

import { DeviceNotConnected } from "@/components/layout/device-not-connected"
import { usePairedScreen } from "@/hooks/use-paired-screen"
import { usePendingOpsSync } from "@/hooks/use-pending-ops-sync"
import { useClockRoster } from "@/hooks/use-clock-roster"
import { useCameraStream } from "@/hooks/use-camera-stream"
import { useWakeLock } from "@/hooks/use-wake-lock"
import { closeToneAudio } from "@/lib/audio/tones"
import {
  canPlayUnknown,
  playClockSuccess,
  playClockUnknown,
  unlockClockSound,
} from "@/lib/clock/sounds"
import { captureJpeg, type NoPhotoReason } from "@/lib/pos/attendance-photo"
import {
  lastKnownMark,
  proposedKind,
  type AttendanceKind,
  type QueuedMark,
} from "@/lib/pos/attendance-kind"
import { findRecentMark, rememberMark, type SessionMark } from "@/lib/clock/session-marks"
import { peekOpsByStream } from "@/lib/pos/pending-ops"
import type { AttendanceMarkPayload } from "@/lib/pos/local-register-state"
import { useSubmitAttendanceMark } from "@/hooks/use-attendance-mark"
import { useQueryClient } from "@tanstack/react-query"

import { ATTENDANCE_FACES_KEY, useAttendanceFaces, useEnrollFace } from "@/hooks/use-attendance-faces"
import { resolveFaceOutcome, useFaceRecognition } from "@/hooks/use-face-recognition"
import type { ClockEmployee } from "@/lib/types/clock"

import { CodeDialog } from "./code-dialog"

/**
 * Cuánto queda el saludo en pantalla.
 *
 * Lo suficiente para leerlo caminando, y no tanto como para que el que viene
 * atrás tenga que esperar a que se vaya el nombre del anterior.
 */
const GREETING_MS = 4000

/** Lo que muestra el bloque central. */
type Phase =
  /** Esperando a la próxima persona. */
  | { kind: "idle" }
  /** Sacando la foto y registrando. */
  | { kind: "marking" }
  /** Saludo. El mismo para la primera marcación y para la repetida. */
  | { kind: "greeting"; name: string; type: AttendanceKind }
  /** No se pudo registrar y la persona tiene que volver a intentar. */
  | { kind: "error" }

export default function MarcacionPage() {
  // El contexto del device: sucursal, nombre del comercio, revocación y
  // heartbeat. `offlineFirst` porque este aparato tiene que seguir andando sin
  // red — ver el docblock del hook.
  //
  // No se suscribe a ningún canal propio: le llegan la revocación (que el
  // hook maneja) y la invalidación del tenant, de la que solo le importa la
  // entidad `employee` (rostros y ventana de registro).
  const qc = useQueryClient()
  const { pairState, ctx } = usePairedScreen({
    module: "clock",
    offlineFirst: true,
    channels: () => [],
    onEvent: () => {},
    // El alta/borrado de un rostro y la apertura del registro publican entidad
    // `employee`: al llegar, se repregunta por rostros y ventana. Es lo que
    // hace que "Registrar rostro" en el panel aparezca acá en segundos.
    onInvalidate: (entity) => {
      if (entity === "employee") void qc.invalidateQueries({ queryKey: ATTENDANCE_FACES_KEY })
    },
  })

  // La cola de operaciones del device. En un reloj solo puede tener
  // marcaciones: no hay ninguna otra pantalla que encole algo acá.
  usePendingOpsSync()

  // La pantalla no se duerme mientras esto esté abierto. Sin soporte del
  // navegador no hace nada — ver el hook.
  useWakeLock()

  // ── El audio, que nace mudo ───────────────────────────────────────────────
  //
  // El primer gesto que reciba el aparato desbloquea el `AudioContext`: puede
  // ser el toque del pareo, abrir el teclado del código, o cualquier toque en
  // la pantalla. Un reloj recién colgado que nadie tocó todavía no suena, y eso
  // es aceptable — lo que no puede es tirar un error por intentarlo.
  React.useEffect(() => {
    const onGesture = () => {
      void unlockClockSound()
    }
    window.addEventListener("pointerdown", onGesture, { once: true, capture: true })
    window.addEventListener("keydown", onGesture, { once: true, capture: true })
    return () => {
      window.removeEventListener("pointerdown", onGesture, true)
      window.removeEventListener("keydown", onGesture, true)
      // El contexto se cierra al salir: esta pantalla queda abierta días y un
      // `AudioContext` colgado por cada remount agota el límite del navegador.
      closeToneAudio()
    }
  }, [])

  /**
   * Cuándo sonó por última vez el aviso de "no te reconozco".
   *
   * En una ref y no en estado: no redibuja nada, y el bucle de reconocimiento
   * lo consulta varias veces por segundo.
   */
  const lastUnknownSoundRef = React.useRef<number | null>(null)

  const outletId = ctx?.outletId ?? ""

  // ¿Este comercio deja marcar con código? Se resuelve ACÁ arriba y no junto al
  // resto de los derivados de abajo porque un efecto lo necesita (el atajo del
  // teclado físico), y los efectos viven antes del corte por `pairState`.
  //
  // Ausente = el default del comercio, que desde 2026-09-18 es SOLO ROSTRO: el
  // código lo prende quien lo necesita, en Ajustes. Un contexto cacheado por
  // una versión anterior tampoco trae la clave, y ahí "ausente" cae del mismo
  // lado que el default nuevo — el servidor rechaza igual una marcación con
  // código que este reloj no debería haber ofrecido.
  const allowCode = ctx?.attendanceAllowPin === true
  const roster = useClockRoster(outletId, pairState === "ready")
  const employees = React.useMemo(() => roster.data?.employees ?? [], [roster.data])
  const submit = useSubmitAttendanceMark()

  // La cámara vive en su propio hook porque este aparato la deja abierta todo
  // el día y eso rompe de maneras que una pantalla no puede arreglar sola (el
  // docblock de `use-camera-stream.ts` tiene los tres casos).
  const { state: cameraState, attach: attachCamera, videoRef } = useCameraStream()
  const cameraOk = cameraState?.ok === true

  const [phase, setPhase] = React.useState<Phase>({ kind: "idle" })
  const [codeOpen, setCodeOpen] = React.useState(false)
  const [codeSeed, setCodeSeed] = React.useState<string | undefined>(undefined)

  /** Lo que este reloj marcó en esta sesión. Ver `lib/clock/session-marks.ts`. */
  const [sessionMarks, setSessionMarks] = React.useState<SessionMark[]>([])

  /**
   * Marcaciones que este dispositivo ya hizo y todavía no envió. Se leen al
   * montar y después de cada marcación: es lo que permite inferir bien el tipo
   * sin red (ver `lib/pos/attendance-kind.ts`).
   */
  const [queued, setQueued] = React.useState<QueuedMark[]>([])
  const refreshQueued = React.useCallback(async () => {
    try {
      const rows = await peekOpsByStream("attendance")
      setQueued(
        rows.map((r) => {
          const p = r.payload as AttendanceMarkPayload
          return { employeeId: p.employeeId, kind: p.kind, markedAt: p.markedAt }
        }),
      )
    } catch {
      // Sin la cola local la inferencia sale del dato del servidor, que es peor
      // pero sirve. No es motivo para romper la pantalla.
    }
  }, [])
  React.useEffect(() => {
    void refreshQueued()
  }, [refreshQueued])

  // ── La hora ───────────────────────────────────────────────────────────────
  //
  // Del aparato, con el formato del aparato: es el mismo reloj con el que se
  // sella cada marcación, y está colgado en el local. Arranca en `null` y se
  // resuelve en un efecto — pintar una hora en el render del servidor es un
  // mismatch de hidratación garantizado.
  const [now, setNow] = React.useState<Date | null>(null)
  React.useEffect(() => {
    setNow(new Date())
    const t = setInterval(() => setNow(new Date()), 1000)
    return () => clearInterval(t)
  }, [])

  // ── Reconocimiento facial (F2) ────────────────────────────────────────────
  //
  // Los rostros bajan por su propio endpoint, aparte del roster: son biometría y
  // cambian con otra frecuencia (ver `use-attendance-faces.ts`). Sin red salen
  // del caché local, así que reconocer sigue andando igual.
  // Solo se piden cuando hay cámara: sin ella no hay nada que comparar, y pedir
  // vectores faciales que nadie va a usar es exponer biometría sin motivo.
  const faces = useAttendanceFaces(outletId, cameraOk && pairState === "ready")
  const enrollFace = useEnrollFace(outletId)

  /**
   * Un registro que este reloj decidió no atender ahora.
   *
   * El reloj NO puede cancelar la habilitación —la abrió el panel y solo el
   * panel la cierra— así que "Ahora no" la aparta de ESTA pantalla y nada más.
   * Vence sola en unos minutos.
   */
  const [dismissedEnrollment, setDismissedEnrollment] = React.useState<string | null>(null)
  const openEnrollment = faces.data?.enrollment ?? null
  const pendingEnrollment =
    openEnrollment && openEnrollment.employeeId !== dismissedEnrollment ? openEnrollment : null

  /**
   * El reconocimiento avisa por una ref y no por el callback directo: el bucle
   * se arma una sola vez y `markNow` necesita leer el roster y las marcaciones
   * de esta sesión, que cambian todo el tiempo.
   */
  const identifyRef = React.useRef<(employeeId: string) => void>(() => {})

  const face = useFaceRecognition({
    videoRef,
    candidates: faces.data?.faces ?? [],
    // Sin cámara no hay nada que mirar, y sin nadie registrado tampoco: así el
    // comercio que no usa el rostro no baja los 8 MB del modelo.
    //
    // La excepción es el REGISTRO: el primero de un comercio ocurre justamente
    // cuando la lista está vacía, y sin esta condición el modelo nunca cargaría
    // y el botón de capturar quedaría deshabilitado para siempre.
    enabled: cameraOk && ((faces.data?.faces.length ?? 0) > 0 || pendingEnrollment !== null),
    // El bucle se detiene mientras se registra una marcación, mientras dura el
    // saludo, con el teclado abierto y durante el registro de un rostro: en
    // todos esos momentos la pantalla ya está ocupada con una persona.
    paused: phase.kind !== "idle" || codeOpen || pendingEnrollment !== null,
    onIdentified: (employeeId) => identifyRef.current(employeeId),
    // Hay alguien parado y no es nadie de los registrados. El bucle avisa en
    // cada vuelta mientras siga ahí, así que el freno de los 10 s va acá: sin
    // él, el reloj le sonaría sin parar a la persona que busca su código.
    onUnmatched: () => {
      const now = Date.now()
      if (!canPlayUnknown(lastUnknownSoundRef.current, now)) return
      lastUnknownSoundRef.current = now
      playClockUnknown()
    },
  })

  // ── Registrar una marcación ───────────────────────────────────────────────
  //
  // Es el ÚNICO camino: lo llaman el reconocimiento y el teclado por igual, y
  // ninguno de los dos elige el tipo ni pide confirmación.
  const busyRef = React.useRef(false)

  const markNow = React.useCallback(
    async (employee: ClockEmployee, via: "pin" | "face") => {
      if (busyRef.current) return
      busyRef.current = true

      try {
        // Marcó recién: no se registra de nuevo y se le repite el saludo que ya
        // se le dio. Ver `session-marks.ts`.
        const recent = findRecentMark(sessionMarks, employee.id, Date.now())
        if (recent) {
          setPhase({ kind: "greeting", name: recent.name, type: recent.kind })
          return
        }

        const markedAt = new Date().toISOString()
        // Lo que este aparato acaba de marcar pesa igual que lo que vino del
        // servidor: con red el roster no se refresca en el instante en que
        // alguien ficha.
        const last = lastKnownMark(
          employee.lastKind,
          employee.lastMarkedAt,
          [...queued, ...sessionMarks],
          employee.id,
        )
        const kind = proposedKind(last)

        setPhase({ kind: "marking" })

        // La foto es la evidencia de ESTA marcación, así que se saca ahora.
        const photo = cameraOk ? await captureJpeg(videoRef.current) : null
        const noPhotoReason: NoPhotoReason | null = photo
          ? null
          : cameraState?.ok === false
            ? cameraState.reason
            : "photo_failed"

        // Qué vio la cámara. Depende de quién terminó marcando: la misma cara
        // vista hace un rato significa cosas distintas según el código que se
        // haya tipeado. Ver `resolveFaceOutcome()`.
        const faceOutcome = resolveFaceOutcome(via, face.lastSighting.current, employee.id)

        await submit.mutateAsync({
          employeeId: employee.id,
          employeeName: employee.name,
          pinHash: via === "pin" ? employee.pinHash : null,
          kind,
          // La hora del DISPOSITIVO, en el momento de marcar. Nunca la del
          // envío: esta marcación puede sincronizar mañana.
          markedAt,
          method: via,
          photoPending: photo !== null,
          noPhotoReason,
          faceOutcome,
          photo,
          // El reloj no tiene caja, y la marcación no pertenece a ninguna: lo
          // que la ubica es la sucursal del device. La cola sabe no cercar por
          // caja una operación sin caja (ver `pending-ops-sync.ts`).
          registerId: "",
        })

        setSessionMarks((prev) =>
          rememberMark(prev, { employeeId: employee.id, name: employee.name, kind, markedAt }),
        )
        setPhase({ kind: "greeting", name: employee.name, type: kind })
        void refreshQueued()
      } catch {
        // El fallo se cuenta en la pantalla y no en un toast: quien marca está a
        // un metro de la tablet y no mira una esquina. El motivo no se muestra
        // (§Regla 8) — no hay nada que esa persona pueda hacer con él.
        setPhase({ kind: "error" })
      } finally {
        // Se olvida lo visto: el parpadeo de quien acaba de marcar no puede
        // acreditar a la persona que venga después.
        face.reset()
        busyRef.current = false
      }
    },
    [sessionMarks, queued, cameraOk, cameraState, videoRef, face, submit, refreshQueued],
  )

  React.useEffect(() => {
    identifyRef.current = (employeeId: string) => {
      // El rostro aporta el ID; todo lo demás sale del roster que ya bajó. Si
      // esa persona no está (se le borró el código, cambió de sucursal) no pasa
      // nada: el reconocimiento nunca habilita a alguien que la pantalla no
      // habilitaría igual por código.
      const found = employees.find((e) => e.id === employeeId)
      if (found) void markNow(found, "face")
    }
  })

  // El saludo (y el aviso de error) se van solos: nadie toca esta pantalla.
  React.useEffect(() => {
    if (phase.kind !== "greeting" && phase.kind !== "error") return
    const t = setTimeout(() => setPhase({ kind: "idle" }), GREETING_MS)
    return () => clearTimeout(t)
  }, [phase])

  // La campanita del saludo. También suena en el saludo REPETIDO (el de quien
  // marcó recién y sigue parado ahí): para esa persona su marca está puesta, y
  // el silencio la haría dudar. La ventana de repetición de `session-marks.ts`
  // ya impide que esto se convierta en un loop.
  React.useEffect(() => {
    if (phase.kind === "greeting") playClockSuccess()
  }, [phase])

  // Teclado físico: tipear un dígito abre el teclado en pantalla con ese dígito
  // puesto. Así el comercio que tiene la tablet con teclado sigue marcando sin
  // tocar nada, sin que el código ocupe la pantalla.
  React.useEffect(() => {
    // Con el código apagado el atajo también muere: sin esto, tipear un dígito
    // abriría un teclado que el botón ya no ofrece y cuya marcación el servidor
    // va a rechazar.
    if (!allowCode || codeOpen || pendingEnrollment) return
    const onKey = (e: KeyboardEvent) => {
      if (e.metaKey || e.ctrlKey || e.altKey) return
      if (!/^[0-9]$/.test(e.key)) return
      e.preventDefault()
      setCodeSeed(e.key)
      setCodeOpen(true)
    }
    window.addEventListener("keydown", onKey, true)
    return () => window.removeEventListener("keydown", onKey, true)
  }, [allowCode, codeOpen, pendingEnrollment])

  /** Guarda el rostro capturado y avisa. No se encola: ver `useEnrollFace`. */
  async function submitEnrollment(samples: number[][], photo: Blob | null) {
    if (!pendingEnrollment) return
    try {
      await enrollFace.mutateAsync({ employeeId: pendingEnrollment.employeeId, samples, photo })
      toast.success(`Listo — ${pendingEnrollment.name} ya se puede identificar con su rostro`)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo registrar el rostro")
    }
  }

  // Sin pareo no hay reloj. La pantalla de vinculación es la misma que usan el
  // KDS y las demás pantallas del comercio.
  if (pairState !== "ready") {
    return <DeviceNotConnected kind="clock" reason="unpaired" />
  }

  // ── Qué dice el bloque central cuando no hay nadie marcando ───────────────
  //
  // Los estados "no se puede marcar" NO son otra pantalla: se dicen sobre la
  // misma, encima del video. Cambiar de pantalla desmontaría el `<video>`, que
  // es justo el camino por el que la cámara se quedaba congelada.
  const nobodyLoaded = employees.length === 0
  // Con el código apagado, tener PIN no identifica a nadie en este reloj: la
  // condición de "se puede marcar" pasa a ser el rostro y nada más.
  const hasCode = allowCode && employees.some((e) => e.pinHash !== null)
  const hasFace = (faces.data?.faces.length ?? 0) > 0
  const nobodyIdentifiable = !nobodyLoaded && !hasCode && !hasFace
  const canMark = !nobodyLoaded && !nobodyIdentifiable

  /**
   * La línea de estado bajo el bloque central.
   *
   * Existe SIEMPRE con la misma altura, aunque esté vacía (§10 de context/14).
   */
  const hint = !canMark
    ? ""
    : phase.kind === "marking"
      ? "Un momento"
      : phase.kind !== "idle"
        ? ""
        : face.status === "loading"
            ? "Preparando la cámara"
            : cameraOk && face.status === "ready"
              ? "Mirá a la cámara"
              : hasCode
                ? "Marcá con tu código"
                : ""

  return (
    // `dark` acá y no en el layout: el grupo `(screen)` fuerza claro y cada
    // pantalla elige su tono (ver `lib/screens/theme.ts`). El reloj es siempre
    // oscuro y no tiene selector — es una cámara en vivo a pantalla completa, y
    // en claro el marco pelea con la imagen.
    // `h-dvh` y no `h-screen`: en un celular `100vh` incluye la barra de
    // direcciones que se esconde sola, y con ella el pie de la pantalla queda
    // fuera de la vista.
    <div className="dark relative h-dvh w-full overflow-hidden bg-background text-foreground">
      {/* El video, siempre montado y siempre del tamaño de la pantalla.
          `scale-x-[-1]`: espejado, como un espejo real — sin esto la persona se
          mueve para el lado contrario al acomodarse. */}
      <video
        ref={attachCamera}
        playsInline
        muted
        autoPlay
        className={cn(
          "absolute inset-0 size-full scale-x-[-1] object-cover",
          !cameraOk && "invisible",
        )}
      />

      {!cameraOk && (
        <div className="absolute inset-0 flex items-center justify-center">
          <CameraOff className="size-16 text-muted-foreground/40" />
        </div>
      )}

      {/* Velo: el video crudo no deja leer nada encima. Más oscuro arriba y
          abajo, que es donde vive el texto. */}
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 bg-gradient-to-b from-background/85 via-background/45 to-background/90"
      />

      {/* Anillo de estado. Existe siempre —transparente en reposo— y se pinta
          SOBRE el borde de la pantalla: nada se desplaza cuando aparece una
          cara (§10 de context/14). */}
      <div
        aria-hidden
        className={cn(
          "pointer-events-none absolute inset-0 ring-8 ring-inset transition-colors duration-300",
          phase.kind === "greeting"
            ? "ring-primary/70"
            : phase.kind === "error"
              ? "ring-destructive/70"
              : phase.kind === "marking"
                ? "ring-primary/40"
                : face.facePresent
                  ? "ring-foreground/25"
                  : "ring-transparent",
        )}
      />

      {/* ── Bloque central ──
          Va ANTES del encabezado y el pie en el DOM: ocupa toda la pantalla,
          así que si fuera después taparía sus controles. `pointer-events-none`
          por el mismo motivo — lo único que se toca acá adentro es el registro
          de rostro, que lo reactiva. */}
      <main className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-3 px-4 text-center sm:px-6">
        {pendingEnrollment ? (
          // Registro de un rostro. Lo habilitó el panel para ESTA persona: la
          // pantalla no elige a quién registra (ver `<FaceEnrollment>`).
          <div className="pointer-events-auto w-full max-w-sm rounded-lg border bg-card/90 p-6 backdrop-blur-sm">
            <FaceEnrollment
              enrollment={pendingEnrollment}
              videoRef={videoRef}
              readOnce={face.readOnce}
              ready={face.status === "ready"}
              submitting={enrollFace.isPending}
              onSubmit={submitEnrollment}
              onCancel={() => setDismissedEnrollment(pendingEnrollment.employeeId)}
            />
          </div>
        ) : phase.kind === "greeting" ? (
          <>
            <p className="text-2xl font-medium text-muted-foreground sm:text-3xl">
              {phase.type === "in" ? "Bienvenido" : "Adiós"}
            </p>
            <p className="text-4xl font-semibold tracking-tight text-balance sm:text-6xl">
              {phase.name}
            </p>
          </>
        ) : phase.kind === "error" ? (
          <p className="text-2xl font-semibold tracking-tight text-balance sm:text-4xl">
            No se pudo registrar. Probá otra vez.
          </p>
        ) : nobodyLoaded ? (
          <>
            <UserCheck className="size-10 text-muted-foreground" />
            <p className="text-2xl font-semibold tracking-tight sm:text-3xl">
              Todavía nadie puede marcar
            </p>
            <p className="text-base text-muted-foreground text-balance">
              Cargá al personal de esta sucursal para habilitar la marcación.
            </p>
          </>
        ) : nobodyIdentifiable ? (
          <>
            <ScanFace className="size-10 text-muted-foreground" />
            <p className="text-2xl font-semibold tracking-tight sm:text-3xl">
              Todavía nadie se puede identificar
            </p>
            <p className="text-base text-muted-foreground text-balance">
              {allowCode
                ? "Registrá el rostro o el código de cada persona."
                : "Registrá el rostro de cada persona."}
            </p>
          </>
        ) : (
          <>
            {/* La hora: lo único que ocupa el centro mientras no hay nadie. Que
                avance a la vista también dice que la pantalla está viva. */}
            <p className="text-7xl font-semibold tracking-tight tabular-nums sm:text-8xl">
              {now ? now.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" }) : ""}
            </p>
            <p className="text-base text-muted-foreground first-letter:uppercase">
              {now
                ? now.toLocaleDateString(undefined, {
                    weekday: "long",
                    day: "numeric",
                    month: "long",
                  })
                : ""}
            </p>
          </>
        )}

        {/* Altura fija aunque esté vacía: un renglón que aparece y desaparece
            movería todo el bloque según si hay una cara delante. */}
        <p className="flex h-6 items-center gap-2 text-base text-muted-foreground">{hint}</p>
      </main>

      {/* ── Encabezado ── */}
      {/* El `pt`/`pb` con las variables de área segura es por el celular
          colgado en la entrada: la app se instala con la barra de estado
          translúcida, así que sin esto el nombre del comercio queda debajo de
          la hora del sistema y el botón del código debajo de la barra de
          gestos. Las variables salen de `globals.css` § "Áreas seguras" — es la
          única fuente, y el guard `lib/pos/__tests__/safe-area.test.ts` lo
          verifica. El encabezado descuenta el eje superior y el pie el
          inferior: UNA vez cada uno, que es la regla — por eso el padding
          seguro no tiene variante `sm:`, solo la tienen los lados.

          `safe-area-x` es por el APAISADO: en un teléfono acostado el notch se
          come un lateral, y ahí es donde están el nombre del comercio y el
          botón del código. En retrato los insets laterales son 0, así que no
          cambia nada. */}
      <header className="safe-area-x absolute inset-x-0 top-0 flex items-start justify-between gap-4 px-4 pb-4 pt-[max(1rem,var(--safe-t))] sm:px-6 sm:pb-6">
        <div className="min-w-0">
          <p className="truncate text-lg font-semibold">{ctx?.companyName}</p>
          <p className="truncate text-sm text-muted-foreground">{ctx?.outletName}</p>
          {/* Lo único que se dice de la cámara, y solo cuando falla: quien
              instaló la tablet puede desbloquearla, y quien viene a marcar ya
              tiene el código abajo. */}
          {cameraState?.ok === false && (
            <p className="truncate text-sm text-muted-foreground">{cameraState.message}</p>
          )}
        </div>
        <ScreenFullscreenToggle className="shrink-0 opacity-60 hover:opacity-100" />
      </header>

      {/* ── Pie: el código, discreto ── */}
      <footer className="safe-area-x absolute inset-x-0 bottom-0 flex items-end justify-between gap-4 px-4 pt-4 pb-[max(1rem,var(--safe-b))] sm:px-6 sm:pt-6">
        {/* Existe siempre, con o sin texto: sin esto el botón del código se
            correría de lugar según la cola. */}
        <p className="min-h-9 text-sm text-muted-foreground">
          {queued.length > 0
            ? `${queued.length} marcación${queued.length === 1 ? "" : "es"} sin enviar`
            : ""}
        </p>
        {/* Con el código apagado el botón NO se renderiza deshabilitado: un
            control gris con tooltip dice "esto no anda hoy" y acá no es eso —
            la acción no existe en este comercio. Lo que sí se deshabilita es lo
            impedido por el estado (sin nadie con código cargado), que es el
            caso que §10 de context/14 manda decir en el propio control. */}
        {allowCode && (
          <Button
            variant="outline"
            // 56px: se toca con el dedo en una tablet colgada de la pared (§2 de
            // context/14 habilita el override con razón documentada).
            className="h-14 shrink-0 px-6 text-base"
            // Sin nadie con código, el teclado no puede identificar a nadie: el
            // impedimento se dice en el control que impide, no en una banda (§10
            // de context/14). El motivo ya está en el centro de la pantalla.
            disabled={!hasCode || phase.kind === "marking" || pendingEnrollment !== null}
            onClick={() => {
              setCodeSeed(undefined)
              setCodeOpen(true)
            }}
          >
            <KeyRound className="size-5" />
            Usar código
          </Button>
        )}
      </footer>

      <CodeDialog
        // Si el panel apaga el código mientras el teclado está abierto, se
        // cierra solo: el contexto se repregunta al llegar el evento 'setting'.
        open={allowCode && codeOpen}
        onOpenChange={setCodeOpen}
        employees={employees}
        seed={codeSeed}
        onIdentified={(employee) => {
          setCodeOpen(false)
          void markNow(employee, "pin")
        }}
      />
    </div>
  )
}
