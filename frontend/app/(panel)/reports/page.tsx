"use client"

/**
 * Hub de Reportes — el índice donde el usuario DESCUBRE qué puede mirar.
 *
 * Estructura (2026-09-11): cinco secciones apiladas a ancho completo, cada una
 * con su grilla de tiles. Antes eran dos columnas de filas planas, y eso tenía
 * dos costos: la columna corta dejaba espacio muerto, y una fila con solo el
 * nombre obliga a entrar al reporte para saber qué responde. Cada tile lleva
 * una línea que dice qué pregunta contesta.
 *
 * El índice ahora lista TODOS los reportes con página propia. Seis existían y
 * no figuraban acá (transacciones, cuentas por cobrar/pagar, movimientos de
 * caja, compras, gift cards y recurrentes): se llegaba a ellos desde otro
 * módulo o sabiendo la URL, que es justo lo contrario de lo que este hub es.
 *
 * `implemented: false` renderiza el tile sin link y con badge "Próximamente".
 * Hoy están todos en true; el mecanismo queda porque es como se suma un
 * reporte que todavía se está construyendo.
 */

import * as React from "react"
import Link from "next/link"
import {
  ArrowDownUp,
  ArrowLeftRight,
  Ban,
  Banknote,
  Boxes,
  Calculator,
  CalendarRange,
  ClipboardCheck,
  ClipboardList,
  CreditCard,
  Factory,
  Gift,
  LayoutDashboard,
  Package,
  PieChart,
  ReceiptText,
  Repeat,
  Scale,
  ShieldCheck,
  ShoppingCart,
  UserSearch,
  Users,
  Wallet,
  type LucideIcon,
} from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
import { cn } from "@/lib/utils"

interface ReportItem {
  title: string
  description: string
  to: string
  icon: LucideIcon
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
    description: "El rendimiento comercial del negocio y quiénes le compran.",
    items: [
      {
        title: "Resumen",
        description: "Vista panorámica del período, comparada con el anterior.",
        to: "/reports/summary",
        icon: LayoutDashboard,
        implemented: true,
      },
      {
        title: "Transacciones",
        description: "Cada venta del período con su documento, estado y detalle.",
        to: "/reports/transactions",
        icon: ReceiptText,
        implemented: true,
      },
      // Artículos absorbió Categorías y Marcas el 2026-09-10: son atributos
      // DEL artículo, no reportes distintos. Aquellas dos páginas se
      // eliminaron sin redirect (decisión del owner: nadie las tenía en
      // marcadores).
      {
        title: "Artículos",
        description: "Qué se vendió en el período, por artículo, categoría o marca.",
        to: "/reports/products",
        icon: Package,
        implemented: true,
      },
      {
        title: "Órdenes",
        description: "Cuántas órdenes entraron, cuánto tardó cada etapa y cómo se usaron los espacios.",
        to: "/reports/orders",
        icon: ClipboardList,
        implemented: true,
      },
      {
        title: "Análisis de clientes",
        description: "Quiénes compran, cuánto y en qué zonas viven.",
        to: "/reports/customers",
        icon: UserSearch,
        implemented: true,
      },
      {
        title: "Gift cards",
        description: "Gift cards emitidas, con su saldo y su estado actual.",
        to: "/reports/giftcards",
        icon: Gift,
        implemented: true,
      },
    ],
  },
  {
    title: "Finanzas",
    description: "Ingresos, egresos, saldos y deudas del negocio.",
    items: [
      // El corte por categoría / centro de costo / cuenta vivía SOLO dentro
      // del módulo, así que desde acá —que es donde se lo busca— parecía no
      // existir. Es el reporte de gastos que pide el contador. Desde el
      // 2026-09-10 tiene página propia acá: Finanzas quedó para OPERAR la
      // plata y /reports para leer cómo fue.
      {
        title: "Ingresos y egresos por categoría",
        description: "Montos del período abiertos por categoría, centro de costo o cuenta.",
        to: "/reports/finance-breakdown",
        icon: PieChart,
        implemented: true,
      },
      {
        title: "Balance",
        description: "Qué tenés y qué debés hoy: es una foto del momento, no un período.",
        to: "/reports/balance",
        icon: Scale,
        implemented: true,
      },
      {
        title: "Flujo de efectivo",
        description: "Entradas y salidas reales de las cuentas, con el saldo inicial y el final.",
        to: "/reports/cashflow",
        icon: ArrowDownUp,
        implemented: true,
      },
      {
        title: "Cuentas por cobrar y pagar",
        description: "Ventas a crédito sin cobrar y compras a crédito sin saldar.",
        to: "/reports/open-invoices",
        icon: Wallet,
        implemented: true,
      },
      {
        title: "Facturas recurrentes",
        description: "Facturas programadas para emisión automática.",
        to: "/reports/recurring",
        icon: Repeat,
        implemented: true,
      },
      {
        title: "Resumen anual",
        description: "Ventas, devoluciones y egresos mes a mes del año.",
        to: "/reports/summary-year",
        icon: CalendarRange,
        implemented: true,
      },
    ],
  },
  {
    title: "Caja",
    description: "Qué pasó en el cajón: cobros, arqueos y movimientos manuales.",
    items: [
      // Medios de pago estaba catalogado en "Ventas y clientes", pero la
      // pregunta que responde —cuánto entró por cada medio— es de caja.
      {
        title: "Medios de pago",
        description: "Cuánto se cobró por cada medio y en cuántas operaciones.",
        to: "/reports/payment-methods",
        icon: CreditCard,
        implemented: true,
      },
      {
        title: "Control de cajas",
        description: "Aperturas y cierres del período, con la diferencia del arqueo.",
        to: "/reports/drawers",
        icon: Calculator,
        implemented: true,
      },
      {
        title: "Movimientos de caja",
        description: "Entradas y salidas manuales del cajón, cargadas desde el POS.",
        to: "/reports/expenses",
        icon: Banknote,
        implemented: true,
      },
    ],
  },
  {
    title: "Inventario y compras",
    description: "Qué entra, qué sale y qué queda en el depósito.",
    items: [
      {
        title: "Movimientos",
        description: "Historial de entradas, salidas y ajustes de stock del período.",
        to: "/reports/inventory",
        icon: ArrowLeftRight,
        implemented: true,
      },
      {
        title: "Niveles de stock",
        description: "Stock actual por producto en la sucursal seleccionada.",
        to: "/reports/stock",
        icon: Boxes,
        implemented: true,
      },
      {
        title: "Conteo",
        description: "Tomas físicas de inventario y su diferencia contra el sistema.",
        to: "/inventory-count",
        icon: ClipboardCheck,
        implemented: true,
      },
      {
        title: "Producción",
        description: "Qué se produjo, cuánto insumo se consumió y con qué rendimiento.",
        to: "/reports/production",
        icon: Factory,
        implemented: true,
      },
      {
        title: "Compras",
        description: "Facturas de compra a proveedores del período.",
        to: "/reports/purchases",
        icon: ShoppingCart,
        implemented: true,
      },
    ],
  },
  {
    title: "Operaciones y equipo",
    description: "La gente y las operaciones del día a día.",
    items: [
      {
        title: "Equipo",
        description: "Ventas, comisiones y ticket promedio por vendedor.",
        to: "/reports/users",
        icon: Users,
        implemented: true,
      },
      {
        title: "Auditoría",
        description: "Qué hizo cada usuario del comercio, cuándo y desde dónde.",
        to: "/reports/audit",
        icon: ShieldCheck,
        implemented: true,
      },
      {
        title: "Anulaciones de comanda",
        description: "Ítems sacados de una comanda y órdenes canceladas, con su motivo.",
        to: "/reports/order-cancellations",
        icon: Ban,
        implemented: true,
      },
    ],
  },
]

