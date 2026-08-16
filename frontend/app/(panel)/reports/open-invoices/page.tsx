"use client"

/**
 * Reporte de Cuentas por Cobrar / Cuentas por Pagar (Open Invoices).
 *
 * Toggle entre `state=income` (cobrar — clientes que nos deben) y `state=outcome`
 * (pagar — proveedores a los que les debemos). Mismo endpoint backend con flag.
 *
 * KPIs arriba: total adeudado, # cuentas, vencidas, por vencer. DataTable abajo
 * con cada contacto y su deuda agregada (totalSales / totalPaid / totalDebt).
 *
 * Detalle por contacto: clickear una fila abre un Dialog con
 * `AccountStatementSection` (mismo componente que el tab "Financiero" de la
 * ficha de contacto — no se duplica la tabla de facturas). Convención del
 * proyecto: Dialog es el default para contenido denso, un panel lateral
 * (Sheet/Drawer) está prohibido para esto — ver context/14-ui-conventions.md.
 */

import * as React from "react"
import Link from "next/link"
import { useSearchParams, useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { AlertCircle, ArrowLeft, AlertTriangle, Clock, Wallet } from "lucide-react"
import { formatPhone } from "@/lib/phone"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog"
import { DataTable } from "@/components/data-table/data-table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useReport,
  type OpenInvoiceContactRow,
  type OpenInvoicesReportResponse,
} from "@/hooks/use-reports"
import { formatMoney } from "@/lib/format"
import { EmptyState } from "@/components/empty-state"
import { StatsRow, StatTile } from "@/components/domain/reports/stat-tile"
import { AccountStatementSection } from "@/components/domain/contacts/account-statement-section"

type State = "income" | "outcome"

export default function OpenInvoicesReportPage() {
  // useSearchParams() requiere Suspense boundary (Next App Router) — mismo
  // patrón que items/page.tsx; ver comentario en pos/layout.tsx.
  return (
    <React.Suspense fallback={null}>
      <OpenInvoicesReportPageInner />
    </React.Suspense>
  )
}

