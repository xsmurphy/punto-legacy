"use client"

/**
 * Comisiones — el detalle liquidable del período.
 *
 * Sale de `GET /v1/reports/users?view=commissions`: por vendedor, cada venta
 * en la que participó con su comisión CONGELADA al vender. No se recalcula
 * nunca contra el porcentaje vigente: liquidar con la tasa de hoy una venta de
 * hace dos meses cambia la plata que ya se le prometió a alguien.
 *
 * Dos niveles y no uno: arriba el subtotal por persona —que es lo que se paga—
 * y abajo el detalle completo en un `<DataTable>` (con su export a Excel, que
 * es cómo sale esto hacia la liquidación). Tocar un vendedor del subtotal
 * filtra el detalle: es el "expandir" sin romper la tabla única, que es la que
 * exporta.
 *
 * El grano del detalle es (vendedor, venta): una venta con dos líneas del mismo
 * vendedor es UNA fila, porque el comprobante que se liquida es uno solo.
 */

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, Percent } from "lucide-react"

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { DataTable, FilterField } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { StatsRow, StatTile } from "@/components/stat-tile"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type UsersCommissionsResponse } from "@/hooks/use-reports"
import { formatDateTime } from "@/lib/format-date"
import { formatInt, formatMoney } from "@/lib/format"

/** Fila del detalle: la venta más el vendedor al que se le liquida. */
interface CommissionDetailRow {
  id: string
  userId: string
  sellerName: string
  transactionId: string
  date: string
  invoiceNo: string
  total: number
  comission: number
}

/** Sentinel del Select: un `value=""` lo rompe (context/20 §select con sentinel). */
const ALL_SELLERS = "__all"

export function UsersCommissionsTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { view: "commissions" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<UsersCommissionsResponse>("users", opts)
  const sellers = React.useMemo(() => data?.sellers ?? [], [data])
  const [seller, setSeller] = React.useState<string>(ALL_SELLERS)

  const allRows = React.useMemo<CommissionDetailRow[]>(
    () =>
      sellers.flatMap((s) =>
        s.rows.map((r) => ({
          id: `${s.userId}:${r.transactionId}`,
          userId: s.userId,
          sellerName: s.name || "(sin nombre)",
          transactionId: r.transactionId,
          date: r.date,
          invoiceNo: r.invoiceNo,
          total: r.total,
          comission: r.comission,
        })),
      ),
    [sellers],
  )
  const rows = React.useMemo(
    () => (seller === ALL_SELLERS ? allRows : allRows.filter((r) => r.userId === seller)),
    [allRows, seller],
  )

  const columns = React.useMemo<ColumnDef<CommissionDetailRow, unknown>[]>(
    () => [
      {
        accessorKey: "sellerName",
        header: "Vendedor",
        cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span>,
        meta: { label: "Vendedor" },
      },
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatDateTime(String(getValue() ?? ""))}
          </span>
        ),
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "invoiceNo",
        header: "Documento",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Documento" },
      },
      {
        accessorKey: "total",
        header: "Vendido",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Vendido", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "comission",
        header: "Comisión",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Comisión", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap],
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

  const t = data?.totals

  return (
    <div className="flex flex-col gap-6">
      <StatsRow>
        <StatTile
          label="Comisiones a liquidar"
          value={formatMoney(t?.comission ?? 0, bootstrap)}
          emphasis
          isLoading={isLoading}
        />
        <StatTile
          label="Ventas con comisión"
          value={formatMoney(t?.total ?? 0, bootstrap)}
          isLoading={isLoading}
        />
        <StatTile
          label="Comprobantes"
          value={formatInt(t?.tickets ?? 0, bootstrap)}
          isLoading={isLoading}
        />
      </StatsRow>

      {sellers.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base font-semibold tracking-tight">
              Subtotal por vendedor
            </CardTitle>
            <CardDescription className="text-xs">
              Lo que le corresponde a cada persona. Tocá una fila para ver solo sus ventas.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Vendedor</TableHead>
                  <TableHead className="text-right">Comprobantes</TableHead>
                  <TableHead className="text-right">Vendido</TableHead>
                  <TableHead className="text-right">Comisión</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {sellers.map((s) => (
                  <TableRow
                    key={s.userId}
                    data-state={seller === s.userId ? "selected" : undefined}
                    className="cursor-pointer"
                    onClick={() =>
                      setSeller((cur) => (cur === s.userId ? ALL_SELLERS : s.userId))
                    }
                  >
                    <TableCell className="font-medium">{s.name || "(sin nombre)"}</TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">
                      {formatInt(s.tickets, bootstrap)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">
                      {formatMoney(s.total, bootstrap)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums font-medium">
                      {formatMoney(s.comission, bootstrap)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      <DataTable
        tableId="report-users-commissions"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por vendedor, documento…"
        exportFileName="comisiones"
        filtersSlot={
          <FilterField label="Vendedor">
            <Select value={seller} onValueChange={setSeller}>
              <SelectTrigger>
                <SelectValue placeholder="Todos" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_SELLERS}>Todos</SelectItem>
                {sellers.map((s) => (
                  <SelectItem key={s.userId} value={s.userId}>
                    {s.name || "(sin nombre)"}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </FilterField>
        }
        activeFilterCount={seller === ALL_SELLERS ? 0 : 1}
        onClearFilters={() => setSeller(ALL_SELLERS)}
        emptyMessage={
          <EmptyState
            icon={Percent}
            title="Sin comisiones en el período"
            description="Ajustá el rango de fechas y volvé a consultar."
          />
        }
      />
    </div>
  )
}
