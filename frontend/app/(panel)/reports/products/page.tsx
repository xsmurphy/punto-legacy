"use client"

/**
 * Reporte de Artículos — el tablero único de qué se vendió.
 *
 * Consolidado el 2026-09-10 (owner): categorías y marcas tenían página propia
 * (`/reports/categories`, `/reports/brands`) y se ELIMINARON, sin redirect por
 * decisión explícita del owner ("nadie los tiene en marcadores"). No eran
 * entidades del reporte sino ATRIBUTOS del artículo: "ventas por marca" es el
 * mismo hecho que "ventas por producto" con otro agrupamiento. Tres páginas
 * separadas obligaban a saber de antemano por cuál corte entrar.
 *
 * Productos y Servicios son un FILTRO sobre las mismas filas (`itemType`), no
 * dos fuentes distintas.
 *
 * `payment-methods` NO se consolidó acá aunque use el mismo wrapper de
 * ranking: un medio de pago no es un atributo del artículo, y agruparlo por
 * parecido técnico habría sido el error que esta consolidación corrige.
 *
 * Vistas sobre el mismo endpoint (/v1/reports/products):
 *  - Ranking (view=general): agregado por producto — unidades, total,
 *    descuento, impuestos, COGS, comisión y utilidad calculada.
 *  - Detallado (view=detail): una fila por línea de venta — comprobante,
 *    cliente, usuario, sucursal/caja. El backend ya lo tenía implementado
 *    (ProductsService::detail(), usado por otras vistas de filtro), solo
 *    faltaba exponerlo acá como pestaña.
 *
 * En ambas vistas, el nombre del artículo linkea a su ficha (tab Stock),
 * donde vive el historial de movimientos por artículo (ver stock-tab.tsx) —
 * no se duplica ese historial acá, solo se enlaza.
 *
 * Vista `combos` queda como follow-up (no es parte de este slice).
 */

import * as React from "react"
import Link from "next/link"
import { useSearchParams } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import {
  AlertCircle,
  ArrowLeft,
  Building2,
  ListTree,
  Package,
  Tag,
  Trash2,
} from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  DateRangePicker,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { RankingReportPage } from "@/components/reports/ranking-report-page"
import {
  useReport,
  type BrandRow,
  type CategoryRow,
  type ProductDetailRow,
  type ProductRow,
  type ProductsDetailReportResponse,
  type ProductsReportResponse,
} from "@/hooks/use-reports"
import { formatInt, formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"
import { StatsRow, StatTile } from "@/components/stat-tile"
import { RankingBarChart } from "@/components/domain/reports/ranking-bar-chart"
import { CompositionDonutChart } from "@/components/domain/reports/composition-donut-chart"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"

/** Link al artículo — mismo destino desde Ranking y Detallado (ver punto 2/3
 *  del pedido: un solo historial, enlazado desde ambos lugares en vez de
 *  duplicarlo). Sin itemId (línea eliminada o venta manual sin ítem) no hay
 *  ficha a dónde ir, así que se muestra como texto plano. */
function ItemLink({ id, children }: { id: string; children: React.ReactNode }) {
  if (!id) return <>{children}</>
  return (
    <Link href={`/items/${id}?tab=stock`} className="hover:underline">
      {children}
    </Link>
  )
}

const TAB_IDS = [
  "dashboard",
  "productos",
  "servicios",
  "categorias",
  "marcas",
  "detallado",
] as const

export default function ProductsReportPage() {
  const { range, setRange } = useDateRange()
  // `?tab=` deep-linkea una pestaña. Lo usan las entradas "Categorías" y
  // "Marcas" de la paleta, que sobrevivieron a la consolidación: quien busca
  // "marcas" no tiene por qué saber que ahora vive adentro de Artículos.
  const searchParams = useSearchParams()
  const requested = searchParams.get("tab")
  const initialTab =
    requested && (TAB_IDS as readonly string[]).includes(requested)
      ? requested
      : "dashboard"

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Artículos</h1>
          <p className="text-sm text-muted-foreground">
            Qué se vendió en el período: por artículo, por categoría, por marca
            o línea por línea. Hacé clic en un artículo para ver su historial de
            movimientos.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      <Tabs defaultValue={initialTab} className="flex flex-col gap-4">
        <TabsList>
          <TabsTrigger value="dashboard">Dashboard</TabsTrigger>
          <TabsTrigger value="productos">Productos</TabsTrigger>
          <TabsTrigger value="servicios">Servicios</TabsTrigger>
          <TabsTrigger value="categorias">Categorías</TabsTrigger>
          <TabsTrigger value="marcas">Marcas</TabsTrigger>
          <TabsTrigger value="detallado">Detallado</TabsTrigger>
        </TabsList>

        <TabsContent value="dashboard" className="m-0">
          <ChartsTab range={range} />
        </TabsContent>

        <TabsContent value="productos" className="m-0">
          <RankingTab range={range} only="product" />
        </TabsContent>

        <TabsContent value="servicios" className="m-0">
          <RankingTab range={range} only="service" />
        </TabsContent>

        <TabsContent value="categorias" className="m-0">
          <RankingReportPage<CategoryRow>
            title="Ventas por categorías"
            description="Ranking de categorías vendidas en el período."
            endpoint="categories"
            embeddedRange={range}
            selectRows={(data) => (Array.isArray(data) ? (data as CategoryRow[]) : [])}
            toRanking={(r) => ({
              id: r.categoryId || r.name,
              name: r.name,
              units: r.usold,
              total: r.total,
            })}
            primaryColLabel="Categoría"
            emptyIcon={Tag}
            emptyLabel="Sin ventas categorizadas en este período"
            exportFileName="categorias"
            searchPlaceholder="Buscar categoría…"
            tableId="report-categories"
          />
        </TabsContent>

        <TabsContent value="marcas" className="m-0">
          <RankingReportPage<BrandRow>
            title="Ventas por marcas"
            description="Ranking de marcas vendidas en el período."
            endpoint="brands"
            embeddedRange={range}
            selectRows={(data) => (Array.isArray(data) ? (data as BrandRow[]) : [])}
            toRanking={(r) => ({
              id: r.brandId || r.name,
              name: r.name,
              units: r.usold,
              total: r.total,
            })}
            primaryColLabel="Marca"
            emptyIcon={Building2}
            emptyLabel="Sin ventas por marca en este período"
            exportFileName="marcas"
            searchPlaceholder="Buscar marca…"
            tableId="report-brands"
          />
        </TabsContent>

        <TabsContent value="detallado" className="m-0">
          <DetailTab range={range} />
        </TabsContent>
      </Tabs>
    </div>
  )
}

