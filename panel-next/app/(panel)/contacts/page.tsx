"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import Link from "next/link"
import { Plus, AlertCircle, Users } from "lucide-react"
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
import { useContacts } from "@/hooks/use-contacts"
import type { ContactListItem } from "@/lib/types/contact"

export default function ContactsPage() {
  const router = useRouter()
  const { data, isLoading, error } = useContacts()
  const [statusFilter, setStatusFilter] = React.useState<"all" | "active" | "archived">("all")

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
            <span className="tabular-nums text-muted-foreground">{v}</span>
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
    ],
    [],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex items-end justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Contactos</h1>
          <p className="text-sm text-muted-foreground">
            Clientes del negocio. Cada contacto puede tener varias direcciones,
            teléfonos y datos fiscales (RUC, CI). Los teléfonos se guardan en
            formato internacional (E.164) pero se muestran como el usuario los tipea.
          </p>
        </div>
        <Button asChild>
          <Link href="/contacts/new">
            <Plus className="size-4" />
            Nuevo contacto
          </Link>
        </Button>
      </header>

      {error && (
        <div className="flex items-start gap-3 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm">
          <AlertCircle className="mt-0.5 size-4 text-destructive" />
          <div>
            <p className="font-medium">No se pudieron cargar los contactos</p>
            <p className="text-xs text-muted-foreground">{error.message}</p>
          </div>
        </div>
      )}

      <Card className="overflow-hidden">
        <CardContent className="p-4">
          <DataTable
            tableId="contacts"
            data={filteredRows}
            columns={columns}
            getRowId={(r) => r.id}
            onRowClick={(r) => router.push(`/contacts/${r.id}`)}
            isLoading={isLoading}
            searchPlaceholder="Buscar por nombre, teléfono, email, RUC…"
            exportFileName="contactos"
            emptyMessage={
              <div className="flex flex-col items-center gap-2 text-muted-foreground">
                <Users className="size-8 opacity-30" />
                <p>No hay contactos todavía.</p>
                <p className="text-xs">
                  Creá el primero con el botón <strong>Nuevo contacto</strong>{" "}
                  arriba a la derecha.
                </p>
              </div>
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
        </CardContent>
      </Card>
    </div>
  )
}
