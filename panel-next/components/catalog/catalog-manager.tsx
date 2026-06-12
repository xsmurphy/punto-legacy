"use client"

import * as React from "react"
import { toast } from "sonner"
import { Plus, Pencil, Trash2, Loader2 } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"
import type { UseMutationResult } from "@tanstack/react-query"

import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { DataTable } from "@/components/data-table/data-table"
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
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"

/**
 * Manager genérico de catálogos (categorías, marcas, impuestos).
 *
 * Recibe los hooks de CRUD + las columnas del DataTable + los campos del
 * formulario. Cada tab del catálogo (Category, Brand, Tax) le pasa su
 * propia configuración. La lógica de dialog, delete confirm y refresh es
 * compartida.
 *
 * Tipo `T` es el shape del row (Category/Brand/Tax). `P` es el shape del
 * payload (subset de T sin auto-generados: id, created_at, etc.).
 */
export interface CatalogField<P> {
  name: keyof P & string
  label: string
  type?: "text" | "number"
  placeholder?: string
  required?: boolean
  helperText?: string
}

export interface CatalogManagerProps<T, P> {
  /** Singular en español para empty state y dialogs. Ej: "categoría". */
  entitySingular: string
  /** Plural — ej: "categorías". */
  entityPlural: string
  /** Rows resueltos por el hook concreto del tab (cada hook devuelve un
   *  shape distinto — categories/brands/taxes — y el tab desestructura). */
  rows: T[]
  isLoading: boolean
  /** Hooks de mutación de TanStack Query. */
  useCreate: () => UseMutationResult<T, Error, P>
  useUpdate: () => UseMutationResult<T, Error, { id: string; values: Partial<P> }>
  useDelete: () => UseMutationResult<{ deleted: boolean }, Error, string>
  /** Columnas del DataTable (sin la columna de acciones, se agrega acá). */
  columns: ColumnDef<T, unknown>[]
  /** Campos del form del dialog (orden = orden de render). */
  fields: CatalogField<P>[]
  /** Mapea un row T al payload P para pre-llenar el form en modo edición. */
  toFormValues: (row: T) => P
  /** Cómo obtener el id del row. */
  getId: (row: T) => string
  /** Cómo obtener el label del row para mostrar en dialogs (ej. row.name). */
  getLabel: (row: T) => string
  /** Empty form values (para el botón Nuevo). */
  emptyFormValues: P
  /** Nombre del archivo de export XLSX (sin extension). */
  exportFileName: string
}

