"use client"

import * as React from "react"
import { Plus, Pencil, Archive } from "lucide-react"
import { useForm } from "react-hook-form"
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
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import {
  useArchiveFinanceCategory,
  useCreateFinanceCategory,
  useFinanceCategories,
  useUpdateFinanceCategory,
  type FinanceCategory,
} from "@/hooks/use-finance-categories"

const categorySchema = z.object({
  name: z.string().min(1, "El nombre es requerido"),
})
type CategoryValues = z.infer<typeof categorySchema>

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
    defaultValues: { name: "" },
  })
  const editForm = useForm<CategoryValues>({
    resolver: zodResolver(categorySchema),
    defaultValues: { name: "" },
  })

  const openCreate = () => {
    createForm.reset({ name: "" })
    setCreateOpen(true)
  }

  const openEdit = (category: FinanceCategory) => {
    editForm.reset({ name: category.name })
    setEditing(category)
  }

  const onCreateSubmit = async (values: CategoryValues) => {
    try {
      await createCategory.mutateAsync({ name: values.name, kind })
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
      await updateCategory.mutateAsync({ id: editing.id, name: values.name })
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
          categories.map((category) => (
            <div
              key={category.id}
              className="flex items-center justify-between gap-2 rounded-md px-2 py-2 hover:bg-accent/50"
            >
              <div className="flex items-center gap-2">
                <span className="text-sm">{category.name}</span>
                {category.isSystem && <Badge variant="secondary">Por defecto</Badge>}
              </div>
              <div className="flex items-center gap-1">
                <Button variant="ghost" size="icon" onClick={() => openEdit(category)}>
                  <Pencil className="size-4" />
                </Button>
                {!category.isSystem && (
                  <Button variant="ghost" size="icon" onClick={() => setArchiving(category)}>
                    <Archive className="size-4" />
                  </Button>
                )}
              </div>
            </div>
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
              <FormField
                control={createForm.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nombre</FormLabel>
                    <FormControl>
                      <Input {...field} autoFocus />
                    </FormControl>
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
              <FormField
                control={editForm.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nombre</FormLabel>
                    <FormControl>
                      <Input {...field} autoFocus />
                    </FormControl>
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

export default function FinanzasCategoriasPage() {
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
