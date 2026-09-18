"use client"

/**
 * Acciones del legajo en el header de la ficha: registrar el egreso y archivar.
 *
 * Son las mismas dos del listado y significan cosas distintas: el EGRESO
 * escribe que la persona dejó de trabajar y el legajo queda como historial; el
 * ARCHIVO saca de la lista una fila cargada por error. Por eso son dos acciones
 * y no un estado con dos valores.
 */

import * as React from "react"
import { Archive, IdCard, UserMinus } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { DatePicker } from "@/components/date-picker"
import { RowActions, type RowAction } from "@/components/data-table/row-actions"
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
import {
  useArchiveEmployee,
  useTerminateEmployee,
  type Employee,
} from "@/hooks/use-employees"

export function EmployeeHeaderActions({
  employee,
  canManage,
  onCreateLegajo,
}: {
  employee: Employee | null
  canManage: boolean
  onCreateLegajo: () => void
}) {
  const archive = useArchiveEmployee()
  const [terminating, setTerminating] = React.useState(false)
  const [archiving, setArchiving] = React.useState(false)

  if (!canManage) return null

  // Sin legajo la única acción posible es crearlo, y va como botón: es LA
  // acción de la pantalla, no una más de un menú.
  if (!employee) {
    return (
      <Button type="button" variant="outline" size="sm" onClick={onCreateLegajo}>
        <IdCard className="size-4" />
        Cargar legajo
      </Button>
    )
  }

  const actions: RowAction[] = [
    {
      label: "Registrar egreso",
      icon: UserMinus,
      onSelect: () => setTerminating(true),
      disabled: !employee.active,
      reason: "Ya tiene registrada su fecha de egreso",
    },
    {
      label: "Archivar",
      icon: Archive,
      variant: "destructive",
      onSelect: () => setArchiving(true),
      hidden: employee.status === 0,
    },
  ]

  const handleArchive = async () => {
    try {
      await archive.mutateAsync(employee.id)
      toast.success("Legajo archivado")
    } catch (e) {
      toast.error("No se pudo archivar el legajo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
    setArchiving(false)
  }

  return (
    <>
      <RowActions actions={actions} />

      <TerminateDialog
        employee={terminating ? employee : null}
        onClose={() => setTerminating(false)}
      />

      <AlertDialog open={archiving} onOpenChange={setArchiving}>
        <AlertDialogContent className="sm:max-w-md">
          <AlertDialogHeader>
            <AlertDialogTitle>Archivar el legajo</AlertDialogTitle>
            <AlertDialogDescription>
              {employee.fullName} sale del listado. Para registrar que dejó de trabajar,
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
    </>
  )
}

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
