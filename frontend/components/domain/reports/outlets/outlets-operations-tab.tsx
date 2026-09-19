"use client"

/**
 * Pestaña Operación del reporte de Sucursales: en qué se diferencian las
 * sucursales al trabajar — horas pico, medios de pago y artículos más
 * vendidos.
 *
 * Mismas definiciones que el dashboard del Inicio (horas = cantidad de
 * ventas por hora; artículos = unidades vendidas) y que el reporte de medios
 * de pago; el backend las sirve por sucursal en UNA lectura
 * (`dataset=operations`).
 *
 * Con pocas sucursales (hasta `OPERATIONS_CARDS_MAX`) va una card por
 * sucursal, lado a lado, para compararlas de un vistazo. Con más, una sola
 * card y un selector: diez columnas no se leen.
 */

import * as React from "react"
import { AlertCircle, Store } from "lucide-react"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { BarList } from "@/components/charts/bar-list"
import { EmptyState } from "@/components/empty-state"
import { rangeToBackend, type DateRangeValue } from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport } from "@/hooks/use-reports"
import { formatInt, formatMoneyCompact, formatPercent } from "@/lib/format"
import { formatQty } from "@/lib/format-qty"
import {
  hourLabel,
  OPERATIONS_CARDS_MAX,
  paymentMix,
  peakHours,
} from "@/lib/reports/outlets-comparison"
import type { OutletOperationsRow, OutletsOperationsResponse } from "@/lib/types/outlets-report"
import type { Bootstrap } from "@/lib/types/bootstrap"

export function OutletsOperationsTab({ range }: { range: DateRangeValue }) {
  const { data: bootstrap } = useBootstrap()
  const { from, to } = React.useMemo(() => rangeToBackend(range), [range])
  const report = useReport<OutletsOperationsResponse>("outlets", {
    from,
    to,
    params: { dataset: "operations" },
  })

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

  if (report.isLoading) {
    return (
      <div className="grid gap-4 md:grid-cols-2">
        <Skeleton className="h-[420px] w-full rounded-xl" />
        <Skeleton className="h-[420px] w-full rounded-xl" />
      </div>
    )
  }

  const rows = (report.data?.rows ?? []).filter(
    (r) => r.hours.length > 0 || r.payments.length > 0 || r.topItems.length > 0,
  )

  if (rows.length === 0) {
    return (
      <EmptyState
        icon={Store}
        title="Sin ventas en este período"
        description="Ajustá el rango de fechas."
      />
    )
  }

  if (rows.length <= OPERATIONS_CARDS_MAX) {
    return (
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-[repeat(auto-fit,minmax(18rem,1fr))]">
        {rows.map((r) => (
          <OutletOperationsCard key={r.outletId} row={r} title={r.name} bootstrap={bootstrap} />
        ))}
      </div>
    )
  }

  return <OutletOperationsPicker rows={rows} bootstrap={bootstrap} />
}

function OutletOperationsPicker({
  rows,
  bootstrap,
}: {
  rows: OutletOperationsRow[]
  bootstrap: Bootstrap | undefined
}) {
  const [selected, setSelected] = React.useState(rows[0].outletId)
  const row = rows.find((r) => r.outletId === selected) ?? rows[0]

  return (
    <div className="flex flex-col gap-4">
      <Select value={row.outletId} onValueChange={setSelected}>
        <SelectTrigger className="w-full sm:w-72" aria-label="Sucursal">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {rows.map((r) => (
            <SelectItem key={r.outletId} value={r.outletId}>
              {r.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <OutletOperationsCard row={row} title={row.name} bootstrap={bootstrap} />
    </div>
  )
}

function OutletOperationsCard({
  row,
  title,
  bootstrap,
}: {
  row: OutletOperationsRow
  title: string
  bootstrap: Bootstrap | undefined
}) {
  const hours = peakHours(row)
  const payments = paymentMix(row)

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-6">
        <Section title="Horas pico">
          {hours.length === 0 ? (
            <Empty />
          ) : (
            <BarList
              items={hours.map((h) => ({
                key: String(h.hour),
                label: hourLabel(h.hour),
                value: h.count,
                display: `${formatInt(h.count, bootstrap)} ventas`,
              }))}
            />
          )}
        </Section>

        <Section title="Medios de pago">
          {payments.length === 0 ? (
            <Empty />
          ) : (
            <BarList
              items={payments.map((p) => ({
                key: p.name,
                label: p.name,
                value: p.amount,
                display: formatPercent(p.pct, bootstrap),
                meta: formatMoneyCompact(p.amount, bootstrap),
              }))}
            />
          )}
        </Section>

        <Section title="Más vendidos">
          {row.topItems.length === 0 ? (
            <Empty />
          ) : (
            <BarList
              items={row.topItems.map((it) => ({
                key: it.itemId,
                label: it.name || "Sin nombre",
                value: it.units,
                display: formatQty(it.units, bootstrap),
                meta: formatMoneyCompact(it.total, bootstrap),
                href: `/items/${it.itemId}`,
              }))}
            />
          )}
        </Section>
      </CardContent>
    </Card>
  )
}

/**
 * Sub-bloque dentro de la card de la sucursal. No es una sección de página ni
 * el título de una card: es un rótulo de grupo dentro de ella, en el tamaño
 * del texto secundario.
 */
function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm font-medium text-muted-foreground">{title}</p>
      {children}
    </div>
  )
}

function Empty() {
  return <p className="text-sm text-muted-foreground">Sin datos en el período.</p>
}