export default function ReportsLandingPage() {
  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Reportes</h1>
        <p className="text-sm text-muted-foreground">
          Centro de reportes del negocio. Elegí qué querés mirar y ajustá el
          período adentro de cada uno.
        </p>
      </header>

      {GROUPS.map((g) => (
        <ReportGroupSection key={g.title} group={g} />
      ))}
    </div>
  )
}

function ReportGroupSection({ group }: { group: ReportGroup }) {
  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-col gap-0.5 border-b pb-2">
        <h3 className="text-base font-semibold tracking-tight">{group.title}</h3>
        <p className="text-sm text-muted-foreground">{group.description}</p>
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
        {group.items.map((it) => (
          <ReportTile key={it.title + it.to} item={it} />
        ))}
      </div>
    </section>
  )
}

function ReportTile({ item }: { item: ReportItem }) {
  const Icon = item.icon
  const card = (
    <Card
      size="sm"
      className={cn(
        "h-full transition-colors",
        item.implemented
          ? "group-hover/tile:bg-accent/50 group-focus-visible/tile:ring-ring/50"
          : "opacity-70",
      )}
    >
      <CardContent className="flex items-start gap-3">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
          <Icon className="size-4" />
        </span>
        <div className="flex min-w-0 flex-col gap-0.5">
          <div className="flex items-center gap-2">
            <span className="text-sm font-medium">{item.title}</span>
            {!item.implemented && (
              <Badge variant="secondary" className="text-[10px]">
                Próximamente
              </Badge>
            )}
          </div>
          <p className="text-sm text-muted-foreground">{item.description}</p>
        </div>
      </CardContent>
    </Card>
  )

  if (!item.implemented) {
    return <div className="cursor-not-allowed">{card}</div>
  }
  return (
    <Link href={item.to} className="group/tile focus-visible:outline-none">
      {card}
    </Link>
  )
}
