"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"

/**
 * Legajo de empleados (RRHH F0, context/83, mig 229).
 *
 * Un empleado NO es un usuario del sistema: el vínculo `userId` es opcional y
 * existe personal que nunca entra a Punto. Ver el docblock de
 * `api/lib/Hr/EmployeeService.php`.
 *
 * Dos bajas distintas, y por eso dos mutaciones:
 *   - `useTerminateEmployee` = EGRESO. Escribe la fecha; el legajo queda.
 *   - `useArchiveEmployee`   = la fila cargada por error sale del listado.
 */

/** Periodicidad del componente FIJO del sueldo. */
export type FixedPeriod = "monthly" | "biweekly" | "weekly"

/** Filtro de estado laboral del listado. */
export type EmployeeState = "active" | "terminated" | "all"

export interface Employee {
  id: string
  fullName: string
  documentNumber: string | null
  /** E.164 sin '+', como lo guarda el backend. */
  phone: string | null
  email: string | null
  address: string | null
  birthDate: string | null
  jobTitle: string | null
  hireDate: string | null
  /** null = sin egreso. */
  endDate: string | null
  endReason: string | null
  outletId: string | null
  outletName: string | null
  /** Contacto type=0 vinculado, si esta persona además opera el sistema. */
  userId: string | null
  userName: string | null
  fixedAmount: number | null
  fixedPeriod: FixedPeriod | null
  hourlyRate: number | null
  commissions: boolean
  notes: string | null
  /**
   * ¿Esta persona tiene PIN de marcación cargado? El PIN mismo NO viaja, ni
   * siquiera hasheado: es SHA-256 sin sal de 4 dígitos, o sea el PIN para quien
   * tenga cinco minutos. Al quiosco baja por otro camino y con otro gate (el
   * bootstrap del device). Un PIN olvidado se reemplaza, no se consulta.
   */
  hasMarkPin: boolean
  /** Horario declarado. `null` = sin horario: el reporte no mide tardanzas. */
  schedule: EmployeeSchedule | null
  biometricConsentAt: string | null
  /** 1 = vigente, 0 = archivado. Distinto de `active`. */
  status: number
  /** Derivado del backend: no tiene fecha de egreso. */
  active: boolean
  createdAt: string | null
  updatedAt: string | null
}

export interface EmployeeAttachment {
  id: string
  filename: string
  mime: string
  sizeBytes: number
  label: string | null
  createdAt: string | null
}

/** Lo que el formulario manda. Todo opcional salvo lo que el backend exige. */
export interface EmployeeFormValues {
  fullName: string
  documentNumber?: string | null
  phone?: string | null
  /** ISO alpha-2 con el que interpretar el teléfono nacional. */
  country?: string | null
  email?: string | null
  address?: string | null
  birthDate?: string | null
  jobTitle?: string | null
  hireDate: string
  outletId?: string | null
  userId?: string | null
  fixedAmount?: number | null
  fixedPeriod?: FixedPeriod | null
  hourlyRate?: number | null
  commissions?: boolean
  notes?: string | null
  /**
   * PIN de marcación en CLARO (4 dígitos). El backend lo guarda hasheado.
   *
   * Tres valores con tres significados, y hay que respetarlos:
   *   ausente → no se toca el que ya tenga
   *   `null`  → se BORRA (la persona deja de poder marcar)
   *   "1234"  → se reemplaza
   *
   * Mandar `null` "por las dudas" en cada edición le sacaría el PIN a todo el
   * equipo cada vez que alguien corrige un teléfono.
   */
  markPin?: string | null
  /** Horario declarado. `null` lo borra. Ausente no lo toca. */
  schedule?: EmployeeSchedule | null
  biometricConsent?: boolean
}

/** Día de la semana del horario declarado. */
export type ScheduleDay = "mon" | "tue" | "wed" | "thu" | "fri" | "sat" | "sun"

/**
 * Horario declarado de una persona (context/83 F1).
 *
 * Un día AUSENTE del mapa es un día NO laborable — no hay una tercera forma de
 * decirlo. La hora de salida es opcional: alcanza con la de entrada para medir
 * tardanzas, que es lo que este dato existe para permitir.
 */
export interface EmployeeSchedule {
  days: Partial<Record<ScheduleDay, { in: string; out: string | null }>>
  /** Minutos de gracia antes de contar una llegada como tarde. */
  toleranceMinutes: number
}

export interface EmployeeFilters {
  q?: string
  state?: EmployeeState
  outletId?: string
  includeArchived?: boolean
}

const KEY = ["employees"] as const

