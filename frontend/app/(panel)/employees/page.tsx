"use client"

/**
 * Equipo — TODAS las personas del comercio (context/83 §9).
 *
 * Antes eran dos pantallas para la misma gente: "Equipo" listaba los usuarios y
 * "Empleados" los legajos. Desde la unificación (una persona = un usuario, el
 * legajo es su satélite) son una sola lista y una sola ficha: acá está el que
 * solo usa el sistema, el que solo tiene legajo y el que tiene las dos cosas.
 *
 * El cruce se hace acá y no en el backend porque no hace falta ninguno nuevo:
 * `Employee.id` ES el id del usuario (mig 233), así que las dos listas que ya
 * existían se aparean por esa clave.
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import type { ColumnDef } from "@tanstack/react-table"
import { CircleOff, Plus, Shield, Users } from "lucide-react"

import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { DataTable, FilterField } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { NewPersonDialog } from "@/components/employees/person-form"
import { OwnPinDialog } from "@/components/domain/contacts/own-pin-dialog"

import { useOutlets } from "@/hooks/use-outlets"
import { usePermission } from "@/hooks/use-permissions"
import { useTeamMembers } from "@/hooks/use-team"
import { useEmployees, type Employee } from "@/hooks/use-employees"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"
import { formatDate } from "@/lib/format-date"
import { formatPhone } from "@/lib/phone"
import { resolveColorBg } from "@/lib/ui/color-palette"

const ALL = "__all__"

/** Una persona: su credencial y, si lo tiene, su legajo. */
interface Person {
  id: string
  name: string
  email: string | null
  phone: string | null
  color: string | null
  roleName: string | null
  outletNames: string[]
  /** ¿La credencial está activa? */
  userActive: boolean
  /** `null` = todavía no tiene legajo. */
  employee: Employee | null
}

