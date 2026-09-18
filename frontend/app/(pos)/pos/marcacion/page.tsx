"use client"

/**
 * Quiosco de marcación de asistencia (context/83 F1).
 *
 * El empleado se para frente a la tablet del comercio, tipea su PIN de
 * marcación, se saca la foto y confirma entrada o salida. Se acabó.
 *
 * ── Sin sesión de operador, a propósito ────────────────────────────────────
 *
 * Esta es la ÚNICA pantalla del POS que no le pregunta nada al operador del
 * lock screen. El quiosco es del COMERCIO y atiende a gente que en su mayoría
 * no tiene usuario del sistema (cocina, limpieza): exigir el PIN de un operador
 * para que un cocinero marque su entrada obligaría a inventarle una credencial,
 * que es justo lo que el modelo de `employee` evita (D2).
 *
 * Quién marcó lo dice su PIN de marcación propio y, sobre todo, la foto.
 *
 * ── Offline-nativa de punta a punta (D7) ───────────────────────────────────
 *
 * El PIN se valida LOCALMENTE contra los hashes que bajaron en el bootstrap —
 * exactamente como el lock screen valida el del operador. La marcación y su
 * foto se encolan y suben cuando vuelve la red. Nada de esta pantalla necesita
 * conexión, y eso no es una optimización: un comercio sin internet sigue
 * teniendo gente que entra y sale.
 *
 * ── Fail-open: la foto NUNCA bloquea (D4) ──────────────────────────────────
 *
 * Se intenta SIEMPRE. Sin cámara, con el permiso denegado o con la captura
 * fallada, la marcación entra igual y queda flageada para que el dueño la
 * revise. Dejar a alguien que sí fue a trabajar sin poder registrarlo es un
 * daño concreto; el fraude se ataca con la evidencia y la revisión.
 *
 * ── El rostro es un ATAJO, nunca un portón (F2, D4) ────────────────────────
 *
 * Con la cara registrada, la persona se para enfrente, parpadea y confirma. Sin
 * ella —modelo que no cargó, contraluz, nadie enrolado, una tablet sin cámara—
 * la pantalla es EXACTAMENTE la de la F1: el teclado, el código, la foto. No hay
 * un solo camino en el que el reconocimiento impida marcar; lo único que hace es
 * ahorrar cuatro dígitos cuando funciona.
 *
 * Por eso el teclado está siempre, en el mismo lugar, con o sin reconocimiento
 * (§10 de context/14): la persona que marca todos los días no tiene que
 * averiguar en qué modo está la pantalla hoy.
 *
 * Y si alguien se paró frente a la cámara, no se lo reconoció, y terminó
 * marcando con un código: la marcación entra y queda para revisar. Ese caso —el
 * código de otro— es justo el que el modelo viejo, con el QR y el celular
 * propio, no dejaba ver.
 *
 * ── Reglas del POS que gobiernan el layout ─────────────────────────────────
 *
 * - Posiciones estables (§10 de context/14): el teclado, los cuatro círculos
 *   del PIN y la franja de estado existen SIEMPRE, en las mismas coordenadas.
 *   Nada aparece empujando al resto — la persona que marca todos los días tiene
 *   memoria muscular de dónde tocar.
 * - El impedimento se dice en el CONTROL que impide (botón deshabilitado +
 *   motivo), nunca en una banda. El estado de la cámara NO es un impedimento
 *   —no bloquea nada— así que va en un indicador único del encabezado que
 *   existe siempre y solo cambia de texto.
 * - Touch-first: los dígitos son objetivos grandes, y el teclado físico
 *   funciona igual para el comercio que tiene la tablet con teclado.
 */

import * as React from "react"
import { Camera, CameraOff, Check, Delete, LogIn, LogOut, ScanFace, UserCheck } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/empty-state"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import { FullscreenToggle } from "@/components/pos/fullscreen-toggle"
import { FaceEnrollment } from "@/components/pos/face-enrollment"
import { cn } from "@/lib/utils"