export function CatalogManager<T, P>({
  entitySingular,
  entityPlural,
  rows,
  isLoading,
  useCreate,
  useUpdate,
  useDelete,
  columns,
  fields,
  toFormValues,
  getId,
  getLabel,
  emptyFormValues,
  exportFileName,
}: CatalogManagerProps<T, P>) {
  const create = useCreate()
  const update = useUpdate()
  const del = useDelete()

  const [editing, setEditing] = React.useState<T | null>(null)
  const [dialogOpen, setDialogOpen] = React.useState(false)
  const [deleteTarget, setDeleteTarget] = React.useState<T | null>(null)

  const openNew = () => {
    setEditing(null)
    setDialogOpen(true)
  }
  const openEdit = (row: T) => {
    setEditing(row)
    setDialogOpen(true)
  }

  const augmentedColumns: ColumnDef<T, unknown>[] = React.useMemo(
    () => [
      ...columns,
      {
        id: "actions",
        header: "",
        cell: ({ row }) => (
          <div className="flex items-center justify-end gap-1">
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              onClick={(e) => {
                e.stopPropagation()
                openEdit(row.original)
              }}
              aria-label={`Editar ${entitySingular}`}
            >
              <Pencil className="size-3.5" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              className="size-8 text-destructive"
              onClick={(e) => {
                e.stopPropagation()
                setDeleteTarget(row.original)
              }}
              aria-label={`Eliminar ${entitySingular}`}
            >
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        ),
        meta: { className: "w-24" },
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [columns, entitySingular],
  )

  return (
    <div className="space-y-4">
      <header className="flex items-center justify-between">
        <div className="space-y-1">
          <h2 className="text-base font-semibold capitalize">{entityPlural}</h2>
          <p className="text-sm text-muted-foreground">
            Administrá las {entityPlural} disponibles para tu catálogo de productos.
          </p>
        </div>
        <Button onClick={openNew}>
          <Plus className="size-4" />
          Nueva {entitySingular}
        </Button>
      </header>

      <Card>
        <CardContent className="p-4">
          <DataTable
            tableId={`catalog-${entityPlural}`}
            data={rows}
            columns={augmentedColumns}
            getRowId={getId}
            onRowClick={openEdit}
            isLoading={isLoading}
            searchPlaceholder={`Buscar ${entityPlural}…`}
            exportFileName={exportFileName}
            emptyMessage={
              <div className="text-center text-muted-foreground">
                <p>No hay {entityPlural} todavía.</p>
                <p className="text-xs mt-1">
                  Creá la primera con el botón <strong>Nueva {entitySingular}</strong>.
                </p>
              </div>
            }
          />
        </CardContent>
      </Card>

      <CatalogFormDialog
        open={dialogOpen}
        onClose={() => setDialogOpen(false)}
        entitySingular={entitySingular}
        editing={editing}
        fields={fields}
        emptyFormValues={emptyFormValues}
        toFormValues={toFormValues}
        getLabel={getLabel}
        onSubmit={async (values) => {
          try {
            if (editing) {
              await update.mutateAsync({ id: getId(editing), values })
              toast.success(`${capitalize(entitySingular)} actualizada`)
            } else {
              await create.mutateAsync(values)
              toast.success(`${capitalize(entitySingular)} creada`)
            }
            setDialogOpen(false)
          } catch (e) {
            toast.error("No se pudo guardar", {
              description: e instanceof Error ? e.message : undefined,
            })
          }
        }}
        saving={create.isPending || update.isPending}
      />

      <AlertDialog
        open={!!deleteTarget}
        onOpenChange={(v) => !v && setDeleteTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Eliminar {entitySingular}</AlertDialogTitle>
            <AlertDialogDescription>
              {deleteTarget && (
                <>
                  ¿Eliminar <strong>{getLabel(deleteTarget)}</strong>? Si está
                  asignada a artículos, esos artículos quedarán sin {entitySingular}.
                </>
              )}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={async () => {
                if (!deleteTarget) return
                try {
                  await del.mutateAsync(getId(deleteTarget))
                  toast.success(`${capitalize(entitySingular)} eliminada`)
                  setDeleteTarget(null)
                } catch (e) {
                  toast.error("No se pudo eliminar", {
                    description: e instanceof Error ? e.message : undefined,
                  })
                }
              }}
            >
              Eliminar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

function CatalogFormDialog<T, P>({
  open,
  onClose,
  entitySingular,
  editing,
  fields,
  emptyFormValues,
  toFormValues,
  getLabel,
  onSubmit,
  saving,
}: {
  open: boolean
  onClose: () => void
  entitySingular: string
  editing: T | null
  fields: CatalogField<P>[]
  emptyFormValues: P
  toFormValues: (row: T) => P
  getLabel: (row: T) => string
  onSubmit: (values: P) => Promise<void>
  saving: boolean
}) {
  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent>
        {open && (
          <CatalogFormBody<T, P>
            entitySingular={entitySingular}
            editing={editing}
            fields={fields}
            emptyFormValues={emptyFormValues}
            toFormValues={toFormValues}
            getLabel={getLabel}
            onSubmit={onSubmit}
            saving={saving}
            onCancel={onClose}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}

function CatalogFormBody<T, P>({
  entitySingular,
  editing,
  fields,
  emptyFormValues,
  toFormValues,
  getLabel,
  onSubmit,
  saving,
  onCancel,
}: {
  entitySingular: string
  editing: T | null
  fields: CatalogField<P>[]
  emptyFormValues: P
  toFormValues: (row: T) => P
  getLabel: (row: T) => string
  onSubmit: (values: P) => Promise<void>
  saving: boolean
  onCancel: () => void
}) {
  const [values, setValues] = React.useState<P>(
    editing ? toFormValues(editing) : emptyFormValues,
  )

  const setField = (key: keyof P & string, value: string) => {
    setValues((prev) => ({ ...prev, [key]: value }))
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>
          {editing ? `Editar ${entitySingular}` : `Nueva ${entitySingular}`}
        </DialogTitle>
        {editing && (
          <DialogDescription>{getLabel(editing)}</DialogDescription>
        )}
      </DialogHeader>

      <div className="space-y-4 py-2">
        {fields.map((f) => {
          const raw = values[f.name]
          const displayValue =
            raw === null || raw === undefined ? "" : String(raw)
          return (
            <div key={f.name} className="space-y-1.5">
              <Label htmlFor={`field-${f.name}`}>
                {f.label}
                {f.required && <span className="text-destructive"> *</span>}
              </Label>
              <Input
                id={`field-${f.name}`}
                type={f.type ?? "text"}
                value={displayValue}
                onChange={(e) => setField(f.name, e.target.value)}
                placeholder={f.placeholder}
              />
              {f.helperText && (
                <p className="text-xs text-muted-foreground">{f.helperText}</p>
              )}
            </div>
          )
        })}
      </div>

      <DialogFooter>
        <Button variant="ghost" onClick={onCancel} disabled={saving}>
          Cancelar
        </Button>
        <Button onClick={() => onSubmit(values)} disabled={saving}>
          {saving && <Loader2 className="size-4 animate-spin" />}
          {editing ? "Guardar" : "Crear"}
        </Button>
      </DialogFooter>
    </>
  )
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1)
}
