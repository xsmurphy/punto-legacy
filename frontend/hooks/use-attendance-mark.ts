"use client"

/**
 * Registrar una marcación de asistencia desde el reloj (context/83 F1, §9.2).
 *
 * ── Una sola mutación, porque una marcación es UN hecho ────────────────────
 *
 * La persona tipea su PIN, se saca la foto y confirma entrada o salida. Eso es
 * una operación: no hay pasos intermedios que puedan quedar a medias, y por eso
 * tampoco hay un "borrador" que persistir.
 *
 * ── El `opId` nace ACÁ, no en la cola ──────────────────────────────────────
 *
 * Mismo motivo que el conteo de stock (`use-stock-count.ts`): hay DOS caminos
 * —con red se manda directo, y si la request no vuelve se encola— y los dos
 * tienen que compartir identidad. El servidor pudo haber aplicado la primera,
 * así que el reintento DEBE llevar el mismo id; si lo generara la cola, el
 * reintento nacería con uno nuevo y la persona quedaría con dos entradas a la
 * misma hora (que el reporte aparearía como si hubiera entrado dos veces).
 *
 * ── La foto se encola aparte, y eso es intencional ─────────────────────────
 *
 * El binario va al store `opBlobs` bajo el mismo `opId` y NO adentro del
 * payload. El porqué completo está en `offline-db.ts`; el resumen es que un
 * `Blob` no sobrevive a `JSON.stringify` y que la cola se lee entera muy
 * seguido. Acá solo importa el orden: **primero el blob, después la operación**.
 * Al revés, una pasada del sync que caiga entre las dos escrituras mandaría la
 * marcación sin su foto —y flageada— aunque la foto existiera.
 */

import { useMutation } from "@tanstack/react-query"

import { posFetch } from "@/lib/api/pos-fetch"
import { ApiError } from "@/lib/api-client"
import { enqueueOp, newOpId, putOpBlob } from "@/lib/pos/pending-ops"
import type { AttendanceMarkPayload } from "@/lib/pos/local-register-state"

export interface AttendanceMarkInput extends AttendanceMarkPayload {
  /** La foto del momento. `null` cuando no se pudo sacar (ver `noPhotoReason`). */
  photo: Blob | null
  /** Caja del device — el cerco de la cola, no un dato que el servidor crea. */
  registerId: string
}

export interface AttendanceMarkResult {
  /** Quedó en la cola y todavía no llegó al servidor. */
  queued: boolean
  /** ¿El servidor la guardó para revisar? `null` si quedó en cola. */
  needsReview: boolean | null
}

/**
 * ¿El fallo fue "no se pudo hablar con el servidor"?
 *
 * Mismo criterio que el resto del POS: `navigator.onLine` no alcanza (dice
 * `true` con un router sin salida), así que lo que decide es que la request no
 * haya obtenido respuesta. Un rechazo del servidor —422 con un empleado que no
 * existe— es una respuesta y NO se encola: reintentarlo sería martillar con el
 * mismo payload.
 */
function isUnreachable(err: unknown): boolean {
  if (typeof navigator !== "undefined" && !navigator.onLine) return true
  return err instanceof TypeError
}

export function useSubmitAttendanceMark() {
  return useMutation<AttendanceMarkResult, Error, AttendanceMarkInput>({
    mutationFn: async (input) => {
      const opId = newOpId()
      const { photo, registerId, ...payload } = input

      const enqueueOffline = async (): Promise<AttendanceMarkResult> => {
        // El blob PRIMERO. Ver el docblock: si la operación entrara a la cola
        // antes, una pasada del sync podría llevársela sin la foto.
        if (photo) {
          await putOpBlob(opId, photo)
        }
        await enqueueOp({
          opId,
          kind: "attendanceMark",
          // Canal propio: las marcaciones son hechos autónomos y no tienen por
          // qué esperar detrás de un cierre de caja rechazado.
          stream: "attendance",
          registerId,
          payload,
          label: `${payload.kind === "in" ? "Entrada" : "Salida"} — ${payload.employeeName}`,
          // Sin `mergePayload`: dos marcaciones de la misma persona son DOS
          // hechos, nunca una corrección de la anterior.
        })
        return { queued: true, needsReview: null }
      }

      if (typeof navigator !== "undefined" && !navigator.onLine) {
        return enqueueOffline()
      }

      const form = new FormData()
      form.set("employeeId", payload.employeeId)
      // Vacío cuando la persona se identificó por el rostro y no tiene código:
      // el servidor lo lee como "no hay PIN que comparar" y, si el método fuera
      // 'pin', lo marca para revisar en vez de rechazar (fail-open, D4).
      form.set("pinHash", payload.pinHash ?? "")
      form.set("kind", payload.kind)
      form.set("markedAt", payload.markedAt)
      form.set("method", payload.method)
      // Ver `AttendanceMarkPayload.faceOutcome`: solo puede sumar un motivo de
      // revisión, nunca rechazar la marcación.
      form.set("faceOutcome", payload.faceOutcome ?? "none")
      if (photo) {
        form.set("photo", photo, "marcacion.jpg")
      } else {
        form.set("noPhotoReason", payload.noPhotoReason ?? "photo_failed")
      }

      try {
        // Sin `Content-Type` a mano: el boundary de `multipart/form-data` lo
        // genera el browser al serializar el FormData, y escribir la cabecera
        // sin él deja un body que el servidor no puede partir.
        const res = await posFetch(
          "/api/v1/attendance",
          { method: "POST", headers: { "X-Punto-Op-Id": opId }, body: form },
          // El Bearer del RELOJ (§9.2). Una caja ya no registra marcaciones.
          "clock",
        )

        const json = (await res.json().catch(() => null)) as {
          ok?: boolean
          data?: { mark?: { needsReview?: boolean } }
          error?: { message?: string }
        } | null

        if (!res.ok || json?.ok === false) {
          throw new ApiError(res.status, json, json?.error?.message ?? `Error ${res.status}`)
        }

        return { queued: false, needsReview: json?.data?.mark?.needsReview === true }
      } catch (err) {
        if (isUnreachable(err)) return enqueueOffline()
        throw err
      }
    },
  })
}
