"use client"

/**
 * Reporte de Ventas por Marcas — espejo de panel/reports/brands.html.
 * Backend: GET /v1/reports/brands?from=&to= → array de rows {brandId, name,
 * usold, total, tax, cogs, comission, discount}.
 */

import { Building2 } from "lucide-react"
import { RankingReportPage } from "@/components/reports/ranking-report-page"
import type { BrandRow } from "@/hooks/use-reports"

export default function BrandsReportPage() {
  return (
    <RankingReportPage<BrandRow>
      title="Ventas por Marcas"
      description="Ranking de marcas / fabricantes vendidos en el período."
      endpoint="brands"
      selectRows={(data) => (Array.isArray(data) ? (data as BrandRow[]) : [])}
      toRanking={(r) => ({
        id: r.brandId || r.name,
        name: r.name,
        units: r.usold,
        total: r.total,
      })}
      primaryColLabel="Marca"
      unitsColLabel="Vendidos"
      emptyIcon={<Building2 className="size-8 opacity-30" />}
      emptyLabel="No hay ventas por marca en este período."
      exportFileName="marcas"
      searchPlaceholder="Buscar marca…"
      tableId="report-brands"
    />
  )
}
