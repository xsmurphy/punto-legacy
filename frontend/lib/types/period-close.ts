/**
 * Shapes de `/v1/period-close` — mirror exacto del endpoint PHP
 * (api/v1/period-close.php). D7/E1b de context/48-escalamiento-de-datos.md.
 */

export interface PeriodCloseMonth {
  /** 'YYYY-MM'. */
  period: string
  transactionCount: number
  closed: boolean
  /** ISO timestamp. null si no está cerrado. */
  closedAt: string | null
  /** Nombre de quien cerró manualmente. null si cerró el job (source='job') o si no está cerrado. */
  closedBy: string | null
  source: "job" | "manual" | null
}

export interface PeriodCloseSummary {
  months: PeriodCloseMonth[]
  /** Ancho de la ventana abierta (mes en curso + N meses anteriores). 1..12. */
  closeMonths: number
  /** Próximo cierre automático (job de mantenimiento), informativo. 'YYYY-MM-DD'. */
  nextAutoClose: string
}
