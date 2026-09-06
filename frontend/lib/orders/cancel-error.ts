/**
 * Traduce el rechazo del backend de una ANULACIÓN a algo que el cajero pueda
 * usar.
 *
 * En una caja, "no se pudo" sin decir qué falta es una llamada al encargado.
 * Los rechazos del backend se traducen a copy accionable: falta permiso, motivo
 * vacío, y —el que importa— ventana de anulación vencida, que dice cuántos
 * minutos pasaron, cuántos permite el comercio y que a partir de ahí lo tiene
 * que hacer un encargado. Mismo criterio que `recallBlockReason`
 * (`lib/kds/board.ts`).
 *
 * ── Por qué un solo traductor para los tres granos ─────────────────────────
 *
 * El backend gatea con UN solo `OrderCancelGate` la anulación de un ítem, la de
 * la orden entera y la de la sesión de una mesa: misma clave, misma ventana,
 * mismo 422 con `details`. Del lado del cliente vivía solo el copy del ítem,
 * adentro de `CancelOrderItemDialog`, y la cancelación de la orden mandaba
 * `err.message` pelado a un toast. Cuando la orden entera pasó a tener ventana,
 * ese camino habría mostrado el mensaje del servidor sin la salida —"pedile a
 * un encargado"— que es la única parte que destraba al cajero.
 *
 * Un solo traductor y no tres: si mañana cambia la política, cambia acá. Lo
 * único que varía por grano es el sujeto de la frase, y eso es un parámetro.
 */

// `PosApiError` y no `OrderApiError` de `use-orders`: esto es `lib/`, y una
// dependencia de lib hacia hooks invierte la dirección. Son la misma clase —
// `use-orders` la re-exporta con el nombre de su dominio.
import { PosApiError } from "@/lib/api/pos-json"

/** Qué se estaba anulando. Determina el sujeto del copy, nada más. */
export type CancelSubject = "item" | "order" | "session"

const SUBJECT_COPY: Record<
  CancelSubject,
  { since: string; whoElse: string; fallback: string }
> = {
  item: {
    since: "desde que se cargó el ítem",
    whoElse: "Lo tiene que anular un encargado con su usuario.",
    fallback: "No se pudo anular el ítem.",
  },
  order: {
    since: "desde que se abrió la orden",
    whoElse: "La tiene que cancelar un encargado con su usuario.",
    fallback: "No se pudo cancelar la orden.",
  },
  session: {
    since: "desde que se abrió la mesa",
    whoElse: "La tiene que cancelar un encargado con su usuario.",
    fallback: "No se pudo cancelar la mesa.",
  },
}

/** "1 minuto" / "12 minutos" — el plural del copy, sin helper de formato. */
export function minutes(n: number): string {
  const v = Math.max(0, Math.round(n))
  return v === 1 ? "1 minuto" : `${v} minutos`
}

/**
 * @param err lo que tiró la mutación (`PosApiError` si vino del backend)
 * @param subject qué se estaba anulando
 */
export function cancelErrorMessage(err: unknown, subject: CancelSubject): string {
  const copy = SUBJECT_COPY[subject]
  if (err instanceof PosApiError) {
    const details = err.details
    if (err.status === 403) {
      return `${err.message} Esta anulación la tiene que hacer un encargado con su usuario.`
    }
    if (details?.code === "cancel_window_expired") {
      const windowMinutes = details.windowMinutes
      const elapsedMinutes = details.elapsedMinutes
      if (typeof windowMinutes === "number" && typeof elapsedMinutes === "number") {
        return (
          `Pasaron ${minutes(elapsedMinutes)} ${copy.since} y el comercio ` +
          `permite anularlo hasta ${minutes(windowMinutes)}. ` +
          `A partir de ahí lo tiene que anular un encargado con su usuario.`
        )
      }
      return `${err.message} ${copy.whoElse}`
    }
    return err.message
  }
  return err instanceof Error ? err.message : copy.fallback
}
