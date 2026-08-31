import { describe, expect, it } from "vitest"

import {
  buildComparison,
  buildDelta,
  comparisonRange,
  extractMetrics,
} from "@/lib/agent/period-comparison"

/**
 * El motor de comparación entre períodos.
 *
 * Lo que se cubre acá no es "que compile": son las tres formas concretas en que
 * una comparativa puede mentir sin que se note.
 *
 *  1. El rango comparado mal calculado. Un agosto que se compara contra el 2 de
 *     julio al 1 de agosto da un número perfectamente creíble y equivocado.
 *  2. El porcentaje sobre una base que no admite porcentaje (0 o negativa).
 *  3. La suma de un campo que no se puede sumar entre filas.
 */

describe("comparisonRange — previous_period", () => {
  it("un mes completo compara contra el mes anterior completo", () => {
    expect(comparisonRange({ from: "2026-08-01", to: "2026-08-31" }, "previous_period")).toEqual({
      from: "2026-07-01",
      to: "2026-07-31",
    })
  })

  /**
   * El caso que hace que NO se reuse `NonAddingSales::previousPeriod()`
   * (`api/lib/Reports/NonAddingSales.php:205-214`): ese helper desplaza por
   * duración en SEGUNDOS y, con fechas sin hora, para agosto devuelve
   * 2026-07-02 23:59 a 2026-08-01 23:59. Ni el mes anterior ni una ventana
   * alineada a nada.
   */
  it("el largo es inclusivo: la ventana anterior mide los mismos días", () => {
    // Un solo día compara contra el día anterior, no contra sí mismo.
    expect(comparisonRange({ from: "2026-08-15", to: "2026-08-15" }, "previous_period")).toEqual({
      from: "2026-08-14",
      to: "2026-08-14",
    })
    // Siete días (lunes a domingo) contra los siete anteriores.
    expect(comparisonRange({ from: "2026-08-10", to: "2026-08-16" }, "previous_period")).toEqual({
      from: "2026-08-03",
      to: "2026-08-09",
    })
  })

  it("cruza el año sin romperse", () => {
    expect(comparisonRange({ from: "2026-01-01", to: "2026-01-31" }, "previous_period")).toEqual({
      from: "2025-12-01",
      to: "2025-12-31",
    })
  })

  it("meses de distinto largo: la ventana anterior mide lo mismo que la pedida", () => {
    // Marzo tiene 31 días; los 31 días anteriores arrancan el 29 de enero, no el
    // 1 de febrero. Es correcto: previous_period compara DURACIONES iguales, y
    // quien quiera "febrero" pide febrero.
    expect(comparisonRange({ from: "2026-03-01", to: "2026-03-31" }, "previous_period")).toEqual({
      from: "2026-01-29",
      to: "2026-02-28",
    })
  })
})

describe("comparisonRange — previous_year", () => {
  it("es el mismo rango calendario del año anterior", () => {
    expect(comparisonRange({ from: "2026-08-01", to: "2026-08-31" }, "previous_year")).toEqual({
      from: "2025-08-01",
      to: "2025-08-31",
    })
  })

  /**
   * El borde bisiesto. 2024-02-29 no existe en 2023: el día se recorta al último
   * de febrero en vez de desbordar a marzo. Así "todo febrero de 2024" compara
   * contra "todo febrero de 2023", cada uno entero, que es lo que un comerciante
   * quiere decir. Desbordar dejaría el rango fuera de febrero.
   */
  it("recorta el 29 de febrero al 28 en un año no bisiesto", () => {
    expect(comparisonRange({ from: "2024-02-01", to: "2024-02-29" }, "previous_year")).toEqual({
      from: "2023-02-01",
      to: "2023-02-28",
    })
  })

  it("el recorte es solo para el día que no existe: el resto pasa igual", () => {
    // El 1 de marzo existe en los dos años y no se toca. Vale la aclaración: un
    // 29 de febrero nunca cae en un año bisiesto anterior —los bisiestos van de
    // a cuatro— así que el recorte es el único desenlace posible para ese día.
    expect(comparisonRange({ from: "2024-03-01", to: "2024-03-01" }, "previous_year")).toEqual({
      from: "2023-03-01",
      to: "2023-03-01",
    })
    expect(comparisonRange({ from: "2024-01-31", to: "2024-12-31" }, "previous_year")).toEqual({
      from: "2023-01-31",
      to: "2023-12-31",
    })
  })

  it("un rango de varios meses conserva los dos extremos", () => {
    expect(comparisonRange({ from: "2026-01-15", to: "2026-06-30" }, "previous_year")).toEqual({
      from: "2025-01-15",
      to: "2025-06-30",
    })
  })
})

