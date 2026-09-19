import { describe, expect, it } from "vitest"

import { goalPaceLabel, goalProgress, type WeeklyGoal } from "@/lib/dashboard/weekly-goal"
import { visibleWeeklyGoal } from "@/lib/dashboard/visibility"

function goal(current: number, total: number, atSamePoint: number, weeksWithSales = 6): WeeklyGoal {
  return {
    weekStart: "2026-09-14",
    current,
    best: { weekStart: "2026-08-10", weekEnd: "2026-08-16", total, atSamePoint },
    weeksWithSales,
  }
}

describe("goalProgress", () => {
  it("barra contra el total, marca contra el ritmo", () => {
    const p = goalProgress(goal(1680, 4200, 1500))
    expect(p.percent).toBeCloseTo(40)
    expect(p.marker).toBeCloseTo(35.714, 2)
    expect(p.remaining).toBe(2520)
  })

  it("arriba del ritmo: % entero sobre lo que llevaba la mejor semana", () => {
    const p = goalProgress(goal(1680, 4200, 1500))
    expect(p.pace).toBe("ahead")
    expect(p.pacePct).toBe(12)
    expect(goalPaceLabel(p)).toBe("Vas 12% arriba de tu mejor semana")
  })

  it("abajo del ritmo", () => {
    const p = goalProgress(goal(1200, 4200, 1500))
    expect(p.pace).toBe("behind")
    expect(goalPaceLabel(p)).toBe("Vas 20% abajo de tu mejor semana")
  })

  it("redondeado a 0% es a la par", () => {
    const p = goalProgress(goal(1502, 4200, 1500))
    expect(p.pace).toBe("even")
    expect(goalPaceLabel(p)).toBe("Vas a la par de tu mejor semana")
  })

  it("superada en total: sin faltante, barra llena", () => {
    const p = goalProgress(goal(4300, 4200, 3000))
    expect(p.pace).toBe("passed")
    expect(p.percent).toBe(100)
    expect(p.remaining).toBe(0)
    expect(goalPaceLabel(p)).toBe("Superaste tu mejor semana")
  })

  it("igualar exacto cuenta como superada", () => {
    expect(goalProgress(goal(4200, 4200, 3000)).pace).toBe("passed")
  })

  it("la mejor semana no había vendido a esta altura: arriba sin porcentaje", () => {
    const p = goalProgress(goal(300, 4200, 0))
    expect(p.pace).toBe("ahead")
    expect(p.pacePct).toBeNull()
    expect(p.marker).toBe(0)
    expect(goalPaceLabel(p)).toBe("Vas arriba de tu mejor semana")
  })

  it("lunes temprano sin ventas de ningún lado: a la par", () => {
    const p = goalProgress(goal(0, 4200, 0))
    expect(p.pace).toBe("even")
    expect(p.percent).toBe(0)
  })
})

describe("visibleWeeklyGoal", () => {
  it("sin datos o goal null → no se muestra", () => {
    expect(visibleWeeklyGoal(undefined)).toBeNull()
    expect(visibleWeeklyGoal({ goal: null })).toBeNull()
  })

  it("menos de 4 semanas con ventas → no se muestra", () => {
    expect(visibleWeeklyGoal({ goal: goal(100, 4200, 50, 3) })).toBeNull()
  })

  it("mejor semana sin ventas → no se muestra", () => {
    expect(visibleWeeklyGoal({ goal: goal(0, 0, 0, 5) })).toBeNull()
  })

  it("con 4 semanas y mejor semana con ventas → se muestra", () => {
    const g = goal(100, 4200, 50, 4)
    expect(visibleWeeklyGoal({ goal: g })).toBe(g)
  })
})