export function useEmployees(filters: EmployeeFilters = {}) {
  return useQuery<Employee[]>({
    queryKey: [...KEY, filters],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (filters.q) params.set("q", filters.q)
      if (filters.state) params.set("state", filters.state)
      if (filters.outletId) params.set("outletId", filters.outletId)
      if (filters.includeArchived) params.set("includeArchived", "1")
      const qs = params.toString()
      const data = await api.get<{ employees: Employee[] }>(
        `/v1/employees${qs ? `?${qs}` : ""}`,
      )
      return data.employees ?? []
    },
    staleTime: 30 * 1000,
  })
}

export function useEmployee(id: string | undefined) {
  return useQuery<Employee>({
    queryKey: [...KEY, "detail", id],
    queryFn: () => api.get<Employee>(`/v1/employees?id=${id}`),
    enabled: !!id,
    staleTime: 30 * 1000,
  })
}

function useInvalidate() {
  const qc = useQueryClient()
  return () => {
    qc.invalidateQueries({ queryKey: KEY })
  }
}

export function useCreateEmployee() {
  const invalidate = useInvalidate()
  return useMutation<Employee, Error, EmployeeFormValues>({
    mutationFn: (values) => api.post<Employee>("/v1/employees", { ...values }),
    onSuccess: invalidate,
  })
}

export function useUpdateEmployee() {
  const invalidate = useInvalidate()
  return useMutation<Employee, Error, { id: string; values: Partial<EmployeeFormValues> }>({
    mutationFn: ({ id, values }) =>
      api.put<Employee>(`/v1/employees?id=${id}`, { ...values }),
    onSuccess: invalidate,
  })
}

/** EGRESO. No borra: escribe la fecha de salida y el legajo queda como historial. */
export function useTerminateEmployee() {
  const invalidate = useInvalidate()
  return useMutation<Employee, Error, { id: string; endDate: string; endReason?: string }>({
    mutationFn: ({ id, endDate, endReason }) =>
      api.post<Employee>(`/v1/employees?id=${id}&action=terminate`, { endDate, endReason }),
    onSuccess: invalidate,
  })
}

/** Archiva la fila (cargada por error). Para el que se fue está el egreso. */
export function useArchiveEmployee() {
  const invalidate = useInvalidate()
  return useMutation<{ archived: boolean }, Error, string>({
    mutationFn: (id) => api.del(`/v1/employees?id=${id}`),
    onSuccess: invalidate,
  })
}

// ── Adjuntos ───────────────────────────────────────────────────────────────

export function useEmployeeAttachments(employeeId: string | undefined) {
  return useQuery<EmployeeAttachment[]>({
    queryKey: [...KEY, "attachments", employeeId],
    queryFn: async () => {
      const data = await api.get<{ attachments: EmployeeAttachment[] }>(
        `/v1/employees?id=${employeeId}&resource=attachments`,
      )
      return data.attachments ?? []
    },
    enabled: !!employeeId,
    staleTime: 30 * 1000,
  })
}

export function useUploadEmployeeAttachment() {
  const qc = useQueryClient()
  return useMutation<
    { attachment: EmployeeAttachment },
    Error,
    { employeeId: string; file: File; label?: string }
  >({
    mutationFn: ({ employeeId, file, label }) => {
      const form = new FormData()
      form.append("file", file)
      if (label) form.append("label", label)
      return api.postForm(`/v1/employees?id=${employeeId}&resource=attachments`, form)
    },
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: [...KEY, "attachments", vars.employeeId] })
    },
  })
}

export function useDeleteEmployeeAttachment() {
  const qc = useQueryClient()
  return useMutation<{ deleted: boolean }, Error, { employeeId: string; attachmentId: string }>({
    mutationFn: ({ employeeId, attachmentId }) =>
      api.del(
        `/v1/employees?id=${employeeId}&resource=attachments&attachmentId=${attachmentId}`,
      ),
    onSuccess: (_, vars) => {
      qc.invalidateQueries({ queryKey: [...KEY, "attachments", vars.employeeId] })
    },
  })
}

/**
 * Baja un adjunto y dispara el "Guardar como" del navegador.
 *
 * Pasa por `api.getBlob` y no por un `<a href>`: los objetos son PRIVADOS en
 * S3, así que la descarga sale del endpoint, que exige el Bearer del panel —
 * un link crudo no puede adjuntar headers y volvería 401.
 */
export async function downloadEmployeeAttachment(
  attachment: EmployeeAttachment,
): Promise<void> {
  const blob = await api.getBlob(
    `/v1/employees?resource=attachment&attachmentId=${attachment.id}`,
  )
  const url = URL.createObjectURL(blob)
  const a = document.createElement("a")
  a.href = url
  a.download = attachment.filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
