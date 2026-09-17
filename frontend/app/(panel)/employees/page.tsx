"use client"

import * as React from "react"
import type { ColumnDef } from "@tanstack/react-table"
import { Archive, IdCard, Pencil, Plus, UserMinus } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import { DataTable, FilterField } from "@/components/data-table/data-table"
import { RowActions, type RowAction } from "@/components/data-table/row-actions"
import { DatePicker } from "@/components/date-picker"
import { EmptyState } from "@/components/empty-state"
import { EmployeeFormDialog } from "@/components/employees/employee-form-dialog"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useOutlets } from "@/hooks/use-outlets"
import { usePermission } from "@/hooks/use-permissions"
import {
  useArchiveEmployee,
  useEmployees,
  useTerminateEmployee,
  type Employee,
  type EmployeeState,
  type FixedPeriod,
} from "@/hooks/use-employees"
import { formatDate } from "@/lib/format-date"
import { formatMoney } from "@/lib/format-money"
import type { TenantLocaleConfig } from "@/lib/tenant-locale"

const ALL = "__all__"

const PERIOD_LABEL: Record<FixedPeriod, string> = {
  monthly: "por mes",
  biweekly: "por quincena",
  weekly: "por semana",
}

/**
 * Legajo de empleados — RRHH F0 (context/83).
 *
 * El listado muestra los legajos VIGENTES (incluido el de quien ya egresó:
 * es historial laboral). Los archivados —la fila cargada por error— quedan
 * fuera salvo que se pida verlos.
 */
