import { describe, expect, it } from "vitest"

import {
  EMPTY_PERSON,
  NONE,
  deriveLegajoOutletId,
  hasLegajoData,
  personSchema,
  toEmployeePayload,
  toUserPayload,
  type PersonFormValues,
} from "@/lib/employees/person-form"

const A = "outlet-a"
const B = "outlet-b"

function values(patch: Partial<PersonFormValues> = {}): PersonFormValues {
  return { ...EMPTY_PERSON, name: "Ana García", ...patch }
}

describe("deriveLegajoOutletId — un solo selector de sucursal", () => {
  it("con una sola sucursal asignada, esa es la del trabajo", () => {
    expect(deriveLegajoOutletId([A], null)).toBe(A)
    expect(deriveLegajoOutletId([A], B)).toBe(A)
  })

  it("con varias, conserva la que ya tenía si sigue en la lista", () => {
    expect(deriveLegajoOutletId([A, B], B)).toBe(B)
  })

  it("con varias, cae en la primera si la que tenía ya no está", () => {
    expect(deriveLegajoOutletId([A, B], "outlet-viejo")).toBe(A)
  })

  it("sin sucursales asignadas (acceso a todas) NO borra la que tenía", () => {
    // El caso que cuesta caro: `contact_outlet` vacío significa acceso global,
    // no "no trabaja en ninguna". Devolver null acá borraría un dato de callado.
    expect(deriveLegajoOutletId([], B)).toBe(B)
    expect(deriveLegajoOutletId([], null)).toBeNull()
  })
})

describe("hasLegajoData — cuándo nace la fila de trabajo", () => {
  it("un formulario que solo toca el usuario no la crea", () => {
    expect(hasLegajoData(values({ phone: "595991742353", roleId: "r1" }))).toBe(false)
  })

  it("cualquier dato laboral la crea", () => {
    expect(hasLegajoData(values({ jobTitle: "Cajera" }))).toBe(true)
    expect(hasLegajoData(values({ fixedAmount: 2_500_000 }))).toBe(true)
    expect(hasLegajoData(values({ commissions: true }))).toBe(true)
    expect(hasLegajoData(values({ documentNumber: "1234567" }))).toBe(true)
    expect(hasLegajoData(values({ biometricConsent: true }))).toBe(true)
  })

  it("no se confunde con espacios en blanco", () => {
    expect(hasLegajoData(values({ jobTitle: "   ", notes: " " }))).toBe(false)
  })
})

describe("payloads — cada dato sale por la puerta de su dueño", () => {
  it("el usuario se lleva nombre, acceso y sucursales", () => {
    const p = toUserPayload(
      values({ name: " Ana ", roleId: "r1", outletIds: [A], lockPass: "1234", active: false }),
    )
    expect(p.name).toBe("Ana")
    expect(p.roleId).toBe("r1")
    expect(p.outletIds).toEqual([A])
    expect(p.lockPass).toBe("1234")
    expect(p.status).toBe("0")
  })

  it("el trabajo NO reenvía el nombre ni el teléfono", () => {
    const p = toEmployeePayload(
      values({ name: "Ana", phone: "595991742353", jobTitle: "Cajera", hireDate: "2026-01-02" }),
      null,
    ) as unknown as Record<string, unknown>
    expect(p.jobTitle).toBe("Cajera")
    expect("fullName" in p).toBe(false)
    expect("phone" in p).toBe(false)
    expect("email" in p).toBe(false)
  })

  it("el monto fijo sin período no viaja con un período inventado", () => {
    const p = toEmployeePayload(values({ fixedAmount: null, fixedPeriod: "monthly" }), null)
    expect(p.fixedPeriod).toBeNull()
  })

  it("la sucursal del trabajo sale del selector único", () => {
    const p = toEmployeePayload(values({ outletIds: [A, B] }), B)
    expect(p.outletId).toBe(B)
  })
})

describe("personSchema", () => {
  const ok = (r: { success: boolean }) => r.success

  it("el alta exige contraseña; la edición no", () => {
    const v = values({ password: "" })
    expect(ok(personSchema({ hasLegajo: false, isCreate: true }).safeParse(v))).toBe(false)
    expect(ok(personSchema({ hasLegajo: false, isCreate: false }).safeParse(v))).toBe(true)
  })

  it("sin datos laborales no pide fecha de ingreso", () => {
    const r = personSchema({ hasLegajo: false, isCreate: false }).safeParse(values())
    expect(ok(r)).toBe(true)
  })

  it("con un dato laboral cargado, la fecha de ingreso pasa a ser obligatoria", () => {
    const r = personSchema({ hasLegajo: false, isCreate: false }).safeParse(
      values({ jobTitle: "Cajera" }),
    )
    expect(ok(r)).toBe(false)
  })

  it("quien ya tiene datos de trabajo siempre necesita la fecha de ingreso", () => {
    const r = personSchema({ hasLegajo: true, isCreate: false }).safeParse(values())
    expect(ok(r)).toBe(false)
  })

  it("el monto fijo exige su periodicidad", () => {
    const base = { hasLegajo: false, isCreate: false }
    expect(
      ok(personSchema(base).safeParse(values({ fixedAmount: 1000, hireDate: "2026-01-02" }))),
    ).toBe(false)
    expect(
      ok(
        personSchema(base).safeParse(
          values({ fixedAmount: 1000, fixedPeriod: "monthly", hireDate: "2026-01-02" }),
        ),
      ),
    ).toBe(true)
  })

  it("el código de caja es de 4 dígitos o nada", () => {
    const base = { hasLegajo: false, isCreate: false }
    expect(ok(personSchema(base).safeParse(values({ lockPass: "12" })))).toBe(false)
    expect(ok(personSchema(base).safeParse(values({ lockPass: "1234" })))).toBe(true)
    expect(ok(personSchema(base).safeParse(values({ lockPass: "" })))).toBe(true)
  })

  it("el nombre es obligatorio y el rol puede quedar sin asignar", () => {
    const base = { hasLegajo: false, isCreate: false }
    expect(ok(personSchema(base).safeParse(values({ name: "" })))).toBe(false)
    expect(ok(personSchema(base).safeParse(values({ roleId: NONE })))).toBe(true)
  })
})
