/**
 * Qué le toca marcar a una persona: entrada o salida (context/83 F1).
 *
 * ── Es una SUGERENCIA, no una regla ────────────────────────────────────────
 *
 * La pantalla propone un tipo y deja cambiarlo de un toque. No podría ser de
 * otra forma: el dato con el que se calcula es viejo por definición —la persona
 * pudo haber marcado en la otra tablet del local hace diez minutos— y hacerlo
 * vinculante convertiría un dato desactualizado en una marcación mal tipificada,
 * que después le cuesta horas en la liquidación.
 *
 * El backend tampoco lo valida: cada marcación lleva su tipo explícito y el
 * reporte las aparea al leer. Dos entradas seguidas no son un error que haya
 * que impedir, son un día raro que el dueño va a querer ver tal como pasó.
 *
 * ── Por qué se mira también la cola local ──────────────────────────────────
 *
 * Sin red, la última marcación que conoce el servidor es la del último
 * bootstrap. Si la persona entró hace tres horas y esa entrada sigue en la cola
 * del dispositivo, proponerle "Entrada" otra vez es proponerle lo que
 * seguro NO quiere. Lo que manda es la marcación más RECIENTE de las dos
 * fuentes, comparada por el momento en que se marcó — nunca por el orden en que
 * se conoció.
 */

export type AttendanceKind = "in" | "out"

/** Una marcación ya hecha por este dispositivo y todavía sin enviar. */
export interface QueuedMark {
  employeeId: string
  kind: AttendanceKind
  /** Momento en que se marcó (ISO). */
  markedAt: string
}

export interface LastMark {
  kind: AttendanceKind
  markedAt: string
}

/**
 * La última marcación conocida de una persona, mirando el servidor Y la cola
 * local. `null` si nunca marcó.
 */
export function lastKnownMark(
  serverLastKind: AttendanceKind | null,
  serverLastMarkedAt: string | null,
  queued: QueuedMark[],
  employeeId: string,
): LastMark | null {
  const candidates: LastMark[] = []

  if (serverLastKind && serverLastMarkedAt) {
    candidates.push({ kind: serverLastKind, markedAt: serverLastMarkedAt })
  }
  for (const q of queued) {
    if (q.employeeId === employeeId) {
      candidates.push({ kind: q.kind, markedAt: q.markedAt })
    }
  }
  if (candidates.length === 0) return null

  return candidates.reduce((latest, next) => {
    const a = Date.parse(latest.markedAt)
    const b = Date.parse(next.markedAt)
    // Una fecha ilegible (snapshot viejo, reloj raro) no puede ganar: se queda
    // la que sí se entiende. Si ninguna se entiende, la primera — con dos datos
    // inservibles la sugerencia da igual, y lo que no puede pasar es devolver
    // `NaN` y que el `>` de arriba resuelva al azar.
    if (Number.isNaN(b)) return latest
    if (Number.isNaN(a)) return next
    return b > a ? next : latest
  })
}

/**
 * El tipo que se propone. Sin marcación previa se propone ENTRADA: es lo que
 * hace alguien que llega por primera vez, y es el único default que no puede
 * cerrar un turno que nunca se abrió.
 */
export function proposedKind(last: LastMark | null): AttendanceKind {
  if (last === null) return "in"
  return last.kind === "in" ? "out" : "in"
}