describe("comparisonRange — rangos que no sirven", () => {
  it("devuelve null en vez de inventar una ventana", () => {
    // Invertido, incompleto, mal formado y calendariamente inexistente: en los
    // cuatro casos la respuesta correcta es no comparar. Una ventana adivinada
    // le llegaría al modelo indistinguible de una correcta.
    expect(comparisonRange({ from: "2026-08-31", to: "2026-08-01" }, "previous_period")).toBeNull()
    expect(comparisonRange({ from: "", to: "2026-08-01" }, "previous_period")).toBeNull()
    expect(comparisonRange({ from: "agosto", to: "2026-08-01" }, "previous_year")).toBeNull()
    expect(comparisonRange({ from: "2026-02-30", to: "2026-03-01" }, "previous_period")).toBeNull()
  })

  it("tolera un sufijo de hora, que es como los devuelven algunos endpoints", () => {
    expect(
      comparisonRange({ from: "2026-08-01 00:00:00", to: "2026-08-31 23:59:59" }, "previous_year"),
    ).toEqual({ from: "2025-08-01", to: "2025-08-31" })
  })
})

describe("buildDelta — el contrato del porcentaje", () => {
  it("base positiva: el porcentaje habitual", () => {
    expect(buildDelta(125, 100)).toEqual({
      current: 125,
      previous: 100,
      absoluteChange: 25,
      percentChange: 25,
      basis: "ratio",
    })
    expect(buildDelta(80, 100).percentChange).toBe(-20)
  })

  /**
   * Crecer "100%" desde cero sigue dando cero, así que ese número sería
   * directamente falso; "infinito" no es algo que se pueda poner en una frase
   * para el dueño del comercio. El cambio absoluto ya dice toda la verdad.
   */
  it("desde cero NO es 100% ni infinito: el porcentaje no existe", () => {
    const d = buildDelta(1_500_000, 0)
    expect(d.percentChange).toBeNull()
    expect(d.basis).toBe("from_zero")
    expect(d.absoluteChange).toBe(1_500_000)
  })

  it("los dos en cero es el único cero honesto sobre base cero", () => {
    expect(buildDelta(0, 0)).toMatchObject({ percentChange: 0, basis: "both_zero" })
  })

  /**
   * Pasa de verdad: `netFlow` de Finanzas y el resultado neto pueden ser
   * negativos. Ir de -100 a -50 es una MEJORA y la fórmula daría -50%.
   */
  it("base negativa: el porcentaje invertiría el signo, así que no se emite", () => {
    const d = buildDelta(-50, -100)
    expect(d.percentChange).toBeNull()
    expect(d.basis).toBe("negative_base")
    expect(d.absoluteChange).toBe(50)
  })

  it("caer a cero desde una base positiva sí es -100%", () => {
    expect(buildDelta(0, 400)).toMatchObject({ percentChange: -100, basis: "ratio" })
  })
})

