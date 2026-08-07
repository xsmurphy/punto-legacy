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
import { formatInt, formatMoney } from "@/lib/format"
import { formatDate, parseNaive } from "@/lib/format-date"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"

type GiftCardStatus = "expired" | "soon" | "used" | "active"

// "now" se recibe como parámetro (no se calcula acá) para que el status no
// dependa del reloj del proceso que renderiza: el server (Node, cualquier TZ)
// y el browser del usuario tienen "ahora" distintos, así que llamar `new
// Date()` durante el render producía React #418 cuando una gift card estaba
// justo en el borde vencida/por vencer. Ver el `useState` de más abajo.
function giftCardStatus(row: GiftCardRow, now: Date): GiftCardStatus {
  // `parseNaive` y NO `new Date(iso.replace(" ", "T"))`: el backend manda el
  // vencimiento como "2026-07-31 23:59:59-03", y ese offset de DOS dígitos
  // hace que `new Date()` devuelva Invalid Date. Toda comparación contra un
  // valor inválido es false, así que ninguna tarjeta se marcaba vencida:
  // las 5 salían "Vigente" y el KPI "Vencidas" quedaba en 0 para siempre.
  // Ver el docblock de parseNaive en lib/format-date.ts.
  const expires = row.expires ? parseNaive(row.expires) : null
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

export default function GiftcardsReportPage() {
  const { data: bootstrap } = useBootstrap()
  // "now" arranca en null: el primer render (server y cliente, antes de
  // hidratar) es idéntico — no hay `new Date()` en el árbol renderizado, así
  // que no hay nada que pueda divergir. Recién después de montar en el
  // browser se fija la hora real y se recalculan los status.
  const [now, setNow] = React.useState<Date | null>(null)
  React.useEffect(() => {
    setNow(new Date())
  }, [])

  const { data, isLoading, error } = useReport<GiftcardsReportResponse>("giftcards", {
    params: { view: "detail" },
  })
  const rows = React.useMemo(() => data?.rows ?? [], [data])

  const kpi = React.useMemo(() => {
    if (!now) return { expired: 0, soon: 0, used: 0, active: 0, activeValue: 0 }
    let expired = 0
    let soon = 0
    let used = 0
    let active = 0
    let activeValue = 0
    rows.forEach((r) => {
      const s = giftCardStatus(r, now)
      if (s === "expired") expired++
      else if (s === "soon") soon++
      else if (s === "used") used++
      else { active++; activeValue += r.value }
    })
    return { expired, soon, used, active, activeValue }
  }, [rows, now])

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
        accessorFn: (r) => (now ? giftCardStatus(r, now) : "active"),
        cell: ({ row }) => {
          if (!now) return null
          const s = giftCardStatus(row.original, now)
          const b = STATUS_BADGE[s]
          return <Badge variant={b.variant} className="text-[10px]">{b.label}</Badge>
        },
        meta: { label: "Estado" },
      },
      {
        accessorKey: "expires",
        header: "Vence",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">{formatDate((getValue() as string) ?? "")}</span>
        ),
        meta: { label: "Vence", className: "tabular-nums" },
      },
      {
        accessorKey: "lastUsed",
        header: "Último uso",
        cell: ({ getValue }) => (
          <span className="tabular-nums text-muted-foreground">{formatDate((getValue() as string) ?? "")}</span>
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
    [bootstrap, now],
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
        <StatsRow>
          <StatTile label="Vencidas" value={formatInt(kpi.expired, bootstrap)} />
          <StatTile label="Por vencer" value={formatInt(kpi.soon, bootstrap)} />
          <StatTile label="Canjeadas" value={formatInt(kpi.used, bootstrap)} />
          <StatTile
            label={`${kpi.active} vigentes — saldo`}
            value={formatMoney(kpi.activeValue, bootstrap)}
            emphasis
          />
        </StatsRow>
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
