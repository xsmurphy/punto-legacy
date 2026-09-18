/**
 * Las marcaciones que ESTE reloj acaba de hacer (context/83 §9.5).
 *
 * Desde que el flujo es automático, la pantalla no le pregunta nada a nadie: la
 * persona se para, se la reconoce y se registra. Eso abre dos problemas que
 * antes resolvía el toque de confirmación, y los dos se contestan con el mismo
 * dato — lo que este aparato marcó recién.
 *
 * ── 1. La misma persona, dos veces ────────────────────────────────────────
 *
 * Quien acaba de fichar sigue parada ahí: guarda el celular, saluda a alguien,
 * se acomoda el delantal. La cámara la vuelve a ver y —sin nadie que confirme—
 * la marcaría de nuevo. Dos entradas a la misma hora no son un error visible en
 * el momento: aparecen en la liquidación, semanas después, como un turno que no
 * cierra.
 *
 * Por eso, dentro de la ventana de repetición, volver a reconocer a la misma
 * persona NO registra nada y se le muestra el saludo que ya se le dio. Que vea
 * la misma respuesta es parte del arreglo: el silencio la haría insistir.
 *
 * Un minuto, y no cinco: nadie entra y sale del trabajo en sesenta segundos,
 * pero el que se equivocó de botón sí quiere poder corregir enseguida —y con
 * una ventana larga tendría que esperar parado frente a la tablet.
 *
 * ── 2. Qué le toca marcar ahora ───────────────────────────────────────────
 *
 * La inferencia entrada/salida sale de la última marcación conocida
 * (`attendance-kind.ts`). Con red, esa última marcación la sabe el SERVIDOR, y
 * el roster que la trae no se refresca en el instante en que alguien ficha: la
 * persona que entra y sale dos veces en la misma hora se llevaría dos entradas
 * seguidas. Por eso lo que este aparato acaba de registrar cuenta igual que lo
 * que vino del servidor y que lo que espera en la cola offline.
 *
 * ── Vive en memoria, y alcanza ────────────────────────────────────────────
 *
 * No se persiste. Si el reloj se recarga, la ventana de repetición se pierde —y
 * no pasa nada: sin red la marcación sigue en la cola local (que también
 * alimenta la inferencia) y con red la sabe el servidor. Persistirla sería
 * guardar en disco un dato que vale sesenta segundos.
 */

import type { AttendanceKind, QueuedMark } from "@/lib/pos/attendance-kind"

/**
 * Una marcación hecha en este aparato durante esta sesión.
 *
 * Extiende `QueuedMark` a propósito: así la lista se le pasa tal cual a
 * `lastKnownMark()` sin convertir nada.
 */
export interface SessionMark extends QueuedMark {
  /** Para repetir el saludo sin volver a buscar a la persona en el roster. */
  name: string
  kind: AttendanceKind
}

/** Cuánto vale una marcación para bloquear la siguiente de la misma persona. */
export const REPEAT_WINDOW_MS = 60_000

/**
 * Cuántas marcaciones se recuerdan.
 *
 * Es un tope de memoria, no una regla de negocio: con la ventana de un minuto,
 * cincuenta marcaciones son más gente de la que puede fichar en ese rato en una
 * sola tablet.
 */
const LIMIT = 50

/** Suma una marcación. Devuelve una lista nueva — nunca muta la anterior. */
export function rememberMark(list: SessionMark[], mark: SessionMark): SessionMark[] {
  const next = [...list, mark]
  return next.length > LIMIT ? next.slice(next.length - LIMIT) : next
}

/**
 * ¿Esta persona marcó recién?
 *
 * Devuelve la marcación más reciente de esa persona dentro de la ventana, o
 * `null`. Una fecha ilegible no cuenta: ante la duda se deja marcar, porque el
 * daño de una marcación de más (una fila para revisar) es mucho menor que el de
 * una marcación de menos (una jornada que no queda registrada).
 */
export function findRecentMark(
  list: SessionMark[],
  employeeId: string,
  now: number,
  windowMs: number = REPEAT_WINDOW_MS,
): SessionMark | null {
  let best: SessionMark | null = null
  let bestAt = -Infinity

  for (const mark of list) {
    if (mark.employeeId !== employeeId) continue
    const at = Date.parse(mark.markedAt)
    if (Number.isNaN(at)) continue
    if (now - at > windowMs) continue
    // Una marcación con fecha futura (reloj del aparato adelantado y corregido
    // después) tampoco se descarta: lo que importa es que fue recién.
    if (at > bestAt) {
      best = mark
      bestAt = at
    }
  }

  return best
}