function OpenInvoicesReportPageInner() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const { data: bootstrap } = useBootstrap()
  // Deep-link vía ?state=income|outcome — el palette puede saltar directo a
  // Cuentas por pagar sin que el user tenga que click el toggle.
  const state: State = searchParams.get("state") === "outcome" ? "outcome" : "income"

  const opts = React.useMemo(
    () => ({ params: { state } }),
    [state],
  )

  const { data, isLoading, error } = useReport<OpenInvoicesReportResponse>(
    "open_invoices",
    opts,
  )

  const rows = data?.rows ?? []
  const kpi = data?.kpi

  const onStateChange = (next: string) => {
    const v: State = next === "outcome" ? "outcome" : "income"
    const sp = new URLSearchParams(searchParams.toString())
    sp.set("state", v)
    router.replace(`/reports/open-invoices?${sp.toString()}`, { scroll: false })
  }

  const isOutcome = state === "outcome"
  const subjectPlural = isOutcome ? "proveedores" : "clientes"
  const subjectSingular = isOutcome ? "proveedor" : "cliente"
  const title = isOutcome ? "Cuentas por pagar" : "Cuentas por cobrar"
  const description = isOutcome
    ? "Compras a crédito sin saldar — proveedores a los que les debemos."
    : "Ventas a crédito sin cobrar — clientes que nos deben."

  const columns = React.useMemo<ColumnDef<OpenInvoiceContactRow>[]>(
    () => [
      {
        accessorKey: "name",
        header: subjectSingular[0].toUpperCase() + subjectSingular.slice(1),
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col">
              <span className="font-medium truncate">
                {r.name || "(sin nombre)"}
              </span>
              {r.tin && r.tin !== "-" && (
                <span className="text-[10px] text-muted-foreground tabular-nums">
                  {r.tin}
                </span>
              )}
            </div>
          )
        },
        meta: { label: subjectSingular },
      },
      {
        accessorKey: "phone",
        header: "Contacto",
        cell: ({ row }) => {
          const r = row.original
          return (
            <div className="flex flex-col text-xs text-muted-foreground tabular-nums">
              {r.phone && <span>{formatPhone(r.phone)}</span>}
              {r.email && <span className="truncate">{r.email}</span>}
              {!r.phone && !r.email && "—"}
            </div>
          )
        },
        meta: { label: "Contacto" },
      },
      {
        accessorKey: "invoices",
        header: "Facturas",
        cell: ({ row }) => (
          <span className="tabular-nums">{row.original.invoices.length}</span>
        ),
        meta: { label: "Facturas", className: "tabular-nums text-right" },
      },
      {
        id: "dueStatus",
        header: "Estado",
        // Semántica de color del design system (context/14): destructive =
        // vencida (alcanza con UNA factura vencida para que el contacto
        // cuente como tal), outline = todavía por vencer. Sin hex colors.
        cell: ({ row }) => {
          const hasExpired = row.original.invoices.some((i) => i.dueStatus === "expired")
          return (
            <Badge variant={hasExpired ? "destructive" : "outline"}>
              {hasExpired ? "Vencida" : "Por vencer"}
            </Badge>
          )
        },
        meta: { label: "Estado" },
      },
      {
        accessorKey: "totalSales",
        header: "Total emitido",
        cell: ({ getValue }) => (
          <span className="tabular-nums">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Total emitido", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "totalPaid",
        header: "Cobrado",
        cell: ({ getValue }) => {
          const v = Number(getValue()) || 0
          if (v <= 0) {
            return <span className="text-muted-foreground">—</span>
          }
          return (
            <span className="tabular-nums text-emerald-600">
              {formatMoney(v, bootstrap)}
            </span>
          )
        },
        meta: { label: isOutcome ? "Pagado" : "Cobrado", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "totalDebt",
        header: "Saldo",
        cell: ({ getValue }) => (
          <span className="tabular-nums font-semibold">
            {formatMoney(Number(getValue()) || 0, bootstrap)}
          </span>
        ),
        meta: { label: "Saldo", className: "tabular-nums text-right" },
      },
    ],
    [bootstrap, isOutcome, subjectSingular],
  )

  const [selectedContact, setSelectedContact] = React.useState<{ id: string; name: string } | null>(null)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <BackLink />
        <h1 className="text-2xl font-semibold">{title}</h1>
        <p className="text-sm text-muted-foreground">{description}</p>
      </header>

      <Tabs value={state} onValueChange={onStateChange}>
        <TabsList className="grid w-full grid-cols-2">
          <TabsTrigger value="income">Cuentas por cobrar</TabsTrigger>
          <TabsTrigger value="outcome">Cuentas por pagar</TabsTrigger>
        </TabsList>
      </Tabs>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudo cargar el reporte</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      {/* KPI strip — espejo del legacy. Total adeudado destacado, # cuentas,
          vencidas y por vencer con sus íconos respectivos. */}
      {!isLoading && kpi && rows.length > 0 && (
        <StatsRow>
          <StatTile
            icon={<Wallet className="size-3.5 text-muted-foreground" />}
            label={isOutcome ? "Total a pagar" : "Total a cobrar"}
            value={formatMoney(kpi.totalDebt, bootstrap)}
            emphasis
          />
          <StatTile
            label={subjectPlural}
            value={kpi.accounts.toString()}
          />
          <StatTile
            icon={<AlertTriangle className="size-3.5 text-destructive" />}
            label="Vencidas"
            value={kpi.expired.toString()}
            tone="negative"
          />
          <StatTile
            icon={<Clock className="size-3.5 text-amber-500" />}
            label="Por vencer (7 días)"
            value={kpi.toExpire.toString()}
          />
        </StatsRow>
      )}

      <DataTable
        tableId={`report-open-invoices-${state}`}
        data={rows}
        columns={columns}
        getRowId={(r) => r.contactId}
        isLoading={isLoading}
        searchPlaceholder={`Buscar por ${subjectSingular}, RUC, teléfono…`}
        exportFileName={
          isOutcome ? "cuentas_por_pagar" : "cuentas_por_cobrar"
        }
        onRowClick={(row) => setSelectedContact({ id: row.contactId, name: row.name || "(sin nombre)" })}
        emptyMessage={
          <EmptyState
            icon={Wallet}
            title={isOutcome ? "Sin cuentas pendientes de pago" : "Sin cuentas pendientes de cobro"}
            description="Cuando se registre una venta o compra a crédito sin saldar, aparece acá."
          />
        }
      />

      {/* Detalle por contacto: Dialog (no panel lateral — prohibido para
          contenido denso, context/14) reusando AccountStatementSection, el
          mismo componente que el tab "Financiero" de la ficha de contacto. */}
      <Dialog open={selectedContact !== null} onOpenChange={(o) => !o && setSelectedContact(null)}>
        <DialogContent className="sm:max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{selectedContact?.name}</DialogTitle>
            <DialogDescription>
              {isOutcome ? "Facturas a crédito pendientes de pago." : "Facturas a crédito pendientes de cobro."}
            </DialogDescription>
          </DialogHeader>
          {selectedContact && (
            <AccountStatementSection
              contactId={selectedContact.id}
              contactType={isOutcome ? 2 : 1}
              contactName={selectedContact.name}
            />
          )}
        </DialogContent>
      </Dialog>
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