export default function EmployeesPage() {
  const { data: bootstrap } = useBootstrap()
  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []

  const canManage = usePermission("hr.employees.manage")

  const [state, setState] = React.useState<EmployeeState | typeof ALL>(ALL)
  const [outletId, setOutletId] = React.useState<string>(ALL)
  const [includeArchived, setIncludeArchived] = React.useState(false)

  const { data: employees = [], isLoading } = useEmployees({
    state: state === ALL ? undefined : state,
    outletId: outletId === ALL ? undefined : outletId,
    includeArchived,
  })

  const [formOpen, setFormOpen] = React.useState(false)
  const [editing, setEditing] = React.useState<Employee | null>(null)
  const [terminating, setTerminating] = React.useState<Employee | null>(null)
  const [archiving, setArchiving] = React.useState<Employee | null>(null)

  const archiveEmployee = useArchiveEmployee()

  const activeFilterCount =
    (state !== ALL ? 1 : 0) + (outletId !== ALL ? 1 : 0) + (includeArchived ? 1 : 0)
  const clearFilters = () => {
    setState(ALL)
    setOutletId(ALL)
    setIncludeArchived(false)
  }

  const openCreate = () => {
    setEditing(null)
    setFormOpen(true)
  }
  const openEdit = (employee: Employee) => {
    setEditing(employee)
    setFormOpen(true)
  }

  const handleArchive = async () => {
    if (!archiving) return
    try {
      await archiveEmployee.mutateAsync(archiving.id)
      toast.success("Legajo archivado")
    } catch (e) {
      toast.error("No se pudo archivar el legajo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
    setArchiving(null)
  }

  const actionsFor = React.useCallback(
    (employee: Employee): RowAction[] => [
      {
        label: "Editar",
        icon: Pencil,
        onSelect: () => openEdit(employee),
        hidden: !canManage,
      },
      {
        label: "Registrar egreso",
        icon: UserMinus,
        onSelect: () => setTerminating(employee),
        hidden: !canManage,
        disabled: !employee.active,
        reason: "Ya tiene registrada su fecha de egreso",
      },
      {
        label: "Archivar",
        icon: Archive,
        variant: "destructive",
        onSelect: () => setArchiving(employee),
        hidden: !canManage || employee.status === 0,
      },
    ],
    [canManage],
  )

  const columns = React.useMemo(
    () => buildColumns(bootstrap, actionsFor),
    [bootstrap, actionsFor],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex flex-col gap-1">
          <h1 className="text-2xl font-semibold">Empleados</h1>
          <p className="text-sm text-muted-foreground">
            El legajo de tu personal: datos, puesto, remuneración y archivos.
          </p>
        </div>
        {canManage && (
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Nuevo empleado
          </Button>
        )}
      </header>

      {!isLoading && employees.length === 0 && activeFilterCount === 0 ? (
        <EmptyState
          icon={IdCard}
          title="Sin empleados"
          description="Cargá a tu personal para llevar su legajo."
          actions={
            canManage ? (
              <Button onClick={openCreate}>
                <Plus className="size-4" />
                Nuevo empleado
              </Button>
            ) : undefined
          }
        />
      ) : (
        <DataTable
          tableId="employees"
          columns={columns}
          data={employees}
          isLoading={isLoading}
          getRowId={(r) => r.id}
          onRowClick={canManage ? (r) => openEdit(r) : undefined}
          searchPlaceholder="Buscar por nombre, documento o puesto…"
          exportFileName="empleados"
          activeFilterCount={activeFilterCount}
          onClearFilters={clearFilters}
          filtersSlot={
            <>
              <FilterField label="Estado">
                <Select
                  value={state}
                  onValueChange={(v) => setState(v as EmployeeState | typeof ALL)}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="Todos" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL}>Todos</SelectItem>
                    <SelectItem value="active">Activos</SelectItem>
                    <SelectItem value="terminated">Egresados</SelectItem>
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
              <FilterField label="Archivados">
                <Select
                  value={includeArchived ? "1" : "0"}
                  onValueChange={(v) => setIncludeArchived(v === "1")}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="0">Ocultar</SelectItem>
                    <SelectItem value="1">Mostrar</SelectItem>
                  </SelectContent>
                </Select>
              </FilterField>
            </>
          }
        />
      )}

      <EmployeeFormDialog
        open={formOpen}
        employee={editing}
        onOpenChange={(open) => {
          setFormOpen(open)
          if (!open) setEditing(null)
        }}
      />

      <TerminateDialog employee={terminating} onClose={() => setTerminating(null)} />

      <AlertDialog open={archiving !== null} onOpenChange={(open) => !open && setArchiving(null)}>
        <AlertDialogContent className="sm:max-w-md">
          <AlertDialogHeader>
            <AlertDialogTitle>Archivar el legajo</AlertDialogTitle>
            <AlertDialogDescription>
              {archiving?.fullName} sale del listado. Para registrar que dejó de trabajar,
              usá &quot;Registrar egreso&quot;.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <Button variant="destructive" onClick={() => void handleArchive()}>
              Archivar
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

/**
 * Egreso. Dialog propio y no un campo más del formulario: registrar que
 * alguien dejó de trabajar es una decisión, no la edición de una fecha.
 */
function TerminateDialog({
  employee,
  onClose,
}: {
  employee: Employee | null
  onClose: () => void
}) {
  const terminate = useTerminateEmployee()
  const [endDate, setEndDate] = React.useState("")
  const [endReason, setEndReason] = React.useState("")

  React.useEffect(() => {
    if (employee) {
      setEndDate(new Date().toISOString().slice(0, 10))
      setEndReason("")
    }
  }, [employee])

  const submit = async () => {
    if (!employee) return
    try {
      await terminate.mutateAsync({ id: employee.id, endDate, endReason })
      toast.success("Egreso registrado")
      onClose()
    } catch (e) {
      toast.error("No se pudo registrar el egreso", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <Dialog open={employee !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Registrar egreso</DialogTitle>
          <DialogDescription>{employee?.fullName}</DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="employee-end-date">Fecha de egreso</Label>
            <DatePicker id="employee-end-date" value={endDate} onChange={setEndDate} />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="employee-end-reason">Motivo</Label>
            <Textarea
              id="employee-end-reason"
              rows={3}
              value={endReason}
              onChange={(e) => setEndReason(e.target.value)}
            />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={terminate.isPending}>
            Cancelar
          </Button>
          <Button onClick={() => void submit()} disabled={terminate.isPending || !endDate}>
            Registrar egreso
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function schemeLabel(employee: Employee, config: TenantLocaleConfig | null | undefined): string {
  // Los tres conviven (D1): se listan todos los que tenga, no "el" esquema.
  const parts: string[] = []
  if (employee.fixedAmount !== null) {
    const period = employee.fixedPeriod ? ` ${PERIOD_LABEL[employee.fixedPeriod]}` : ""
    parts.push(`${formatMoney(employee.fixedAmount, config ?? null)}${period}`)
  }
  if (employee.hourlyRate !== null) {
    parts.push(`${formatMoney(employee.hourlyRate, config ?? null)} por hora`)
  }
  if (employee.commissions) {
    parts.push("comisiones")
  }
  return parts.length > 0 ? parts.join(" + ") : "—"
}

function buildColumns(
  config: TenantLocaleConfig | null | undefined,
  actionsFor: (employee: Employee) => RowAction[],
): ColumnDef<Employee>[] {
  return [
    {
      accessorKey: "fullName",
      header: "Nombre",
      cell: ({ row }) => <span className="font-medium">{row.original.fullName}</span>,
    },
    {
      accessorKey: "jobTitle",
      header: "Puesto",
      cell: ({ row }) => row.original.jobTitle ?? "—",
    },
    {
      accessorKey: "outletName",
      header: "Sucursal",
      cell: ({ row }) => row.original.outletName ?? "—",
    },
    {
      id: "scheme",
      header: "Remuneración",
      enableSorting: false,
      cell: ({ row }) => (
        <span className="whitespace-nowrap">{schemeLabel(row.original, config)}</span>
      ),
    },
    {
      accessorKey: "hireDate",
      header: "Ingreso",
      cell: ({ row }) => (
        <span className="whitespace-nowrap tabular-nums text-sm">
          {row.original.hireDate ? formatDate(row.original.hireDate) : "—"}
        </span>
      ),
    },
    {
      id: "status",
      header: "Estado",
      cell: ({ row }) => {
        const employee = row.original
        if (employee.status === 0) {
          return <Badge variant="outline">Archivado</Badge>
        }
        return employee.active ? (
          <Badge variant="secondary">Activo</Badge>
        ) : (
          <Badge variant="outline">
            Egresó {employee.endDate ? formatDate(employee.endDate) : ""}
          </Badge>
        )
      },
    },
    {
      id: "actions",
      header: "",
      enableSorting: false,
      cell: ({ row }) => (
        <div className="flex justify-end" onClick={(e) => e.stopPropagation()}>
          <RowActions actions={actionsFor(row.original)} />
        </div>
      ),
    },
  ]
}
