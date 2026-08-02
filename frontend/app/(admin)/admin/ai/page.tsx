"use client"

import * as React from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Sparkles, Plus, Loader2 } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { DataTable } from "@/components/data-table/data-table"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Switch } from "@/components/ui/switch"
import { Input } from "@/components/ui/input"
import { MoneyInput } from "@/components/ui/money-input"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
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
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { EmptyState } from "@/components/empty-state"

import {
  useAdminAiConfig,
  useAdminUpsertAiModel,
  useAdminCreateAiPackage,
  useAdminUpdateAiPackage,
  useAdminArchiveAiPackage,
  type AdminAiModel,
  type AdminAiPackage,
  type AdminAiConsumptionRow,
} from "@/hooks/use-admin"

// ── Modelos por capability ───────────────────────────────────────────────────

function ModelRow({ model }: { model: AdminAiModel }) {
  const upsert = useAdminUpsertAiModel()
  const [slug, setSlug] = React.useState(model.model)
  const [credits, setCredits] = React.useState<number | null>(model.creditsPerKToken)

  const commitSlug = () => {
    if (slug === model.model) return
    upsert.mutate(
      { capability: model.capability, model: slug, enabled: model.enabled, creditsPerKToken: model.creditsPerKToken },
      { onError: (err) => { toast.error(err.message ?? "Error"); setSlug(model.model) } },
    )
  }

  const commitCredits = () => {
    if (credits === model.creditsPerKToken) return
    upsert.mutate(
      { capability: model.capability, model: model.model, enabled: model.enabled, creditsPerKToken: credits ?? 0 },
      { onError: (err) => { toast.error(err.message ?? "Error"); setCredits(model.creditsPerKToken) } },
    )
  }

  return (
    <div className="grid grid-cols-[140px_1fr_140px_100px] items-center gap-3 py-2 border-b last:border-0">
      <Badge variant="secondary" className="w-fit font-mono">{model.capability}</Badge>
      <Input
        value={slug}
        onChange={(e) => setSlug(e.target.value)}
        onBlur={commitSlug}
        className="font-mono text-sm"
        placeholder="proveedor/slug"
      />
      <MoneyInput
        value={credits}
        onChange={setCredits}
        onBlur={commitCredits}
      />
      <div className="flex items-center gap-2">
        <Switch
          checked={model.enabled}
          onCheckedChange={(checked) =>
            upsert.mutate(
              { capability: model.capability, model: model.model, enabled: checked, creditsPerKToken: model.creditsPerKToken },
              { onError: (err) => toast.error(err.message ?? "Error") },
            )
          }
        />
      </div>
    </div>
  )
}

const newModelSchema = z.object({
  capability: z.string().min(1, "Requerido").regex(/^[a-z0-9_-]+$/i, "Solo a-z, 0-9, _ o -"),
  model: z.string().min(1, "Requerido").regex(/^[\w.-]+\/[\w.:-]+$/, "Formato proveedor/slug"),
  creditsPerKToken: z.number().min(0),
})

function NewModelDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (v: boolean) => void }) {
  const upsert = useAdminUpsertAiModel()
  const form = useForm<z.infer<typeof newModelSchema>>({
    resolver: zodResolver(newModelSchema),
    defaultValues: { capability: "", model: "", creditsPerKToken: 1 },
  })

  const onSubmit = (values: z.infer<typeof newModelSchema>) => {
    upsert.mutate(
      { ...values, enabled: true },
      {
        onSuccess: () => { toast.success("Capability creada"); form.reset(); onOpenChange(false) },
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader><DialogTitle>Nueva capability</DialogTitle></DialogHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField control={form.control} name="capability" render={({ field }) => (
              <FormItem>
                <FormLabel>Capability</FormLabel>
                <FormControl><Input placeholder="ej. embeddings" {...field} /></FormControl>
                <FormMessage />
              </FormItem>
            )} />
            <FormField control={form.control} name="model" render={({ field }) => (
              <FormItem>
                <FormLabel>Modelo (slug OpenRouter)</FormLabel>
                <FormControl><Input placeholder="proveedor/slug" {...field} /></FormControl>
                <FormMessage />
              </FormItem>
            )} />
            <FormField control={form.control} name="creditsPerKToken" render={({ field }) => (
              <FormItem>
                <FormLabel>Créditos por 1K tokens</FormLabel>
                <FormControl>
                  <MoneyInput value={field.value} onChange={(v) => field.onChange(v ?? 0)} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )} />
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancelar</Button>
              <Button type="submit" disabled={upsert.isPending}>
                {upsert.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Crear
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}

// ── Paquetes de créditos ─────────────────────────────────────────────────────

const packageSchema = z.object({
  name: z.string().min(1, "Nombre requerido"),
  credits: z.number().int().min(1, "Debe ser mayor a 0"),
  price: z.number().min(0),
})

function PackageDialog({
  open,
  onOpenChange,
  pkg,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  pkg: AdminAiPackage | null
}) {
  const createPkg = useAdminCreateAiPackage()
  const updatePkg = useAdminUpdateAiPackage()
  const pending = createPkg.isPending || updatePkg.isPending

  const form = useForm<z.infer<typeof packageSchema>>({
    resolver: zodResolver(packageSchema),
    values: pkg ? { name: pkg.name, credits: pkg.credits, price: pkg.price } : { name: "", credits: 100, price: 0 },
  })

  const onSubmit = (values: z.infer<typeof packageSchema>) => {
    if (pkg) {
      updatePkg.mutate(
        { packageId: pkg.packageId, ...values },
        { onSuccess: () => { toast.success("Paquete actualizado"); onOpenChange(false) }, onError: (err) => toast.error(err.message ?? "Error") },
      )
    } else {
      createPkg.mutate(values, {
        onSuccess: () => { toast.success("Paquete creado"); form.reset(); onOpenChange(false) },
        onError: (err) => toast.error(err.message ?? "Error"),
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader><DialogTitle>{pkg ? "Editar paquete" : "Nuevo paquete"}</DialogTitle></DialogHeader>
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField control={form.control} name="name" render={({ field }) => (
              <FormItem>
                <FormLabel>Nombre</FormLabel>
                <FormControl><Input {...field} /></FormControl>
                <FormMessage />
              </FormItem>
            )} />
            <FormField control={form.control} name="credits" render={({ field }) => (
              <FormItem>
                <FormLabel>Créditos</FormLabel>
                <FormControl>
                  <Input type="number" min={1} value={field.value} onChange={(e) => field.onChange(Number(e.target.value) || 0)} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )} />
            <FormField control={form.control} name="price" render={({ field }) => (
              <FormItem>
                <FormLabel>Precio</FormLabel>
                <FormControl>
                  <MoneyInput value={field.value} onChange={(v) => field.onChange(v ?? 0)} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )} />
            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancelar</Button>
              <Button type="submit" disabled={pending}>
                {pending && <Loader2 className="mr-2 size-4 animate-spin" />}
                {pkg ? "Guardar" : "Crear"}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}

// ── Página ────────────────────────────────────────────────────────────────────

export default function AdminAiPage() {
  const { data, isLoading } = useAdminAiConfig()
  const archivePkg = useAdminArchiveAiPackage()
  const [newModelOpen, setNewModelOpen] = React.useState(false)
  const [pkgDialogOpen, setPkgDialogOpen] = React.useState(false)
  const [editingPkg, setEditingPkg] = React.useState<AdminAiPackage | null>(null)

  const models = data?.models ?? []
  const packages = data?.packages ?? []
  const consumption = data?.consumption ?? []

  const packageColumns: ColumnDef<AdminAiPackage, unknown>[] = [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <span className="font-medium">{row.original.name}</span>
          {row.original.archived && <Badge variant="outline">archivado</Badge>}
        </div>
      ),
    },
    {
      accessorKey: "credits",
      header: "Créditos",
      cell: ({ row }) => <span className="tabular-nums">{row.original.credits.toLocaleString("es-PY")}</span>,
    },
    {
      accessorKey: "price",
      header: "Precio",
      cell: ({ row }) => (
        <span className="tabular-nums">
          {row.original.price.toLocaleString("es-PY", { style: "currency", currency: "PYG", maximumFractionDigits: 0 })}
        </span>
      ),
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => {
        const pkg = row.original
        return (
          <div className="flex justify-end gap-2" onClick={(e) => e.stopPropagation()}>
            <Button variant="ghost" size="sm" onClick={() => setEditingPkg(pkg)}>Editar</Button>
            {!pkg.archived && (
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="ghost" size="sm" className="text-destructive">Archivar</Button>
                </AlertDialogTrigger>
                <AlertDialogContent className="sm:max-w-md">
                  <AlertDialogHeader>
                    <AlertDialogTitle>¿Archivar &quot;{pkg.name}&quot;?</AlertDialogTitle>
                    <AlertDialogDescription>Deja de estar disponible para compra. No afecta créditos ya otorgados.</AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                    <AlertDialogAction
                      onClick={() =>
                        archivePkg.mutate(pkg.packageId, {
                          onSuccess: () => toast.success("Paquete archivado"),
                          onError: (err) => toast.error(err.message ?? "Error"),
                        })
                      }
                    >
                      Archivar
                    </AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
            )}
          </div>
        )
      },
    },
  ]

  const consumptionColumns: ColumnDef<AdminAiConsumptionRow, unknown>[] = [
    { accessorKey: "month", header: "Mes" },
    { accessorKey: "companyName", header: "Tenant" },
    {
      accessorKey: "capability",
      header: "Capability",
      cell: ({ row }) => <Badge variant="secondary" className="font-mono">{row.original.capability}</Badge>,
    },
    {
      accessorKey: "credits",
      header: "Créditos consumidos",
      cell: ({ row }) => <span className="tabular-nums">{row.original.credits.toLocaleString("es-PY")}</span>,
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Sparkles className="size-5 text-muted-foreground" />
        <h1 className="text-2xl font-bold">Créditos IA</h1>
      </div>

      <Tabs defaultValue="models">
        <TabsList>
          <TabsTrigger value="models">Modelos</TabsTrigger>
          <TabsTrigger value="packages">Paquetes</TabsTrigger>
          <TabsTrigger value="consumption">Consumo</TabsTrigger>
        </TabsList>

        <TabsContent value="models" className="space-y-4">
          <div className="flex items-center justify-between">
            <p className="text-sm text-muted-foreground">Un modelo activo por capability. El slug es el id de OpenRouter.</p>
            <Button size="sm" className="gap-2" onClick={() => setNewModelOpen(true)}>
              <Plus className="size-4" /> Nueva capability
            </Button>
          </div>
          {isLoading ? (
            <p className="text-sm text-muted-foreground">Cargando…</p>
          ) : models.length === 0 ? (
            <EmptyState icon={Sparkles} title="Sin modelos configurados" />
          ) : (
            <div className="rounded-md border p-4">
              <div className="grid grid-cols-[140px_1fr_140px_100px] gap-3 pb-2 text-xs font-medium text-muted-foreground border-b">
                <span>Capability</span>
                <span>Modelo</span>
                <span>Créditos / 1K tok</span>
                <span>Activo</span>
              </div>
              {models.map((m) => <ModelRow key={m.capability} model={m} />)}
            </div>
          )}
        </TabsContent>

        <TabsContent value="packages" className="space-y-4">
          <div className="flex justify-end">
            <Button size="sm" className="gap-2" onClick={() => setPkgDialogOpen(true)}>
              <Plus className="size-4" /> Nuevo paquete
            </Button>
          </div>
          <DataTable
            tableId="admin-ai-packages"
            data={packages}
            columns={packageColumns}
            isLoading={isLoading}
            getRowId={(r) => r.packageId}
            emptyMessage="Sin paquetes"
          />
        </TabsContent>

        <TabsContent value="consumption" className="space-y-4">
          <p className="text-sm text-muted-foreground">Créditos consumidos por tenant, últimos 3 meses. Solo lectura.</p>
          <DataTable
            tableId="admin-ai-consumption"
            data={consumption}
            columns={consumptionColumns}
            isLoading={isLoading}
            getRowId={(r) => `${r.companyId}-${r.month}-${r.capability}`}
            emptyMessage="Sin consumo registrado"
          />
        </TabsContent>
      </Tabs>

      <NewModelDialog open={newModelOpen} onOpenChange={setNewModelOpen} />
      <PackageDialog open={pkgDialogOpen} onOpenChange={setPkgDialogOpen} pkg={null} />
      {editingPkg && (
        <PackageDialog
          open={!!editingPkg}
          onOpenChange={(v) => { if (!v) setEditingPkg(null) }}
          pkg={editingPkg}
        />
      )}
    </div>
  )
}
