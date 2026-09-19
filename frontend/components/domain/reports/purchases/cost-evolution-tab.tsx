"use client"

/**
 * Pestaña "Evolución de costos" de Reportes › Compras.
 *
 * Histórico del costo de compra de cada artículo, asociado al proveedor: el
 * mismo producto cuesta distinto según a quién se le compra. Solo lectura.
 *
 *   Sin artículo → un artículo por fila con su último costo del período, el
 *                  anterior del mismo proveedor y la variación (ordenable, para
 *                  ver qué subió más). Clic en una fila abre ese artículo.
 *   Con artículo → gráfico del costo unitario en el tiempo (una línea por
 *                  proveedor), el último costo de cada proveedor marcando el más
 *                  barato, y las compras con su variación.
 *
 * Los filtros (artículo, proveedor) y el período vienen de la página: el
 * rango se toma de arriba y nunca se abre un segundo `useDateRange()`.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, TrendingUp } from "lucide-react"
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from "recharts"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  ChartContainer,
  ChartLegend,
  ChartLegendContent,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import {
  useReport,
  type PurchaseCostItemRow,
  type PurchaseCostRow,
  type PurchaseCostSupplier,
  type PurchaseCostsResponse,
} from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { resolveNumberLocale } from "@/lib/tenant-locale"
import type { Bootstrap } from "@/lib/types/bootstrap"

const NO_SUPPLIER = "__none__"

interface Props {
  from?: string
  to?: string
  itemId: string
  supplierId: string
  enabled: boolean
  bootstrap: Bootstrap | undefined
  /** Abre un artículo desde el listado (la página lo pone en la URL). */
  onSelectItem: (id: string, name: string) => void
  /** La respuesta ya trae los nombres: la página los usa en los filtros. */
  onNames: (names: { item?: string; supplier?: string }) => void
}

export function CostEvolutionTab({
  from,
  to,
  itemId,
  supplierId,
  enabled,
  bootstrap,
  onSelectItem,
  onNames,
}: Props) {
  const report = useReport<PurchaseCostsResponse>("purchases", {
    from,
    to,
    params: { view: "costs", itemId, supplierId },
    enabled,
  })
  const data = report.data

  // Nombres para los filtros cuando se entró con los ids en la URL.
  React.useEffect(() => {
    if (!data) return
    if (data.mode === "item") {
      const sup = supplierId
        ? data.suppliers.find((s) => s.supplierId === supplierId)?.supplierName
        : undefined
      onNames({ item: data.item?.itemName, supplier: sup })
    } else if (supplierId) {
      onNames({ supplier: data.items.find((i) => i.supplierId === supplierId)?.supplierName })
    }
  }, [data, supplierId, onNames])

  const pct = React.useMemo(() => {
    const nf = new Intl.NumberFormat(resolveNumberLocale(bootstrap), {
      maximumFractionDigits: 1,
      signDisplay: "exceptZero",
    })
    return (v: number | null) => (v === null ? null : `${nf.format(v)} %`)
  }, [bootstrap])

  if (report.error) {
    return (
      <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
        <AlertCircle className="mt-0.5 size-4 text-destructive" />
        <div>
          <p className="font-medium">No se pudo cargar el reporte</p>
          <p className="text-sm text-muted-foreground">{report.error.message}</p>
        </div>
      </div>
    )
  }

  if (!itemId) {
    return (
      <ItemsTable
        rows={data?.mode === "items" ? data.items : []}
        isLoading={report.isLoading}
        bootstrap={bootstrap}
        pct={pct}
        onSelectItem={onSelectItem}
      />
    )
  }

  const rows = data?.mode === "item" ? data.rows : []
  const suppliers = data?.mode === "item" ? data.suppliers : []

  return (
    <div className="flex flex-col gap-4">
      {rows.length > 0 && <CostChart rows={rows} bootstrap={bootstrap} />}
      {suppliers.length > 0 && (
        <SupplierComparison suppliers={suppliers} bootstrap={bootstrap} />
      )}
      <PurchasesTable rows={rows} isLoading={report.isLoading} bootstrap={bootstrap} pct={pct} />
    </div>
  )
}

/* ───────────────────────── variación ───────────────────────── */

function Variation({ value, label }: { value: number | null; label: string | null }) {
  if (value === null || label === null) {
    return <span className="text-muted-foreground">—</span>
  }
  // Que el costo suba es lo que hay que mirar: va en rojo. Si baja, normal.
  return (
    <span className={value > 0 ? "font-medium text-destructive" : undefined}>{label}</span>
  )
}

/* ───────────────────────── sin artículo ───────────────────────── */

