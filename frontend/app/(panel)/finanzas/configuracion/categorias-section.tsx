"use client"

import * as React from "react"
import { Plus } from "lucide-react"
import { useForm, type UseFormReturn } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Dialog,
  DialogContent,
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  useArchiveFinanceCategory,
  useCreateFinanceCategory,
  useFinanceCategories,
  useUpdateFinanceCategory,
  type FinanceCategory,
} from "@/hooks/use-finance-categories"
import { TaxonomyRow } from "./taxonomy-row"

const NO_PARENT = "__none__"

const categorySchema = z.object({
  name: z.string().min(1, "El nombre es requerido"),
  // Código contable EXTERNO (mig 167): el que matchea esta categoría contra el
  // plan de cuentas del contador del comercio. Opcional — un comercio puede no
  // llevar sus categorías a ningún sistema de afuera. El backend normaliza
  // '' → null y rechaza códigos repetidos dentro del comercio.
  code: z.string().max(40, "El código no puede superar los 40 caracteres"),
  parentId: z.string(),
})
type CategoryValues = z.infer<typeof categorySchema>

const EMPTY_FORM: CategoryValues = { name: "", code: "", parentId: NO_PARENT }

/** Agrupa categorías en raíces + hijas (árbol de 2 niveles), cada nivel ordenado por sortOrder. */
function groupByParent(categories: FinanceCategory[]) {
  const roots = categories
    .filter((c) => !c.parentId)
    .sort((a, b) => a.sortOrder - b.sortOrder || a.name.localeCompare(b.name))
  const childrenByParent = new Map<string, FinanceCategory[]>()
  for (const c of categories) {
    if (!c.parentId) continue
    const list = childrenByParent.get(c.parentId) ?? []
    list.push(c)
    childrenByParent.set(c.parentId, list)
  }
  for (const list of childrenByParent.values()) {
    list.sort((a, b) => a.sortOrder - b.sortOrder || a.name.localeCompare(b.name))
  }
  return roots.map((root) => ({ root, children: childrenByParent.get(root.id) ?? [] }))
}

function CategoryRow({
  category,
  indented,
  hasChildren,
  onEdit,
  onArchive,
}: {
  category: FinanceCategory
  indented: boolean
  hasChildren: boolean
  onEdit: () => void
  onArchive: () => void
}) {
  return (
    <TaxonomyRow
      name={category.name}
      code={category.code}
      indented={indented}
      badges={
        <>
          {category.isSystem && <Badge variant="secondary">Por defecto</Badge>}
          {hasChildren && <Badge variant="outline">{"Tiene subcategorías"}</Badge>}
        </>
      }
      onEdit={onEdit}
      // Las categorías por defecto no se archivan (el backend también lo
      // rechaza) — sin handler, la fila no muestra el botón.
      onArchive={category.isSystem ? undefined : onArchive}
    />
  )
}

/**
 * Nombre + código contable. Idéntico en alta y edición, y en las dos columnas
 * (ingresos y egresos): vive una sola vez para que un cambio no entre en tres
 * de los cuatro lugares.
 *
 * El código aplica a categorías de INGRESO igual que a las de egreso. El owner
 * habló de gastos, pero es la misma tabla y el mismo formulario — separar por
 * `kind` sería un `if` sin beneficio, y el contador mapea el plan de cuentas
 * entero, no solo la mitad.
 */
