import { describe, expect, it } from "vitest"

import { isValidSerie, normalizeSerie, sanitizeSerieInput } from "@/lib/documents/serie"
import { isSerieRejection, rejectionFix } from "@/lib/einvoice/rejection-fix"
import {
  invoiceSerieForRegister,
  invoiceSeriesForRegister,
  invoiceSeriesKey,
} from "@/lib/pos/invoice-series"
import type { PosRegister } from "@/lib/types/pos-bootstrap"

/**
 * Serie SIFEN (`dSerieNum`, mig 223) del lado del front: el campo del panel,
 * la identidad de la serie en el device y la traducción del rechazo 1110.
 */

describe("serie — formato", () => {
  it("vacía es válida (sin serie es el default)", () => {
    expect(isValidSerie("")).toBe(true)
    expect(isValidSerie(null)).toBe(true)
  })

  it("dos letras mayúsculas; normaliza minúsculas y espacios", () => {
    expect(isValidSerie("AA")).toBe(true)
    expect(normalizeSerie(" ab ")).toBe("AB")
    expect(isValidSerie("ab")).toBe(true)
  })

  it("rechaza una letra, tres letras o dígitos", () => {
    expect(isValidSerie("A")).toBe(false)
    expect(isValidSerie("AAA")).toBe(false)
    expect(isValidSerie("A1")).toBe(false)
  })

  it("el input deja solo letras, en mayúsculas, máximo dos", () => {
    expect(sanitizeSerieInput("a")).toBe("A")
    expect(sanitizeSerieInput("a1b")).toBe("AB")
    expect(sanitizeSerieInput("abc")).toBe("AB")
    expect(sanitizeSerieInput("  ")).toBe("")
  })
})

describe("invoiceSeriesKey — identidad de la serie en el device", () => {
  it("sin serie SIFEN la clave es la de antes de la mig 223 (no huérfana contadores offline)", () => {
    expect(invoiceSeriesKey("18260177", "001-001")).toBe("18260177|001-001")
    expect(invoiceSeriesKey("18260177", "001-001", "")).toBe("18260177|001-001")
    expect(invoiceSeriesKey("18260177", "001-001", null)).toBe("18260177|001-001")
  })

  it("con serie la clave es otra: configurar o cambiar la serie abre un contador nuevo", () => {
    const sinSerie = invoiceSeriesKey("18260177", "001-001")
    const aa = invoiceSeriesKey("18260177", "001-001", "AA")
    const ab = invoiceSeriesKey("18260177", "001-001", "AB")
    expect(aa).toBe("18260177|001-001|AA")
    expect(new Set([sinSerie, aa, ab]).size).toBe(3)
  })

  it("serie y clave salen de la misma caja; caja desconocida da null en los dos", () => {
    const registers: PosRegister[] = [
      { id: "r1", name: "Caja 1", outletId: "o1", expeditionPoint: "001-001", authNumber: "18260177", invoiceSerie: "aa" },
      { id: "r2", name: "Caja 2", outletId: "o1", expeditionPoint: "001-002", authNumber: "18260177", invoiceSerie: null },
    ]
    expect(invoiceSeriesForRegister(registers, "r1")).toBe("18260177|001-001|AA")
    expect(invoiceSerieForRegister(registers, "r1")).toBe("AA")
    expect(invoiceSeriesForRegister(registers, "r2")).toBe("18260177|001-002")
    expect(invoiceSerieForRegister(registers, "r2")).toBe("")
    expect(invoiceSeriesForRegister(registers, "r9")).toBeNull()
    expect(invoiceSerieForRegister(registers, "r9")).toBeNull()
  })
})

describe("rechazo 1110 — serie informada incorrecta", () => {
  it("se reconoce por código o por texto", () => {
    expect(isSerieRejection("1110 — Serie informada incorrecta")).toBe(true)
    expect(isSerieRejection("SIFEN: serie informada incorrecta")).toBe(true)
    expect(isSerieRejection("11100 — otra cosa")).toBe(false)
    expect(isSerieRejection("1002 — Documento duplicado")).toBe(false)
  })

  it("rutea a la caja con el mensaje para el comercio", () => {
    const fix = rejectionFix("1110 — Serie informada incorrecta")
    expect(fix.target).toBe("stamp")
    expect(fix.title).toBe(
      "La serie del punto de expedición no coincide con la registrada en SIFEN — configurala en la caja.",
    )
  })
})