import { useCatalogStore } from "@/lib/catalog/store"
import { sha256Hex } from "@/lib/pos/pin-hash"
import { captureJpeg, describeCameraError, type NoPhotoReason } from "@/lib/pos/attendance-photo"
import {
  lastKnownMark,
  proposedKind,
  type AttendanceKind,
  type QueuedMark,
} from "@/lib/pos/attendance-kind"
import { peekOpsByStream } from "@/lib/pos/pending-ops"
import type { AttendanceMarkPayload } from "@/lib/pos/local-register-state"
import { useSubmitAttendanceMark } from "@/hooks/use-attendance-mark"
import { useAttendanceFaces, useEnrollFace } from "@/hooks/use-attendance-faces"
import { resolveFaceOutcome, useFaceRecognition } from "@/hooks/use-face-recognition"
import type { PosEmployee } from "@/lib/types/pos-bootstrap"

const PIN_LENGTH = 4

/** Estado de la cámara. `null` = todavía se está pidiendo el permiso. */
type CameraState = { ok: true } | { ok: false; reason: NoPhotoReason; message: string } | null

export default function MarcacionPage() {
  const employees = useCatalogStore((s) => s.employees)
  const rosterMissing = useCatalogStore((s) => s.attendanceRosterMissing)
  const outlet = useCatalogStore((s) => s.outlet)
  const activeRegisterId = useCatalogStore((s) => s.activeRegisterId)
  const submit = useSubmitAttendanceMark()

  const [pin, setPin] = React.useState("")
  const [error, setError] = React.useState(false)
  /**
   * La persona identificada, esperando confirmar entrada o salida.
   *
   * `via` dice CÓMO se la identificó. No cambia lo que se ve —los dos botones
   * son los mismos— pero sí lo que se informa al registrar la marcación.
   */
  const [matched, setMatched] = React.useState<{
    employee: PosEmployee
    proposed: AttendanceKind
    via: "pin" | "face"
  } | null>(null)

  const videoRef = React.useRef<HTMLVideoElement | null>(null)
  const [camera, setCamera] = React.useState<CameraState>(null)

  /**
   * Marcaciones que este dispositivo ya hizo y todavía no envió. Se leen al
   * montar y después de cada marcación: es lo que permite proponer bien el
   * tipo sin red (ver `lib/pos/attendance-kind.ts`).
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
      // Sin la cola local la sugerencia sale del dato del servidor, que es
      // peor pero sirve. No es motivo para romper la pantalla.
    }
  }, [])
  React.useEffect(() => {
    void refreshQueued()
  }, [refreshQueued])

  // ── Cámara ────────────────────────────────────────────────────────────────
  //
  // El stream se abre UNA vez, al entrar, y vive mientras la pantalla esté
  // montada. Pedirlo en cada marcación agregaría el prompt de permiso y un
  // segundo de arranque justo en el momento en que la persona ya apuntó a la
  // cámara — y en un quiosco ese segundo se paga cincuenta veces por día.
  React.useEffect(() => {
    let stream: MediaStream | null = null
    let cancelled = false

    async function open() {
      if (typeof navigator === "undefined" || !navigator.mediaDevices?.getUserMedia) {
        setCamera({
          ok: false,
          reason: "no_camera",
          message: "Este dispositivo no tiene cámara. Se puede marcar igual.",
        })
        return
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          // Cámara frontal: la persona mira la pantalla mientras marca.
          video: { facingMode: "user", width: { ideal: 640 } },
          audio: false,
        })
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        if (videoRef.current) {
          videoRef.current.srcObject = stream
          await videoRef.current.play().catch(() => undefined)
        }
        setCamera({ ok: true })
      } catch (err) {
        if (!cancelled) setCamera({ ok: false, ...describeCameraError(err) })
      }
    }

    void open()
    return () => {
      cancelled = true
      // Soltar el stream al salir NO es opcional: sin esto la luz de la cámara
      // queda prendida con la pantalla cerrada, que para quien mira la tablet
      // es un aparato filmando el local.
      stream?.getTracks().forEach((t) => t.stop())
    }
  }, [])

  // ── Reconocimiento facial (F2) ────────────────────────────────────────────
  //
  // Los rostros bajan por su propio endpoint y no en el bootstrap: son biometría
  // y una sola pantalla los usa (ver `use-attendance-faces.ts`). Sin conexión
  // salen del caché local, así que reconocer sigue andando sin internet.
  const outletId = outlet?.id ?? ""
  // Solo se piden cuando hay cámara: sin ella no hay nada que comparar, y pedir
  // vectores faciales que nadie va a usar es exponer biometría sin motivo.
  const faces = useAttendanceFaces(outletId, camera?.ok === true)
  const enrollFace = useEnrollFace(outletId)

  /**
   * Un registro que este quiosco decidió no atender ahora.
   *
   * El quiosco NO puede cancelar la habilitación —la abrió el panel y solo el
   * panel la cierra— así que "Ahora no" la aparta de ESTA pantalla y nada más.
   * Vence sola en unos minutos. Fingir que la cancela sería mentirle a quien la
   * abrió, que seguiría viendo "esperando al quiosco" en la ficha.
   */
  const [dismissedEnrollment, setDismissedEnrollment] = React.useState<string | null>(null)
  const openEnrollment = faces.data?.enrollment ?? null
  const pendingEnrollment =
    openEnrollment && openEnrollment.employeeId !== dismissedEnrollment ? openEnrollment : null

  /**
   * Alguien quedó identificado por la cara.
   *
   * Se busca en el roster que ya bajó en el bootstrap —el mismo del código— y no
   * en otra lista: el rostro aporta el ID, todo lo demás (nombre, puesto, última
   * marcación) sale de donde salía antes. Si esa persona no está en el roster
   * (se le borró el código, cambió de sucursal) no se propone nada: el
   * reconocimiento nunca puede habilitar a alguien que la pantalla no habilitaría
   * igual por código.
   */
  const identifyByFace = React.useCallback(
    (employeeId: string) => {
      const found = employees.find((e) => e.id === employeeId)
      if (!found) return
      const last = lastKnownMark(found.lastKind, found.lastMarkedAt, queued, found.id)
      setPin("")
      setError(false)
      setMatched({ employee: found, proposed: proposedKind(last), via: "face" })
    },
    [employees, queued],
  )

  const face = useFaceRecognition({
    videoRef,
    candidates: faces.data?.faces ?? [],
    // Sin cámara no hay nada que mirar, y sin nadie registrado tampoco: así el
    // comercio que no usa el rostro no baja los 8 MB del modelo.
    //
    // La excepción es el REGISTRO: el primero de un comercio ocurre justamente
    // cuando la lista está vacía, y sin esta condición el modelo nunca cargaría
    // y el botón de capturar quedaría deshabilitado para siempre.
    enabled:
      camera?.ok === true &&
      ((faces.data?.faces.length ?? 0) > 0 || pendingEnrollment !== null),
    // Con la confirmación abierta o en pleno registro de un rostro, el bucle se
    // detiene: seguir proponiendo nombres mientras la persona decide sería
    // pisarle la pantalla debajo del dedo.
    paused: matched !== null || pendingEnrollment !== null,
    onIdentified: identifyByFace,
  })

  // ── Identificación por PIN ────────────────────────────────────────────────
  //
  // Local y sin red, contra los hashes del snapshot. Mismo mecanismo que el
  // lock screen (`lib/pos/pin-hash.ts`).
  React.useEffect(() => {
    if (pin.length !== PIN_LENGTH) return
    let cancelled = false

    const timer = setTimeout(async () => {
      const hash = await sha256Hex(pin)
      if (cancelled) return

      const found = employees.find((e) => e.markPinHash === hash) ?? null
      if (!found) {
        setError(true)
        setPin("")
        return
      }
      const last = lastKnownMark(found.lastKind, found.lastMarkedAt, queued, found.id)
      setError(false)
      setPin("")
      setMatched({ employee: found, proposed: proposedKind(last), via: "pin" })
    }, 80)

    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [pin, employees, queued])

  // Teclado físico: el comercio que tiene la tablet con teclado marca sin tocar
  // la pantalla. No reemplaza al teclado en pantalla, lo acompaña.
  React.useEffect(() => {
    if (matched) return
    const onKey = (e: KeyboardEvent) => {
      if (e.metaKey || e.ctrlKey || e.altKey) return
      if (e.key === "Backspace") {
        e.preventDefault()
        setError(false)
        setPin((prev) => prev.slice(0, -1))
        return
      }
      if (/^[0-9]$/.test(e.key)) {
        e.preventDefault()
        setError(false)
        setPin((prev) => (prev.length >= PIN_LENGTH ? prev : prev + e.key))
      }
    }
    window.addEventListener("keydown", onKey, true)
    return () => window.removeEventListener("keydown", onKey, true)
  }, [matched])

  function pressDigit(digit: string) {
    setError(false)
    setPin((prev) => (prev.length >= PIN_LENGTH ? prev : prev + digit))
  }

  async function confirmMark(kind: AttendanceKind) {
    if (!matched) return
    const employee = matched.employee

    // La foto se saca ACÁ, al confirmar, y no al identificar: es la evidencia
    // de la marcación, así que tiene que ser del momento en que la persona la
    // hizo — no de treinta segundos antes, cuando solo había tipeado un código.
    const photo = camera?.ok ? await captureJpeg(videoRef.current) : null
    const noPhotoReason: NoPhotoReason | null = photo
      ? null
      : camera?.ok === false
        ? camera.reason
        : "photo_failed"

    // Qué vio la cámara. Se resuelve ACÁ, al confirmar, porque depende de quién
    // terminó marcando: la misma cara vista treinta segundos antes significa
    // cosas distintas según el código que se haya tipeado. Ver
    // `resolveFaceOutcome()`.
    const method = matched.via
    const faceOutcome = resolveFaceOutcome(method, face.lastSighting.current, employee.id)

    try {
      const result = await submit.mutateAsync({
        employeeId: employee.id,
        employeeName: employee.name,
        markPinHash: employee.markPinHash,
        kind,
        // La hora del DISPOSITIVO, en el momento de marcar. Nunca la del envío:
        // esta marcación puede sincronizar mañana.
        markedAt: new Date().toISOString(),
        method,
        photoPending: photo !== null,
        noPhotoReason,
        faceOutcome,
        photo,
        registerId: activeRegisterId,
      })

      const verb = kind === "in" ? "Entrada" : "Salida"
      if (result.queued) {
        toast.success(`${verb} registrada — ${employee.name}`, {
          description: "Se va a enviar sola cuando vuelva la conexión.",
        })
      } else if (result.needsReview || !photo) {
        toast.success(`${verb} registrada — ${employee.name}`, {
          description: "Quedó guardada para que la revise el encargado.",
        })
      } else {
        toast.success(`${verb} registrada — ${employee.name}`)
      }

      setMatched(null)
      // Se olvida lo visto: el parpadeo de quien acaba de marcar no puede
      // acreditar a la persona que venga después.
      face.reset()
      void refreshQueued()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "No se pudo registrar la marcación")
    }
  }

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

  // ── Estados en los que no hay marcación posible ────────────────────────────
  //
  // Se resuelven ANTES del layout: son pantallas distintas, no un layout con
  // partes apagadas.

  // RRHH es core: el roster baja siempre. Que falte la clave solo puede
  // significar que esta caja guarda un arranque anterior a ese cambio — se
  // arregla con una sincronización, no hay nada que habilitar.
  if (rosterMissing) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <EmptyState
          icon={UserCheck}
          title="Esta caja necesita actualizarse"
          description="Conectá el dispositivo a internet y volvé a abrir la app para que baje la lista de empleados."
        />
      </div>
    )
  }

  if (employees.length === 0) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <EmptyState
          icon={UserCheck}
          title="Todavía nadie puede marcar"
          description="Cargá el PIN de marcación en el legajo de cada persona, desde Empleados en el panel."
        />
      </div>
    )
  }

  const cameraBadge: { label: string; reason: string } = camera === null
    ? { label: "Preparando cámara", reason: "Estamos activando la cámara del dispositivo." }
    : camera.ok
      ? {
          label: "Cámara lista",
          reason:
            face.status === "ready"
              ? "Te reconoce por tu rostro, y se guarda una foto al marcar."
              : "Se guarda una foto en el momento de marcar.",
        }
      : { label: "Sin cámara", reason: `${camera.message} La marcación queda para revisar.` }

  /**
   * La línea de ayuda del reconocimiento.
   *
   * Existe SIEMPRE con la misma altura, aunque esté vacía (§10 de context/14):
   * un renglón que aparece y desaparece movería el teclado varios pixeles según
   * si en ese instante hay una cara delante de la cámara, y el teclado es lo que
   * la persona busca con el dedo sin mirar.
   *
   * Vacía cuando no hay nada que decir — sin cámara, sin nadie registrado, con
   * el modelo que no cargó. En todos esos casos la pantalla es la de la F1 y no
   * hace falta explicar por qué: se marca con el código, como siempre.
   */
  const faceHint =
    matched !== null
      ? ""
      : face.status === "loading"
        ? "Preparando el reconocimiento"
        : face.awaitingBlink
          ? "Parpadeá para confirmar"
          : face.status === "ready" && face.facePresent
            ? "Mirá a la cámara"
            : ""

  return (
    <div className="flex h-full flex-col">
      <header className="flex shrink-0 items-center gap-3 border-b p-4">
        <div className="min-w-0 flex-1">
          <h1 className="text-2xl font-semibold">Marcación</h1>
          {/* Altura constante: la línea existe siempre, diga lo que diga. */}
          <p className="truncate text-sm text-muted-foreground">
            {outlet?.name || "Sin sucursal"} · {employees.length} persona
            {employees.length === 1 ? "" : "s"} habilitada
            {employees.length === 1 ? "" : "s"}
          </p>
        </div>

        {/* Indicador ÚNICO del estado de la cámara, SIEMPRE presente: no
            aparece ni desaparece, solo cambia de texto. No va sobre el botón de
            confirmar porque no es un impedimento —sin cámara se marca igual— y
            el motivo va en el tooltip para que la línea no crezca. */}
        <Tooltip>
          {/* `span` envolvente: el trigger necesita una ref y `<Badge>` no la
              reenvía. */}
          <TooltipTrigger asChild>
            <span className="shrink-0">
              <Badge variant={camera?.ok ? "outline" : "secondary"}>
                {camera?.ok ? (
                  <Camera className="mr-1 size-3" />
                ) : (
                  <CameraOff className="mr-1 size-3" />
                )}
                {cameraBadge.label}
              </Badge>
            </span>
          </TooltipTrigger>
          <TooltipContent>{cameraBadge.reason}</TooltipContent>
        </Tooltip>

        <FullscreenToggle />
      </header>

      <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-6 p-4">
        {/* Visor de la cámara. Existe SIEMPRE, con o sin permiso: sin él, el
            teclado saltaría 200px hacia arriba en los dispositivos sin cámara y
            el mismo local tendría dos layouts distintos según la tablet. */}
        <div className="relative size-40 shrink-0 overflow-hidden rounded-full border bg-muted">
          <video
            ref={videoRef}
            playsInline
            muted
            // `scale-x-[-1]`: espejado, como un espejo real. Sin esto la persona
            // se ve invertida y se mueve para el lado contrario al corregir su
            // posición.
            className={cn(
              "size-full scale-x-[-1] object-cover",
              !camera?.ok && "invisible",
            )}
          />
          {!camera?.ok && (
            <div className="absolute inset-0 flex items-center justify-center">
              <CameraOff className="size-8 text-muted-foreground" />
            </div>
          )}
          {/* Anillo de reconocimiento: se pinta SOBRE el visor que ya existe, no
              se agrega un bloque al lado. La señal de estado va encima de un
              elemento fijo (§10) — así nada se desplaza cuando aparece una cara. */}
          {face.facePresent && !matched && (
            <div
              className={cn(
                "pointer-events-none absolute inset-0 rounded-full ring-4 transition-colors",
                face.awaitingBlink ? "ring-primary" : "ring-foreground/30",
              )}
            />
          )}
        </div>

        {/* Altura fija aunque esté vacía: ver `faceHint`. */}
        <p className="flex h-5 items-center gap-1.5 text-sm text-muted-foreground">
          {faceHint && <ScanFace className="size-4" />}
          {faceHint}
        </p>

        {pendingEnrollment ? (
          // ── Registro de un rostro ──
          // Lo habilitó el panel para ESTA persona. La pantalla no elige a quién
          // registra: ver el docblock de `<FaceEnrollment>`.
          <FaceEnrollment
            enrollment={pendingEnrollment}
            videoRef={videoRef}
            readOnce={face.readOnce}
            ready={face.status === "ready"}
            submitting={enrollFace.isPending}
            onSubmit={submitEnrollment}
            onCancel={() => setDismissedEnrollment(pendingEnrollment.employeeId)}
          />
        ) : matched ? (
          // ── Confirmación ──
          // La persona ya está identificada: lo único que queda es decir si
          // entra o sale. Los dos botones son grandes y del mismo tamaño, con el
          // propuesto enfatizado — no se esconde el otro, porque la sugerencia
          // sale de un dato que puede estar viejo.
          <div className="flex w-full max-w-sm flex-col items-center gap-5">
            <div className="text-center">
              <p className="text-2xl font-semibold">{matched.employee.name}</p>
              <p className="text-sm text-muted-foreground">
                {matched.employee.jobTitle || "Sin puesto cargado"}
              </p>
            </div>

            <div className="grid w-full grid-cols-2 gap-3">
              <Button
                size="lg"
                variant={matched.proposed === "in" ? "default" : "outline"}
                className="h-20 flex-col gap-1 text-base"
                disabled={submit.isPending}
                onClick={() => void confirmMark("in")}
              >
                <LogIn className="size-5" />
                Entrada
              </Button>
              <Button
                size="lg"
                variant={matched.proposed === "out" ? "default" : "outline"}
                className="h-20 flex-col gap-1 text-base"
                disabled={submit.isPending}
                onClick={() => void confirmMark("out")}
              >
                <LogOut className="size-5" />
                Salida
              </Button>
            </div>

            <Button
              variant="ghost"
              className="h-12 w-full"
              disabled={submit.isPending}
              onClick={() => {
                setMatched(null)
                // Se olvida lo visto: si no era esa persona, el parpadeo que se
                // contó tampoco era suyo.
                face.reset()
              }}
            >
              No soy yo
            </Button>
          </div>
        ) : (
          // ── Identificación ──
          <div className="flex w-full max-w-sm flex-col items-center gap-6">
            {/* Cuatro círculos, igual que el lock screen: es el mismo gesto y
                no hay razón para que se vea distinto. */}
            <div className="flex items-center gap-8">
              {Array.from({ length: PIN_LENGTH }).map((_, i) => (
                <span
                  key={i}
                  className={cn(
                    "block size-5 rounded-full border-2 transition-colors",
                    i < pin.length
                      ? "border-foreground bg-foreground"
                      : "border-foreground/40 bg-transparent",
                  )}
                />
              ))}
            </div>

            {/* Altura fija: el mensaje de error no puede empujar el teclado. */}
            <p
              className={cn(
                "h-5 text-sm",
                error ? "font-semibold text-destructive" : "text-muted-foreground",
              )}
            >
              {error ? "Ese código no es de nadie" : "Ingresá tu código de marcación"}
            </p>

            {/* Teclado en pantalla. Botones de 72px: se tocan con el dedo en una
                tablet colgada de la pared (§2 habilita el override con razón
                documentada). */}
            <div className="grid w-full grid-cols-3 gap-3">
              {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((d) => (
                <Button
                  key={d}
                  variant="outline"
                  className="h-[72px] text-2xl font-medium"
                  onClick={() => pressDigit(d)}
                >
                  {d}
                </Button>
              ))}
              {/* La celda vacía mantiene al 0 centrado y al borrar a la derecha,
                  que es donde está en cualquier teclado numérico. */}
              <span aria-hidden />
              <Button
                variant="outline"
                className="h-[72px] text-2xl font-medium"
                onClick={() => pressDigit("0")}
              >
                0
              </Button>
              <Button
                variant="outline"
                aria-label="Borrar"
                className="h-[72px]"
                onClick={() => {
                  setError(false)
                  setPin((prev) => prev.slice(0, -1))
                }}
              >
                <Delete className="size-6" />
              </Button>
            </div>
          </div>
        )}
      </div>

      {/* Franja de estado: existe siempre y solo cambia de texto. */}
      <footer className="flex shrink-0 items-center justify-center gap-2 border-t p-4 text-sm text-muted-foreground">
        <Check className="size-4" />
        {queued.length === 0
          ? "Todas las marcaciones están enviadas"
          : `${queued.length} marcación${queued.length === 1 ? "" : "es"} esperando conexión`}
      </footer>
    </div>
  )
}
