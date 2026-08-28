import { describe, it, expect } from "vitest"

import { formatPhone, formatPhoneForTenant } from "../phone"

/**
 * La BD guarda E.164 SIN el '+' (`feedback_phone_storage_no_plus`), y eso a
 * libphonenumber le resulta indistinguible de un número nacional de un país
 * desconocido: tiraba INVALID_COUNTRY y el helper devolvía el crudo. Por eso
 * todos los listados mostraban "595991742353" (reporte del owner 2026-08-28).
 */

describe("formatPhone", () => {
  it("formatea lo que hay GUARDADO en la BD (E.164 sin '+')", () => {
    expect(formatPhone("595991742353")).toBe("0991 742353")
  })

  it("formatea E.164 canónico", () => {
    expect(formatPhone("+595991742353")).toBe("0991 742353")
  })

  it("un guardado de otro país sale en SU formato nacional, no en el del tenant", () => {
    // Con el país del tenant como interpretación, este número no parseaba y
    // salía crudo. El prefijo del propio número manda.
    expect(formatPhone("5491123456789", "PY")).toBe("011 15-2345-6789")
  })

  it("con país de referencia formatea lo que tipea una persona", () => {
    expect(formatPhone("0991742353", "PY")).toBe("0991 742353")
  })

  it("sin país de referencia, un nacional suelto se devuelve tal cual", () => {
    // No hay forma de saber de qué país es: inventarlo sería peor.
    expect(formatPhone("0991742353")).toBe("0991742353")
  })

  it("no rompe con vacío, parcial o basura", () => {
    expect(formatPhone("")).toBe("")
    expect(formatPhone(null)).toBe("")
    expect(formatPhone(undefined)).toBe("")
    expect(formatPhone("099")).toBe("099")
    expect(formatPhone("no es un teléfono")).toBe("no es un teléfono")
  })

  it("un número inválido con largo de E.164 no se disfraza de válido", () => {
    // 15 dígitos arrancando en 9: pasa el regex de candidato pero no es un
    // número real. `isValid()` lo frena y sale crudo.
    expect(formatPhone("999999999999999")).toBe("999999999999999")
  })
})

describe("formatPhoneForTenant", () => {
  it("toma el país del bootstrap del tenant", () => {
    expect(formatPhoneForTenant("0991742353", { country: "PY" })).toBe("0991 742353")
  })

  it("sin config, el guardado igual sale nacional por su propio prefijo", () => {
    expect(formatPhoneForTenant("595991742353", null)).toBe("0991 742353")
  })
})
