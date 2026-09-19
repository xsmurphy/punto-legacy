"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname, useRouter, useSearchParams } from "next/navigation"
import { Plus, Receipt } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DataTable } from "@/components/data-table/data-table"
import {
  DateRangePicker,
  rangeToBackend,
} from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type PurchaseReportRow,
  type PurchasesReportResponse,
} from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { SaleType } from "@/lib/domain/sale-type"
import { EmptyState } from "@/components/empty-state"
import { BackLink } from "@/components/page/back-link"
import { CostEvolutionTab } from "@/components/domain/reports/purchases/cost-evolution-tab"
import {
  CostItemFilter,
  CostSupplierFilter,
} from "@/components/domain/reports/purchases/cost-evolution-filters"

const TABS = ["compras", "costos"] as const
type TabKey = (typeof TABS)[number]

function isTab(v: string | null): v is TabKey {
  return v !== null && (TABS as readonly string[]).includes(v)
}

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/**
 * Reporte de compras y gastos — espejo del listado de `panel/a_purchase.php`,
 * pero conceptualmente vive como REPORTE (no como sección de primer nivel).
 * El acceso al form de crear compra se hace via el item "Compras y Gastos"
 * del menú user del sidebar (→ `/purchase`), no desde acá. El botón "Nueva"
 * acá es un atajo a la misma URL.
 *
 * Consume el endpoint canónico de reporte (`/v1/reports/purchases?view=general`,
 * `Reports/PurchasesService::general`) — no el CRUD `/v1/purchases` (ese sigue
 * siendo el correcto para el form de `/purchase` y su detalle). El de reporte
 * ya trae `authNo`/`prefix`/`userName` resueltos y cubre compras contado
 * (transactionType=1) y crédito (transactionType=4).
 *
 * Filtros activos: rango de fechas (DateRangePicker, server-side).
 * Click en fila → `/purchase/[id]` para ver el detalle completo (mismo id:
 * `transactionId` — el CRUD busca por transactionId, ver PurchasesService::find).
 *
 * Dos pestañas sobre el mismo período (`?tab=`):
 *   Compras              → el listado de siempre.
 *   Evolución de costos  → `CostEvolutionTab`, con artículo y proveedor como
 *                          filtros propios en la URL (`?itemId=&supplierId=`),
 *                          así la ficha del artículo abre el reporte ya filtrado.
 * El período va en el encabezado y se pasa hacia abajo (un solo `useDateRange`).
 */
export default function PurchasesReportPage() {
  // useSearchParams() requiere Suspense boundary (Next App Router) — mismo
  // patrón que items/[id]/page.tsx.
  return (
    <React.Suspense fallback={null}>
      <PurchasesReportPageInner />
    </React.Suspense>
  )
}

