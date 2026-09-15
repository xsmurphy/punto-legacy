"use client"

import * as React from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { CreditCard, Plus, Loader2, Users } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { DataTable } from "@/components/data-table/data-table"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { Input } from "@/components/ui/input"
import { MoneyInput } from "@/components/ui/money-input"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { Separator } from "@/components/ui/separator"
import { AdminRoleGate } from "@/components/admin/admin-role-gate"

import {
  useAdminPlanCatalog,
  useAdminCreatePlan,
  useAdminUpdatePlan,
  type AdminPlanFull,
  type AdminPlanInput,
} from "@/hooks/use-admin"
import { formatPuntoSaasMoney } from "@/lib/punto-saas-locale"

// Flags de features conocidas (seed 13_seed_plans_zero_and_trial.sql) — el
// plan las expone al gating del panel vía plans.features (jsonb).
const FEATURE_KEYS: { key: string; label: string }[] = [
  { key: "loyalty", label: "Fidelización" },
  { key: "tables", label: "Espacios" },
  { key: "calendar", label: "Agenda" },
  { key: "ordersPanel", label: "Panel de órdenes" },
  { key: "electronicInvoice", label: "Facturación electrónica" },
  { key: "customRoles", label: "Roles personalizados" },
  { key: "schedule", label: "Horarios" },
  { key: "inventory", label: "Inventario" },
  { key: "delivery", label: "Delivery" },
  { key: "production", label: "Producción" },
  { key: "drawerControl", label: "Control de caja" },
  { key: "activityLog", label: "Registro de actividad" },
  { key: "storeCredit", label: "Crédito en tienda" },
]

const planSchema = z.object({
  name: z.string().min(1, "Nombre requerido"),
  type: z.string().min(1, "Tipo requerido"),
  price: z.number().min(0),
  duration_days: z.number().int().min(1),
  max_items: z.number().int().min(0),
  max_users: z.number().int().min(0),
  max_customers: z.number().int().min(0),
  max_outlets: z.number().int().min(0),
  max_registers: z.number().int().min(0),
  max_suppliers: z.number().int().min(0),
  max_categories: z.number().int().min(0),
  max_brands: z.number().int().min(0),
  ai_credits_monthly: z.number().int().min(0),
  features: z.record(z.string(), z.boolean()),
})

type PlanValues = z.infer<typeof planSchema>

function emptyValues(): PlanValues {
  return {
    name: "", type: "custom", price: 0, duration_days: 30,
    max_items: 0, max_users: 0, max_customers: 0, max_outlets: 0, max_registers: 0,
    max_suppliers: 0, max_categories: 0, max_brands: 0, ai_credits_monthly: 0,
    features: {},
  }
}

function planToValues(plan: AdminPlanFull): PlanValues {
  return {
    name: plan.name, type: plan.type, price: plan.price, duration_days: plan.durationDays,
    max_items: plan.maxItems, max_users: plan.maxUsers, max_customers: plan.maxCustomers,
    max_outlets: plan.maxOutlets, max_registers: plan.maxRegisters, max_suppliers: plan.maxSuppliers,
    max_categories: plan.maxCategories, max_brands: plan.maxBrands,
    ai_credits_monthly: plan.aiCreditsMonthly, features: plan.features ?? {},
  }
}

function LimitField({
  form,
  name,
  label,
}: {
  form: ReturnType<typeof useForm<PlanValues>>
  name: keyof PlanValues
  label: string
}) {
  return (
    <FormField
      control={form.control}
      name={name as "max_items"}
      render={({ field }) => (
        <FormItem>
          <FormLabel className="text-xs text-muted-foreground">{label}</FormLabel>
          <FormControl>
            <Input
              type="number"
              min={0}
              value={field.value as number}
              onChange={(e) => field.onChange(Number(e.target.value) || 0)}
            />
          </FormControl>
          <FormMessage />
        </FormItem>
      )}
    />
  )
}

