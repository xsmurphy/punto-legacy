"use client"

/**
 * Reporte Resumen de Ventas — espejo de panel/reports/summary.html.
 *
 * El más usado del legacy. Muestra una vista panorámica del período:
 *  - KPIs principales: total bruto, devoluciones, neto.
 *  - Breakdown por tipo de venta (contado / crédito).
 *  - Breakdown por método de pago.
 *  - Gift cards vendidas.
 *  - Notas: "non-adding sales" del legacy (cosas que entran al cajón pero
 *    no suman a la métrica de venta — gift cards y otros).
 *
 * Backend: GET /v1/reports/sales?dataset=summary → { totals, returns,
 * byType, giftcards, payments, nonAddingToSales }.
 */

import * as React from "react"
import Link from "next/link"
import {
  AlertCircle,
  ArrowLeft,
  ArrowUpRight,
  ArrowDownLeft,
  CreditCard,
  Gift,
  Wallet,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import {
  DateRangePicker,
  defaultDateRange,
  rangeToBackend,
  type DateRangeValue,
} from "@/components/date-range-picker"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type SalesSummaryResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"

export default function SummaryReportPage() {
  const { data: bootstrap } = useBootstrap()
  const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)
  const opts = React.useMemo(
    () => ({ ...rangeToBackend(range), params: { dataset: "summary" } }),
    [range],
  )

  const { data, isLoading, error } = useReport<SalesSummaryResponse>("sales", opts)

  const total = num(data?.totals?.total)
  const tax = num(data?.totals?.tax)
  const discount = num(data?.totals?.discount)
  const count = num(data?.totals?.count)
  const returns = num(data?.returns?.total)
  const net = total - returns
  const nonAdding = num(data?.nonAddingToSales?.total)
  const nonAddingGifts = num(data?.nonAddingToSales?.totalGiftCards)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <BackLink />
          <h1 className="text-2xl font-semibold">Resumen</h1>
          <p className="text-sm text-muted-foreground">
            Vista panorámica de ventas del período: totales, devoluciones, tipos
            de venta, métodos de pago y gift cards.
          </p>
        </div>
        <DateRangePicker value={range} onChange={setRange} />
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el resumen</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {/* KPIs principales — 3 cards prominentes (Total / Devoluciones / Neto). */}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <KpiBlock
          icon={<ArrowUpRight className="size-4 text-[var(--brand)]" />}
          label="Total facturado"
          value={`${bootstrap?.currency ?? ""} ${formatMoney(total, bootstrap)}`}
          isLoading={isLoading}
          accent
        />
        <KpiBlock
          icon={<ArrowDownLeft className="size-4 text-destructive" />}
          label="Devoluciones"
          value={`${bootstrap?.currency ?? ""} ${formatMoney(returns, bootstrap)}`}
          isLoading={isLoading}
        />
        <KpiBlock
          icon={<Wallet className="size-4 text-muted-foreground" />}
          label="Neto del período"
          value={`${bootstrap?.currency ?? ""} ${formatMoney(net, bootstrap)}`}
          isLoading={isLoading}
        />
      </div>

      {/* Secundarios — descuento, impuesto, cantidad de operaciones. */}
      <div className="flex flex-wrap gap-6 border-y py-3 text-sm">
        <Stat label="Operaciones" value={isLoading ? null : count.toString()} />
        <Stat
          label="Descuento aplicado"
          value={isLoading ? null : formatMoney(discount, bootstrap)}
        />
        <Stat
          label="Impuesto cobrado"
          value={isLoading ? null : formatMoney(tax, bootstrap)}
        />
        {nonAdding > 0 && (
          <Stat
            label="Otras entradas al cajón"
            value={formatMoney(nonAdding, bootstrap)}
          />
        )}
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Tipos de venta */}
        <Section title="Tipos de venta" empty="Sin desglose por tipo.">
          {isLoading ? (
            <SkeletonRows />
          ) : (data?.byType ?? []).length === 0 ? null : (
            <Table
              rows={(data?.byType ?? []).map((r) => ({
                left: r.name ?? r.type,
                middle: r.count != null ? `${r.count} ops` : "",
                right: formatMoney(r.total, bootstrap),
              }))}
              total={(data?.byType ?? []).reduce((s, r) => s + r.total, 0)}
              bootstrap={bootstrap}
            />
          )}
        </Section>

        {/* Métodos de pago */}
        <Section title="Métodos de pago" empty="Sin cobros registrados.">
          {isLoading ? (
            <SkeletonRows />
          ) : (data?.payments ?? []).length === 0 ? null : (
            <Table
              rows={(data?.payments ?? []).map((p) => ({
                left: p.name || p.type,
                middle: "",
                right: formatMoney(p.price, bootstrap),
                icon: <CreditCard className="size-3.5 text-muted-foreground" />,
              }))}
              total={(data?.payments ?? []).reduce((s, p) => s + p.price, 0)}
              bootstrap={bootstrap}
            />
          )}
        </Section>

        {/* Gift Cards vendidas */}
        <Section title="Gift cards vendidas" empty="No se vendieron gift cards.">
          {isLoading ? (
            <SkeletonRows />
          ) : (data?.giftcards ?? []).length === 0 ? null : (
            <Table
              rows={(data?.giftcards ?? []).map((g) => ({
                left: g.name || "Gift card",
                middle: g.count != null ? `${g.count} ud` : "",
                right: formatMoney(g.total, bootstrap),
                icon: <Gift className="size-3.5 text-muted-foreground" />,
              }))}
              total={(data?.giftcards ?? []).reduce((s, g) => s + g.total, 0)}
              bootstrap={bootstrap}
            />
          )}
        </Section>

        {/* Notas / non-adding */}
        <Section title="Notas del período" empty="—">
          <p className="text-xs text-muted-foreground">
            <strong>Otras entradas al cajón</strong>: gift cards y cobros que
            no suman a la métrica de venta del período. Aparecen acá para que
            el arqueo cierre.
          </p>
          {nonAdding > 0 && (
            <div className="mt-2 flex flex-col gap-1.5">
              <Row
                label="Total no-vendido al cajón"
                value={formatMoney(nonAdding, bootstrap)}
              />
              {nonAddingGifts > 0 && (
                <Row
                  label="↳ Gift cards"
                  value={formatMoney(nonAddingGifts, bootstrap)}
                  muted
                />
              )}
            </div>
          )}
        </Section>
      </div>
    </div>
  )
}

