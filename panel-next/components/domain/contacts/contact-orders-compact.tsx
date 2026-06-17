"use client"

import * as React from "react"
import { ShoppingBag } from "lucide-react"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { EmptyState } from "@/components/empty-state"
import { useReport, type OrderRow, type OrdersReportResponse } from "@/hooks/use-reports"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney } from "@/lib/format"

const STATUS_MAP: Record<number, { label: string; variant: "default" | "secondary" | "outline" | "destructive" }> = {
  0: { label: "Pendiente", variant: "outline" },
  1: { label: "Pendiente", variant: "outline" },
  2: { label: "En espera", variant: "secondary" },
  3: { label: "En proceso", variant: "secondary" },
  4: { label: "Finalizado", variant: "default" },
  5: { label: "Enviado", variant: "default" },
  6: { label: "Cancelado", variant: "destructive" },
}

function niceDate(iso: string | null | undefined): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString(undefined, {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}

interface Props {
  customerId: string
}

export function ContactOrdersCompact({ customerId }: Props) {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const [search, setSearch] = React.useState("")

  const opts = React.useMemo(
    () => ({
      ...rangeToBackend(range),
      params: { customerId },
    }),
    [range, customerId],
  )

  const { data, isLoading } = useReport<OrdersReportResponse>("orders", opts)
  const rows: OrderRow[] = React.useMemo(() => data?.rows ?? [], [data])

  const filtered = React.useMemo(() => {
    if (!search.trim()) return rows
    const q = search.toLowerCase()
    return rows.filter(
      (r) =>
        r.orderNo?.toLowerCase().includes(q) ||
        r.customerName?.toLowerCase().includes(q),
    )
  }, [rows, search])

  return (
    <div className="flex flex-col gap-3">
      {/* Barra de filtros */}
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Input
          placeholder="Buscar orden…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-8 text-sm sm:max-w-[200px]"
        />
        <div className="sm:ml-auto">
          <DateRangePicker value={range} onChange={setRange} />
        </div>
      </div>

      {/* Lista */}
      {isLoading ? (
        <div className="flex flex-col gap-2">
          {[1, 2, 3].map((i) => (
            <Skeleton key={i} className="h-16 w-full rounded-lg" />
          ))}
        </div>
      ) : filtered.length === 0 ? (
        <EmptyState
          icon={ShoppingBag}
          title="Sin órdenes"
          description="No hay órdenes en el rango seleccionado."
          showMarquee={false}
          className="border-0 py-6"
        />
      ) : (
        <ul className="divide-y divide-border rounded-lg border">
          {filtered.map((row) => {
            const st = STATUS_MAP[row.status] ?? { label: String(row.status), variant: "secondary" as const }
            return (
              <li key={row.id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                <div className="flex min-w-0 flex-col gap-0.5">
                  <span className="font-medium tabular-nums truncate">{row.orderNo || "—"}</span>
                  <span className="text-xs text-muted-foreground truncate">{niceDate(row.date)}</span>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-0.5">
                  <span className="tabular-nums font-medium">{formatMoney(row.total, bootstrap)}</span>
                  <Badge variant={st.variant} className="text-[10px]">{st.label}</Badge>
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
