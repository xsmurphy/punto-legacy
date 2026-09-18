/**
 * El formulario ÚNICO de una persona del comercio: sus datos, su acceso y su
 * legajo (pedido del owner — "una sola pantalla de edición").
 *
 * Acá vive solo lo que no es React: el esquema, los valores y la traducción de
 * esos valores a los dos payloads que salen al backend. El JSX está en
 * `components/employees/person-form.tsx`.
 *
 * ── Por qué DOS writes y no uno ─────────────────────────────────────────────
 *
 * El formulario es uno; los dueños del dato son dos, y son dos a propósito:
 *
 *   - El USUARIO (`/v1/users`) — nombre, teléfono, email, rol, sucursales,
 *     código de caja, estado. Gate `contacts.user.manage`, y encima de ese gate
 *     tres guardas de escalación de privilegios que viven en ese PUT: no podés
 *     cambiar tu propio rol, no podés editar a alguien de rol superior, no
 *     podés asignar un rol superior al tuyo.
 *   - El LEGAJO (`/v1/employees`) — documento, dirección, nacimiento, puesto,
 *     ingreso, remuneración, horario, rostro. Gate `hr.employees.manage`.
 *
 * Meter los campos del usuario en el update del legajo haría que
 * `hr.employees.manage` alcance para cambiar un ROL y un PIN — o sea, una
 * segunda puerta a la mutación de credenciales, sin las guardas de la primera.
 * Es exactamente la clase de puerta que documenta `RoleEscalation`. Además una
 * persona SIN legajo no tiene fila en `employee`: sus datos no tendrían por
 * dónde entrar.
 *
 * Así que el submit es uno y los writes son dos, secuenciales, cada uno con su
 * gate. Si uno falla y el otro no, el formulario NO se resetea: se avisa qué
 * quedó sin guardar y lo tipeado sigue en pantalla.
 */

import { z } from "zod"

import type {
  EmployeeFormValues,
  EmployeeSchedule,
  FixedPeriod,
} from "@/hooks/use-employees"
import type { TeamMemberFormValues } from "@/hooks/use-team"

/**
 * Centinela de "sin selección": `<SelectItem>` de shadcn no admite value=""
 * (Radix lo usa para limpiar). Se traduce a `null` al salir.
 */
export const NONE = "__none__"

export const PERIOD_LABEL: Record<FixedPeriod, string> = {
  monthly: "Por mes",
  biweekly: "Por quincena",
  weekly: "Por semana",
}

export interface PersonFormValues {
  // ── Identidad ──
  /** Del usuario. Es el único nombre que tiene la persona (mig 233). */
  name: string
  /** Del legajo. */
  documentNumber: string
  /** Del usuario. E.164 sin '+' al guardar; nacional en pantalla. */
  phone: string
  /** Del usuario. */
  email: string
  /** Del legajo. */
  address: string
  /** Del legajo. */
  birthDate: string
  /** Del usuario: el color de la agenda y del avatar. */
  color: string

  // ── Acceso ──
  roleId: string
  /** Vacío en la edición = no se cambia. */
  password: string
  lockPass: string
  /** El ÚNICO selector de sucursal de la ficha. Vacío = todas. */
  outletIds: string[]
  active: boolean
  inCalendar: boolean

  // ── Trabajo ──
  jobTitle: string
  hireDate: string
  notes: string

  // ── Remuneración ──
  fixedAmount: number | null
  fixedPeriod: string
  hourlyRate: number | null
  commissions: boolean

  // ── Pestañas propias de la ficha (viajan en el mismo submit) ──
  schedule: EmployeeSchedule | null
  biometricConsent: boolean
}

export const EMPTY_PERSON: PersonFormValues = {
  name: "",
  documentNumber: "",
  phone: "",
  email: "",
  address: "",
  birthDate: "",
  color: "",
  roleId: NONE,
  password: "",
  lockPass: "",
  outletIds: [],
  active: true,
  inCalendar: false,
  jobTitle: "",
  hireDate: "",
  notes: "",
  fixedAmount: null,
  fixedPeriod: "",
  hourlyRate: null,
  commissions: false,
  schedule: null,
  biometricConsent: false,
}

/**
 * ¿Los campos del legajo tienen algo cargado?
 *
 * Decide si una persona que todavía NO tiene legajo se lleva uno al guardar.
 * Sin esto habría que pedirle la fecha de ingreso a cualquiera que solo viene a
 * cambiar un teléfono.
 */