describe("extractMetrics — solo se suma lo que se puede sumar", () => {
  it("suma los campos aditivos de las filas y cuenta las filas", () => {
    const rows = [
      { total: 100, unitsSold: 2, price: 50 },
      { total: 250, unitsSold: 5, price: 50 },
    ]
    expect(extractMetrics(rows)).toEqual({ total: 350, unitsSold: 7, rowCount: 2 })
  })

  /**
   * `price` y `averageUnitCost` son POR UNIDAD y `onHand`/`balance` son fotos
   * del momento. Sumarlos entre filas da un número plausible y falso, que es el
   * peor resultado posible: el modelo lo presenta con confianza y nadie tiene
   * cómo detectarlo desde afuera.
   */
  it("ignora precios, costos unitarios, saldos y promedios cuando vienen en filas", () => {
    const metrics = extractMetrics([
      { price: 1000, averageUnitCost: 700, onHand: 5, balance: 90, marginPercent: 30, averageTicket: 12 },
    ])
    expect(metrics).toEqual({ rowCount: 1 })
  })

  /**
   * "Sumable" y "comparable" no son lo mismo. Un ticket promedio no se acumula
   * entre filas, pero "¿subió mi ticket promedio contra el año pasado?" es la
   * pregunta central de un análisis de ventas: cuando el backend ya lo calculó
   * para el período entero y llega como escalar propio, se compara.
   */
  it("sí toma promedios y porcentajes cuando el backend los calculó para el período", () => {
    expect(
      extractMetrics({ total: 47_500_000, averageTicket: 131_000, marginPercent: 75, totalBalance: 900 }),
    ).toEqual({ total: 47_500_000, averageTicket: 131_000, marginPercent: 75 })
  })

  it("lee las filas dentro de `rows` igual que sueltas", () => {
    expect(extractMetrics({ rows: [{ total: 10 }, { total: 5 }], month: false })).toEqual({
      total: 15,
      rowCount: 2,
    })
  })

  it("un objeto de totales aporta sus propios escalares", () => {
    // La forma de `/v1/finance/summary`: no tiene `rows`, tiene los totales
    // arriba. `totalBalance` queda afuera por ser una foto, no un flujo.
    expect(
      extractMetrics({ totalIncome: 900, totalExpense: 400, netFlow: 500, totalBalance: 12_000 }),
    ).toEqual({ totalIncome: 900, totalExpense: 400, netFlow: 500 })
  })

  it("acepta números serializados como string (los manda el driver PDO)", () => {
    expect(extractMetrics([{ total: "100.5" }, { total: "20" }])).toEqual({
      total: 120.5,
      rowCount: 2,
    })
  })

  it("un payload sin campos conocidos no produce métricas inventadas", () => {
    expect(extractMetrics([{ transactionStatus: 4, taxName: "10" }])).toEqual({ rowCount: 1 })
  })
})

describe("buildComparison", () => {
  const ranges = {
    current: { from: "2026-08-01", to: "2026-08-31" },
    baseline: { from: "2025-08-01", to: "2025-08-31" },
  }

  it("nombra los dos períodos por sus fechas y calcula cada delta", () => {
    const out = buildComparison({
      mode: "previous_year",
      ...ranges,
      currentPayload: [{ total: 120, unitsSold: 12 }],
      baselinePayload: [{ total: 100, unitsSold: 20 }],
    })

    expect(out.mode).toBe("previous_year")
    expect(out.current).toEqual(ranges.current)
    expect(out.baseline).toEqual(ranges.baseline)
    expect(out.metrics.total).toMatchObject({ current: 120, previous: 100, percentChange: 20 })
    expect(out.metrics.unitsSold).toMatchObject({ absoluteChange: -8, percentChange: -40 })
  })

  it("una métrica que solo aparece en un lado cuenta como 0 en el otro", () => {
    const out = buildComparison({
      mode: "previous_period",
      ...ranges,
      currentPayload: [{ total: 50, salesCommission: 5 }],
      baselinePayload: [{ total: 50 }],
    })
    expect(out.metrics.salesCommission).toMatchObject({ previous: 0, basis: "from_zero" })
  })

  it("advierte, y solo cuando corresponde, sobre los porcentajes que faltan", () => {
    const sinBordes = buildComparison({
      mode: "previous_period",
      ...ranges,
      currentPayload: [{ total: 120 }],
      baselinePayload: [{ total: 100 }],
    })
    expect(sinBordes.notes).toHaveLength(1)

    const conCero = buildComparison({
      mode: "previous_period",
      ...ranges,
      currentPayload: [{ total: 120 }],
      baselinePayload: [{ total: 0 }],
    })
    expect(conCero.notes.some((n) => n.includes("from_zero"))).toBe(true)
  })
})