/**
 * Gráficos del reporte de productos.
 *
 * Sale del MISMO `view=general` que el Ranking, sin pedir nada nuevo al
 * backend: `ProductRow` ya trae `category` y `brand`, así que los cortes por
 * categoría y por marca son una agregación de lo que la pantalla ya tiene.
 * Pedirlos a `/reports/categories` y `/reports/brands` traería los mismos
 * números por otro camino y abriría la puerta a que difieran.
 *
 * Esos dos informes siguen existiendo con página propia —el chart también
 * está allá, en `RankingReportPage`—; acá se incorporan porque categoría y
 * marca son atributos DEL producto y este es el tablero del producto (pedido
 * del owner 2026-09-10).
 *
 * Facturación y unidades van SEPARADOS y no en un gráfico de dos series: no
 * comparten unidad (plata vs. cantidad), así que una escala común aplasta una
 * de las dos. Y son la pregunta interesante justamente cuando NO coinciden:
 * lo más vendido suele ser lo más barato.
 */
function ChartsTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "general" } }),
    [range],
  )
  const { data, isLoading, error } = useReport<ProductsReportResponse>("products", opts)
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  /** Misma fórmula que los totalizadores del Ranking. */
  const utilityOf = React.useCallback(
    (r: ProductRow) =>
      typeof r.utility === "number" ? r.utility : r.total - r.cogs - r.comission,
    [],
  )

  const money = React.useCallback(
    (v: number) => formatMoney(v, bootstrap),
    [bootstrap],
  )
  const units = React.useCallback(
    (v: number) => formatInt(v, bootstrap),
    [bootstrap],
  )

  /** Suma `total` y `usold` por un atributo del artículo (categoría o marca). */
  const groupBy = React.useCallback(
    (key: "category" | "brand") => {
      const acc = new Map<string, { total: number; usold: number }>()
      for (const r of rows) {
        // Sin valor cargado no se inventa un "Otros" que mezcle cosas
        // distintas: se saltea, y el chart dice cuántos quedaron afuera.
        const label = (r[key] ?? "").trim()
        if (label === "") continue
        const prev = acc.get(label) ?? { total: 0, usold: 0 }
        acc.set(label, { total: prev.total + r.total, usold: prev.usold + r.usold })
      }
      return [...acc.entries()].map(([label, v]) => ({ label, ...v }))
    },
    [rows],
  )

  const porCategoria = React.useMemo(() => groupBy("category"), [groupBy])
  const porMarca = React.useMemo(() => groupBy("brand"), [groupBy])

  /**
   * Cuántos de los que más facturan no tienen costo cargado.
   *
   * Importa decirlo: sin costo, `utility` es igual al total y la barra se
   * pinta como utilidad pura. El gráfico estaría afirmando un margen del 100%
   * que en realidad es un costo que nadie registró — y ese es justamente el
   * artículo sobre el que un dueño tomaría una decisión equivocada.
   */
  /**
   * Artículos vendidos sin marca cargada. El corte por marca los saltea —
   * agruparlos en un "Sin marca" los pondría a competir como si fueran una
   * marca más—, así que la card lo declara en vez de que el total del gráfico
   * no cierre contra el del período sin explicación.
   */
  const sinMarca = React.useMemo(
    () => rows.filter((r) => r.total > 0 && (r.brand ?? "").trim() === "").length,
    [rows],
  )

  const sinCosto = React.useMemo(
    () =>
      [...rows]
        .sort((a, b) => b.total - a.total)
        .slice(0, 10)
        .filter((r) => r.total > 0 && r.cogs === 0).length,
    [rows],
  )

  if (error) {
    return (
      <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
        <AlertCircle className="mt-0.5 size-4 text-destructive" />
        <div>
          <p className="font-medium">No se pudo cargar el reporte</p>
          <p className="text-xs text-muted-foreground">{error.message}</p>
        </div>
      </div>
    )
  }

  if (isLoading) {
    return (
      <div className="grid gap-4 lg:grid-cols-2">
        {[0, 1, 2, 3].map((i) => (
          <Skeleton key={i} className="h-[320px] w-full" />
        ))}
      </div>
    )
  }

  if (rows.length === 0) {
    return (
      <EmptyState
        icon={Package}
        title="No hay ventas en el período"
        description="Elegí otro rango de fechas para ver los gráficos."
      />
    )
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <ChartCard
        title="Más facturado"
        description="La barra entera es lo facturado; la parte oscura, lo que quedó de utilidad."
        footnote={sinCosto > 0
          ? `${sinCosto} de estos artículos no tienen costo cargado: su barra se ve como utilidad pura, pero es costo sin registrar, no margen.`
          : undefined}
      >
        <RankingBarChart
          data={rows.map((r) => ({
            label: r.name,
            value: r.total,
            overlayValue: utilityOf(r),
          }))}
          valueLabel="Total facturado"
          overlay={{ label: "Utilidad", restLabel: "Costo y comisión" }}
          formatValue={money}
        />
      </ChartCard>

      <ChartCard title="Más vendido" description="Por unidades, que rara vez es el mismo orden que por facturación.">
        <RankingBarChart
          data={rows.map((r) => ({ label: r.name, value: r.usold }))}
          valueLabel="Unidades"
          formatValue={units}
        />
      </ChartCard>

      {/* Dona y no barras: acá la pregunta es qué PORCIÓN se lleva cada uno,
          no quién es el primero. Los dos de arriba sí son rankings. */}
      <ChartCard title="Por categoría" description="Qué porción de la facturación se lleva cada categoría.">
        <CompositionDonutChart
          data={porCategoria.map((c) => ({ label: c.label, value: c.total }))}
          formatValue={money}
        />
      </ChartCard>

      <ChartCard
        title="Por marca"
        description="Qué porción se lleva cada marca."
        footnote={sinMarca > 0
          ? `${sinMarca} artículos vendidos no tienen marca cargada y quedan fuera de este gráfico.`
          : undefined}
      >
        <CompositionDonutChart
          data={porMarca.map((m) => ({ label: m.label, value: m.total }))}
          formatValue={money}
          restLabel="Otras marcas"
        />
      </ChartCard>
    </div>
  )
}