function NameAndCodeFields({ form }: { form: UseFormReturn<CategoryValues> }) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <FormField
        control={form.control}
        name="name"
        render={({ field }) => (
          <FormItem className="sm:col-span-2">
            <FormLabel>Nombre</FormLabel>
            <FormControl>
              <Input {...field} autoFocus />
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
              {/* `font-mono` porque se copia carácter a carácter desde el
                  listado del contador. */}
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

function CategoryColumn({
  kind,
  title,
  categories,
}: {
  kind: "income" | "expense"
  title: string
  categories: FinanceCategory[]
}) {
  const createCategory = useCreateFinanceCategory()
  const updateCategory = useUpdateFinanceCategory()
  const archiveCategory = useArchiveFinanceCategory()

  const [createOpen, setCreateOpen] = React.useState(false)
  const [editing, setEditing] = React.useState<FinanceCategory | null>(null)
  const [archiving, setArchiving] = React.useState<FinanceCategory | null>(null)

  const createForm = useForm<CategoryValues>({
    resolver: zodResolver(categorySchema),
    defaultValues: EMPTY_FORM,
  })
  const editForm = useForm<CategoryValues>({
    resolver: zodResolver(categorySchema),
    defaultValues: EMPTY_FORM,
  })

  // Solo categorías raíz del mismo kind pueden ser "padre" (árbol de 2 niveles).
  const parentOptions = categories.filter((c) => !c.parentId)
  const childIds = new Set(categories.filter((c) => c.parentId).map((c) => c.parentId as string))
  const editingHasChildren = editing ? childIds.has(editing.id) : false

  const openCreate = () => {
    createForm.reset(EMPTY_FORM)
    setCreateOpen(true)
  }

  const openEdit = (category: FinanceCategory) => {
    editForm.reset({
      name: category.name,
      code: category.code ?? "",
      parentId: category.parentId ?? NO_PARENT,
    })
    setEditing(category)
  }

  const onCreateSubmit = async (values: CategoryValues) => {
    try {
      await createCategory.mutateAsync({
        name: values.name,
        kind,
        code: values.code,
        parentId: values.parentId === NO_PARENT ? null : values.parentId,
      })
      toast.success("Categoría creada")
      setCreateOpen(false)
    } catch (e) {
      toast.error("No se pudo crear la categoría", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const onEditSubmit = async (values: CategoryValues) => {
    if (!editing) return
    try {
      await updateCategory.mutateAsync({
        id: editing.id,
        name: values.name,
        code: values.code,
        parentId: values.parentId === NO_PARENT ? null : values.parentId,
      })
      toast.success("Categoría actualizada")
      setEditing(null)
    } catch (e) {
      toast.error("No se pudo actualizar la categoría", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const handleArchive = async () => {
    if (!archiving) return
    try {
      await archiveCategory.mutateAsync(archiving.id)
      toast.success("Categoría archivada")
      setArchiving(null)
    } catch (e) {
      toast.error("No se pudo archivar la categoría", {
        description: e instanceof Error ? e.message : undefined,
      })
      setArchiving(null)
    }
  }

  const groups = groupByParent(categories)

  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-base font-semibold tracking-tight">{title}</CardTitle>
        <Button size="sm" onClick={openCreate}>
          <Plus className="size-4" />
          Nueva categoría
        </Button>
      </CardHeader>
      <CardContent className="flex flex-col gap-1">
        {categories.length === 0 ? (
          <p className="text-sm text-muted-foreground">Sin categorías todavía.</p>
        ) : (
          groups.map(({ root, children }) => (
            <React.Fragment key={root.id}>
              <CategoryRow
                category={root}
                indented={false}
                hasChildren={children.length > 0}
                onEdit={() => openEdit(root)}
                onArchive={() => setArchiving(root)}
              />
              {children.map((child) => (
                <CategoryRow
                  key={child.id}
                  category={child}
                  indented
                  hasChildren={false}
                  onEdit={() => openEdit(child)}
                  onArchive={() => setArchiving(child)}
                />
              ))}
            </React.Fragment>
          ))
        )}
      </CardContent>

      {/* Nueva categoría */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Nueva categoría de {kind === "income" ? "ingresos" : "egresos"}</DialogTitle>
          </DialogHeader>
          <Form {...createForm}>
            <form onSubmit={createForm.handleSubmit(onCreateSubmit)} className="flex flex-col gap-4">
              <NameAndCodeFields form={createForm} />
              <FormField
                control={createForm.control}
                name="parentId"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Categoría padre (opcional)</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder="Sin categoría padre" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_PARENT}>Sin categoría padre</SelectItem>
                        {parentOptions.map((p) => (
                          <SelectItem key={p.id} value={p.id}>
                            {p.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                  Cancelar
                </Button>
                <Button type="submit" disabled={createCategory.isPending}>
                  Crear categoría
                </Button>
              </DialogFooter>
            </form>
          </Form>
        </DialogContent>
      </Dialog>

      {/* Editar categoría */}
      <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Editar categoría</DialogTitle>
          </DialogHeader>
          <Form {...editForm}>
            <form onSubmit={editForm.handleSubmit(onEditSubmit)} className="flex flex-col gap-4">
              <NameAndCodeFields form={editForm} />
              <FormField
                control={editForm.control}
                name="parentId"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Categoría padre (opcional)</FormLabel>
                    <Select
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={editingHasChildren}
                    >
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder="Sin categoría padre" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={NO_PARENT}>Sin categoría padre</SelectItem>
                        {parentOptions
                          .filter((p) => p.id !== editing?.id)
                          .map((p) => (
                            <SelectItem key={p.id} value={p.id}>
                              {p.name}
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                    {editingHasChildren && (
                      <p className="text-xs text-muted-foreground">
                        Esta categoría tiene subcategorías: no puede tener padre.
                      </p>
                    )}
                    <FormMessage />
                  </FormItem>
                )}
              />
              <DialogFooter>
                <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                  Cancelar
                </Button>
                <Button type="submit" disabled={updateCategory.isPending}>
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
            <AlertDialogTitle>Archivar categoría</AlertDialogTitle>
            <AlertDialogDescription>
              ¿Archivar &quot;{archiving?.name}&quot;? Dejará de estar disponible para
              movimientos nuevos.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={() => void handleArchive()} disabled={archiveCategory.isPending}>
              Archivar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </Card>
  )
}

export function CategoriasSection() {
  const { data: categories, isLoading } = useFinanceCategories()

  if (isLoading) {
    return (
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Skeleton className="h-64 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  const rows = categories ?? []
  const incomeCategories = rows.filter((c) => c.kind === "income")
  const expenseCategories = rows.filter((c) => c.kind === "expense")

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      <CategoryColumn kind="income" title="Ingresos" categories={incomeCategories} />
      <CategoryColumn kind="expense" title="Egresos" categories={expenseCategories} />
    </div>
  )
}
