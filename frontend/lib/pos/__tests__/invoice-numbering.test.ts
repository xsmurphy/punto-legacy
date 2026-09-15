import { beforeEach, describe, expect, it, vi } from "vitest"

/**
 * `localStorage` en memoria: el entorno de vitest es `node` y no lo trae.
 * Mismo patrón que `context-reset.test.ts`, y se instala ANTES de importar el
 * módulo porque sus helpers lo tocan al primer uso.
 */
const store = new Map<string, string>()
vi.stubGlobal("localStorage", {
  getItem: (k: string) => store.get(k) ?? null,
  setItem: (k: string, v: string) => void store.set(k, v),
  removeItem: (k: string) => void store.delete(k),
  clear: () => store.clear(),
  key: (i: number) => Array.from(store.keys())[i] ?? null,
  get length() {
    return store.size
  },
})

const { getNextInvoiceNo, primeInvoiceNumbering } = await import(
  "@/lib/pos/invoice-numbering"
)
const { invoiceSeriesKey } = await import("@/lib/pos/invoice-series")

/**
 * El contador de comprobantes del POS — la pieza que ASIGNA el número.
 *
 * No tenía tests, y es donde un error reemite un número ya usado: el peor
 * error posible del sistema. Se agregan al cerrar el trabajo de series
 * (2026-09-09), con foco en las dos reglas que se contradicen entre sí si se
 * las mira sin la serie puesta.
 */

const REGISTER = "reg-1"
const SERIE_A = "12345678:001-001"
const SERIE_B = "12345678:001-002"

beforeEach(() => {
  localStorage.clear()
})

describe("primeInvoiceNumbering", () => {
  it("siembra el número del servidor cuando el device no sabe nada", () => {
    primeInvoiceNumbering(REGISTER, SERIE_A, 615)
    expect(getNextInvoiceNo(REGISTER, SERIE_A)).toBe(615)
  })

  it("DENTRO de la misma serie, el device adelantado manda sobre el servidor", () => {
    // Ventas emitidas offline que el servidor todavía no vio: bajar el
    // contador reemitiría números que este mismo device ya imprimió.
    primeInvoiceNumbering(REGISTER, SERIE_A, 700)
    expect(getNextInvoiceNo(REGISTER, SERIE_A)).toBe(700) // consume 700, queda 701
    primeInvoiceNumbering(REGISTER, SERIE_A, 650)
    expect(getNextInvoiceNo(REGISTER, SERIE_A)).toBe(701)
  })

  it("una serie NUEVA arranca donde dice el servidor, sin heredar el contador de la otra", () => {
    // El incidente que motivó todo esto: el device tenía 839 de la serie
    // `001-001` y el servidor decía 615 para `001-002`. Heredar mandaba el
    // 839 contra un punto de expedición que iba por 614.
    primeInvoiceNumbering(REGISTER, SERIE_A, 839)
    primeInvoiceNumbering(REGISTER, SERIE_B, 615)
    expect(getNextInvoiceNo(REGISTER, SERIE_B)).toBe(615)
  })

  it("el contador de la clave VIEJA (sin serie) se descarta, no se adopta", () => {
    // La clave anterior no guardaba serie, así que su valor no se puede
    // atribuir a ninguna: adoptarlo como piso resucitaba el incidente.
    localStorage.setItem(`pos_invoice_next_no:${REGISTER}`, "839")

    primeInvoiceNumbering(REGISTER, SERIE_B, 615)

    expect(getNextInvoiceNo(REGISTER, SERIE_B)).toBe(615)
    expect(localStorage.getItem(`pos_invoice_next_no:${REGISTER}`)).toBeNull()
  })

  it("cada serie lleva su propio contador en paralelo", () => {
    primeInvoiceNumbering(REGISTER, SERIE_A, 100)
    primeInvoiceNumbering(REGISTER, SERIE_B, 1)

    expect(getNextInvoiceNo(REGISTER, SERIE_A)).toBe(100)
    expect(getNextInvoiceNo(REGISTER, SERIE_B)).toBe(1)
    expect(getNextInvoiceNo(REGISTER, SERIE_A)).toBe(101)
  })

  it("serie SIFEN recién configurada: el contador SIN serie del mismo punto es el piso (mig 223)", () => {
    // El device venía emitiendo offline en 18260177|001-001 sin serie y va por
    // 845; el servidor todavía no lo vio y abre la serie AA en 841. Sembrar AA
    // en 841 volvería a usar 841-844 en el mismo punto de expedición.
    const sinSerie = invoiceSeriesKey("18260177", "001-001")
    const conSerie = invoiceSeriesKey("18260177", "001-001", "AA")
    primeInvoiceNumbering(REGISTER, sinSerie, 845)

    primeInvoiceNumbering(REGISTER, conSerie, 841)

    expect(getNextInvoiceNo(REGISTER, conSerie)).toBe(845)
  })

  it("el piso NO se toma entre dos series con serie (AA → AB reinicia)", () => {
    const aa = invoiceSeriesKey("18260177", "001-001", "AA")
    const ab = invoiceSeriesKey("18260177", "001-001", "AB")
    primeInvoiceNumbering(REGISTER, aa, 9_000_000)

    primeInvoiceNumbering(REGISTER, ab, 1)

    expect(getNextInvoiceNo(REGISTER, ab)).toBe(1)
  })

  it("ignora un número de servidor inválido en vez de romper el contador", () => {
    primeInvoiceNumbering(REGISTER, SERIE_A, 615)
    primeInvoiceNumbering(REGISTER, SERIE_A, 0)
    primeInvoiceNumbering(REGISTER, SERIE_A, null)
    expect(getNextInvoiceNo(REGISTER, SERIE_A)).toBe(615)
  })
})

describe("getNextInvoiceNo", () => {
  it("persiste el siguiente en el acto — un reload a mitad de cobro no reemite", () => {
    primeInvoiceNumbering(REGISTER, SERIE_A, 615)
    expect(getNextInvoiceNo(REGISTER, SERIE_A)).toBe(615)
    // Simula el reload: el módulo vuelve a leer de localStorage.
    expect(getNextInvoiceNo(REGISTER, SERIE_A)).toBe(616)
  })

  it("corta si el device nunca conoció un número para esa serie", () => {
    expect(() => getNextInvoiceNo(REGISTER, SERIE_A)).toThrow()
  })
})