export default function TeamPage() {
  const router = useRouter()
  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []

  const canViewUsers = usePermission("contacts.user.view")
  const canManageUsers = usePermission("contacts.user.manage")
  const canViewHr = usePermission("hr.employees.view")

  const { data: teamData, isLoading: usersLoading } = useTeamMembers()
  // `state: "all"` + archivados: la lista de personas no se recorta por el
  // estado del LEGAJO — quien egresó sigue siendo alguien del historial, y sin
  // esto su fila desaparecería por no tener legajo vigente.
  const { data: legajos, isLoading: hrLoading } = useEmployees(
    { state: "all", includeArchived: true },
    canViewHr,
  )

  const [outletId, setOutletId] = React.useState<string>(ALL)
  const [state, setState] = React.useState<"all" | "active" | "inactive" | "sin-legajo">("all")

  const [creating, setCreating] = React.useState(false)

  const people = React.useMemo<Person[]>(() => {
    const byId = new Map<string, Employee>()
    for (const e of legajos ?? []) byId.set(e.id, e)

    const users = teamData?.users ?? []
    const rows: Person[] = users.map((u) => ({
      id: u.id,
      name: u.name,
      email: u.email,
      phone: u.phone,
      color: u.color,
      roleName: u.roleName,
      outletNames: u.outletNames ?? [],
      userActive: u.status === 1,
      employee: byId.get(u.id) ?? null,
    }))

    // Un legajo cuya persona no vino en `/v1/users` (por ejemplo, sin permiso
    // de ver usuarios) igual tiene que aparecer: la pantalla es la lista de
    // personas, no la de credenciales.
    const seen = new Set(rows.map((r) => r.id))
    for (const e of legajos ?? []) {
      if (seen.has(e.id)) continue
      rows.push({
        id: e.id,
        name: e.fullName,
        email: e.email,
        phone: e.phone,
        color: null,
        roleName: null,
        outletNames: e.outletName ? [e.outletName] : [],
        userActive: e.userActive,
        employee: e,
      })
    }

    return rows.sort((a, b) => a.name.localeCompare(b.name))
  }, [teamData?.users, legajos])

  const filtered = React.useMemo(() => {
    return people.filter((p) => {
      if (state === "active" && !p.userActive) return false
      if (state === "inactive" && p.userActive) return false
      if (state === "sin-legajo" && p.employee !== null) return false
      if (outletId !== ALL) {
        const inLegajo = p.employee?.outletId === outletId
        const inUser = (teamData?.users ?? [])
          .find((u) => u.id === p.id)
          ?.outletIds?.includes(outletId)
        if (!inLegajo && !inUser) return false
      }
      return true
    })
  }, [people, state, outletId, teamData?.users])

  const activeFilterCount = (state !== "all" ? 1 : 0) + (outletId !== ALL ? 1 : 0)
  const clearFilters = () => {
    setState("all")
    setOutletId(ALL)
  }

  useAgentPageSnapshot(
    {
      route: "/employees",
      routeLabel: "Equipo",
      summary: { personas: people.length, filasVisibles: filtered.length },
    },
    [people.length, filtered.length],
  )

  const columns = React.useMemo(() => buildColumns(), [])
  const isLoading = usersLoading || hrLoading

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Equipo</h1>
          <p className="text-sm text-muted-foreground">
            Las personas del comercio: su acceso al sistema y su trabajo.
          </p>
        </div>
        {canManageUsers && (
          <Button onClick={() => setCreating(true)}>
            <Plus className="size-4" />
            Nueva persona
          </Button>
        )}
      </header>

      {!isLoading && people.length === 0 ? (
        <EmptyState
          icon={Users}
          title="Sin personas cargadas"
          description="Agregá a la primera persona del comercio."
          actions={
            canManageUsers ? (
              <Button onClick={() => setCreating(true)}>
                <Plus className="size-4" />
                Nueva persona
              </Button>
            ) : undefined
          }
        />
      ) : (
        <DataTable
          tableId="team"
          columns={columns}
          data={filtered}
          isLoading={isLoading}
          getRowId={(r) => r.id}
          onRowClick={(r) => router.push(`/employees/${r.id}`)}
          searchPlaceholder="Buscar por nombre, puesto o rol…"
          exportFileName="equipo"
          activeFilterCount={activeFilterCount}
          onClearFilters={clearFilters}
          filtersSlot={
            <>
              <FilterField label="Estado">
                <Select
                  value={state}
                  onValueChange={(v) => setState(v as typeof state)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Todos" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">Todos</SelectItem>
                    <SelectItem value="active">Activos</SelectItem>
                    <SelectItem value="inactive">Inactivos</SelectItem>
                    <SelectItem value="sin-legajo">Sin puesto</SelectItem>
                  </SelectContent>
                </Select>
              </FilterField>
              <FilterField label="Sucursal">
                <Select value={outletId} onValueChange={setOutletId}>
                  <SelectTrigger>
                    <SelectValue placeholder="Todas" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL}>Todas</SelectItem>
                    {outlets.map((o) => (
                      <SelectItem key={o.id} value={o.id}>
                        {o.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </FilterField>
            </>
          }
        />
      )}

      {/* El alta es el MISMO formulario que la ficha, en un diálogo: en un paso
          quedan sus datos, su acceso y su trabajo. Antes eran dos diálogos
          encadenados que pedían el nombre y la sucursal dos veces. */}
      <NewPersonDialog
        open={creating}
        onOpenChange={setCreating}
        onCreated={(created) => router.push(`/employees/${created.id}`)}
      />

      {/* Código POS propio cuando todavía es el del alta de la cuenta y el
          comercio ya tiene otro usuario (context/72 §9.3). Vivía en la pestaña
          Equipo de Contactos; se muda con ella. */}
      {canViewUsers && <OwnPinDialog />}
    </div>
  )
}

function initials(name: string) {
  return name
    .split(" ")
    .map((w) => w[0])
    .slice(0, 2)
    .join("")
    .toUpperCase()
}

function avatarStyle(color: string | null) {
  const hex = resolveColorBg(color)
  if (!hex) return {}
  return { backgroundColor: hex + "33", color: hex }
}

function buildColumns(): ColumnDef<Person, unknown>[] {
  return [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => {
        const p = row.original
        return (
          <div className="flex items-center gap-3">
            <Avatar className="size-8 shrink-0">
              <AvatarFallback className="text-xs" style={avatarStyle(p.color)}>
                {initials(p.name ?? "?")}
              </AvatarFallback>
            </Avatar>
            <div className="flex min-w-0 flex-col">
              <span className="truncate font-medium">{p.name}</span>
              {p.email && (
                <span className="truncate text-xs text-muted-foreground">{p.email}</span>
              )}
            </div>
          </div>
        )
      },
    },
    {
      accessorKey: "roleName",
      header: "Rol",
      cell: ({ row }) =>
        row.original.roleName ? (
          <Badge variant="secondary" className="gap-1">
            <Shield className="size-3" />
            {row.original.roleName}
          </Badge>
        ) : (
          <span className="text-xs text-muted-foreground">Sin rol</span>
        ),
    },
    {
      id: "jobTitle",
      header: "Puesto",
      accessorFn: (p) => p.employee?.jobTitle ?? "",
      cell: ({ row }) => {
        const e = row.original.employee
        return e?.jobTitle ?? <span className="text-xs text-muted-foreground">—</span>
      },
    },
    {
      id: "outlet",
      header: "Sucursal",
      accessorFn: (p) => p.employee?.outletName ?? p.outletNames.join(", "),
      cell: ({ row }) => {
        const p = row.original
        const fromLegajo = p.employee?.outletName
        if (fromLegajo) return <span>{fromLegajo}</span>
        const names = p.outletNames
        if (names.length === 0) return <span className="text-xs text-muted-foreground">Todas</span>
        if (names.length === 1) return <span>{names[0]}</span>
        return (
          <Badge variant="secondary" title={names.join(", ")}>
            {names.length} sucursales
          </Badge>
        )
      },
    },
    {
      accessorKey: "phone",
      header: "Teléfono",
      cell: ({ row }) => {
        const p = formatPhone(row.original.phone)
        return p ? p : <span className="text-xs text-muted-foreground">—</span>
      },
    },
    {
      id: "status",
      header: "Estado",
      cell: ({ row }) => {
        const p = row.original
        const e = p.employee
        // El egreso manda sobre el estado de la credencial: alguien que dejó de
        // trabajar es "egresó" aunque su usuario siga habilitado.
        if (e && e.status === 1 && !e.active) {
          return (
            <Badge variant="outline">
              Egresó {e.endDate ? formatDate(e.endDate) : ""}
            </Badge>
          )
        }
        return p.userActive ? (
          <Badge variant="outline" className="gap-1.5">
            <span className="size-1.5 rounded-full bg-[var(--chart-1)]" />
            Activo
          </Badge>
        ) : (
          <Badge variant="outline" className="gap-1 text-muted-foreground">
            <CircleOff className="size-3" />
            Inactivo
          </Badge>
        )
      },
    },
  ]
}