// ── Sub-componentes ─────────────────────────────────────────────────────────

function KpiBlock({
  icon,
  label,
  value,
  isLoading,
  accent,
}: {
  icon: React.ReactNode
  label: string
  value: string
  isLoading: boolean
  accent?: boolean
}) {
  return (
    <div
      className={
        accent
          ? "flex flex-col gap-1 rounded-lg border bg-[color-mix(in_oklab,var(--brand)_8%,var(--card))] p-4 ring-1 ring-[var(--brand)]/20"
          : "flex flex-col gap-1 rounded-lg border p-4"
      }
    >
      <div className="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {icon}
        {label}
      </div>
      {isLoading ? (
        <Skeleton className="h-8 w-32" />
      ) : (
        <span className="text-2xl font-bold tabular-nums">{value}</span>
      )}
    </div>
  )
}

function Stat({ label, value }: { label: string; value: string | null }) {
  return (
    <div className="flex flex-col gap-0.5">
      <span className="text-[10px] uppercase tracking-wide text-muted-foreground">
        {label}
      </span>
      {value === null ? (
        <Skeleton className="h-5 w-20" />
      ) : (
        <span className="text-sm font-medium tabular-nums">{value}</span>
      )}
    </div>
  )
}

function Section({
  title,
  empty,
  children,
}: {
  title: string
  empty: string
  children: React.ReactNode
}) {
  const hasChildren = React.Children.toArray(children).filter(Boolean).length > 0
  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-col gap-0.5 border-b pb-2">
        <h3 className="text-base font-semibold tracking-tight">{title}</h3>
      </div>
      {hasChildren ? (
        <div className="flex flex-col gap-2">{children}</div>
      ) : (
        <p className="text-xs text-muted-foreground">{empty}</p>
      )}
    </section>
  )
}

function Table({
  rows,
  total,
  bootstrap,
}: {
  rows: Array<{
    left: string
    middle: string
    right: string
    icon?: React.ReactNode
  }>
  total: number
  bootstrap: ReturnType<typeof useBootstrap>["data"]
}) {
  return (
    <div className="flex flex-col">
      {rows.map((r, i) => (
        <div
          key={i}
          className="flex items-center justify-between gap-2 border-b py-2 text-sm last:border-b-0"
        >
          <span className="flex items-center gap-1.5 truncate">
            {r.icon}
            {r.left}
          </span>
          <div className="flex items-center gap-3 tabular-nums">
            {r.middle && (
              <span className="text-xs text-muted-foreground">{r.middle}</span>
            )}
            <span className="font-medium">{r.right}</span>
          </div>
        </div>
      ))}
      <div className="flex items-center justify-between gap-2 py-2 text-sm">
        <span className="text-xs uppercase tracking-wide text-muted-foreground">
          Total
        </span>
        <span className="font-semibold tabular-nums">
          {formatMoney(total, bootstrap)}
        </span>
      </div>
    </div>
  )
}

function Row({
  label,
  value,
  muted,
}: {
  label: string
  value: string
  muted?: boolean
}) {
  return (
    <div className="flex items-center justify-between text-sm">
      <span className={muted ? "text-xs text-muted-foreground" : ""}>{label}</span>
      <span className={muted ? "text-xs tabular-nums text-muted-foreground" : "tabular-nums font-medium"}>
        {value}
      </span>
    </div>
  )
}

function SkeletonRows() {
  return (
    <div className="flex flex-col gap-2">
      <Skeleton className="h-5 w-full" />
      <Skeleton className="h-5 w-full" />
      <Skeleton className="h-5 w-2/3" />
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

function num(v: unknown): number {
  if (typeof v === "number" && Number.isFinite(v)) return v
  if (typeof v === "string") {
    const n = Number(v)
    return Number.isFinite(n) ? n : 0
  }
  return 0
}