function ItemsTable({
  rows,
  isLoading,
  bootstrap,
  pct,
  onSelectItem,
}: {
  rows: PurchaseCostItemRow[]
  isLoading: boolean
  bootstrap: Bootstrap | undefined
  pct: (v: number | null) => string | null
  onSelectItem: (id: string, name: string) => void
}) {
  const columns = React.useMemo<ColumnDef<PurchaseCostItemRow>[]>(
    () => [
      {
        accessorKey: "itemName",
        header: "Artículo",
        meta: { label: "Artículo" },
        cell: ({ row }) => row.original.itemName || <span className="text-muted-foreground">—</span>,
      },
      {
        accessorKey: "supplierName",
        header: "Proveedor",
        meta: { label: "Proveedor" },
        cell: ({ row }) =>
          row.original.supplierName || <span className="text-muted-foreground">Sin proveedor</span>,
      },
      {
        accessorKey: "lastDate",
        header: "Última compra",
        meta: { label: "Última compra" },
        cell: ({ row }) => formatDate(row.original.lastDate),
      },
      {
        accessorKey: "lastCost",
        header: () => <div className="text-right">Último costo</div>,
        meta: { label: "Último costo", className: "text-right" },
        cell: ({ row }) => (
          <div className="text-right tabular-nums">{formatMoney(row.original.lastCost, bootstrap)}</div>
        ),
      },
      {
        accessorKey: "previousCost",
        header: () => <div className="text-right">Costo anterior</div>,
        meta: { label: "Costo anterior", className: "text-right" },
        cell: ({ row }) => (
          <div className="text-right tabular-nums">
            {row.original.previousCost === null ? (
              <span className="text-muted-foreground">—</span>
            ) : (
              formatMoney(row.original.previousCost, bootstrap)
            )}
          </div>
        ),
      },
      {
        accessorKey: "variationPct",
        header: () => <div className="text-right">Variación</div>,
        meta: { label: "Variación", className: "text-right" },
        sortUndefined: "last",
        cell: ({ row }) => (
          <div className="text-right tabular-nums">
            <Variation value={row.original.variationPct} label={pct(row.original.variationPct)} />
          </div>
        ),
      },
    ],
    [bootstrap, pct],
  )

  return (
    <DataTable<PurchaseCostItemRow>
      tableId="purchase-costs-items"
      data={rows}
      columns={columns}
      getRowId={(r) => r.itemId}
      isLoading={isLoading}
      onRowClick={(r) => onSelectItem(r.itemId, r.itemName)}
      searchPlaceholder="Buscar artículo o proveedor…"
      emptyMessage={
        <EmptyState
          icon={TrendingUp}
          title="Sin compras de artículos en este período"
          description="Ajustá el rango de fechas o el proveedor."
        />
      }
      exportFileName="evolucion-costos"
    />
  )
}

/* ───────────────────────── con artículo ───────────────────────── */

function supplierKey(id: string | null): string {
  return id ?? NO_SUPPLIER
}

function CostChart({
  rows,
  bootstrap,
}: {
  rows: PurchaseCostRow[]
  bootstrap: Bootstrap | undefined
}) {
  // Una serie por proveedor. Las claves del chart son `s0…sN` (un uuid no es
  // un nombre de variable CSS válido para `--color-…`).
  const { config, data, series } = React.useMemo(() => {
    const order: string[] = []
    const names = new Map<string, string>()
    for (const r of rows) {
      const k = supplierKey(r.supplierId)
      if (!names.has(k)) {
        order.push(k)
        names.set(k, r.supplierName || "Sin proveedor")
      }
    }
    const keyOf = new Map(order.map((k, i) => [k, `s${i}`]))
    const cfg: ChartConfig = {}
    order.forEach((k, i) => {
      cfg[`s${i}`] = { label: names.get(k) ?? "", color: `var(--chart-${(i % 5) + 1})` }
    })
    // Un punto por día: si el mismo proveedor vendió dos veces el mismo día,
    // queda la última compra (las filas vienen en orden cronológico).
    const byDay = new Map<string, Record<string, string | number>>()
    for (const r of rows) {
      const day = r.date.slice(0, 10)
      const point = byDay.get(day) ?? { day, label: formatDate(r.date) }
      point[keyOf.get(supplierKey(r.supplierId)) as string] = r.unitCost
      byDay.set(day, point)
    }
    return {
      config: cfg,
      data: [...byDay.values()],
      series: order.map((_, i) => `s${i}`),
    }
  }, [rows])

  return (
    <Card>
      <CardHeader>
        <CardTitle>Costo unitario por proveedor</CardTitle>
      </CardHeader>
      <CardContent>
        <ChartContainer config={config} className="h-[280px] w-full">
          <LineChart data={data} margin={{ top: 10, right: 12, left: 12, bottom: 0 }}>
            <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
            <XAxis
              dataKey="label"
              fontSize={10}
              stroke="var(--muted-foreground)"
              tickLine={false}
              axisLine={false}
            />
            <YAxis
              fontSize={10}
              stroke="var(--muted-foreground)"
              tickLine={false}
              axisLine={false}
              width={80}
              tickFormatter={(v: number) => formatMoney(v, bootstrap)}
            />
            <ChartTooltip
              content={
                <ChartTooltipContent
                  formatter={(value, name) => (
                    <div className="flex w-full items-center justify-between gap-3">
                      <span className="text-muted-foreground">
                        {config[name as string]?.label ?? name}
                      </span>
                      <span className="font-medium tabular-nums">
                        {formatMoney(Number(value) || 0, bootstrap)}
                      </span>
                    </div>
                  )}
                />
              }
            />
            <ChartLegend content={<ChartLegendContent />} />
            {series.map((key) => (
              <Line
                key={key}
                type="monotone"
                dataKey={key}
                stroke={`var(--color-${key})`}
                strokeWidth={2}
                dot={{ r: 3 }}
                connectNulls
              />
            ))}
          </LineChart>
        </ChartContainer>
      </CardContent>
    </Card>
  )
}

