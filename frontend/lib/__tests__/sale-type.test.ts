import { describe, expect, it } from "vitest"
import fs from "node:fs"
import path from "node:path"

import {
  REPORT_DETAIL_SALE_TYPES,
  SALE_TYPE_LABELS,
  SaleType,
  isCashSale,
  isCreditSale,
  isEditableSale,
  isInvoicedSale,
  isPurchase,
  isQuote,
  isReceipt,
  isReturn,
  isVoided,
  saleTypeLabel,
  saleTypeLabelOrNull,
  toSaleType,
} from "@/lib/domain/sale-type"

/**
 * Paridad con el enum PHP, que es la fuente de verdad del dominio.
 *
 * Este test es la razón de ser del slice. El vocabulario de tipos de
 * transacción ya estaba en tres lugares (el enum PHP, un mapa parcial dentro de
 * un componente de UI y enteros mágicos sueltos) y las tres copias divergieron:
 * el mapa cubría 9 de los 15 casos. Unificar en `lib/domain/sale-type.ts` sin
 * esto solo crea una cuarta copia que se desincroniza igual la próxima vez que
 * alguien agregue un `case` del lado PHP.
 *
 * Por eso lee el archivo del filesystem en vez de fijar una lista a mano:
 * agregar un tipo en PHP tiene que romper acá.
 */

const PHP_ENUM_PATH = path.resolve(
  import.meta.dirname,
  "../../../api/lib/Sales/SaleType.php",
)

/** `case Cashsale = 0;` → `{ name: "Cashsale", value: 0 }`. */
function parsePhpEnumCases(source: string): { name: string; value: number }[] {
  const out: { name: string; value: number }[] = []
  const re = /^\s*case\s+(\w+)\s*=\s*(\d+)\s*;/gm
  let m: RegExpExecArray | null
  while ((m = re.exec(source)) !== null) {
    out.push({ name: m[1], value: Number(m[2]) })
  }
  return out
}

describe("paridad con api/lib/Sales/SaleType.php", () => {
  it("encuentra el enum PHP y le parsea los casos", () => {
    expect(
      fs.existsSync(PHP_ENUM_PATH),
      `No se encontró el enum PHP en ${PHP_ENUM_PATH}. Es la fuente de verdad: ` +
        "si se movió, hay que actualizar esta ruta, no borrar el test.",
    ).toBe(true)
    expect(parsePhpEnumCases(fs.readFileSync(PHP_ENUM_PATH, "utf8")).length).toBeGreaterThan(0)
  })

  it("cubre todos los casos del enum PHP, con el mismo valor y etiqueta", () => {
    const cases = parsePhpEnumCases(fs.readFileSync(PHP_ENUM_PATH, "utf8"))

    const faltantes = cases.filter(({ name }) => !(name in SaleType))
    expect(
      faltantes.map((c) => `${c.name} = ${c.value}`),
      "El enum PHP tiene casos que `lib/domain/sale-type.ts` no cubre",
    ).toEqual([])

    for (const { name, value } of cases) {
      expect(SaleType[name as keyof typeof SaleType], `SaleType.${name}`).toBe(value)
      expect(SALE_TYPE_LABELS[value as SaleType], `label de ${name} (${value})`).toBeTruthy()
    }
  })

  it("no inventa tipos que el enum PHP no tiene", () => {
    const phpValues = new Set(
      parsePhpEnumCases(fs.readFileSync(PHP_ENUM_PATH, "utf8")).map((c) => c.value),
    )
    const sobrantes = Object.values(SaleType).filter((v) => !phpValues.has(v))
    expect(sobrantes, "Tipos en TS que no existen del lado PHP").toEqual([])
  })
})