export function hasLegajoData(v: PersonFormValues): boolean {
  return (
    v.documentNumber.trim() !== "" ||
    v.address.trim() !== "" ||
    v.birthDate !== "" ||
    v.jobTitle.trim() !== "" ||
    v.notes.trim() !== "" ||
    v.hireDate !== "" ||
    v.fixedAmount !== null ||
    v.hourlyRate !== null ||
    v.commissions ||
    v.schedule !== null ||
    v.biometricConsent
  )
}

/**
 * La sucursal del LEGAJO sale de las sucursales del usuario — el selector es
 * uno solo (pedido del owner).
 *
 * Dos reglas, y la segunda existe para no borrar un dato de callado:
 *
 *   1. Si la persona tiene sucursales asignadas, la del legajo es una de ellas:
 *      se conserva la que ya tenía si sigue en la lista, si no la primera.
 *   2. Si no tiene ninguna —que en `contact_outlet` significa acceso a TODAS
 *      (context/25)— el legajo conserva la suya: "puede entrar a todas" no es
 *      "no trabaja en ninguna".
 */
export function deriveLegajoOutletId(
  outletIds: string[],
  current: string | null,
): string | null {
  if (outletIds.length === 0) return current
  if (current !== null && outletIds.includes(current)) return current
  return outletIds[0] ?? null
}

/** Lo que entiende `/v1/users`. */
export function toUserPayload(v: PersonFormValues): TeamMemberFormValues {
  return {
    name: v.name.trim(),
    email: v.email.trim(),
    phone: v.phone,
    password: v.password,
    roleId: v.roleId,
    outletIds: v.outletIds,
    lockPass: v.lockPass,
    inCalendar: v.inCalendar,
    color: v.color,
    status: v.active ? "1" : "0",
  }
}

/**
 * Lo que entiende `/v1/employees`.
 *
 * El nombre, el teléfono y el email NO viajan acá ni siquiera en el alta: los
 * escribe el write del usuario, que es su dueño. Mandarlos por las dos puertas
 * sería tener dos lugares donde se cambia un nombre, que es justo lo que este
 * formulario vino a terminar.
 */
export function toEmployeePayload(
  v: PersonFormValues,
  currentOutletId: string | null,
): EmployeeFormValues {
  return {
    documentNumber: v.documentNumber.trim() || null,
    address: v.address.trim() || null,
    birthDate: v.birthDate || null,
    jobTitle: v.jobTitle.trim() || null,
    hireDate: v.hireDate,
    outletId: deriveLegajoOutletId(v.outletIds, currentOutletId),
    fixedAmount: v.fixedAmount,
    fixedPeriod: v.fixedAmount === null ? null : (v.fixedPeriod as FixedPeriod),
    hourlyRate: v.hourlyRate,
    commissions: v.commissions,
    notes: v.notes.trim() || null,
    schedule: v.schedule,
    biometricConsent: v.biometricConsent,
  }
}

const scheduleSchema = z.custom<EmployeeSchedule | null>(() => true)

/**
 * El esquema del formulario.
 *
 * `hasLegajo` decide si la fecha de ingreso es obligatoria siempre (la persona
 * ya tiene legajo) o solo cuando se está cargando uno. `isCreate` hace lo mismo
 * con la contraseña.
 */
export function personSchema(opts: { hasLegajo: boolean; isCreate: boolean }) {
  return z
    .object({
      name: z.string().min(1, "El nombre es obligatorio"),
      documentNumber: z.string(),
      phone: z.string(),
      email: z.union([z.string().email("Email inválido"), z.literal("")]),
      address: z.string(),
      birthDate: z.string(),
      color: z.string(),
      roleId: z.string(),
      password: z.string(),
      lockPass: z.string().refine((s) => s === "" || /^\d{4}$/.test(s), {
        message: "El código de caja tiene 4 dígitos",
      }),
      outletIds: z.array(z.string()),
      active: z.boolean(),
      inCalendar: z.boolean(),
      jobTitle: z.string(),
      hireDate: z.string(),
      notes: z.string(),
      fixedAmount: z.number().nullable(),
      fixedPeriod: z.string(),
      hourlyRate: z.number().nullable(),
      commissions: z.boolean(),
      schedule: scheduleSchema,
      biometricConsent: z.boolean(),
    })
    .superRefine((v, ctx) => {
      if (opts.isCreate && v.password.trim() === "") {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["password"],
          message: "La contraseña es obligatoria",
        })
      }
      if ((opts.hasLegajo || hasLegajoData(v)) && v.hireDate === "") {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["hireDate"],
          message: "La fecha de ingreso es requerida",
        })
      }
      if (v.fixedAmount !== null && v.fixedPeriod === "") {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ["fixedPeriod"],
          message: "Elegí cada cuánto se paga",
        })
      }
    })
}
