"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import {
  BarChart3,
  Building2,
  FileText,
  Globe,
  Palette,
  Receipt,
  ReceiptText,
  ScanBarcode,
  ScanLine,
  Settings as SettingsIcon,
  ShoppingCart,
  Tag,
  Wallet,
} from "lucide-react"
import {
  Command,
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "@/components/ui/command"
import type { NavEntry } from "@/components/layout/app-sidebar"

interface Props {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Items del menú lateral para mostrar como atajos de navegación. */
  nav: NavEntry[]
}

/**
 * Rutas que NO viven en el sidebar pero deben ser alcanzables por el palette.
 * Cubre todo lo "secundario" (configuración por sección, sucursales, plantillas,
 * etc.) — el sidebar queda minimalista (5 items) y el palette es el catch-all.
 *
 * Cada entry trae:
 *   - `title`: lo que el user busca y ve en el item.
 *   - `to`:    href de destino (deep-link cuando aplica, ej. ?tab=brands).
 *   - `icon`:  componente lucide.
 *   - `group`: heading donde se agrupa el item en el palette.
 *   - `keywords`: alias adicionales para el search de cmdk (ej. "stock" → Sucursales).
 */
interface ExtraRoute {
  title: string
  to: string
  icon: React.ComponentType<{ className?: string }>
  group: "Operaciones" | "Configuración" | "Catálogo" | "Reportes"
  keywords?: string[]
}

const EXTRA_ROUTES: ExtraRoute[] = [
  // Operaciones (cosas que el user hace, no ajustes)
  {
    title: "Sucursales",
    to: "/outlets",
    icon: Building2,
    group: "Operaciones",
    keywords: ["outlet", "tienda", "branch", "local"],
  },
  {
    title: "Códigos de barras",
    to: "/items/barcodes",
    icon: ScanBarcode,
    group: "Operaciones",
    keywords: ["barcode", "etiquetas", "labels", "print"],
  },

  // Configuración (modal o página dedicada)
  {
    title: "Configuración · Empresa",
    to: "/settings",
    icon: SettingsIcon,
    group: "Configuración",
    keywords: ["company", "datos", "razon social", "ruc"],
  },
  {
    title: "Configuración · Localización",
    to: "/settings",
    icon: Globe,
    group: "Configuración",
    keywords: ["idioma", "zona horaria", "moneda", "pais", "language"],
  },
  {
    title: "Configuración · POS",
    to: "/settings",
    icon: ScanLine,
    group: "Configuración",
    keywords: ["caja", "pos", "punto de venta", "ventas"],
  },
  {
    title: "Configuración · Apariencia",
    to: "/settings",
    icon: Palette,
    group: "Configuración",
    keywords: ["tema", "dark", "light", "oscuro", "claro", "theme"],
  },
  {
    title: "Configuración · Documentos",
    to: "/settings",
    icon: FileText,
    group: "Configuración",
    keywords: ["plantilla", "template", "factura", "ticket"],
  },
  {
    title: "Plantillas de impresión (editor)",
    to: "/settings/print-templates",
    icon: FileText,
    group: "Configuración",
    keywords: ["editor", "template builder", "diseñar"],
  },

  // Catálogo (deep-link a cada tab)
  {
    title: "Catálogo · Categorías",
    to: "/settings/catalog?tab=categories",
    icon: Tag,
    group: "Catálogo",
    keywords: ["categories", "tag", "clasificar"],
  },
  {
    title: "Catálogo · Marcas",
    to: "/settings/catalog?tab=brands",
    icon: Building2,
    group: "Catálogo",
    keywords: ["brands", "fabricantes", "marca"],
  },
  {
    title: "Catálogo · Impuestos",
    to: "/settings/catalog?tab=taxes",
    icon: Receipt,
    group: "Catálogo",
    keywords: ["taxes", "iva", "tax", "impuesto"],
  },

  // Acciones rápidas del menú user (también accesibles desde aquí)
  {
    title: "Mi Plan",
    to: "/history-billing",
    icon: ReceiptText,
    group: "Operaciones",
    keywords: ["plan", "billing", "estado de cuenta", "facturas", "pagos", "suscripción"],
  },
  {
    title: "Compras y gastos",
    to: "/purchase",
    icon: ShoppingCart,
    group: "Operaciones",
    keywords: ["expenses", "purchase", "egresos"],
  },

  // Reportes — landing + sub-reports implementados. La landing tiene
  // discoverability del resto (que están marked "próximamente").
  {
    title: "Reportes (índice)",
    to: "/reports",
    icon: BarChart3,
    group: "Reportes",
    keywords: ["reports", "analytics", "informes"],
  },
  {
    title: "Reportes · Control de cajas",
    to: "/reports/drawers",
    icon: Wallet,
    group: "Reportes",
    keywords: ["drawers", "arqueo", "cierre de caja", "apertura"],
  },
  {
    title: "Reportes · Transacciones",
    to: "/reports/transactions",
    icon: Receipt,
    group: "Reportes",
    keywords: ["transactions", "ventas", "facturas", "tickets"],
  },
  {
    title: "Reportes · Productos y servicios",
    to: "/reports/products",
    icon: Tag,
    group: "Reportes",
    keywords: ["products", "ranking", "items", "vendidos", "top items"],
  },
  {
    title: "Reportes · Cuentas por cobrar",
    to: "/reports/open-invoices",
    icon: Wallet,
    group: "Reportes",
    keywords: ["open invoices", "credit", "deuda", "cobrar", "income"],
  },
  {
    title: "Reportes · Cuentas por pagar",
    to: "/reports/open-invoices?state=outcome",
    icon: Wallet,
    group: "Reportes",
    keywords: ["open invoices", "pagar", "deuda proveedores", "outcome"],
  },
  {
    title: "Reportes · Resumen",
    to: "/reports/summary",
    icon: BarChart3,
    group: "Reportes",
    keywords: ["summary", "totales", "resumen", "ventas totales", "kpi"],
  },
  {
    title: "Reportes · Análisis de clientes",
    to: "/reports/customers",
    icon: BarChart3,
    group: "Reportes",
    keywords: ["customers", "ranking clientes", "consumo", "loyalty"],
  },
  {
    title: "Reportes · Categorías",
    to: "/reports/categories",
    icon: Tag,
    group: "Reportes",
    keywords: ["categorias", "categories", "ranking", "ventas por categoria"],
  },
  {
    title: "Reportes · Marcas",
    to: "/reports/brands",
    icon: Building2,
    group: "Reportes",
    keywords: ["brands", "marcas", "ranking", "fabricantes"],
  },
  {
    title: "Reportes · Medios de pago",
    to: "/reports/payment-methods",
    icon: Wallet,
    group: "Reportes",
    keywords: ["payment methods", "tarjeta", "efectivo", "cobros"],
  },
  {
    title: "Reportes · Movimientos de Caja",
    to: "/reports/expenses",
    icon: Wallet,
    group: "Reportes",
    keywords: ["expenses", "movimientos", "extracciones", "ingresos manuales", "cajon"],
  },
]

/**
 * Command palette del panel — abre con ⌘K o click en el "Buscar…" del sidebar.
 * Combina dos fuentes:
 *  1. Items del nav lateral (pasados como prop) → grupo "Navegación".
 *  2. Rutas extra hardcodeadas (`EXTRA_ROUTES`) → grupos secundarios por tipo.
 *
 * El palette es el catch-all: cualquier ruta debe ser alcanzable desde acá,
 * aunque NO esté en el sidebar (que mantenemos minimalista con 5 items).
 */
export function AppCommandPalette({ open, onOpenChange, nav }: Props) {
  const router = useRouter()

  const go = React.useCallback(
    (path: string) => {
      onOpenChange(false)
      router.push(path)
    },
    [router, onOpenChange],
  )

  // Aplanar el nav (algunos entries pueden ser grupos con items).
  const flat = React.useMemo(() => {
    type Flat = {
      title: string
      to: string
      icon: React.ComponentType<{ className?: string }>
    }
    const result: Flat[] = []
    nav.forEach((entry) => {
      if ("items" in entry && Array.isArray(entry.items)) {
        entry.items.forEach((c) =>
          result.push({ title: `${entry.title} · ${c.title}`, to: c.to, icon: c.icon }),
        )
      } else if ("to" in entry) {
        result.push({ title: entry.title, to: entry.to, icon: entry.icon })
      }
    })
    return result
  }, [nav])

  // Agrupar los EXTRA_ROUTES por su `group` para renderizar cada CommandGroup.
  const grouped = React.useMemo(() => {
    const acc: Record<ExtraRoute["group"], ExtraRoute[]> = {
      Operaciones: [],
      Reportes: [],
      Configuración: [],
      Catálogo: [],
    }
    EXTRA_ROUTES.forEach((r) => {
      acc[r.group].push(r)
    })
    return acc
  }, [])

  return (
    <CommandDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Buscar"
      description="Buscar por nombre o ir a una sección."
    >
      {/* cmdk requiere el <Command> root como contexto para CommandInput/
          List/Item. El CommandDialog del preset shadcn nuevo NO lo envuelve
          automáticamente — hay que pasarlo explícito acá. */}
      <Command>
        <CommandInput placeholder="Buscar sección o acción…" />
        <CommandList>
          <CommandEmpty>Sin resultados.</CommandEmpty>
          <CommandGroup heading="Navegación">
            {flat.map((it) => {
              const Icon = it.icon
              return (
                <CommandItem
                  key={it.to}
                  value={it.title}
                  onSelect={() => go(it.to)}
                >
                  {Icon && <Icon className="size-4" />}
                  <span>{it.title}</span>
                </CommandItem>
              )
            })}
          </CommandGroup>

          {(Object.keys(grouped) as Array<ExtraRoute["group"]>).map((g) => {
            const items = grouped[g]
            if (items.length === 0) return null
            return (
              <React.Fragment key={g}>
                <CommandSeparator />
                <CommandGroup heading={g}>
                  {items.map((it) => {
                    const Icon = it.icon
                    // cmdk usa `value` para search — concatenamos keywords para
                    // que el filter encuentre el item por sinónimos en otros idiomas.
                    const searchValue = [it.title, ...(it.keywords ?? [])].join(" ")
                    return (
                      <CommandItem
                        key={`${it.to}|${it.title}`}
                        value={searchValue}
                        onSelect={() => go(it.to)}
                      >
                        {Icon && <Icon className="size-4" />}
                        <span>{it.title}</span>
                      </CommandItem>
                    )
                  })}
                </CommandGroup>
              </React.Fragment>
            )
          })}
        </CommandList>
      </Command>
    </CommandDialog>
  )
}
