"use client"

/**
 * Reporte de Medios de Pago — espejo de panel/reports/payment-methods.html.
 * Backend: GET /v1/reports/payment-methods?from=&to= → { detail, summary }.
 * Usamos `summary` para el ranking (count + total por método de pago).
 */

import { CreditCard } from "lucide-react"
import { RankingReportPage } from "@/components/reports/ranking-report-page"
import type {
  PaymentMethodsReportResponse,
  PaymentSummaryRow,
} from "@/hooks/use-reports"

export default function PaymentMethodsReportPage() {
  return (
    <RankingReportPage<PaymentSummaryRow>
      title="Medios de pago"
      description="Total cobrado y cantidad de operaciones por método de pago."
      endpoint="payment-methods"
      selectRows={(data) =>
        (data as PaymentMethodsReportResponse | undefined)?.summary ?? []
      }
      toRanking={(r) => ({
        id: r.type || r.name,
        name: r.name,
        units: r.count,
        total: r.total,
      })}
      primaryColLabel="Método"
      unitsColLabel="Operaciones"
      emptyIcon={<CreditCard className="size-8 opacity-30" />}
      emptyLabel="No hay cobros en este período."
      exportFileName="medios_de_pago"
      searchPlaceholder="Buscar método (efectivo, tarjeta…)…"
      tableId="report-payment-methods"
    />
  )
}
