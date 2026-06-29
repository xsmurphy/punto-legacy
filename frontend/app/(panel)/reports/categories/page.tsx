"use client"

/**
 * Reporte de Ventas por Categorías — espejo de panel/reports/categories.html.
 * Backend: GET /v1/reports/categories?from=&to= → array de rows {categoryId,
 * name, usold, total, tax, cogs, comission, discount}.
 */

import { Tag } from "lucide-react"
import { RankingReportPage } from "@/components/reports/ranking-report-page"
import type { CategoryRow } from "@/hooks/use-reports"

export default function CategoriesReportPage() {
  return (
    <RankingReportPage<CategoryRow>
      title="Ventas por Categorías"
      description="Ranking de categorías de productos vendidos en el período."
      endpoint="categories"
      selectRows={(data) => (Array.isArray(data) ? (data as CategoryRow[]) : [])}
      toRanking={(r) => ({
        id: r.categoryId || r.name,
        name: r.name,
        units: r.usold,
        total: r.total,
      })}
      primaryColLabel="Categoría"
      unitsColLabel="Vendidos"
      emptyIcon={Tag}
      emptyLabel="Sin ventas categorizadas en este período"
      exportFileName="categorias"
      searchPlaceholder="Buscar categoría…"
      tableId="report-categories"
    />
  )
}
