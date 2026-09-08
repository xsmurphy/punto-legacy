import { describe, expect, it } from "vitest"

import {
  DEFAULT_ESTABLISHMENT_GEO,
  emptyEstablishment,
  establishmentCodesFromRegisters,
  establishmentsForCodes,
} from "@/lib/einvoice/establecimientos"
import { SIFEN_TAX_REGIMES, taxRegimeLabel } from "@/lib/einvoice/tax-regimes"

/**
 * Alta del emisor — de dónde sale la lista de establecimientos y qué régimen
 * se le ofrece al comercio.
 *
 * Las dos cosas terminan DECLARADAS ante la autoridad tributaria en cada
 * documento que el comercio emita, así que lo que se fija acá no es la UI: es
 * que la lista salga de las cajas (única fuente de qué locales existen) y que
 * el catálogo de regímenes sean códigos del motor, no una lista tipeada.
 */

function register(invoicePrefix: string, status = true) {
  return { status, fiscal: { invoicePrefix } }
}

describe("establecimientos del alta", () => {
  it("deriva un establecimiento por EEE distinto de los puntos de expedición", () => {
    // Dos cajas del mismo local (001-001 y 001-002) son UN establecimiento:
    // lo que cambia por caja es el punto de expedición, no el local.
    expect(
      establishmentCodesFromRegisters([
        register("001-001"),
        register("001-002"),
        register("002-001"),
      ]),
    ).toEqual(["001", "002"])
  })

  it("ignora las cajas inactivas y las que no tienen punto de expedición", () => {
    // El backend lee los timbrados de las cajas ACTIVAS: pedirle al comercio
    // el domicilio de un local que ya no factura sería pedir un dato que el
    // alta no va a usar.
    expect(
      establishmentCodesFromRegisters([
        register("003-001", false),
        register(""),
        register("001-001"),
      ]),
    ).toEqual(["001"])
  })

  it("hidrata desde el espejo guardado y no pisa lo que el usuario está tipeando", () => {
    const guardado = { ...emptyEstablishment("001"), direccion: "Guardada", ciudad: 1 as const }
    const enCurso = { ...emptyEstablishment("002"), direccion: "Tipeando ahora" }

    const filas = establishmentsForCodes(["001", "002"], [guardado], [enCurso])

    expect(filas.map((f) => f.direccion)).toEqual(["Guardada", "Tipeando ahora"])
  })

  it("una caja nueva abre con la dirección en blanco, sin heredar la de otro local", () => {
    const filas = establishmentsForCodes(["001", "002"], [], [])
    expect(filas.map((f) => f.codigo)).toEqual(["001", "002"])
    expect(filas.every((f) => f.direccion === "" && f.numeroCasa === "")).toBe(true)
  })

  it("preselecciona Asunción en un establecimiento nuevo", () => {
    const [fila] = establishmentsForCodes(["001"], [], [])
    expect(fila).toMatchObject(DEFAULT_ESTABLISHMENT_GEO)
  })

  it("un domicilio ya declarado gana sobre la preselección", () => {
    const guardado = {
      ...emptyEstablishment("001"),
      departamento: 7 as const,
      departamentoDescripcion: "ITAPUA",
      distrito: 116,
      distritoDescripcion: "ENCARNACION",
      ciudad: 2711,
      ciudadDescripcion: "ENCARNACION",
    }

    const [fila] = establishmentsForCodes(["001"], [guardado], [])

    expect(fila.departamento).toBe(7)
    expect(fila.ciudadDescripcion).toBe("ENCARNACION")
  })

  it("un alta a medias, sin domicilio guardado, muestra la preselección y no tres campos vacíos", () => {
    // El vacío de un alta que quedó por la mitad NO es una declaración: si
    // pisara el default, el comercio vería la cascada en blanco justo donde
    // el alta se frenaba.
    const aMedias = {
      ...emptyEstablishment("001"),
      departamento: "" as const,
      departamentoDescripcion: "",
      ciudad: "" as const,
      ciudadDescripcion: "",
    }

    const [fila] = establishmentsForCodes(["001"], [aMedias], [])

    expect(fila).toMatchObject(DEFAULT_ESTABLISHMENT_GEO)
  })
})

describe("catálogo de regímenes tributarios", () => {
  it("son códigos únicos dentro del rango que acepta el motor (1-15)", () => {
    const codes = SIFEN_TAX_REGIMES.map((r) => r.code)
    expect(new Set(codes).size).toBe(codes.length)
    expect(codes.every((c) => Number.isInteger(c) && c >= 1 && c <= 15)).toBe(true)
  })

  it("no hay régimen por default: sin elección no hay etiqueta que mostrar", () => {
    expect(taxRegimeLabel(undefined)).toBeNull()
    expect(taxRegimeLabel(8)).toBe("Régimen Contable")
  })
})