function SupplierComparison({
  suppliers,
  bootstrap,
}: {
  suppliers: PurchaseCostSupplier[]
  bootstrap: Bootstrap | undefined
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>Último costo por proveedor</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="divide-y">
          {suppliers.map((s) => (
            <div
              key={supplierKey(s.supplierId)}
              className="flex items-center justify-between gap-3 py-2.5 text-sm"
            >
              <div className="flex min-w-0 items-center gap-2">
                <span className="truncate font-medium">{s.supplierName || "Sin proveedor"}</span>
                {s.cheapest && <Badge variant="secondary">Más barato</Badge>}
              </div>
              <div className="flex shrink-0 items-center gap-4">
                <span className="text-muted-foreground">{formatDate(s.lastDate)}</span>
                <span className="w-32 text-right font-medium tabular-nums">
                  {formatMoney(s.lastCost, bootstrap)}
                </span>
              </div>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  )
}

function PurchasesTable({
  rows,
  isLoading,
  bootstrap,
  pct,
}: {
  rows: PurchaseCostRow[]
  isLoading: boolean
  bootstrap: Bootstrap | undefined
  pct: (v: number | null) => string | null
}) {
  const columns = React.useMemo<ColumnDef<PurchaseCostRow>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        meta: { label: "Fecha" },
        cell: ({ row }) => formatDate(row.original.date),
      },
      {
        accessorKey: "supplierName",
        header: "Proveedor",
        meta: { label: "Proveedor" },
        cell: ({ row }) =>
          row.original.supplierName || <span className="text-muted-foreground">Sin proveedor</span>,
      },
      {
        accessorKey: "units",
        header: () => <div className="text-right">Cantidad</div>,
        meta: { label: "Cantidad", className: "text-right" },
        cell: ({ row }) => (
          <div className="text-right tabular-nums">
            {new Intl.NumberFormat(resolveNumberLocale(bootstrap), {
              maximumFractionDigits: 3,
            }).format(row.original.units)}
          </div>
        ),
      },
      {
        accessorKey: "unitCost",
        header: () => <div className="text-right">Costo unitario</div>,
        meta: { label: "Costo unitario", className: "text-right" },
        cell: ({ row }) => (
          <div className="text-right font-medium tabular-nums">
            {formatMoney(row.original.unitCost, bootstrap)}
          </div>
        ),
      },
      {
        accessorKey: "variationPct",
        header: () => <div className="text-right">Variación</div>,
        meta: { label: "Variación", className: "text-right" },
        sortUndefined: "last",
        cell: ({ row }) => (
          <div className="text-right tabular-nums">
            <Variation value={row.original.variationPct} label={pct(row.original.variationPct)} />
          </div>
        ),
      },
      {
        id: "purchase",
        header: "",
        enableSorting: false,
        meta: { label: "Compra" },
        cell: ({ row }) => (
          <Button asChild variant="link" size="sm" className="h-auto p-0">
            <Link href={`/purchase/${row.original.transactionId}`}>Ver compra</Link>
          </Button>
        ),
      },
    ],
    [bootstrap, pct],
  )

  return (
    <DataTable<PurchaseCostRow>
      tableId="purchase-costs-item"
      data={rows}
      columns={columns}
      getRowId={(r) => r.transactionId}
      isLoading={isLoading}
      emptyMessage={
        <EmptyState
          icon={TrendingUp}
          title="Sin compras de este artículo en el período"
          description="Ajustá el rango de fechas o el proveedor."
        />
      }
      exportFileName="evolucion-costos-articulo"
    />
  )
}
