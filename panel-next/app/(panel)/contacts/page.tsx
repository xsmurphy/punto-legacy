"use client"

import * as React from "react"
import { useRouter, useSearchParams } from "next/navigation"
import Link from "next/link"
import { Plus, AlertCircle, Users } from "lucide-react"
import { formatPhone } from "@/lib/phone"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { DataTable } from "@/components/data-table/data-table"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useContacts, type ContactType } from "@/hooks/use-contacts"

/** "1990-05-12" → "12/05" (formato corto, ocultando el año). */
function formatBday(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  if (!m) return iso
  return `${m[3]}/${m[2]}`
}
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"
import type { ContactListItem } from "@/lib/types/contact"
import { EmptyState } from "@/components/empty-state"
import { TeamSection } from "@/components/domain/contacts/team-section"

type ActiveTab = "1" | "2" | "team"

function getActiveTab(searchParams: ReturnType<typeof useSearchParams>): ActiveTab {
  const typeParam = searchParams.get("type")
  const tabParam = searchParams.get("tab")
  if (tabParam === "team" || typeParam === "0") return "team"
  if (typeParam === "2") return "2"
  return "1"
}

function ContactsPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const activeTab = getActiveTab(searchParams)

  const setActiveTab = (next: ActiveTab) => {
    const params = new URLSearchParams(searchParams.toString())
    params.set("type", next === "team" ? "0" : next)
    params.delete("tab")
    router.replace(`/contacts?${params.toString()}`)
  }

  // contactType solo se usa cuando activeTab !== "team"
  const contactType: ContactType = activeTab === "2" ? 2 : 1

  const { data, isLoading, error } = useContacts({ type: contactType })
  const [statusFilter, setStatusFilter] = React.useState<"all" | "active" | "archived">("all")
  const isSupplier = activeTab === "2"
  const teamOpenCreateRef = React.useRef<(() => void) | null>(null)

  const filteredRows = React.useMemo(() => {
    const rows = data?.contacts ?? []
    if (statusFilter === "active") return rows.filter((r) => r.status === 1)
    if (statusFilter === "archived") return rows.filter((r) => r.status !== 1)
    return rows
  }, [data, statusFilter])

  const columns = React.useMemo<ColumnDef<ContactListItem>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Nombre",
        cell: ({ row }) => {
          const c = row.original
          const hasFiscal = !!c.fullname // si fullname existe, c.name es razón social
          return (
            <div className="flex flex-col">
              <Link
                href={`/contacts/${c.id}`}
                className="font-medium hover:underline"
                onClick={(e) => e.stopPropagation()}
              >
                {c.name || "(sin nombre)"}
              </Link>
              {hasFiscal && (
                <span className="text-xs text-muted-foreground">
                  {c.fullname}
                </span>
              )}
            </div>
          )
        },
      },
      {
        accessorKey: "phone",
        header: "Teléfono",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? (
            <span className="tabular-nums text-muted-foreground">{formatPhone(v)}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Teléfono", className: "tabular-nums" },
      },
      {
        accessorKey: "email",
        header: "Email",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? (
            <span className="text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Email" },
      },
      {
        accessorKey: "tin",
        header: "RUC",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? (
            <span className="tabular-nums text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "RUC", className: "tabular-nums" },
      },
      // Columnas opcionales — hidden por default vía initialColumnVisibility.
      // El user las prende desde el column-toggle del DataTable; el estado se
      // persiste por tableId en localStorage.
      {
        accessorKey: "ci",
        header: "CI",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? (
            <span className="tabular-nums text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "CI", className: "tabular-nums" },
      },
      {
        accessorKey: "bday",
        header: "Cumpleaños",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? (
            <span className="tabular-nums text-muted-foreground">{formatBday(v)}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Cumpleaños", className: "tabular-nums" },
      },
      {
        accessorKey: "loyaltyAmount",
        header: "Loyalty",
        cell: ({ getValue }) => {
          const v = getValue() as number | string | null
          const n = typeof v === "string" ? Number(v) : (v ?? 0)
          if (!n) return <span className="opacity-40">—</span>
          return (
            <span className="tabular-nums text-muted-foreground">{n.toLocaleString()}</span>
          )
        },
        meta: { label: "Loyalty", className: "tabular-nums text-right" },
      },
      {
        accessorKey: "city",
        header: "Ciudad",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? (
            <span className="text-muted-foreground">{v}</span>
          ) : (
            <span className="opacity-40">—</span>
          )
        },
        meta: { label: "Ciudad" },
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
              {s === 1 ? "Activo" : "Archivado"}
            </Badge>
          )
        },
        meta: { className: "w-24" },
      },
      {
        accessorKey: "date",
        header: "Fecha",
        enableSorting: true,
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? new Intl.DateTimeFormat("es-PY", { day: "2-digit", month: "2-digit", year: "numeric" }).format(new Date(v)) : <span className="opacity-40">—</span>
        },
        meta: { label: "Fecha", className: "tabular-nums whitespace-nowrap" },
      },
    ],
    [],
  )

  // Columnas opcionales arrancan ocultas — disponibles desde el column-toggle.
  // No las mostramos por default para no saturar la grilla. El user las
  // prende cuando le interesan; la elección se persiste por tableId.
  const initialColumnVisibility = React.useMemo(
    () => ({ ci: false, bday: false, loyaltyAmount: false, city: false, date: false }),
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Contactos</h1>
          <p className="text-sm text-muted-foreground">
            {activeTab === "team"
              ? "Usuarios con acceso al panel y la caja."
              : "Clientes y proveedores del negocio."}
          </p>
        </div>
        {activeTab !== "team" ? (
          <Button asChild>
            <Link href={isSupplier ? "/contacts/new?type=2" : "/contacts/new"}>
              <Plus className="size-4" />
              {isSupplier ? "Nuevo proveedor" : "Nuevo cliente"}
            </Link>
          </Button>
        ) : (
          <Button onClick={() => teamOpenCreateRef.current?.()}>
            <Plus className="size-4" />
            Nuevo usuario
          </Button>
        )}
      </header>

      <Tabs
        value={activeTab}
        onValueChange={(v) => setActiveTab(v as ActiveTab)}
      >
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="1">Clientes</TabsTrigger>
          <TabsTrigger value="2">Proveedores</TabsTrigger>
          <TabsTrigger value="team">Equipo</TabsTrigger>
        </TabsList>

        <TabsContent value="team" className="mt-6">
          <TeamSection openCreateRef={teamOpenCreateRef} />
        </TabsContent>
      </Tabs>

      {activeTab !== "team" && (
        <>
          {error && (
            <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
              <AlertCircle className="mt-0.5 size-4 text-destructive" />
              <div>
                <p className="font-medium">No se pudieron cargar los contactos</p>
                <p className="text-xs text-muted-foreground">{error.message}</p>
              </div>
            </div>
          )}

          {/* DataTable sin Card wrapper — el DataTable interno provee border-y
              (top+bottom de la tabla) y los rows tienen divisores. El feedback del
              user fue claro: sin bordes externos. */}
          <DataTable
            tableId="contacts"
            data={filteredRows}
            columns={columns}
            initialColumnVisibility={initialColumnVisibility}
            getRowId={(r) => r.id}
            onRowClick={(r) => router.push(`/contacts/${r.id}`)}
            isLoading={isLoading}
            searchPlaceholder="Buscar por nombre, teléfono, email, RUC…"
            exportFileName="contactos"
            emptyMessage={
              <EmptyState
                icon={Users}
                title={isSupplier ? "Sin proveedores todavía" : "Sin clientes todavía"}
                description={
                  <>
                    Creá el primero con el botón{" "}
                    <strong>{isSupplier ? "Nuevo proveedor" : "Nuevo cliente"}</strong>{" "}
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
                  <SelectItem value="all">Todos</SelectItem>
                  <SelectItem value="active">Activos</SelectItem>
                  <SelectItem value="archived">Archivados</SelectItem>
                </SelectContent>
              </Select>
            }
          />
        </>
      )}
    </div>
  )
}

export default function ContactsPageWrapper() {
  return (
    <React.Suspense fallback={null}>
      <ContactsPage />
    </React.Suspense>
  )
}
