"use client"

/**
 * Landing de Reportes — réplica del legacy `panel/a_reports.php`.
 *
 * Misma organización en 3 grupos: Ventas / Inventario / Administrativos.
 * Reports YA implementados en panel-next se renderizan con link activo;
 * el resto se muestra con badge "Próximamente" pero no son links (evita
 * 404). El user descubre todos los reports disponibles desde acá.
 *
 * Cada vez que se migra un report al panel-next, basta cambiar
 * `implemented: false` a `true` en la lista de abajo.
 */

import * as React from "react"
import Link from "next/link"
import { ArrowRight, BarChart3 } from "lucide-react"

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
    title: "Ventas",
    description: "Analizá el rendimiento de tu empresa.",
    items: [
      { title: "Resumen",              to: "/reports/summary",       implemented: false },
      { title: "Transacciones",        to: "/reports/transactions",  implemented: true  },
      { title: "Productos y servicios", to: "/reports/products",     implemented: true  },
      { title: "Análisis de clientes",  to: "/reports/customers",    implemented: false },
      { title: "Staff y usuarios",      to: "/reports/users",        implemented: false },
      { title: "Categorías",            to: "/reports/categories",   implemented: false },
      { title: "Marcas",                to: "/reports/brands",       implemented: false },
      { title: "Medios de pago",        to: "/reports/payment-methods", implemented: false },
    ],
  },
  {
    title: "Inventario",
    description: "Conocé tu inventario con información detallada.",
    items: [
      { title: "Movimientos",           to: "/reports/inventory",   implemented: false },
      { title: "Niveles de stock",      to: "/reports/stock",       implemented: false },
      { title: "Conteo",                to: "/reports/inventory-count", implemented: false },
      { title: "Producción",            to: "/reports/production",  implemented: false },
      { title: "Gift cards",            to: "/reports/giftcards",   implemented: false },
    ],
  },
  {
    title: "Administrativos y Financieros",
    description: "Administrá cada aspecto de tu empresa.",
    items: [
      { title: "Cuentas por cobrar",    to: "/reports/open-invoices",   implemented: true  },
      { title: "Cuentas por pagar",     to: "/reports/open-invoices?state=outcome", implemented: true  },
      { title: "Resumen anual",         to: "/reports/summary-year",    implemented: false },
      { title: "Flujo de caja",         to: "/reports/cashflow",        implemented: false },
      { title: "Compras y gastos",      to: "/reports/purchases",       implemented: false },
      { title: "Pagos ePOS",            to: "/reports/vpayments",       implemented: false },
      { title: "Control de cajas",      to: "/reports/drawers",         implemented: true  },
      { title: "Movimientos de caja",   to: "/reports/expenses",        implemented: false },
      { title: "Agendamientos",         to: "/reports/schedule",        implemented: false },
      { title: "Facturas recurrentes",  to: "/reports/recurring",       implemented: false },
      { title: "Órdenes",               to: "/reports/orders",          implemented: false },
      { title: "Calificación de clientes", to: "/reports/satisfaction", implemented: false },
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
      <div className="flex items-center gap-2 min-w-0">
        <BarChart3 className="size-4 text-muted-foreground shrink-0" />
        <span className="truncate">{item.title}</span>
      </div>
      {item.implemented ? (
        <ArrowRight className="size-4 text-muted-foreground transition-colors group-hover:text-foreground" />
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
