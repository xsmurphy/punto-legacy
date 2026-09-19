import { describe, expect, it } from "vitest"
import {
  asGranularity,
  averageLabel,
  averagePerBucket,
  bucketOpacity,
  bucketTooltipLabel,
  formatBucketLabel,
  formatBucketTick,
  perUnit,
  PARTIAL_OPACITY,
  tooltipPoint,
} from "@/lib/charts/granularity"

/**
 * Formato de los gráficos temporales (la regla del grano vive en el servidor,
 * `api/lib/Support/TimeBuckets.php`; esto solo la muestra).
 *
 * Lo que muerde: una fecha pelada leída como UTC se corre un día en América
 * (el 1 de septiembre saldría "31 ago"); la semana que cruza de mes o de año;
 * y el promedio que se hunde por contar un período recortado.
 */

describe("formatBucketTick", () => {
  it("día: '18 sep' sin correrse por zona horaria", () => {
    expect(formatBucketTick("2026-09-18", "day")).toBe("18 sep")
    expect(formatBucketTick("2026-09-01", "day")).toBe("1 sep")
  })

  it("semana dentro del mismo mes: '8–14 sep'", () => {
    expect(formatBucketTick("2026-09-08", "week", "2026-09-14")).toBe("8–14 sep")
  })

  it("semana sin `end`: lo deriva (+6 días)", () => {
    expect(formatBucketTick("2026-09-07", "week")).toBe("7–13 sep")
  })

  it("semana que cruza de mes", () => {
    expect(formatBucketTick("2026-09-28", "week", "2026-10-04")).toBe("28 sep–4 oct")
  })

  it("semana que cruza de año lleva los dos años", () => {
    expect(formatBucketTick("2025-12-29", "week", "2026-01-04")).toBe("29 dic 2025–4 ene 2026")
  })

  it("mes: 'sep 2026'", () => {
    expect(formatBucketTick("2026-09-01", "month", "2026-09-30")).toBe("sep 2026")
  })

  it("hora: '09h'", () => {
    expect(formatBucketTick("9", "hour")).toBe("09h")
  })

  it("basura: devuelve el valor crudo en vez de 'Invalid Date'", () => {
    expect(formatBucketTick("ayer", "day")).toBe("ayer")
  })
})

describe("formatBucketLabel (tooltip)", () => {
  it("lleva el año", () => {
    expect(formatBucketLabel("2026-09-18", "day")).toBe("18 sep 2026")
    expect(formatBucketLabel("2026-09-08", "week", "2026-09-14")).toBe("8–14 sep 2026")
    expect(formatBucketLabel("2026-09-01", "month")).toBe("septiembre 2026")
    expect(formatBucketLabel("14", "hour")).toBe("14:00")
  })
})

describe("bucketTooltipLabel", () => {
  it("marca el período incompleto", () => {
    expect(
      bucketTooltipLabel({ bucket: "2026-08-31", end: "2026-09-06", partial: true }, "week"),
    ).toBe("31 ago–6 sep 2026 (período incompleto)")
  })

  it("no marca el completo", () => {
    expect(bucketTooltipLabel({ bucket: "2026-09-07", end: "2026-09-13", partial: false }, "week")).toBe(
      "7–13 sep 2026",
    )
  })

  it("sin punto, vacío", () => {
    expect(bucketTooltipLabel(null, "day")).toBe("")
  })
})

describe("tooltipPoint", () => {
  it("lee el punto del payload de Recharts", () => {
    const point = { bucket: "2026-09-01", end: "2026-09-30", partial: true, total: 5 }
    expect(tooltipPoint([{ payload: point }])).toBe(point)
    expect(tooltipPoint([])).toBeNull()
    expect(tooltipPoint(undefined)).toBeNull()
  })
})

describe("textos por grano", () => {
  it("Promedio por …", () => {
    expect(averageLabel("day")).toBe("Promedio por día")
    expect(averageLabel("week")).toBe("Promedio por semana")
    expect(averageLabel("month")).toBe("Promedio por mes")
  })

  it("título por unidad", () => {
    expect(perUnit("Ventas", "week")).toBe("Ventas por semana")
    expect(perUnit("Ventas", "hour")).toBe("Ventas por hora")
  })

  it("asGranularity cae a día ante basura", () => {
    expect(asGranularity("month")).toBe("month")
    expect(asGranularity(undefined)).toBe("day")
    expect(asGranularity("year")).toBe("day")
  })
})

describe("períodos incompletos", () => {
  it("atenúan la barra relativo a la opacidad de la serie", () => {
    expect(bucketOpacity({ partial: false })).toBe(1)
    expect(bucketOpacity({ partial: true })).toBe(PARTIAL_OPACITY)
    expect(bucketOpacity({ partial: true }, 0.6)).toBeCloseTo(0.6 * PARTIAL_OPACITY)
  })

  it("el promedio no cuenta los incompletos", () => {
    const pts = [
      { partial: true, v: 10 },
      { partial: false, v: 100 },
      { partial: false, v: 200 },
      { partial: true, v: 5 },
    ]
    expect(averagePerBucket(pts, (p) => p.v)).toBe(150)
  })

  it("si todos son incompletos, promedia todos", () => {
    expect(averagePerBucket([{ partial: true, v: 10 }, { partial: true, v: 30 }], (p) => p.v)).toBe(20)
  })

  it("serie vacía → 0", () => {
    expect(averagePerBucket([], () => 1)).toBe(0)
  })
})