function PurchasesReportPageInner() {
  const router = useRouter()
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const opts = React.useMemo(() => rangeToBackend(range), [range])

  const pathname = usePathname()
  const searchParams = useSearchParams()
  const requested = searchParams.get("tab")
  const tab: TabKey = isTab(requested) ? requested : "compras"
  const rawItem = searchParams.get("itemId") ?? ""
  const rawSupplier = searchParams.get("supplierId") ?? ""
  const itemId = UUID_RE.test(rawItem) ? rawItem : ""
  const supplierId = UUID_RE.test(rawSupplier) ? rawSupplier : ""

  // Nombres de los filtros: los pone el picker al elegir, o el reporte cuando
  // se entra con el id ya en la URL.
  const [itemName, setItemName] = React.useState("")
  const [supplierName, setSupplierName] = React.useState("")
  const onNames = React.useCallback((n: { item?: string; supplier?: string }) => {
    if (n.item) setItemName(n.item)
    if (n.supplier) setSupplierName(n.supplier)
  }, [])

  const setParams = React.useCallback(
    (patch: Record<string, string>) => {
      const params = new URLSearchParams(searchParams.toString())
      for (const [k, v] of Object.entries(patch)) {
        if (v) params.set(k, v)
        else params.delete(k)
      }
      router.replace(`${pathname}?${params.toString()}`, { scroll: false })
    },
    [pathname, router, searchParams],
  )
  const setTab = React.useCallback((v: string) => setParams({ tab: v }), [setParams])
  const setItem = React.useCallback(
    (id: string, name: string) => {
      setItemName(name)
      setParams({ tab: "costos", itemId: id })
    },
    [setParams],
  )
  const setSupplier = React.useCallback(
    (id: string, name: string) => {
      setSupplierName(name)
      setParams({ supplierId: id })
    },
    [setParams],
  )

  const purchases = useReport<PurchasesReportResponse>("purchases", {
    from: opts.from,
    to: opts.to,
    params: { view: "general" },
    enabled: tab === "compras",
  })

  const columns = React.useMemo<ColumnDef<PurchaseReportRow>[]>(
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
          row.original.supplierName || (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        id: "document",
        header: "Documento",
        meta: { label: "Documento" },
        cell: ({ row }) => {
          const { prefix, invoiceNo } = row.original
          if (!invoiceNo) return <span className="text-muted-foreground">—</span>
          const num = String(invoiceNo).padStart(7, "0")
          return (
            <span className="font-mono text-xs">
              {prefix ? `${prefix}-${num}` : num}
            </span>
          )
        },
      },
      {
        accessorKey: "authNo",
        header: "Timbrado",
        meta: { label: "Timbrado", className: "text-muted-foreground" },
        cell: ({ row }) => (
          <span className="text-muted-foreground text-xs">
            {row.original.authNo || "—"}
          </span>
        ),
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        meta: { label: "Sucursal" },
        cell: ({ row }) =>
          row.original.outletName || (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "userName",
        header: "Usuario",
        meta: { label: "Usuario" },
        cell: ({ row }) =>
          row.original.userName || (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "category",
        header: "Categoría",
        meta: { label: "Categoría" },
        cell: ({ row }) =>
          row.original.category || (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "costCenter",
        header: "Centro de costo",
        meta: { label: "Centro de costo" },
        cell: ({ row }) =>
          row.original.costCenter || (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        accessorKey: "dueDate",
        header: "Vencimiento",
        meta: { label: "Vencimiento" },
        cell: ({ row }) =>
          row.original.dueDate ? (
            formatDate(row.original.dueDate)
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        id: "condition",
        header: "Condición",
        meta: { label: "Condición" },
        cell: ({ row }) => {
          // Etiquetas propias, no las del mapa global: en esta tabla la columna
          // es "Condición" y ya se sabe que son compras, así que dice "Contado"
          // y no "Compra al contado".
          const t = row.original.transactionType
          if (t === SaleType.CashPurchase) return <Badge variant="secondary">Contado</Badge>
          if (t === SaleType.CreditPurchase) return <Badge variant="outline">Crédito</Badge>
          return <Badge variant="outline">{t}</Badge>
        },
      },
      {
        accessorKey: "total",
        header: () => <div className="text-right">Total</div>,
        meta: {
          label: "Total",
          className: "text-right",
          footerSum: true,
          footerFormat: (sum) => (
            <div className="text-right font-medium tabular-nums">
              {formatMoney(sum, bootstrap)}
            </div>
          ),
        },
        cell: ({ row }) => (
          <div className="text-right font-medium tabular-nums">
            {formatMoney(row.original.total, bootstrap)}
          </div>
        ),
      },
      {
        accessorKey: "transactionStatus",
        header: "Estado",
        meta: { label: "Estado" },
        cell: ({ row }) => {
          // Estado del DOCUMENTO, no de pago: decía "Completa" para status=1
          // y se leía como "pagada" — una compra a crédito impaga se veía
          // saldada. El estado de pago es `transactionComplete`, otro campo.
          const s = row.original.transactionStatus
          if (s === "1") return <Badge variant="secondary">Vigente</Badge>
          if (s === "6") return <Badge variant="destructive">Anulada</Badge>
          return <Badge variant="outline">{s}</Badge>
        },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink href="/reports" label="Volver a reportes" />
          <h1 className="text-2xl font-semibold">Compras y gastos</h1>
          <p className="text-sm text-muted-foreground">
            Historial de facturas de compra a proveedores
          </p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
          {tab === "costos" && (
            <>
              <CostItemFilter value={itemId} displayName={itemName} onChange={setItem} />
              <CostSupplierFilter
                value={supplierId}
                displayName={supplierName}
                onChange={setSupplier}
              />
            </>
          )}
          <DateRangePicker value={range} onChange={setRange} />
          {tab === "compras" && (
            <Button asChild>
              <Link href="/purchase">
                <Plus className="mr-1.5 size-4" />
                Nueva compra
              </Link>
            </Button>
          )}
        </div>
      </header>

      <Tabs value={tab} onValueChange={setTab} className="flex flex-col gap-4">
        <TabsList>
          <TabsTrigger value="compras">Compras</TabsTrigger>
          <TabsTrigger value="costos">Evolución de costos</TabsTrigger>
        </TabsList>

        <TabsContent value="compras" className="m-0">
          <DataTable<PurchaseReportRow>
            tableId="purchases-report"
            data={purchases.data?.rows ?? []}
            columns={columns}
            getRowId={(r) => r.transactionId}
            isLoading={purchases.isLoading}
            onRowClick={(r) => router.push(`/purchase/${r.transactionId}`)}
            emptyMessage={
              <EmptyState
                icon={Receipt}
                title="Sin compras registradas en este período"
                description="Ajustá el rango de fechas o registrá una compra nueva."
                actions={
                  <Button asChild size="sm" variant="outline">
                    <Link href="/purchase">
                      <Plus className="mr-1.5 size-4" />
                      Registrar primera compra
                    </Link>
                  </Button>
                }
              />
            }
            exportFileName="compras"
          />
        </TabsContent>

        <TabsContent value="costos" className="m-0">
          <CostEvolutionTab
            from={opts.from}
            to={opts.to}
            itemId={itemId}
            supplierId={supplierId}
            enabled={tab === "costos"}
            bootstrap={bootstrap}
            onSelectItem={setItem}
            onNames={onNames}
          />
        </TabsContent>
      </Tabs>
    </div>
  )
}

function formatDate(s: string): string {
  try {
    const d = new Date(s)
    const dd = String(d.getDate()).padStart(2, "0")
    const mm = String(d.getMonth() + 1).padStart(2, "0")
    return `${dd}/${mm}/${d.getFullYear()}`
  } catch {
    return s
  }
}
