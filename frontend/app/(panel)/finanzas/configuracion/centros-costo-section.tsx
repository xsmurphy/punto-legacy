"use client"

import * as React from "react"
import { Building2, Plus } from "lucide-react"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog"
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { EmptyState } from "@/components/empty-state"
import {
  useArchiveFinanceCostCenter,
  useCreateFinanceCostCenter,
  useFinanceCostCenters,
  useUpdateFinanceCostCenter,
  type FinanceCostCenter,
} from "@/hooks/use-finance-cost-centers"
import { TaxonomyRow } from "./taxonomy-row"

const costCenterSchema = z.object({
  name: z.string().min(1, "El nombre es requerido"),
  // El código es OPCIONAL: un comercio puede querer centros de costo sin
  // llevarlos al sistema del contador. El backend normaliza '' → null.
  code: z.string().max(40, "El código no puede superar los 40 caracteres"),
})
type CostCenterValues = z.infer<typeof costCenterSchema>

const EMPTY_FORM: CostCenterValues = { name: "", code: "" }

export function CentrosCostoSection() {
  const { data: costCenters, isLoading } = useFinanceCostCenters()
  const createCostCenter = useCreateFinanceCostCenter()
  const updateCostCenter = useUpdateFinanceCostCenter()
  const archiveCostCenter = useArchiveFinanceCostCenter()

  const [createOpen, setCreateOpen] = React.useState(false)
  const [editing, setEditing] = React.useState<FinanceCostCenter | null>(null)
  const [archiving, setArchiving] = React.useState<FinanceCostCenter | null>(null)

  const createForm = useForm<CostCenterValues>({
    resolver: zodResolver(costCenterSchema),
    defaultValues: EMPTY_FORM,
  })
  const editForm = useForm<CostCenterValues>({
    resolver: zodResolver(costCenterSchema),
    defaultValues: EMPTY_FORM,
  })

  const openCreate = () => {
    createForm.reset(EMPTY_FORM)
    setCreateOpen(true)
  }

  const openEdit = (costCenter: FinanceCostCenter) => {
    editForm.reset({ name: costCenter.name, code: costCenter.code ?? "" })
    setEditing(costCenter)
  }

  const onCreateSubmit = async (values: CostCenterValues) => {
    try {
      await createCostCenter.mutateAsync({ name: values.name, code: values.code })
      toast.success("Centro de costo creado")
      setCreateOpen(false)
    } catch (e) {
      toast.error("No se pudo crear el centro de costo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const onEditSubmit = async (values: CostCenterValues) => {
    if (!editing) return
    try {
      await updateCostCenter.mutateAsync({
        id: editing.id,
        name: values.name,
        code: values.code,
      })
      toast.success("Centro de costo actualizado")
      setEditing(null)
    } catch (e) {
      toast.error("No se pudo actualizar el centro de costo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const handleArchive = async () => {
    if (!archiving) return
    try {
      await archiveCostCenter.mutateAsync(archiving.id)
      toast.success("Centro de costo archivado")
    } catch (e) {
      toast.error("No se pudo archivar el centro de costo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
    setArchiving(null)
  }

  if (isLoading) {
    return <Skeleton className="h-64 w-full" />
  }

  const rows = costCenters ?? []

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base font-semibold tracking-tight">Centros de costo</CardTitle>
        <Button size="sm" onClick={openCreate}>
          <Plus className="size-4" />
          Nuevo centro de costo
        </Button>
      </CardHeader>
      <CardContent className="flex flex-col gap-1">
        {rows.length === 0 ? (
          // Sin auto-seed: a diferencia de las categorías, no hay centros de
          // costo por defecto que sembrar — la lista arranca vacía siempre.
          <EmptyState
            icon={Building2}
            title="Sin centros de costo todavía"
            description="Creá el primero para poder imputar a qué centro va cada gasto."
            ghost={false}
            className="border-dashed py-10"
          />
        ) : (
          rows.map((costCenter) => (
            <TaxonomyRow
              key={costCenter.id}
              name={costCenter.name}
              code={costCenter.code}
              onEdit={() => openEdit(costCenter)}
              onArchive={() => setArchiving(costCenter)}
            />
          ))
        )}
      </CardContent>

      {/* Nuevo centro de costo */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Nuevo centro de costo</DialogTitle>
            <DialogDescription>
              Los centros de costo clasifican a qué centro se imputa cada gasto.
            </DialogDescription>
          </DialogHeader>
          <Form {...createForm}>
            <form onSubmit={createForm.handleSubmit(onCreateSubmit)} className="flex flex-col gap-4">
              <CostCenterFields form={createForm} />
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                  Cancelar
                </Button>
                <Button type="submit" disabled={createCostCenter.isPending}>
                  Crear centro de costo
                </Button>
              </DialogFooter>
            </form>
          </Form>
        </DialogContent>
      </Dialog>

      {/* Editar centro de costo */}
      <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Editar centro de costo</DialogTitle>
          </DialogHeader>
          <Form {...editForm}>
            <form onSubmit={editForm.handleSubmit(onEditSubmit)} className="flex flex-col gap-4">
              <CostCenterFields form={editForm} />
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                  Cancelar
                </Button>
                <Button type="submit" disabled={updateCostCenter.isPending}>
                  Guardar cambios
                </Button>
              </DialogFooter>
            </form>
          </Form>
        </DialogContent>
      </Dialog>

      {/* Archivar */}
      <AlertDialog open={!!archiving} onOpenChange={(o) => !o && setArchiving(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Archivar centro de costo</AlertDialogTitle>
            <AlertDialogDescription>
              ¿Archivar &quot;{archiving?.name}&quot;? Los gastos ya imputados lo conservan;
              deja de estar disponible para movimientos nuevos.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => void handleArchive()}
              disabled={archiveCostCenter.isPending}
            >
              Archivar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  )
}

/**
 * Nombre + código. Idéntico en alta y edición, así que vive una sola vez —
 * duplicarlo garantizaba que un cambio futuro entrara en uno de los dos.
 */
function CostCenterFields({ form }: { form: UseFormReturn<CostCenterValues> }) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <FormField
        control={form.control}
        name="name"
        render={({ field }) => (
          <FormItem className="sm:col-span-2">
            <FormLabel>Nombre</FormLabel>
            <FormControl>
              <Input {...field} autoFocus placeholder="Sucursal Centro, Producción, Obra…" />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />
      <FormField
        control={form.control}
        name="code"
        render={({ field }) => (
          <FormItem>
            <FormLabel>Código</FormLabel>
            <FormControl>
              <Input {...field} className="font-mono" placeholder="Opcional" />
            </FormControl>
            <FormDescription>Para matchear con el sistema del contador.</FormDescription>
            <FormMessage />
          </FormItem>
        )}
      />
    </div>
  )
}
