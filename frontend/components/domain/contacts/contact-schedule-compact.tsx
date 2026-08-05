"use client"

import * as React from "react"
import { CalendarDays } from "lucide-react"
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
import { useReport, type ScheduleRow, type ScheduleReportResponse } from "@/hooks/use-reports"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"

const STATUS_MAP: Record<number, { label: string; variant: "default" | "secondary" | "outline" | "destructive" }> = {
  0: { label: "Pendiente", variant: "outline" },
  4: { label: "Cancelado", variant: "destructive" },
  5: { label: "No show", variant: "secondary" },
  6: { label: "Finalizado", variant: "default" },
  7: { label: "Bloqueado", variant: "secondary" },
}


interface Props {
  customerId: string
}

export function ContactScheduleCompact({ customerId }: Props) {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const [search, setSearch] = React.useState("")

  const opts = React.useMemo(
    () => ({
      ...rangeToBackend(range),
      params: { view: "detail", customerId },
    }),
    [range, customerId],
  )

  const { data, isLoading } = useReport<ScheduleReportResponse>("schedule", opts)
  const rows: ScheduleRow[] = React.useMemo(() => data?.rows ?? [], [data])

  const filtered = React.useMemo(() => {
    if (!search.trim()) return rows
    const q = search.toLowerCase()
    return rows.filter(
      (r) =>
        r.scheduleNo?.toLowerCase().includes(q) ||
        r.responsibleName?.toLowerCase().includes(q) ||
        r.items?.some((item) => item.toLowerCase().includes(q)),
    )
  }, [rows, search])

  return (
    <div className="flex flex-col gap-3">
      {/* Barra de filtros */}
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Input
          placeholder="Buscar cita…"
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
          icon={CalendarDays}
          title="Sin citas"
          description="No hay citas en el rango seleccionado."
        />
      ) : (
        <ul className="divide-y divide-border rounded-lg border">
          {filtered.map((row) => {
            const st = STATUS_MAP[row.transactionStatus] ?? { label: String(row.transactionStatus), variant: "secondary" as const }
            return (
              <li key={row.transactionId} className="flex items-start justify-between gap-3 px-3 py-2.5 text-sm">
                <div className="flex min-w-0 flex-col gap-0.5">
                  <span className="font-medium tabular-nums truncate">
                    {row.scheduleNo || formatDateTime(row.fromDate ?? "", "d MMM yyyy HH:mm")}
                  </span>
                  <span className="text-xs text-muted-foreground">{formatDateTime(row.fromDate ?? "", "d MMM yyyy HH:mm")}</span>
                  {row.items?.length > 0 && (
                    <div className="flex flex-wrap gap-1 mt-0.5">
                      {row.items.slice(0, 3).map((item, i) => (
                        <Badge key={i} variant="outline" className="text-[10px]">
                          {item}
                        </Badge>
                      ))}
                      {row.items.length > 3 && (
                        <span className="text-[10px] text-muted-foreground">+{row.items.length - 3}</span>
                      )}
                    </div>
                  )}
                </div>
                <div className="flex shrink-0 flex-col items-end gap-0.5">
                  {row.total > 0 && (
                    <span className="tabular-nums font-medium">{formatMoney(row.total, bootstrap)}</span>
                  )}
                  <Badge variant={st.variant} className="text-[10px]">{st.label}</Badge>
                  {row.responsibleName && (
                    <span className="text-[10px] text-muted-foreground">{row.responsibleName}</span>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
