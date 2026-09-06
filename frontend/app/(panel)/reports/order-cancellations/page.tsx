"use client"

/**
 * Reporte de Anulaciones de comanda.
 *
 * Backend: GET /v1/reports/order-cancellations?from=&to=&outletId=
 * → { rows: [...], totals: { count, amount } }
 *
 * QUÉ CONTESTA. Lo anulado no se borra: sale del total y queda tachado en la
 * comanda digital. Eso está bien para la operación del día, pero deja una
 * pregunta abierta para el dueño —cuánto se anula, quién anula y por qué— que
 * hasta ahora había que reconstruir abriendo orden por orden. Cada fila es un
 * evento de anulación con su motivo y su autor; `amount` es la plata que dejó
 * de cobrarse.
 *
 * DOS GRANOS en la misma tabla, distinguidos por `scope`: una línea suelta
 * ("2× Milanesa") y la orden ENTERA ("Orden completa · 8 ítems"). Hasta
 * 2026-09-06 esta pantalla solo mostraba el primero — una comanda de ocho
 * líneas cancelada de un click no aparecía en ninguna fila, que era justo el
 * caso más grave. No se separaron en dos reportes porque la pregunta del dueño
 * es UNA ("¿cuánto se anuló y quién?") y dos pantallas que la contestan a
 * medias obligan a sumarlas a mano.
 *
 * Los montos ya vienen sin doble contar: si a una orden le anularon líneas una
 * por una y después la cancelaron entera, el backend le resta a la fila de la
 * orden lo que ya contaron las filas de ítem. Las filas SUMAN al total, sin
 * aritmética de por medio acá.
 *
 * Date-scoped como el resto de los reportes. El filtro de sucursal va en el
 * panel de filtros del `<DataTable>` (context/14 §3) y solo aparece con más de
 * una sucursal — con una sola no acota nada.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Ban } from "lucide-react"

import { Button } from "@/components/ui/button"
import { DataTable, FilterField } from "@/components/data-table/data-table"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { DateRangePicker, rangeToBackend } from "@/components/date-range-picker"
import { useDateRange } from "@/hooks/use-date-range"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type OrderCancellationRow,
  type OrderCancellationsResponse,
} from "@/hooks/use-reports"
import { formatDateTime } from "@/lib/format-date"
import { formatInt, formatMoney } from "@/lib/format"
import { ACTOR_KIND_LABEL } from "@/lib/orders/order-display"

/** Sentinel del `<Select>`: Radix no acepta `value=""` (context/20 §4). */
const ALL_OUTLETS = "all"