describe("etiquetas", () => {
  /**
   * Los 9 valores que ya estaban en producción dentro de `TX_TYPE_LABELS`. Este
   * test los fija: el refactor no podía mover ninguna etiqueta que el usuario
   * ya ve.
   */
  it("conserva exactas las etiquetas que venían de TX_TYPE_LABELS", () => {
    expect(SALE_TYPE_LABELS[0]).toBe("Contado")
    expect(SALE_TYPE_LABELS[2]).toBe("Guardado")
    expect(SALE_TYPE_LABELS[3]).toBe("Crédito")
    expect(SALE_TYPE_LABELS[5]).toBe("Recibo")
    expect(SALE_TYPE_LABELS[6]).toBe("Devolución")
    expect(SALE_TYPE_LABELS[7]).toBe("Anulada")
    expect(SALE_TYPE_LABELS[9]).toBe("Cotización")
    expect(SALE_TYPE_LABELS[12]).toBe("Mesa")
    expect(SALE_TYPE_LABELS[13]).toBe("Cita")
  })

  it("distingue la mesa abierta (11) de la orden etiquetada 'Mesa' (12)", () => {
    expect(SALE_TYPE_LABELS[11]).not.toBe(SALE_TYPE_LABELS[12])
  })

  it("no repite etiquetas entre tipos", () => {
    const labels = Object.values(SALE_TYPE_LABELS)
    expect(new Set(labels).size).toBe(labels.length)
  })

  it("acepta number y string — varios call-sites vienen de estado de UI", () => {
    expect(saleTypeLabel(3)).toBe("Crédito")
    expect(saleTypeLabel("3")).toBe("Crédito")
  })

  it("cae al fallback `Tipo N` con un tipo desconocido", () => {
    expect(saleTypeLabel(99)).toBe("Tipo 99")
    expect(saleTypeLabelOrNull(99)).toBeNull()
    expect(saleTypeLabelOrNull(0)).toBe("Contado")
  })

  it("normaliza a null lo que no es un tipo del dominio", () => {
    expect(toSaleType(null)).toBeNull()
    expect(toSaleType(undefined)).toBeNull()
    expect(toSaleType("")).toBeNull()
    expect(toSaleType("abc")).toBeNull()
    expect(toSaleType(1.5)).toBeNull()
    expect(toSaleType("9")).toBe(SaleType.Quote)
  })
})

describe("predicados", () => {
  it("identifican cada tipo por su número", () => {
    expect(isCashSale(0)).toBe(true)
    expect(isCreditSale(3)).toBe(true)
    expect(isReceipt(5)).toBe(true)
    expect(isReturn(6)).toBe(true)
    expect(isVoided(7)).toBe(true)
    expect(isQuote(9)).toBe(true)
  })

  it("no matchean tipos vecinos ni valores vacíos", () => {
    expect(isCreditSale(0)).toBe(false)
    expect(isQuote(12)).toBe(false)
    expect(isVoided(6)).toBe(false)
    expect(isReceipt(null)).toBe(false)
    expect(isCashSale(undefined)).toBe(false)
  })

  it("isInvoicedSale es contado o crédito, y nada más", () => {
    expect(isInvoicedSale(0)).toBe(true)
    expect(isInvoicedSale(3)).toBe(true)
    expect(isInvoicedSale(9)).toBe(false)
    expect(isInvoicedSale(1)).toBe(false)
  })

  it("isPurchase es compra contado o crédito, no la venta homónima", () => {
    expect(isPurchase(1)).toBe(true)
    expect(isPurchase(4)).toBe(true)
    expect(isPurchase(0)).toBe(false)
    expect(isPurchase(3)).toBe(false)
  })

  it("isEditableSale cubre contado, crédito y cotización — el estado va aparte", () => {
    expect(isEditableSale(0)).toBe(true)
    expect(isEditableSale(3)).toBe(true)
    expect(isEditableSale(9)).toBe(true)
    expect(isEditableSale(7)).toBe(false)
    expect(isEditableSale(6)).toBe(false)
  })
})

describe("REPORT_DETAIL_SALE_TYPES", () => {
  it("es el universo de TransactionsService::TX_TYPES ('0,3,6,7,8')", () => {
    expect(REPORT_DETAIL_SALE_TYPES).toEqual([0, 3, 6, 7, 8])
  })

  it("todos tienen etiqueta — el filtro los ofrece por nombre", () => {
    for (const t of REPORT_DETAIL_SALE_TYPES) {
      expect(saleTypeLabelOrNull(t), `tipo ${t}`).toBeTruthy()
    }
  })
})
