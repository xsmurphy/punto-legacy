"use client"

/**
 * Reporte de asistencia del panel (context/83 F1).
 *
 * Hook propio y no `useReport()`: el endpoint no vive bajo `/v1/reports/*` sino
 * en `/v1/attendance`, que es el mismo recurso que el quiosco escribe. Que la
 * lectura y la escritura compartan endpoint es lo que hace que la invalidación
 * por realtime funcione sola — el publisher deriva la entity del path, así que
 * una marcación que llega de la cola offline a las tres de la tarde refresca el
 * reporte que alguien dejó abierto a la mañana (ver `use-realtime-sync.ts`).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"

import { api } from "@/lib/api-client"

/** Motivos por los que una marcación quedó para revisar. Espejo del backend. */
export type AttendanceReviewReason =
  | "no_camera"
  | "camera_denied"
  | "photo_failed"
  | "photo_lost"
  | "pin_stale"
  | "employee_inactive"

export interface AttendanceMarkRow {
  id: string
  employeeId: string
  employeeName: string
  jobTitle: string | null
  outletId: string | null
  outletName: string | null
  kind: "in" | "out"
  /** Instante de la marcación (ISO con offset). */
  markedAt: string
  /** Cuándo llegó al servidor. Distinto de `markedAt` si viajó en la cola. */
  receivedAt: string | null
  /** Día y hora del TENANT, ya resueltos por Postgres con la zona del comercio. */
  localDay: string
  localTime: string
  method: "pin" | "face"
  hasPhoto: boolean
  needsReview: boolean
  reviewReason: AttendanceReviewReason | null
  reviewedAt: string | null
  /**
   * Minutos de tardanza de la PRIMERA entrada del día. `null` = no se sabe (sin
   * horario declarado, o ese día no es laborable). `0` = llegó a horario. Son
   * cosas distintas y la tabla las muestra distinto.
   */
  lateMinutes: number | null
  /** Minutos del par que esta salida cerró. `null` en las entradas. */
  pairedMinutes: number | null
  /** Entrada sin salida (o salida sin entrada). No suma horas. */
  unpaired: boolean
}

export interface AttendanceEmployeeRow {
  employeeId: string
  employeeName: string
  jobTitle: string | null
  outletName: string | null
  workedMinutes: number
  pairs: number
  openPairs: number
  days: number
  lateCount: number
  lateMinutes: number
  needsReview: number
  hasSchedule: boolean
}

export interface AttendanceReport {
  marks: AttendanceMarkRow[]
  employees: AttendanceEmployeeRow[]
  totals: {
    marks: number
    employees: number
    workedMinutes: number
    lateCount: number
    needsReview: number
  }
}

export interface AttendanceFilters {
  from?: string
  to?: string
  employeeId?: string
  outletId?: string
  needsReview?: boolean
}

export function useAttendanceReport(filters: AttendanceFilters, enabled = true) {
  const params = new URLSearchParams()
  if (filters.from) params.set("from", filters.from)
  if (filters.to) params.set("to", filters.to)
  if (filters.employeeId) params.set("employeeId", filters.employeeId)
  if (filters.outletId) params.set("outletId", filters.outletId)
  if (filters.needsReview) params.set("needsReview", "1")
  const qs = params.toString()

  return useQuery<AttendanceReport>({
    queryKey: ["attendance", "report", qs],
    enabled,
    queryFn: () => api.get<AttendanceReport>(`/v1/attendance${qs ? `?${qs}` : ""}`),
    staleTime: 60 * 1000,
  })
}

/**
 * Marca una marcación flageada como REVISADA.
 *
 * No la corrige ni la borra: un hecho que ocurrió no se edita. "Revisada"
 * significa que una persona la miró y la dio por buena, que es exactamente lo
 * que el flag pedía.
 */
export function useReviewAttendanceMark() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, string>({
    mutationFn: (markId) =>
      api.post(`/v1/attendance?id=${encodeURIComponent(markId)}&action=review`, {}),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ["attendance"] })
    },
  })
}

/**
 * La foto de una marcación, como `Blob`.
 *
 * Pasa por `api.getBlob` y no por un `<img src>`: el objeto es PRIVADO en S3,
 * así que la foto sale del endpoint, que exige el Bearer del panel — y una
 * navegación del browser (o un `src`) no adjunta headers, así que volvería 401.
 * Es el mismo camino que ya usan los adjuntos del legajo.
 *
 * El llamador arma el object URL y lo revoca: quien lo crea sabe cuándo deja de
 * mirarlo.
 */
export function fetchAttendancePhoto(markId: string): Promise<Blob> {
  return api.getBlob(`/v1/attendance?resource=photo&id=${encodeURIComponent(markId)}`)
}
