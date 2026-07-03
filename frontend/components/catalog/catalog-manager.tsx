"use client"

import * as React from "react"
import { toast } from "sonner"
import { Plus, Pencil, Trash2, Loader2, Inbox, GripVertical } from "lucide-react"
import { EmptyState } from "@/components/empty-state"
import type { ColumnDef } from "@tanstack/react-table"
import type { UseMutationResult } from "@tanstack/react-query"
import {
  DndContext,
  closestCenter,
  PointerSensor,
  KeyboardSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"
import { ColorPicker } from "@/components/ui/color-picker"

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
import { Switch } from "@/components/ui/switch"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

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
export interface CatalogFieldOption {
  value: string
  label: string
}

export interface CatalogField<P> {
  name: keyof P & string
  label: string
  type?: "text" | "number" | "switch" | "select" | "color"
  placeholder?: string
  required?: boolean
  helperText?: string
  /** Opciones para `type: "select"`. Ignorado en otros tipos. */
  options?: CatalogFieldOption[]
  /** Deshabilita el control (ej. cuenta fija de Efectivo). Puede depender del row en edición. */
  disabled?: boolean | ((values: P) => boolean)
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
  /** Normaliza el payload antes de enviarlo (ej. sentinel de select → null). */
  transformPayload?: (values: P) => P
  /**
   * Opta-in a orden por drag&drop. Cuando se pasa, el listado deja de usar el
   * DataTable compartido y renderiza una lista sortable dedicada (dnd-kit). Los
   * demás tabs del catálogo (sin este prop) siguen con el DataTable intacto.
   */
  reorderable?: {
    onReorder: (orderedIds: string[]) => void
    /** Render del contenido de cada fila (a la derecha del handle). */
    renderRow: (row: T) => React.ReactNode
  }
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
  transformPayload,
  reorderable,
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
          {reorderable ? (
            <SortableCatalogList
              rows={rows}
              isLoading={isLoading}
              getId={getId}
              onReorder={reorderable.onReorder}
              renderRow={reorderable.renderRow}
              onEdit={openEdit}
              onDelete={setDeleteTarget}
              entitySingular={entitySingular}
              entityPlural={entityPlural}
            />
          ) : (
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
                <EmptyState
                  icon={Inbox}
                  title={`Sin ${entityPlural} todavía`}
                  description={
                    <>
                      Creá la primera con el botón <strong>Nueva {entitySingular}</strong>.
                    </>
                  }
                  showMarquee={false}
                  className="border-0 py-6"
                />
              }
            />
          )}
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
        onSubmit={async (rawValues) => {
          const values = transformPayload ? transformPayload(rawValues) : rawValues
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
      <DialogContent className="max-h-[85vh] overflow-y-auto">
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

  const setField = (key: keyof P & string, value: string | boolean) => {
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
          const disabled =
            typeof f.disabled === "function" ? f.disabled(values) : !!f.disabled
          const fieldId = `field-${f.name}`

          if (f.type === "switch") {
            return (
              <div key={f.name} className="flex items-center justify-between gap-3">
                <div className="space-y-0.5">
                  <Label htmlFor={fieldId}>{f.label}</Label>
                  {f.helperText && (
                    <p className="text-xs text-muted-foreground">{f.helperText}</p>
                  )}
                </div>
                <Switch
                  id={fieldId}
                  checked={!!raw}
                  onCheckedChange={(checked) => setField(f.name, checked)}
                  disabled={disabled}
                />
              </div>
            )
          }

          if (f.type === "select") {
            const current = raw === null || raw === undefined ? "" : String(raw)
            return (
              <div key={f.name} className="space-y-1.5">
                <Label htmlFor={fieldId}>
                  {f.label}
                  {f.required && <span className="text-destructive"> *</span>}
                </Label>
                <Select
                  value={current}
                  onValueChange={(v) => setField(f.name, v)}
                  disabled={disabled}
                >
                  <SelectTrigger id={fieldId} className="w-full">
                    <SelectValue placeholder={f.placeholder} />
                  </SelectTrigger>
                  <SelectContent>
                    {(f.options ?? []).map((opt) => (
                      <SelectItem key={opt.value} value={opt.value}>
                        {opt.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {f.helperText && (
                  <p className="text-xs text-muted-foreground">{f.helperText}</p>
                )}
              </div>
            )
          }

          if (f.type === "color") {
            return (
              <div key={f.name} className="space-y-1.5">
                <Label>{f.label}</Label>
                <ColorPicker
                  value={raw === null || raw === undefined ? "" : String(raw)}
                  onChange={(key) => setField(f.name, key)}
                  allowNone
                />
                {f.helperText && (
                  <p className="text-xs text-muted-foreground">{f.helperText}</p>
                )}
              </div>
            )
          }

          const displayValue =
            raw === null || raw === undefined ? "" : String(raw)
          return (
            <div key={f.name} className="space-y-1.5">
              <Label htmlFor={fieldId}>
                {f.label}
                {f.required && <span className="text-destructive"> *</span>}
              </Label>
              <Input
                id={fieldId}
                type={f.type ?? "text"}
                value={displayValue}
                onChange={(e) => setField(f.name, e.target.value)}
                placeholder={f.placeholder}
                disabled={disabled}
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

// ── Lista sortable (opt-in vía prop `reorderable`) ────────────────────────────
// Solo se usa cuando el tab opta al reorder. Los demás tabs siguen con DataTable.

function SortableCatalogList<T, P>({
  rows,
  isLoading,
  getId,
  onReorder,
  renderRow,
  onEdit,
  onDelete,
  entitySingular,
  entityPlural,
}: {
  rows: T[]
  isLoading: boolean
  getId: (row: T) => string
  onReorder: (orderedIds: string[]) => void
  renderRow: (row: T) => React.ReactNode
  onEdit: (row: T) => void
  onDelete: (row: T) => void
  entitySingular: string
  entityPlural: string
}) {
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const ids = React.useMemo(() => rows.map(getId), [rows, getId])

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event
    if (!over || active.id === over.id) return
    const oldIndex = ids.indexOf(String(active.id))
    const newIndex = ids.indexOf(String(over.id))
    if (oldIndex < 0 || newIndex < 0) return
    onReorder(arrayMove(ids, oldIndex, newIndex))
  }

  if (isLoading) {
    return (
      <div className="flex h-32 items-center justify-center">
        <Loader2 className="size-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (rows.length === 0) {
    return (
      <EmptyState
        icon={Inbox}
        title={`Sin ${entityPlural} todavía`}
        description={
          <>
            Creá la primera con el botón <strong>Nueva {entitySingular}</strong>.
          </>
        }
        showMarquee={false}
        className="border-0 py-6"
      />
    )
  }

  return (
    <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
      <SortableContext items={ids} strategy={verticalListSortingStrategy}>
        <ul className="flex flex-col gap-1.5">
          {rows.map((row) => (
            <SortableRow
              key={getId(row)}
              id={getId(row)}
              entitySingular={entitySingular}
              onEdit={() => onEdit(row)}
              onDelete={() => onDelete(row)}
            >
              {renderRow(row)}
            </SortableRow>
          ))}
        </ul>
      </SortableContext>
    </DndContext>
  )
}

function SortableRow({
  id,
  children,
  entitySingular,
  onEdit,
  onDelete,
}: {
  id: string
  children: React.ReactNode
  entitySingular: string
  onEdit: () => void
  onDelete: () => void
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } =
    useSortable({ id })

  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.6 : 1,
  }

  return (
    <li
      ref={setNodeRef}
      style={style}
      className="flex items-center gap-2 rounded-md border bg-card px-2 py-2"
    >
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="size-7 cursor-grab text-muted-foreground active:cursor-grabbing"
        aria-label="Reordenar"
        {...attributes}
        {...listeners}
      >
        <GripVertical className="size-4" />
      </Button>
      <button
        type="button"
        onClick={onEdit}
        className="flex min-w-0 flex-1 items-center gap-3 text-left"
      >
        {children}
      </button>
      <Button
        variant="ghost"
        size="icon"
        className="size-8"
        onClick={onEdit}
        aria-label={`Editar ${entitySingular}`}
      >
        <Pencil className="size-3.5" />
      </Button>
      <Button
        variant="ghost"
        size="icon"
        className="size-8 text-destructive"
        onClick={onDelete}
        aria-label={`Eliminar ${entitySingular}`}
      >
        <Trash2 className="size-3.5" />
      </Button>
    </li>
  )
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1)
}
