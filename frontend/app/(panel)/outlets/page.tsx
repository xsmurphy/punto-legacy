"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import Link from "next/link"
import { Plus, AlertCircle, MapPin } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
import { DataTable } from "@/components/data-table/data-table"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import { useOutlets } from "@/hooks/use-outlets"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { usePermission } from "@/hooks/use-permissions"
import { useOutletRequestStatus } from "@/hooks/use-outlet-request"
import { OutletRequestDialog } from "@/components/outlets/outlet-request-dialog"
import { formatDate } from "@/lib/format-date"
import { resolveDateLocale } from "@/lib/tenant-locale"
import type { OutletListItem } from "@/lib/types/outlet"
import { formatPhone } from "@/lib/phone"
import { EmptyState } from "@/components/empty-state"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"

export default function OutletsPage() {
  const router = useRouter()
  const { data, isLoading, error } = useOutlets()
  const { data: bootstrap } = useBootstrap()
  const [statusFilter, setStatusFilter] = React.useState<"all" | "active" | "inactive">("all")

  // Alta con paywall: el botón pide una sucursal, no la crea. Misma clave de
  // permiso que gatea el alta en el backend (`/v1/outlets.php`).
  const canManageOutlets = usePermission("settings.outlet.manage")
  const { data: requestStatus } = useOutletRequestStatus(canManageOutlets)
  const pending = requestStatus?.pending ?? null
  const [requestOpen, setRequestOpen] = React.useState(false)

  // Filtrado custom por estado (lo aplicamos antes de pasar a DataTable).
  // El search global del DataTable cubre nombre/dirección/teléfono/ruc.
  const filteredRows = React.useMemo(() => {
    const rows = data?.rows ?? []
    if (statusFilter === "active") return rows.filter((r) => r.status === 1)
    if (statusFilter === "inactive") return rows.filter((r) => r.status !== 1)
    return rows
  }, [data, statusFilter])

  useAgentPageSnapshot(
    {
      route: "/outlets",
      routeLabel: "Listado de sucursales",
      summary: {
        filtroEstado: statusFilter,
        filasVisibles: filteredRows.length,
      },
    },
    [statusFilter, filteredRows.length],
  )

  const columns = React.useMemo<ColumnDef<OutletListItem>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <Link
              href={`/outlets/${row.original.id}`}
              className="font-medium hover:underline"
              onClick={(e) => e.stopPropagation()}
            >
              {row.original.name || "(sin nombre)"}
            </Link>
            {row.original.ecom && (
              <Badge variant="outline" className="text-[10px]">E-com</Badge>
            )}
          </div>
        ),
      },
      {
        accessorKey: "address",
        header: "Dirección",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? (
            <span className="text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Dirección" },
      },
      {
        accessorKey: "phone",
        header: "Teléfono",
        cell: ({ getValue }) => {
          // formatPhone: la BD guarda E.164 sin '+'; la columna lo pintaba crudo.
          const v = formatPhone(getValue() as string)
          return v ? (
            <span className="text-muted-foreground tabular-nums">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Teléfono" },
      },
      {
        accessorKey: "ruc",
        header: "RUC",
        cell: ({ getValue }) => {
          const v = getValue() as string
          return v ? (
            <span className="text-muted-foreground tabular-nums">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "RUC", className: "tabular-nums" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ getValue }) => {
          const s = getValue() as number
          return (
            <Badge
              variant={s === 1 ? "default" : "secondary"}
              className={s === 1 ? "" : "bg-muted text-muted-foreground"}
            >
              {s === 1 ? "Activa" : "Inactiva"}
            </Badge>
          )
        },
        meta: { className: "w-24" },
      },
      {
        accessorKey: "outletDate",
        header: "Fecha",
        enableSorting: true,
        cell: ({ getValue }) => {
          const v = getValue() as string | null | undefined
          return v ? new Intl.DateTimeFormat(resolveDateLocale(bootstrap), { day: "2-digit", month: "2-digit", year: "numeric" }).format(new Date(v)) : <span className="opacity-40">—</span>
        },
        meta: { label: "Fecha", className: "tabular-nums whitespace-nowrap" },
      },
    ],
    [bootstrap],
  )

  const initialColumnVisibility = React.useMemo(
    () => ({ outletDate: false }),
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Sucursales</h1>
          <p className="text-sm text-muted-foreground">
            Puntos de venta con su propia caja e inventario.
          </p>
        </div>
        {/* El alta de sucursal tiene PAYWALL: se pide y Punto la aprueba (cada
            sucursal se factura al precio del plan). Este botón abre el MISMO
            diálogo que la entrada del switcher — dos formularios de alta en
            paralelo volverían el paywall decorativo.
            Impedimento = control deshabilitado con el motivo, no una banda
            (memoria `feedback_pos_alerts_on_the_action_not_banners`). */}
        <Tooltip>
          <TooltipTrigger asChild>
            {/* `span` porque un botón deshabilitado no emite eventos de hover
                y el tooltip nunca aparecería. */}
            <span className="inline-flex">
              <Button
                onClick={() => setRequestOpen(true)}
                disabled={!canManageOutlets || pending !== null}
              >
                <Plus className="size-4" />
                Nueva sucursal
              </Button>
            </span>
          </TooltipTrigger>
          {(!canManageOutlets || pending !== null) && (
            <TooltipContent>
              {!canManageOutlets
                ? "Necesitás permiso de administración de sucursales"
                : `Ya pediste una sucursal${
                    pending?.createdAt ? ` el ${formatDate(pending.createdAt)}` : ""
                  }. Te avisamos cuando se resuelva.`}
            </TooltipContent>
          )}
        </Tooltip>
      </header>

      <OutletRequestDialog open={requestOpen} onOpenChange={setRequestOpen} />

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudieron cargar las sucursales</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      <DataTable
        tableId="outlets"
        data={filteredRows}
        columns={columns}
        initialColumnVisibility={initialColumnVisibility}
        getRowId={(r) => r.id}
        onRowClick={(r) => router.push(`/outlets/${r.id}`)}
        isLoading={isLoading}
        searchPlaceholder="Buscar por nombre, dirección, RUC…"
        exportFileName="sucursales"
        emptyMessage={
          <EmptyState
            icon={MapPin}
            title="Sin sucursales todavía"
            description={
              <>
                Creá la primera con el botón <strong>Nueva sucursal</strong>{" "}
                arriba a la derecha.
              </>
            }
          />
        }
        toolbarSlot={
          <Select
            value={statusFilter}
            onValueChange={(v) => setStatusFilter(v as typeof statusFilter)}
          >
            <SelectTrigger className="h-9 w-[140px]">
              <SelectValue placeholder="Estado" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Todas</SelectItem>
              <SelectItem value="active">Activas</SelectItem>
              <SelectItem value="inactive">Inactivas</SelectItem>
            </SelectContent>
          </Select>
        }
      />
    </div>
  )
}
