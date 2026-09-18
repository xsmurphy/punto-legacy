"use client"

/**
 * Los rostros que el reloj de marcación puede reconocer, y el registro de uno
 * nuevo (RRHH F2, context/83 D5 y §9.2).
 *
 * ── Solo el reloj los pide ─────────────────────────────────────────────────
 *
 * Desde el §9.2 la marcación es un dispositivo propio (`module='clock'`), así
 * que estos vectores no bajan a ninguna caja: se mandan a quien los va a usar,
 * cuando los va a usar. Bajar biometría "por las dudas" a cada dispositivo del
 * comercio es justo lo que la D5 evita.
 *
 * ── Y sin embargo funciona sin internet ────────────────────────────────────
 *
 * La lista se cachea en IndexedDB por sucursal (`face-cache.ts`) y el hook
 * arranca desde ahí. Sin red, el reloj reconoce con la última lista que bajó
 * — que es exactamente el comportamiento de cualquier otro dato derivado del
 * POS sin conexión.
 *
 * ── Cómo se entera de que algo cambió ──────────────────────────────────────
 *
 * Por el `queryKey`. `useRealtimeSync` mapea la entidad `employee` a
 * `["employees"]`, y como esta consulta cuelga de ahí, invalidar el legajo
 * —enrolar, egresar, retirar el consentimiento— la refresca sola. No hizo falta
 * agregar nada al mapa de entidades: hizo falta elegir bien la clave.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { ApiError } from "@/lib/api-client"
import { posFetch } from "@/lib/api/pos-fetch"
import { loadFaces, saveFaces } from "@/lib/pos/face/face-cache"
import { FACE_MODEL_VERSION, type FaceCandidate } from "@/lib/pos/face/face-match"

/** La ventana de registro abierta desde el panel, si le toca a este reloj. */
export interface PendingEnrollment {
  employeeId: string
  name: string
  jobTitle: string | null
  /** ISO — a partir de acá deja de estar habilitado. */
  expiresAt: string
}

export interface AttendanceFaces {
  faces: FaceCandidate[]
  enrollment: PendingEnrollment | null
  /**
   * Esta lista salió del caché local y no de la red. La pantalla no lo muestra
   * —no es información accionable para quien marca— pero el hook lo distingue
   * para no pisar un dato fresco con uno viejo.
   */
  fromCache: boolean
}

const KEY = ["employees", "faces"] as const

/**
 * Trae los rostros de la sucursal del dispositivo y la ventana de registro.
 *
 * `outletId` viene del store del catálogo y NO se manda al servidor: el alcance
 * lo resuelve el backend con el contexto del device. Acá sirve solo para la
 * clave del caché local — una tablet que cambió de sucursal no puede reconocer
 * con la lista de la anterior.
 */
export function useAttendanceFaces(outletId: string, enabled = true) {
  return useQuery<AttendanceFaces>({
    queryKey: [...KEY, outletId],
    enabled,
    queryFn: async () => {
      try {
        const res = await posFetch(
          `/api/v1/attendance?resource=faces&modelVersion=${encodeURIComponent(FACE_MODEL_VERSION)}`,
          {},
          // El Bearer del RELOJ, no el de la caja (§9.2): desde el refactor el
          // único aparato que puede pedir rostros es el reloj de marcación, y
          // el slot de token está namespaceado por module.
          "clock",
        )
        const json = (await res.json().catch(() => null)) as {
          ok?: boolean
          data?: { faces?: FaceCandidate[]; enrollment?: PendingEnrollment | null }
        } | null

        if (!res.ok || json?.ok === false) {
          throw new ApiError(res.status, json, `Error ${res.status}`)
        }

        const faces = Array.isArray(json?.data?.faces) ? json.data.faces : []
        // El caché se refresca con lo que acaba de llegar, incluso si vino
        // vacío: una lista vacía es un dato (nadie tiene rostro registrado), no
        // una falla, y conservar la anterior haría reconocer a alguien a quien
        // el comercio le borró el rostro.
        void saveFaces(outletId, faces)

        return { faces, enrollment: json?.data?.enrollment ?? null, fromCache: false }
      } catch {
        // Sin red: la última lista que bajó. La ventana de registro NO se
        // cachea y queda en `null` a propósito — es un permiso vigente por diez
        // minutos, y ofrecer uno viejo sería habilitar una captura que el panel
        // ya no autoriza.
        const cached = await loadFaces(outletId)
        return { faces: cached?.faces ?? [], enrollment: null, fromCache: true }
      }
    },
    // Los rostros cambian poco (se registran una vez). Lo que los refresca de
    // verdad es el evento de realtime, no el paso del tiempo.
    staleTime: 5 * 60 * 1000,
    // Sin reintentos: el fallo ya cae al caché local, que es una respuesta
    // válida. Reintentar solo demoraría esa caída.
    retry: false,
  })
}

export interface EnrollFaceInput {
  employeeId: string
  /** Las tomas calculadas en el dispositivo. El servidor las promedia. */
  samples: number[][]
  /** La foto de la última toma, para que el panel pueda auditar el registro. */
  photo: Blob | null
}

/**
 * Manda el rostro capturado.
 *
 * Es la única operación de esta pantalla que NO se encola offline, y es
 * deliberado: el registro del rostro depende de una ventana que el servidor
 * abrió hace minutos y que puede haber vencido o haber sido cancelada. Guardar
 * la captura para mandarla más tarde sería completar un permiso que ya no
 * existe. Sin conexión no se registra; se registra cuando vuelve.
 *
 * (Marcar, en cambio, sí se encola — porque una marcación es un hecho que ya
 * ocurrió, y eso no vence.)
 */
export function useEnrollFace(outletId: string) {
  const qc = useQueryClient()

  return useMutation<{ enrolledAt: string | null }, Error, EnrollFaceInput>({
    mutationFn: async ({ employeeId, samples, photo }) => {
      const form = new FormData()
      form.set("employeeId", employeeId)
      form.set("modelVersion", FACE_MODEL_VERSION)
      form.set("samples", JSON.stringify(samples))
      if (photo) form.set("photo", photo, "rostro.jpg")

      // Sin `Content-Type` a mano: el boundary del multipart lo pone el browser
      // al serializar el FormData.
      const res = await posFetch(
        "/api/v1/attendance?action=face-enroll",
        { method: "POST", body: form },
        "clock",
      )
      const json = (await res.json().catch(() => null)) as {
        ok?: boolean
        data?: { face?: { enrolledAt?: string | null } }
        error?: { message?: string }
      } | null

      if (!res.ok || json?.ok === false) {
        throw new ApiError(res.status, json, json?.error?.message ?? "No se pudo registrar el rostro")
      }
      return { enrolledAt: json?.data?.face?.enrolledAt ?? null }
    },
    onSuccess: () => {
      // La lista y la ventana cambiaron: una se sumó, la otra se consumió.
      qc.invalidateQueries({ queryKey: [...KEY, outletId] })
    },
  })
}