export default function OrderCancellationsReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { range, setRange } = useDateRange()
  const [outletId, setOutletId] = React.useState<string>(ALL_OUTLETS)

  const outlets = bootstrap?.outlets ?? []
  const hasOutletFilter = outlets.length > 1
  const activeFilterCount = outletId !== ALL_OUTLETS ? 1 : 0

  const opts = React.useMemo(
    () => ({
      ...rangeToBackend(range),
      params: outletId !== ALL_OUTLETS ? { outletId } : undefined,
    }),
    [range, outletId],
  )

  const { data, isLoading, error } = useReport<OrderCancellationsResponse>(
    "order-cancellations",
    opts,
  )
  const rows = React.useMemo(() => data?.rows ?? [], [data])
  const totals = data?.totals

  const columns = React.useMemo<ColumnDef<OrderCancellationRow>[]>(
    () => [
      {
        accessorKey: "at",
        header: "Fecha",
        cell: ({ getValue }) => {
          const v = getValue() as string
          if (!v) return <span className="text-muted-foreground">—</span>
          return (
            <span className="tabular-nums">{formatDateTime(v, "d MMM yyyy HH:mm")}</span>
          )
        },
        meta: { label: "Fecha" },
      },
      {
        id: "order",
        accessorFn: (r) => (r.orderNumber !== null ? `#${r.orderNumber}` : ""),
        header: "Orden",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium tabular-nums">
                {r.orderNumber !== null ? `#${r.orderNumber}` : "—"}
              </span>
              {r.spaceName && (
                <span className="text-xs text-muted-foreground">{r.spaceName}</span>
              )}
            </div>
          )
        },
        meta: { label: "Orden" },
      },
      {
        id: "what",
        // `accessorFn` y no `accessorKey`: el buscador del DataTable filtra
        // sobre este texto, y con `itemName` a secas una orden entera —donde es
        // `null`— sería invisible para cualquier búsqueda.
        accessorFn: (r) =>
          r.scope === "order" ? "Orden completa" : r.itemName || "(sin nombre)",
        header: "Qué se anuló",
        cell: ({ row }) => {
          const r = row.original
          if (r.scope === "order") {
            return (
              <div className="flex flex-col">
                <span className="font-medium">Orden completa</span>
                <span className="text-xs text-muted-foreground">
                  {r.itemCount === 1
                    ? "1 ítem"
                    : `${formatInt(r.itemCount ?? 0, bootstrap)} ítems`}
                </span>
              </div>
            )
          }
          return <span className="font-medium">{r.itemName || "(sin nombre)"}</span>
        },
        meta: { label: "Qué se anuló" },
      },
      {
        accessorKey: "qty",
        header: "Cantidad",
        cell: ({ row }) => {
          const r = row.original
          // La orden entera no tiene una cantidad propia: sus líneas son de
          // productos distintos y sumarlas daría un número sin unidad. La
          // cantidad de líneas ya se lee arriba, en "Orden completa".
          if (r.scope === "order") return <span className="text-muted-foreground">—</span>
          return <span className="tabular-nums">{formatInt(r.qty ?? 0, bootstrap)}</span>
        },
        meta: { label: "Cantidad", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "amount",
        header: "Monto anulado",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Monto anulado", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "reason",
        header: "Motivo",
        cell: ({ getValue }) => {
          const v = (getValue() as string | null) ?? ""
          // El backend exige el motivo, así que el vacío solo puede venir de
          // una anulación anterior a esta regla. Se dice, no se disimula.
          return v !== "" ? (
            <span>{v}</span>
          ) : (
            <span className="text-muted-foreground">Sin motivo registrado</span>
          )
        },
        meta: { label: "Motivo" },
      },
      {
        id: "actor",
        accessorFn: (r) => r.actorName ?? ACTOR_KIND_LABEL[r.actorKind],
        header: "Anuló",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span>{r.actorName || "(desconocido)"}</span>
              <span className="text-xs text-muted-foreground">
                {ACTOR_KIND_LABEL[r.actorKind]}
              </span>
            </div>
          )
        },
        meta: { label: "Anuló" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Anulaciones de comanda</h1>
          <p className="text-sm text-muted-foreground">
            Ítems sacados de una comanda y órdenes canceladas enteras — cuánto
            se anuló, quién lo hizo y con qué motivo.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {!isLoading && totals && totals.count > 0 && (
        <StatsRow>
          <StatTile label="Anulaciones" value={formatInt(totals.count, bootstrap)} />
          <StatTile
            label="Monto anulado"
            value={formatMoney(totals.amount, bootstrap)}
            emphasis
          />
        </StatsRow>
      )}

      <DataTable
        tableId="report-order-cancellations"
        data={rows}
        columns={columns}
        getRowId={(r) => r.eventId}
        isLoading={isLoading}
        searchPlaceholder="Buscar por artículo, motivo, orden…"
        exportFileName="anulaciones_de_comanda"
        activeFilterCount={activeFilterCount}
        onClearFilters={() => setOutletId(ALL_OUTLETS)}
        filtersSlot={
          hasOutletFilter ? (
            <FilterField label="Sucursal">
              <Select value={outletId} onValueChange={setOutletId}>
                <SelectTrigger className="h-9">
                  <SelectValue placeholder="Sucursal" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value={ALL_OUTLETS}>Todas las sucursales</SelectItem>
                  {outlets.map((o) => (
                    <SelectItem key={o.id} value={o.id}>
                      {o.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </FilterField>
          ) : undefined
        }
        emptyMessage={
          <EmptyState
            icon={Ban}
            title="Sin anulaciones de comanda"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
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
