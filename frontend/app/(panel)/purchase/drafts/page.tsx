"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { ArrowLeft, FileText, Loader2 } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  usePurchaseDrafts,
  type PurchaseDraftStatus,
  type PurchaseDraftSummary,
} from "@/hooks/use-purchase-drafts"
import { formatMoney } from "@/lib/format"

/**
 * Cola de revisión de borradores de compra generados por OCR/IA.
 * context/32-ocr-facturas-compra.md. Click en fila → pantalla de revisión
 * (`/purchase/drafts/[id]`) con la imagen al lado del form editable.
 */
export default function PurchaseDraftsPage() {
  const router = useRouter()
  const { data: bootstrap } = useBootstrap()
  const [status, setStatus] = React.useState<PurchaseDraftStatus | "all">("pending")

  const drafts = usePurchaseDrafts(status === "all" ? undefined : status)
  const rows = drafts.data?.rows ?? []

  const columns = React.useMemo<ColumnDef<PurchaseDraftSummary>[]>(
    () => [
      {
        accessorKey: "imageUrl",
        header: "",
        enableSorting: false,
        cell: ({ row }) => {
          const url = row.original.imageUrl
          // Mismo criterio que la pantalla de revisión: extensión del
          // object key (".pdf" lo fuerza el backend al subir un PDF).
          const isPdf = url?.toLowerCase().endsWith(".pdf") ?? false
          if (!url || isPdf) {
            return (
              <div className="flex h-10 w-10 items-center justify-center rounded border bg-muted text-muted-foreground">
                <FileText className="size-4" />
              </div>
            )
          }
          return (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={url} alt="Factura" className="h-10 w-10 rounded object-cover border" />
          )
        },
      },
      {
        accessorKey: "supplierName",
        header: "Proveedor",
        cell: ({ row }) =>
          row.original.supplierName ?? (
            <span className="text-muted-foreground italic">Sin detectar</span>
          ),
      },
      {
        accessorKey: "invoiceNumber",
        header: "Factura",
        cell: ({ row }) => (
          <span className="font-mono text-xs">
            {row.original.invoiceNumber ?? <span className="text-muted-foreground">—</span>}
          </span>
        ),
      },
      {
        accessorKey: "total",
        header: () => <div className="text-right">Total</div>,
        cell: ({ row }) => (
          <div className="text-right font-medium tabular-nums">
            {row.original.total !== null ? formatMoney(row.original.total, bootstrap) : "—"}
          </div>
        ),
      },
      {
        accessorKey: "confidence",
        header: "Confianza IA",
        cell: ({ row }) => <ConfidenceBadge value={row.original.confidence} />,
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ row }) => <StatusBadge status={row.original.status} />,
      },
      {
        accessorKey: "createdAt",
        header: "Subido",
        cell: ({ row }) => formatDateTime(row.original.createdAt),
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-4">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <Button
            asChild
            variant="ghost"
            size="sm"
            className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
          >
            <Link href="/purchase">
              <ArrowLeft className="size-3.5" />
              Volver a compras
            </Link>
          </Button>
          <h1 className="text-2xl font-semibold">Borradores de factura</h1>
          <p className="text-sm text-muted-foreground">
            Facturas subidas por foto — revisá y aprobá para registrar la compra.
          </p>
        </div>
        <Tabs value={status} onValueChange={(v) => setStatus(v as PurchaseDraftStatus | "all")}>
          <TabsList>
            <TabsTrigger value="pending">Pendientes</TabsTrigger>
            <TabsTrigger value="approved">Aprobados</TabsTrigger>
            <TabsTrigger value="rejected">Rechazados</TabsTrigger>
            <TabsTrigger value="all">Todos</TabsTrigger>
          </TabsList>
        </Tabs>
      </header>

      <DataTable<PurchaseDraftSummary>
        tableId="purchase-drafts"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={drafts.isLoading}
        onRowClick={(r) => router.push(`/purchase/drafts/${r.id}`)}
        emptyMessage={
          <EmptyState
            icon={FileText}
            title="Sin borradores en este estado"
            description="Subí una foto de factura desde /purchase con el botón «Subir factura»."
          />
        }
      />
    </div>
  )
}

function ConfidenceBadge({ value }: { value: number | null }) {
  if (value === null) return <span className="text-muted-foreground">—</span>
  const pct = Math.round(value * 100)
  if (value < 0.6) {
    return <Badge variant="destructive">{pct}%</Badge>
  }
  if (value < 0.85) {
    return <Badge variant="outline">{pct}%</Badge>
  }
  return <Badge variant="secondary">{pct}%</Badge>
}

function StatusBadge({ status }: { status: PurchaseDraftStatus }) {
  // `En cola` / `Leyendo` son de la extracción asíncrona (context/32): el
  // borrador ya existe con su imagen pero todavía no tiene datos. Sin
  // distinguirlos, una factura recién subida se ve como una "pendiente" vacía y
  // el usuario cree que la lectura falló.
  if (status === "queued") return <Badge variant="outline">En cola</Badge>
  if (status === "processing") {
    return (
      <Badge variant="outline" className="gap-1">
        <Loader2 className="size-3 animate-spin" />
        Leyendo
      </Badge>
    )
  }
  if (status === "failed") return <Badge variant="destructive">No se pudo leer</Badge>
  if (status === "pending") return <Badge variant="outline">Pendiente</Badge>
  if (status === "approved") return <Badge variant="secondary">Aprobado</Badge>
  return <Badge variant="destructive">Rechazado</Badge>
}

function formatDateTime(s: string): string {
  try {
    const d = new Date(s)
    const dd = String(d.getDate()).padStart(2, "0")
    const mm = String(d.getMonth() + 1).padStart(2, "0")
    const hh = String(d.getHours()).padStart(2, "0")
    const mi = String(d.getMinutes()).padStart(2, "0")
    return `${dd}/${mm}/${d.getFullYear()} ${hh}:${mi}`
  } catch {
    return s
  }
}