function PlanDialog({
  open,
  onOpenChange,
  plan,
}: {
  open: boolean
  onOpenChange: (v: boolean) => void
  plan: AdminPlanFull | null
}) {
  const isEdit = !!plan
  const createPlan = useAdminCreatePlan()
  const updatePlan = useAdminUpdatePlan()
  const pending = createPlan.isPending || updatePlan.isPending

  const initial = React.useMemo(() => (plan ? planToValues(plan) : emptyValues()), [plan])
  const form = useForm<PlanValues>({
    resolver: zodResolver(planSchema),
    values: initial,
  })

  // Un plan se edita en el lugar: el cambio aplica a todos sus tenants
  // (owner 2026-09-15, context/34 §F4 SUPERSEDED).
  const tenants = plan?.tenants ?? 0

  const onSubmit = (values: PlanValues) => {
    const payload: AdminPlanInput = { ...values }
    if (isEdit) {
      updatePlan.mutate(
        { code: plan!.code, data: payload },
        {
          onSuccess: (res) => {
            toast.success(
              res.tenants > 0
                ? `Plan actualizado — aplica a ${res.tenants} ${res.tenants === 1 ? "cliente" : "clientes"}`
                : "Plan actualizado",
            )
            onOpenChange(false)
          },
          onError: (err) => toast.error(err.message ?? "Error"),
        },
      )
    } else {
      createPlan.mutate(payload, {
        onSuccess: () => { toast.success("Plan creado"); form.reset(emptyValues()); onOpenChange(false) },
        onError: (err) => toast.error(err.message ?? "Error"),
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{isEdit ? `Editar plan — ${plan.name}` : "Nuevo plan"}</DialogTitle>
        </DialogHeader>

        {isEdit && tenants > 0 && (
          <Alert>
            <Users />
            <AlertDescription>
              El cambio se aplica a {tenants === 1 ? "el cliente" : `los ${tenants} clientes`} con este plan, precio
              incluido: rige para lo que se facture de acá en adelante.
            </AlertDescription>
          </Alert>
        )}

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nombre</FormLabel>
                    <FormControl><Input {...field} /></FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="type"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Tipo</FormLabel>
                    <FormControl><Input {...field} /></FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="price"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Precio</FormLabel>
                    <FormControl>
                      <MoneyInput value={field.value} onChange={(v) => field.onChange(v ?? 0)} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="duration_days"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Duración (días)</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        min={1}
                        value={field.value}
                        onChange={(e) => field.onChange(Number(e.target.value) || 0)}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="ai_credits_monthly"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Créditos IA / mes</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        min={0}
                        value={field.value}
                        onChange={(e) => field.onChange(Number(e.target.value) || 0)}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            <Separator />
            <div>
              <p className="text-sm font-medium mb-2">Límites</p>
              <fieldset className="grid grid-cols-4 gap-3">
                <LimitField form={form} name="max_items" label="Artículos" />
                <LimitField form={form} name="max_users" label="Usuarios" />
                <LimitField form={form} name="max_customers" label="Clientes" />
                <LimitField form={form} name="max_outlets" label="Sucursales" />
                <LimitField form={form} name="max_registers" label="Cajas" />
                <LimitField form={form} name="max_suppliers" label="Proveedores" />
                <LimitField form={form} name="max_categories" label="Categorías" />
                <LimitField form={form} name="max_brands" label="Marcas" />
              </fieldset>
            </div>

            <Separator />
            <div>
              <p className="text-sm font-medium mb-2">Módulos incluidos</p>
              <fieldset className="grid grid-cols-2 gap-2">
                {FEATURE_KEYS.map(({ key, label }) => (
                  <div key={key} className="flex items-center gap-2">
                    <Checkbox
                      id={`feat-${key}`}
                      checked={!!form.watch("features")[key]}
                      onCheckedChange={(checked) =>
                        form.setValue("features", { ...form.getValues("features"), [key]: !!checked })
                      }
                    />
                    <Label htmlFor={`feat-${key}`} className="text-sm font-normal">{label}</Label>
                  </div>
                ))}
              </fieldset>
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>Cancelar</Button>
              <Button type="submit" disabled={pending}>
                {pending && <Loader2 className="mr-2 size-4 animate-spin" />}
                {isEdit ? "Guardar" : "Crear plan"}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  )
}

export default function AdminPlansPage() {
  return (
    <AdminRoleGate minRole="owner">
      <AdminPlansPageContent />
    </AdminRoleGate>
  )
}

function AdminPlansPageContent() {
  const { data, isLoading } = useAdminPlanCatalog()
  const [dialogOpen, setDialogOpen] = React.useState(false)
  const [editingPlan, setEditingPlan] = React.useState<AdminPlanFull | null>(null)

  const rows = data?.rows ?? []

  const columns: ColumnDef<AdminPlanFull, unknown>[] = [
    {
      accessorKey: "name",
      header: "Nombre",
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <span className="font-medium">{row.original.name}</span>
          {row.original.isDefault && <Badge variant="secondary">default</Badge>}
          {row.original.archived && <Badge variant="outline">archivado</Badge>}
        </div>
      ),
    },
    {
      accessorKey: "price",
      header: "Precio",
      cell: ({ row }) => (
        <span className="tabular-nums">
          {formatPuntoSaasMoney(row.original.price)}
        </span>
      ),
      meta: { label: "Precio" },
    },
    {
      accessorKey: "durationDays",
      header: "Duración",
      cell: ({ row }) => <span className="tabular-nums">{row.original.durationDays} días</span>,
      meta: { label: "Duración" },
    },
    {
      accessorKey: "tenants",
      header: "Tenants",
      cell: ({ row }) => <span className="tabular-nums">{row.original.tenants ?? 0}</span>,
      meta: { label: "Tenants" },
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => {
        const plan = row.original
        return (
          <div className="flex justify-end gap-2" onClick={(e) => e.stopPropagation()}>
            <Button variant="ghost" size="sm" onClick={() => setEditingPlan(plan)}>Editar</Button>
          </div>
        )
      },
    },
  ]

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <CreditCard className="size-5 text-muted-foreground" />
          <h1 className="text-2xl font-bold">Planes</h1>
        </div>
        <Button onClick={() => setDialogOpen(true)} className="gap-2">
          <Plus className="size-4" />
          Nuevo plan
        </Button>
      </div>

      <DataTable
        tableId="admin-plans"
        data={rows}
        columns={columns}
        isLoading={isLoading}
        getRowId={(r) => String(r.code)}
        searchPlaceholder="Buscar plan…"
        emptyMessage="Sin planes"
      />

      <PlanDialog open={dialogOpen} onOpenChange={setDialogOpen} plan={null} />
      {editingPlan && (
        <PlanDialog
          open={!!editingPlan}
          onOpenChange={(v) => { if (!v) setEditingPlan(null) }}
          plan={editingPlan}
        />
      )}
    </div>
  )
}
