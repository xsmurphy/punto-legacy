"use client"

/**
 * Landing de Reportes — réplica del legacy `panel/a_reports.php`.
 *
 * Misma organización en 3 grupos: Ventas / Inventario / Administrativos.
 * Reports YA implementados en frontend se renderizan con link activo;
 * el resto se muestra con badge "Próximamente" pero no son links (evita
 * 404). El user descubre todos los reports disponibles desde acá.
 *
 * Cada vez que se migra un report al frontend, basta cambiar
 * `implemented: false` a `true` en la lista de abajo.
 */

import * as React from "react"
import Link from "next/link"
import { ArrowRight } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"

interface ReportItem {
  title: string
  to: string
  implemented: boolean
}

interface ReportGroup {
  title: string
  description: string
  items: ReportItem[]
}

const GROUPS: ReportGroup[] = [
  {
    title: "Ventas y clientes",
    description: "El rendimiento comercial de tu negocio y quiénes te compran.",
    items: [
      { title: "Resumen",                  to: "/reports/summary",         implemented: true  },
      { title: "Productos y servicios",    to: "/reports/products",        implemented: true  },
      { title: "Categorías",               to: "/reports/categories",      implemented: true  },
      { title: "Marcas",                   to: "/reports/brands",          implemented: true  },
      { title: "Medios de pago",           to: "/reports/payment-methods", implemented: true  },
      { title: "Órdenes",                  to: "/reports/orders",          implemented: true  },
      { title: "Análisis de clientes",     to: "/reports/customers",       implemented: true  },
    ],
  },
  {
    title: "Finanzas y caja",
    description: "La plata: entradas, salidas y saldos.",
    items: [
      { title: "Finanzas",      to: "/finanzas",             implemented: true  },
      // El corte por categoría / centro de costo / cuenta vivía SOLO dentro
      // del módulo, así que desde acá —que es donde se lo busca— parecía no
      // existir. Es el reporte de gastos que pide el contador.
      { title: "Gastos por categoría", to: "/finanzas/reportes", implemented: true  },
      { title: "Balance",       to: "/reports/balance",       implemented: true  },
      { title: "Flujo de efectivo", to: "/reports/cashflow",  implemented: true  },
      { title: "Control de cajas", to: "/reports/drawers",    implemented: true  },
      { title: "Resumen anual", to: "/reports/summary-year",  implemented: true  },
    ],
  },
  {
    title: "Inventario",
    description: "Tu stock con información detallada.",
    items: [
      { title: "Movimientos",      to: "/reports/inventory",   implemented: true  },
      { title: "Niveles de stock", to: "/reports/stock",       implemented: true  },
      { title: "Conteo",           to: "/inventory-count",     implemented: true  },
      { title: "Producción",       to: "/reports/production",  implemented: true  },
    ],
  },
  {
    title: "Operaciones y equipo",
    description: "Tu gente y las operaciones del día a día.",
    items: [
      { title: "Equipo",    to: "/reports/users", implemented: true  },
      { title: "Auditoría", to: "/reports/audit", implemented: true  },
      { title: "Anulaciones de ítems", to: "/reports/order-item-cancellations", implemented: true },
    ],
  },
]

export default function ReportsLandingPage() {
  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Reportes</h1>
        <p className="text-sm text-muted-foreground">
          Centro de reportes del negocio. Algunos están migrados a este panel;
          el resto sigue disponible en el panel legacy mientras se migran.
        </p>
      </header>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {GROUPS.map((g) => (
          <ReportGroupCard key={g.title} group={g} />
        ))}
      </div>
    </div>
  )
}

function ReportGroupCard({ group }: { group: ReportGroup }) {
  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-col gap-0.5 border-b pb-2">
        <h3 className="text-base font-semibold tracking-tight">{group.title}</h3>
        <p className="text-xs text-muted-foreground">{group.description}</p>
      </div>
      <div className="flex flex-col">
        {group.items.map((it) => (
          <ReportLink key={it.title + it.to} item={it} />
        ))}
      </div>
    </section>
  )
}

function ReportLink({ item }: { item: ReportItem }) {
  const cls = cn(
    "group flex items-center justify-between gap-3 border-b px-3 py-3 text-sm last:border-b-0",
    item.implemented
      ? "hover:bg-accent/50"
      : "cursor-not-allowed text-muted-foreground",
  )
  const content = (
    <>
      <span className="truncate">{item.title}</span>
      {item.implemented ? (
        <ArrowRight className="size-4 text-muted-foreground transition-colors group-hover:text-foreground shrink-0" />
      ) : (
        <Badge variant="secondary" className="text-[10px]">
          Próximamente
        </Badge>
      )}
    </>
  )
  if (item.implemented) {
    return (
      <Link href={item.to} className={cls}>
        {content}
      </Link>
    )
  }
  return <div className={cls}>{content}</div>
}
