/**
 * "Objetivo semanal" del dashboard — lógica pura (widget `goal`,
 * `api/lib/Reports/WeeklyGoalService.php`).
 *
 * Objetivo = la mejor semana en ventas de las últimas 12 completas. La barra
 * mide lo vendido esta semana contra el TOTAL de esa semana; la comparación
 * del texto es contra su RITMO: lo que llevaba a esta misma altura (mismo día
 * y hora). Un jueves no se compara contra una semana entera.
 */

export interface WeeklyGoal {
  /** Lunes de la semana en curso, 'YYYY-MM-DD'. */
  weekStart: string
  /** Ventas de la semana en curso hasta ahora. */
  current: number
  best: {
    weekStart: string
    weekEnd: string
    total: number
    /** Lo que llevaba la mejor semana a esta misma altura. */
    atSamePoint: number
  }
  /** Semanas completas con ventas en la ventana (mínimo 4 para mostrarse). */
  weeksWithSales: number
}

export interface GoalWidget {
  goal: WeeklyGoal | null
}

/** Semanas completas con ventas necesarias (espejo de `WeeklyGoalService::MIN_WEEKS`). */
export const GOAL_MIN_WEEKS = 4

export type GoalPace = "passed" | "ahead" | "behind" | "even"

export interface GoalProgress {
  /** current / total de la mejor semana, 0-100. */
  percent: number
  /** Dónde iba la mejor semana a esta altura, 0-100. */
  marker: number
  pace: GoalPace
  /** Diferencia contra el ritmo, en % entero; `null` sin ritmo contra qué medir. */
  pacePct: number | null
  /** Lo que falta para igualar la mejor semana; 0 si ya se superó. */
  remaining: number
}

function pct(part: number, whole: number): number {
  if (!(whole > 0)) return 0
  return Math.max(0, Math.min(100, (part / whole) * 100))
}

export function goalProgress(goal: WeeklyGoal): GoalProgress {
  const current = Number(goal.current) || 0
  const total = Number(goal.best.total) || 0
  const pace = Number(goal.best.atSamePoint) || 0
  const remaining = Math.max(0, total - current)

  if (total > 0 && current >= total) {
    return { percent: 100, marker: pct(pace, total), pace: "passed", pacePct: null, remaining: 0 }
  }

  let status: GoalPace
  let pacePct: number | null
  if (pace > 0) {
    pacePct = Math.round((Math.abs(current - pace) / pace) * 100)
    status = pacePct === 0 ? "even" : current > pace ? "ahead" : "behind"
  } else {
    // La mejor semana todavía no había vendido a esta altura: sin base para
    // un porcentaje. Con ventas vas arriba; sin ventas, a la par.
    pacePct = null
    status = current > 0 ? "ahead" : "even"
  }

  return { percent: pct(current, total), marker: pct(pace, total), pace: status, pacePct, remaining }
}

/** La línea de estado debajo de la barra. */
export function goalPaceLabel(p: Pick<GoalProgress, "pace" | "pacePct">): string {
  switch (p.pace) {
    case "passed":
      return "Superaste tu mejor semana"
    case "even":
      return "Vas a la par de tu mejor semana"
    case "ahead":
      return p.pacePct === null ? "Vas arriba de tu mejor semana" : `Vas ${p.pacePct}% arriba de tu mejor semana`
    case "behind":
      return `Vas ${p.pacePct ?? 0}% abajo de tu mejor semana`
  }
}