/** Card de un gráfico. El caso "sin datos" lo resuelve `RankingBarChart`, que
 *  es quien sabe si alguna fila tiene valor. */
function ChartCard({
  title,
  description,
  footnote,
  children,
}: {
  title: string
  description: string
  /** Advertencia sobre la calidad del dato, al pie del gráfico. */
  footnote?: string
  children: React.ReactNode
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base font-semibold tracking-tight">{title}</CardTitle>
        <CardDescription className="text-xs">{description}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        {children}
        {footnote && <p className="text-xs text-muted-foreground">{footnote}</p>}
      </CardContent>
    </Card>
  )
}

function BackLink() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports">
        <ArrowLeft className="size-3.5" />
        Volver a reportes
      </Link>
    </Button>
  )
}

/* ─────────────────────────── Ranking (view=general) ─────────────────────────── */

/**
 * Kinds que van a la pestaña SERVICIOS. Todo lo demás vendible es Producto.
 *
 * Se listan los de servicio y no los de producto porque la lista corta es la
 * que se puede mantener: un kind nuevo en el catálogo (otro tipo de combo,
 * otra forma de producción) es mercadería salvo prueba en contrario, y cae del
 * lado correcto sin que nadie se acuerde de tocar esto.
 */
const SERVICE_KINDS = new Set(["servicio", "servicio_sesiones", "pack"])

