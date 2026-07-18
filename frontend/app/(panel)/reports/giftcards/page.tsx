"use client"

/**
 * Reporte Gift Cards — espejo de panel/reports/giftcards.html.
 *
 * Backend: GET /v1/reports/giftcards?view=detail
 * → { rows: [{ id, doc, beneficiary, expires, code, value,
 *             lastUsed, sendDate, outletName, note, ... }] }
 *
 * Reporte SNAPSHOT — NO date-scoped (muestra todas las gift cards activadas).
 * KPIs: vencidas / por vencer / canjeadas / vigentes + valor disponible.
 */

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, Gift } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useReport, type GiftCardRow, type GiftcardsReportResponse } from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"

type GiftCardStatus = "expired" | "soon" | "used" | "active"

function giftCardStatus(row: GiftCardRow): GiftCardStatus {
  const now = new Date()
  const expires = row.expires ? new Date(row.expires.replace(" ", "T")) : null
  if (row.value <= 0) return "used"
  if (expires && expires < now) return "expired"
  if (expires) {
    const daysLeft = (expires.getTime() - now.getTime()) / (1000 * 60 * 60 * 24)
    if (daysLeft < 30) return "soon"
  }
  return "active"
}

const STATUS_BADGE: Record<GiftCardStatus, { label: string; variant: "default" | "secondary" | "outline" | "destructive" }> = {
  active: { label: "Vigente", variant: "default" },
  soon: { label: "Por vencer", variant: "outline" },
  expired: { label: "Vencida", variant: "secondary" },
  used: { label: "Canjeada", variant: "secondary" },
}

function niceDate(iso: string): string {
  if (!iso) return "—"
  const d = new Date(iso.replace(" ", "T"))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString(undefined, { day: "2-digit", month: "short", year: "numeric" })
}

export default function GiftcardsReportPage() {
  const { data: bootstrap } = useBootstrap()
  const { data, isLoading, error } = useReport<GiftcardsReportResponse>("giftcards", {
    params: { view: "detail" },
  })
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  const kpi = React.useMemo(() => {
    let expired = 0
    let soon = 0
    let used = 0
    let active = 0
    let activeValue = 0
    rows.forEach((r) => {
      const s = giftCardStatus(r)
      if (s === "expired") expired++
      else if (s === "soon") soon++
      else if (s === "used") used++
      else { active++; activeValue += r.value }
    })
    return { expired, soon, used, active, activeValue }
  }, [rows])

  const columns = React.useMemo<ColumnDef<GiftCardRow>[]>(
    () => [
      {
        accessorKey: "doc",
        header: "Factura",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Factura", className: "tabular-nums" },
      },
      {
        accessorKey: "beneficiary",
        header: "Beneficiario",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? <span className="font-medium">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Beneficiario" },
      },
      {
        accessorKey: "code",
        header: "Código",
        cell: ({ getValue }) => (
          <span className="font-mono text-sm">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Código" },
      },
      {
        accessorKey: "value",
        header: "Saldo",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-medium">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Saldo", className: "tabular-nums text-right" },
      },
      {
        id: "status",
        header: "Estado",
        accessorFn: (r) => giftCardStatus(r),
        cell: ({ row }) => {
          const s = giftCardStatus(row.original)
          const b = STATUS_BADGE[s]
          return <Badge variant={b.variant} className="text-[10px]">{b.label}</Badge>
        },
        meta: { label: "Estado" },
      },
      {
        accessorKey: "expires",
        header: "Vence",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">{niceDate((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Vence", className: "tabular-nums" },
      },
      {
        accessorKey: "lastUsed",
        header: "Último uso",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">{niceDate((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Último uso", className: "tabular-nums" },
      },
      {
        accessorKey: "outletName",
        header: "Sucursal",
        cell: ({ getValue }) => (
          <span className="text-muted-foreground">{(getValue() as string) || "—"}</span>
        ),
        meta: { label: "Sucursal" },
      },
      {
        accessorKey: "note",
        header: "Nota",
        cell: ({ getValue }) => {
          const v = (getValue() as string) ?? ""
          return v ? <span className="text-xs truncate">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Nota" },
      },
    ],
    [bootstrap],
  )

  const initialColumnVisibility = React.useMemo(() => ({ note: false }), [])

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <BackLink />
        <h1 className="text-2xl font-semibold">Gift Cards activadas</h1>
        <p className="text-sm text-muted-foreground">
          Todas las gift cards emitidas y su estado actual.
        </p>
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

      {!isLoading && rows.length > 0 && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <KpiCard label="Vencidas" value={kpi.expired.toLocaleString()} />
          <KpiCard label="Por vencer" value={kpi.soon.toLocaleString()} />
          <KpiCard label="Canjeadas" value={kpi.used.toLocaleString()} />
          <KpiCard
            label={`${kpi.active} vigentes — saldo`}
            value={`${bootstrap?.currency ?? ""} ${formatMoney(kpi.activeValue, bootstrap)}`}
            emphasis
          />
        </div>
      )}

      <DataTable
        tableId="report-giftcards"
        data={rows}
        columns={columns}
        initialColumnVisibility={initialColumnVisibility}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por beneficiario, código, factura…"
        exportFileName="gift_cards"
        emptyMessage={
          <EmptyState
            icon={Gift}
            title="Sin gift cards activadas"
            description="No se encontraron gift cards emitidas."
          />
        }
      />
    </div>
  )
}

function KpiCard({ label, value, emphasis }: { label: string; value: string; emphasis?: boolean }) {
  return (
    <div className="rounded-lg border bg-card p-4">
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={emphasis ? "mt-1 text-lg font-semibold tabular-nums" : "mt-1 text-2xl font-bold tabular-nums"}>
        {value}
      </p>
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