/**
 * `only` separa Productos de Servicios por el KIND canónico del artículo.
 *
 * El criterio anterior —`trackInventory`— estaba mal y el owner lo reportó:
 * metía combos y producción adentro de Servicios, porque ninguno de los dos
 * lleva stock. `itemType` tampoco alcanza: servicio, pack de sesiones y
 * producción directa son los tres `product`.
 *
 * Sin `kind` (artículo anterior a la mig 15, o `/api` anterior a este cambio)
 * se cae a `trackInventory`, que era el criterio viejo — imperfecto pero mejor
 * que esconder la fila. Y sin ninguno de los dos, la fila pasa: mostrar de más
 * es preferible a que un artículo vendido no aparezca en ningún lado.
 */
function RankingTab({
  range,
  only,
}: {
  range: DateRangeValue
  only?: "product" | "service"
}) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "general" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<ProductsReportResponse>(
    "products",
    opts,
  )

  const rows = React.useMemo(() => {
    const all = data?.rows ?? []
    if (!only) return all
    return all.filter((r) => {
      const kind = (r.kind ?? "").trim()
      if (kind !== "") {
        const esServicio = SERVICE_KINDS.has(kind)
        return only === "product" ? !esServicio : esServicio
      }
      if (r.trackInventory === undefined) return true
      return only === "product" ? r.trackInventory : !r.trackInventory
    })
  }, [data, only])

  const columns = React.useMemo<ColumnDef<ProductRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Artículo",
        cell: ({ row }) => {
          const r = row.original
          return (
            <ItemLink id={r.id}>
              <div className="flex flex-col">
                <div className="flex items-center gap-1.5 font-medium">
                  <span className="truncate">{r.name || "(sin nombre)"}</span>
                  {r.deleted && (
                    <Badge variant="secondary" className="gap-1 text-[10px]">
                      <Trash2 className="size-3" /> eliminado
                    </Badge>
                  )}
                </div>
                {(r.sku || r.brand || r.category) && (
                  <span className="text-[10px] text-muted-foreground tabular-nums">
                    {[r.sku, r.brand, r.category].filter(Boolean).join(" · ")}
                  </span>
                )}
              </div>
            </ItemLink>
          )
        },
        meta: { label: "Artículo" },
      },
      {
        accessorKey: "usold",
        header: "Vendidos",
        cell: ({ getValue }) => (
          <span className="tabular-nums">
            {formatInt(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Vendidos", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Total", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "discount",
        header: "Descuento",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          if (v <= 0) return <span className="text-muted-foreground">—</span>
          return (
            <span className="tabular-nums text-amber-600">
              {formatMoney(v, bootstrap)}
            </span>
          )
        },
        meta: { label: "Descuento", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "cogs",
        header: "Costo",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Costo (COGS)", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "utility",
        header: "Utilidad",
        cell: ({ row }) => {
          const r = row.original
          // El backend calcula utility = (total - cogs) - comission antes de devolver.
          // Fallback al cálculo cliente si no llegó (versión de service antigua).
          const u =
            typeof r.utility === "number"
              ? r.utility
              : r.total - r.cogs - r.comission
          if (u === 0) {
            return <span className="text-muted-foreground tabular-nums">—</span>
          }
          return (
            <span
              className={
                u > 0
                  ? "tabular-nums text-emerald-600"
                  : "tabular-nums text-destructive"
              }
            >
              {formatMoney(u, bootstrap)}
            </span>
          )
        },
        meta: { label: "Utilidad", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  // Totalizadores agregados — el legacy los muestra en el footer.
  const totals = React.useMemo(() => {
    let total = 0
    let cogs = 0
    let usold = 0
    let utility = 0
    rows.forEach((r) => {
      total += r.total
      cogs += r.cogs
      usold += r.usold
      utility +=
        typeof r.utility === "number" ? r.utility : r.total - r.cogs - r.comission
    })
    return { total, cogs, usold, utility }
  }, [rows])

  return (
    <div className="flex flex-col gap-6">
      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {!isLoading && rows.length > 0 && (
        <StatsRow>
          <StatTile label="Artículos" value={rows.length.toString()} />
          <StatTile
            label="Unidades vendidas"
            value={formatInt(totals.usold, bootstrap)}
          />
          <StatTile
            label="Total facturado"
            value={formatMoney(totals.total, bootstrap)}
            emphasis
          />
          <StatTile
            label="Costo total"
            value={formatMoney(totals.cogs, bootstrap)}
          />
          <StatTile
            label="Utilidad"
            value={formatMoney(totals.utility, bootstrap)}
            emphasis
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-products"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por nombre, SKU, marca o categoría…"
        exportFileName="productos"
        emptyMessage={
          <div className="flex flex-col items-center gap-2 text-muted-foreground">
            <Package className="size-8 opacity-30" />
            <p>No se vendieron artículos en este período.</p>
            <p className="text-xs">Ajustá el rango de fechas y volvé a consultar.</p>
          </div>
        }
      />
    </div>
  )
}

/* ─────────────────────────── Detallado (view=detail) ─────────────────────────── */

function DetailTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "detail" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<ProductsDetailReportResponse>(
    "products",
    opts,
  )

  const rows = data?.rows ?? []

  const columns = React.useMemo<ColumnDef<ProductDetailRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-sm whitespace-nowrap">
            {formatDateTime((getValue() as string) ?? "", "d MMM yyyy HH:mm")}
          </span>
        ),
        meta: { label: "Fecha", className: "tabular-nums" },
      },
      {
        accessorKey: "name",
        header: "Artículo",
        cell: ({ row }) => {
          const r = row.original
          return (
            <ItemLink id={r.itemId}>
              <div className="flex flex-col">
                <div className="flex items-center gap-1.5 font-medium">
                  <span className="truncate">{r.name || "(sin nombre)"}</span>
                  {r.deleted && (
                    <Badge variant="secondary" className="gap-1 text-[10px]">
                      <Trash2 className="size-3" /> eliminado
                    </Badge>
                  )}
                </div>
                {r.sku && (
                  <span className="text-[10px] text-muted-foreground tabular-nums">
                    {r.sku}
                  </span>
                )}
              </div>
            </ItemLink>
          )
        },
        meta: { label: "Artículo" },
      },
      {
        accessorKey: "invoiceNo",
        header: "Comprobante",
        cell: ({ getValue }) => (
          <span className="text-sm tabular-nums">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Comprobante" },
      },
      {
        accessorKey: "customerName",
        header: "Cliente",
        cell: ({ getValue }) => (
          <span className="text-sm">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Cliente" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        cell: ({ getValue }) => (
          <span className="text-xs text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Usuario" },
      },
      {
        accessorKey: "usold",
        header: "Cantidad",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatInt(Number(getValue()) || 0, bootstrap)}</span>
        ),
        meta: { label: "Cantidad", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "discount",
        header: "Descuento",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          if (v <= 0) return <span className="text-muted-foreground">—</span>
          return <span className="tabular-nums text-amber-600">{formatMoney(v, bootstrap)}</span>
        },
        meta: { label: "Descuento", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "tax",
        header: "Impuesto",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Impuesto", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "cogs",
        header: "Costo",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Costo (COGS)", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "utility",
        header: "Utilidad",
        cell: ({ getValue }) => {
          const u = Number(getValue()) || 0
          if (u === 0) return <span className="text-muted-foreground tabular-nums">—</span>
          return (
            <span className={`tabular-nums ${u > 0 ? "text-emerald-600" : "text-destructive"}`}>
              {formatMoney(u, bootstrap)}
            </span>
          )
        },
        meta: { label: "Utilidad", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "total",
        header: "Total",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Total", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-4">
      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {!isLoading && rows.length >= 2000 && (
        <p className="text-xs text-muted-foreground">
          Mostrando las primeras 2.000 líneas del período. Acortá el rango de
          fechas para ver el detalle completo.
        </p>
      )}

      <DataTable
        tableId="report-products-detail"
        data={rows}
        columns={columns}
        getRowId={(r) => r.itemSoldId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por artículo, cliente, comprobante…"
        exportFileName="productos_detallado"
        emptyMessage={
          <EmptyState
            icon={ListTree}
            title="Sin líneas de venta en este período"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}
