"use client"

import * as React from "react"
import { useParams, useRouter } from "next/navigation"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import {
  ArrowLeft,
  LogIn,
  Trash2,
  ShieldOff,
  Loader2,
} from "lucide-react"
import { toast } from "sonner"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Separator } from "@/components/ui/separator"
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
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { MoneyInput } from "@/components/ui/money-input"

import {
  useAdminCompany,
  useAdminPlans,
  useAdminBilling,
  useAdminUpdateCompany,
  useAdminGrantAiCredits,
  useAdminSetAddons,
  useAdminSoftDelete,
  useAdminHardDelete,
  useAdminEnterCompany,
  useAdminRequests,
  useAdminResolveRequest,
} from "@/hooks/use-admin"
import { AdminApiError } from "@/lib/api-admin"

// ── Schemas ───────────────────────────────────────────────────────────────────

const billingSchema = z.object({
  plan: z.string().optional(),
  status: z.string().optional(),
  blocked: z.boolean().optional(),
  isTrial: z.boolean().optional(),
  planExpired: z.boolean().optional(),
  balance: z.number().nullable().optional(),
  discount: z.number().nullable().optional(),
  smsCredit: z.number().nullable().optional(),
  expiresAt: z.string().optional(),
  settingName: z.string().optional(),
})

const aiCreditsSchema = z.object({
  delta: z.number().int("Debe ser entero"),
  reason: z.string().min(1, "Razón requerida"),
})

const addonsSchema = z.object({
  extraUsers: z.number().int().min(0).optional(),
  extraRegisters: z.number().int().min(0).optional(),
  extraItems: z.number().int().min(0).optional(),
})

type BillingValues = z.infer<typeof billingSchema>
type AiCreditsValues = z.infer<typeof aiCreditsSchema>
type AddonsValues = z.infer<typeof addonsSchema>

// ── Helpers ───────────────────────────────────────────────────────────────────

function statusBadge(status: string, blocked: number) {
  if (blocked) return <Badge variant="destructive">Bloqueada</Badge>
  if (status === "active") return <Badge className="bg-green-600 text-white border-0">Activa</Badge>
  if (status === "cancelled") return <Badge variant="destructive">Cancelada</Badge>
  if (status === "suspended")
    return <Badge variant="outline" className="text-amber-600 border-amber-600">Suspendida</Badge>
  return <Badge variant="secondary">{status || "—"}</Badge>
}

function fmtDate(v: string | null | undefined): string {
  if (!v) return "—"
  return new Date(v).toLocaleDateString("es-PY")
}

function fmtMoney(n: number | null | undefined): string {
  if (n == null) return "—"
  return new Intl.NumberFormat("es-PY", { maximumFractionDigits: 2 }).format(n)
}

// ── Tabs ──────────────────────────────────────────────────────────────────────

function InfoTab({ id }: { id: string }) {
  const { data: company, isLoading } = useAdminCompany(id)
  const enterCompany = useAdminEnterCompany()
  const softDelete = useAdminSoftDelete()
  const hardDelete = useAdminHardDelete()
  const router = useRouter()
  const [hardDeleteName, setHardDeleteName] = React.useState("")
  const [hardDeleteOpen, setHardDeleteOpen] = React.useState(false)

  const handleEnter = () => {
    enterCompany.mutate(id, {
      onSuccess: (res) => {
        toast.success("Ingresando como empresa…")
        // BFF ya seteó _jwt_panel; abrir el panel en una nueva pestaña.
        window.open(res?.redirectUrl ?? "/", "_blank", "noopener,noreferrer")
      },
      onError: (err) => {
        toast.error("No se pudo ingresar como empresa", {
          description: err instanceof AdminApiError ? err.message : String(err),
        })
      },
    })
  }

  const handleSoftDelete = () => {
    softDelete.mutate(id, {
      onSuccess: () => {
        toast.success("Empresa suspendida")
        router.push("/admin/companies")
      },
      onError: (err) => toast.error(err.message ?? "Error"),
    })
  }

  const handleHardDelete = () => {
    const expected = company?.settingName || company?.name || ""
    if (hardDeleteName.trim() !== expected.trim()) {
      toast.error("El nombre no coincide")
      return
    }
    hardDelete.mutate(
      { id, confirm: hardDeleteName.trim() },
      {
        onSuccess: () => {
          toast.success("Empresa eliminada permanentemente")
          router.push("/admin/companies")
        },
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  if (isLoading) {
    return (
      <div className="space-y-4">
        {[...Array(6)].map((_, i) => <Skeleton key={i} className="h-6 w-full" />)}
      </div>
    )
  }
  if (!company) return <p className="text-muted-foreground">Empresa no encontrada</p>

  const fields: [string, React.ReactNode][] = [
    ["Nombre comercial", company.settingName || "—"],
    ["Razón social (BD)", company.name || company.companyName || "—"],
    ["Slug", company.slug || "—"],
    ["País", company.country || "—"],
    ["Estado", statusBadge(company.status, company.blocked)],
    ["Plan (código)", company.plan ?? "—"],
    ["Plan nombre", company.planName || "—"],
    ["Bloqueada", company.blocked ? "Sí" : "No"],
    ["ePOS habilitado", company.epos ? "Sí" : "No"],
    ["eCommerce", company.ecom ? "Sí" : "No"],
    ["Creada", fmtDate(company.createdAt)],
    ["Outlets", company.counts?.outlets ?? "—"],
    ["Cajas", company.counts?.registers ?? "—"],
    ["Usuarios", company.counts?.users ?? "—"],
    ["Clientes", company.counts?.customers ?? "—"],
    ...(company.owner
      ? [
          ["Propietario", [company.owner.name, company.owner.secondName].filter(Boolean).join(" ") || "—"] as [string, React.ReactNode],
          ["Email propietario", company.owner.email || "—"] as [string, React.ReactNode],
          ["Teléfono propietario", company.owner.phone || "—"] as [string, React.ReactNode],
        ]
      : []),
  ]

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Información general</CardTitle>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-2 sm:grid-cols-2">
            {fields.map(([label, value]) => (
              <div key={label} className="flex flex-col gap-0.5">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="text-sm font-medium">{value}</dd>
              </div>
            ))}
          </dl>
        </CardContent>
      </Card>

      {/* Acciones */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Acciones</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex flex-col gap-2 sm:flex-row">
            <Button
              onClick={handleEnter}
              disabled={enterCompany.isPending}
              className="gap-2"
            >
              {enterCompany.isPending ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <LogIn className="size-4" />
              )}
              Ingresar como empresa
            </Button>
            <p className="text-xs text-muted-foreground self-center">
              Abre el panel en una nueva pestaña con acceso al tenant.
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Zona de peligro */}
      <Card className="border-destructive/50">
        <CardHeader>
          <CardTitle className="text-base text-destructive">Zona de peligro</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Suspender */}
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-sm font-medium">Suspender empresa</p>
              <p className="text-xs text-muted-foreground">
                Cambia el estado a cancelada y bloquea el acceso. Reversible desde la pestaña Facturación.
              </p>
            </div>
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="outline" className="border-destructive text-destructive hover:bg-destructive hover:text-destructive-foreground gap-2 shrink-0">
                  <ShieldOff className="size-4" />
                  Suspender
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>¿Suspender empresa?</AlertDialogTitle>
                  <AlertDialogDescription>
                    La empresa <strong>{company.settingName || company.name}</strong> quedará suspendida
                    y sus usuarios no podrán acceder al panel. Esta acción es reversible.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancelar</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={handleSoftDelete}
                    className="bg-destructive hover:bg-destructive/90"
                    disabled={softDelete.isPending}
                  >
                    {softDelete.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                    Suspender
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </div>

          <Separator />

          {/* Eliminar permanentemente */}
          <div className="flex items-start justify-between gap-4">
            <div>
              <p className="text-sm font-medium">Eliminar permanentemente</p>
              <p className="text-xs text-muted-foreground">
                Elimina TODOS los datos de la empresa en cascada. IRREVERSIBLE.
                Deberás confirmar escribiendo el nombre exacto de la empresa.
              </p>
            </div>
            <AlertDialog open={hardDeleteOpen} onOpenChange={setHardDeleteOpen}>
              <AlertDialogTrigger asChild>
                <Button variant="destructive" className="gap-2 shrink-0">
                  <Trash2 className="size-4" />
                  Eliminar
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Eliminar empresa permanentemente</AlertDialogTitle>
                  <AlertDialogDescription asChild>
                    <div className="space-y-3">
                      <p>
                        Esta acción eliminará TODOS los datos de{" "}
                        <strong>{company.settingName || company.name}</strong>: transacciones, contactos,
                        artículos, sucursales, usuarios y más. <strong>IRREVERSIBLE.</strong>
                      </p>
                      <p>
                        Escribí el nombre exacto de la empresa para confirmar:
                        <br />
                        <strong>{company.settingName || company.name}</strong>
                      </p>
                      <Input
                        value={hardDeleteName}
                        onChange={(e) => setHardDeleteName(e.target.value)}
                        placeholder="Nombre de la empresa"
                        className="mt-1"
                      />
                    </div>
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel onClick={() => setHardDeleteName("")}>Cancelar</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={handleHardDelete}
                    className="bg-destructive hover:bg-destructive/90"
                    disabled={
                      hardDelete.isPending ||
                      hardDeleteName.trim() !== (company.settingName || company.name || "").trim()
                    }
                  >
                    {hardDelete.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                    Eliminar permanentemente
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

function BillingTab({ id }: { id: string }) {
  const { data: company } = useAdminCompany(id)
  const { data: billing, isLoading: loadingBilling } = useAdminBilling(id)
  const { data: plansData } = useAdminPlans()
  const updateCompany = useAdminUpdateCompany()
  const grantAi = useAdminGrantAiCredits()
  const setAddons = useAdminSetAddons()
  const { data: allRequests } = useAdminRequests("")
  const resolveRequest = useAdminResolveRequest()

  const plans = plansData?.rows ?? []
  const companyRequests = Array.isArray(allRequests)
    ? allRequests.filter((r) => r.companyId === id)
    : []

  const billingForm = useForm<BillingValues>({
    resolver: zodResolver(billingSchema),
    values: {
      plan: company?.plan != null ? String(company.plan) : "",
      status: company?.status ?? "",
      blocked: Boolean(company?.blocked),
      isTrial: Boolean(company?.isTrial),
      planExpired: Boolean(company?.planExpired),
      balance: company?.balance ?? null,
      discount: company?.discount ?? null,
      smsCredit: company?.smsCredit ?? null,
      expiresAt: company?.expiresAt ?? "",
      settingName: company?.settingName ?? "",
    },
  })

  const aiForm = useForm<AiCreditsValues>({
    resolver: zodResolver(aiCreditsSchema),
    defaultValues: { delta: 0, reason: "" },
  })

  const addonsForm = useForm<AddonsValues>({
    resolver: zodResolver(addonsSchema),
    values: {
      extraUsers: (company?.moduleData?.extraUsers as number) ?? 0,
      extraRegisters: (company?.moduleData?.extraRegisters as number) ?? 0,
      extraItems: (company?.moduleData?.extraItems as number) ?? 0,
    },
  })

  const onBillingSubmit = (values: BillingValues) => {
    const patch: Record<string, unknown> = {}
    if (values.plan !== undefined && values.plan !== "") patch.plan = parseInt(values.plan, 10)
    if (values.status !== undefined) patch.status = values.status
    if (values.blocked !== undefined) patch.blocked = values.blocked ? 1 : 0
    if (values.isTrial !== undefined) patch.isTrial = values.isTrial
    if (values.planExpired !== undefined) patch.planExpired = values.planExpired
    if (values.balance !== undefined) patch.balance = values.balance
    if (values.discount !== undefined) patch.discount = values.discount
    if (values.smsCredit !== undefined) patch.smsCredit = values.smsCredit
    if (values.expiresAt !== undefined) patch.expiresAt = values.expiresAt || null
    if (values.settingName !== undefined) patch.settingName = values.settingName

    updateCompany.mutate(
      { id, data: patch },
      {
        onSuccess: () => toast.success("Empresa actualizada"),
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  const onAiSubmit = (values: AiCreditsValues) => {
    grantAi.mutate(
      { id, delta: values.delta, reason: values.reason },
      {
        onSuccess: () => {
          toast.success("Créditos IA actualizados")
          aiForm.reset()
        },
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  const onAddonsSubmit = (values: AddonsValues) => {
    setAddons.mutate(
      { id, addons: values },
      {
        onSuccess: () => toast.success("Add-ons actualizados"),
        onError: (err) => toast.error(err.message ?? "Error"),
      },
    )
  }

  return (
    <div className="space-y-6">
      {/* Edición de plan y datos de facturación */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Plan y facturación</CardTitle>
        </CardHeader>
        <CardContent>
          <Form {...billingForm}>
            <form onSubmit={billingForm.handleSubmit(onBillingSubmit)} className="grid gap-4 sm:grid-cols-2">
              <FormField
                control={billingForm.control}
                name="settingName"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Nombre comercial</FormLabel>
                    <FormControl>
                      <Input {...field} placeholder="Nombre visible" />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={billingForm.control}
                name="plan"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Plan</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder="Seleccionar plan" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {plans.map((p) => (
                          <SelectItem key={p.code} value={String(p.code)}>
                            {p.name} — {fmtMoney(p.price)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={billingForm.control}
                name="status"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Estado</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger>
                          <SelectValue placeholder="Estado" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value="active">Activa</SelectItem>
                        <SelectItem value="suspended">Suspendida</SelectItem>
                        <SelectItem value="cancelled">Cancelada</SelectItem>
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={billingForm.control}
                name="expiresAt"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Vence el</FormLabel>
                    <FormControl>
                      <Input type="date" {...field} value={field.value ?? ""} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={billingForm.control}
                name="balance"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Balance</FormLabel>
                    <FormControl>
                      <MoneyInput
                        value={field.value ?? null}
                        onChange={field.onChange}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={billingForm.control}
                name="discount"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Descuento (%)</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        min={0}
                        max={100}
                        step={0.01}
                        value={field.value ?? ""}
                        onChange={(e) => field.onChange(e.target.value === "" ? null : parseFloat(e.target.value))}
                        placeholder="0"
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={billingForm.control}
                name="smsCredit"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Crédito SMS</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        min={0}
                        value={field.value ?? ""}
                        onChange={(e) => field.onChange(e.target.value === "" ? null : parseInt(e.target.value, 10))}
                        placeholder="0"
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {/* Switches */}
              <div className="flex flex-col gap-3 sm:col-span-2">
                <FormField
                  control={billingForm.control}
                  name="blocked"
                  render={({ field }) => (
                    <FormItem className="flex items-center gap-3">
                      <FormControl>
                        <Switch checked={field.value} onCheckedChange={field.onChange} />
                      </FormControl>
                      <Label>Bloqueada</Label>
                    </FormItem>
                  )}
                />
                <FormField
                  control={billingForm.control}
                  name="isTrial"
                  render={({ field }) => (
                    <FormItem className="flex items-center gap-3">
                      <FormControl>
                        <Switch checked={field.value} onCheckedChange={field.onChange} />
                      </FormControl>
                      <Label>En trial</Label>
                    </FormItem>
                  )}
                />
                <FormField
                  control={billingForm.control}
                  name="planExpired"
                  render={({ field }) => (
                    <FormItem className="flex items-center gap-3">
                      <FormControl>
                        <Switch checked={field.value} onCheckedChange={field.onChange} />
                      </FormControl>
                      <Label>Plan vencido</Label>
                    </FormItem>
                  )}
                />
              </div>

              <div className="sm:col-span-2">
                <Button type="submit" disabled={updateCompany.isPending}>
                  {updateCompany.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                  Guardar cambios
                </Button>
              </div>
            </form>
          </Form>
        </CardContent>
      </Card>

      {/* Créditos IA */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Créditos IA</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {loadingBilling ? (
            <Skeleton className="h-6 w-32" />
          ) : (
            <p className="text-sm">
              Saldo actual:{" "}
              <span className="font-bold tabular-nums">{billing?.aiCreditsBalance ?? 0}</span> créditos
            </p>
          )}

          <Form {...aiForm}>
            <form onSubmit={aiForm.handleSubmit(onAiSubmit)} className="flex gap-3 flex-wrap items-end">
              <FormField
                control={aiForm.control}
                name="delta"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Delta (positivo = otorgar, negativo = descontar)</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        className="w-32"
                        {...field}
                        onChange={(e) => field.onChange(parseInt(e.target.value, 10))}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={aiForm.control}
                name="reason"
                render={({ field }) => (
                  <FormItem className="flex-1 min-w-48">
                    <FormLabel>Razón</FormLabel>
                    <FormControl>
                      <Input placeholder="Ej: Promoción junio 2026" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <Button type="submit" disabled={grantAi.isPending}>
                {grantAi.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Aplicar
              </Button>
            </form>
          </Form>

          {/* Ledger IA */}
          {billing?.aiLedger && billing.aiLedger.length > 0 && (
            <div>
              <p className="text-xs text-muted-foreground mb-2">Últimos movimientos</p>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Fecha</TableHead>
                    <TableHead>Delta</TableHead>
                    <TableHead>Saldo post</TableHead>
                    <TableHead>Razón</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {billing.aiLedger.map((l) => (
                    <TableRow key={l.id}>
                      <TableCell className="text-xs tabular-nums">{fmtDate(l.createdAt)}</TableCell>
                      <TableCell className={`text-xs tabular-nums font-medium ${l.delta >= 0 ? "text-green-600" : "text-destructive"}`}>
                        {l.delta >= 0 ? "+" : ""}{l.delta}
                      </TableCell>
                      <TableCell className="text-xs tabular-nums">{l.balanceAfter}</TableCell>
                      <TableCell className="text-xs">{l.reason}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Add-ons */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Add-ons</CardTitle>
        </CardHeader>
        <CardContent>
          <Form {...addonsForm}>
            <form onSubmit={addonsForm.handleSubmit(onAddonsSubmit)} className="flex gap-4 flex-wrap items-end">
              <FormField
                control={addonsForm.control}
                name="extraUsers"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Usuarios extra</FormLabel>
                    <FormControl>
                      <Input type="number" min={0} className="w-28" {...field}
                        onChange={(e) => field.onChange(parseInt(e.target.value, 10) || 0)} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={addonsForm.control}
                name="extraRegisters"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Cajas extra</FormLabel>
                    <FormControl>
                      <Input type="number" min={0} className="w-28" {...field}
                        onChange={(e) => field.onChange(parseInt(e.target.value, 10) || 0)} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={addonsForm.control}
                name="extraItems"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Artículos extra</FormLabel>
                    <FormControl>
                      <Input type="number" min={0} className="w-28" {...field}
                        onChange={(e) => field.onChange(parseInt(e.target.value, 10) || 0)} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <Button type="submit" disabled={setAddons.isPending}>
                {setAddons.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Guardar add-ons
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>

      {/* Historial de pagos */}
      {loadingBilling ? (
        <Skeleton className="h-40 w-full" />
      ) : billing?.payments && billing.payments.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Historial de pagos</CardTitle>
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Monto</TableHead>
                  <TableHead>Estado</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {billing.payments.map((p) => (
                  <TableRow key={p.id}>
                    <TableCell className="text-sm tabular-nums">{fmtDate(p.date)}</TableCell>
                    <TableCell className="text-sm tabular-nums font-medium">{fmtMoney(p.amount)}</TableCell>
                    <TableCell>
                      <Badge variant={p.status === 1 ? "default" : "secondary"}>
                        {p.status === 1 ? "Confirmado" : "Pendiente"}
                      </Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      ) : null}

      {/* Solicitudes de plan de esta empresa */}
      {companyRequests.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Solicitudes de cambio de plan</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {companyRequests.map((req) => (
                <div key={req.id} className="flex items-center justify-between rounded-md border px-3 py-2">
                  <div className="text-sm space-y-0.5">
                    <p>Plan solicitado: <strong>{req.requestedPlanCode}</strong></p>
                    {req.note && <p className="text-xs text-muted-foreground">{req.note}</p>}
                    <p className="text-xs text-muted-foreground">{fmtDate(req.createdAt)}</p>
                  </div>
                  {req.status === "pending" ? (
                    <div className="flex gap-2 shrink-0">
                      <Button
                        size="sm"
                        variant="outline"
                        className="text-green-600 border-green-600"
                        disabled={resolveRequest.isPending}
                        onClick={() =>
                          resolveRequest.mutate(
                            { requestId: req.id, approve: true, companyId: id },
                            {
                              onSuccess: () => toast.success("Solicitud aprobada"),
                              onError: (err) => toast.error(err.message ?? "Error"),
                            },
                          )
                        }
                      >
                        Aprobar
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="text-destructive border-destructive"
                        disabled={resolveRequest.isPending}
                        onClick={() =>
                          resolveRequest.mutate(
                            { requestId: req.id, approve: false, companyId: id },
                            {
                              onSuccess: () => toast.success("Solicitud rechazada"),
                              onError: (err) => toast.error(err.message ?? "Error"),
                            },
                          )
                        }
                      >
                        Rechazar
                      </Button>
                    </div>
                  ) : (
                    <Badge variant={req.status === "approved" ? "default" : "secondary"}>
                      {req.status === "approved" ? "Aprobada" : "Rechazada"}
                    </Badge>
                  )}
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  )
}

// ── Main ──────────────────────────────────────────────────────────────────────

export default function AdminCompanyDetailPage() {
  const params = useParams()
  const router = useRouter()
  const id = params.id as string

  const { data: company, isLoading } = useAdminCompany(id)

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => router.push("/admin/companies")}
          className="gap-1"
        >
          <ArrowLeft className="size-4" />
          Empresas
        </Button>
        <Separator orientation="vertical" className="h-5" />
        {isLoading ? (
          <Skeleton className="h-7 w-48" />
        ) : (
          <h1 className="text-xl font-bold truncate">
            {company?.settingName || company?.name || "Empresa"}
          </h1>
        )}
        {company && !isLoading && (
          <div className="ml-1">
            {company.blocked
              ? <Badge variant="destructive">Bloqueada</Badge>
              : company.status === "active"
              ? <Badge className="bg-green-600 text-white border-0">Activa</Badge>
              : <Badge variant="secondary">{company.status}</Badge>
            }
          </div>
        )}
      </div>

      <Tabs defaultValue="info">
        <TabsList>
          <TabsTrigger value="info">Información</TabsTrigger>
          <TabsTrigger value="billing">Facturación</TabsTrigger>
        </TabsList>
        <TabsContent value="info" className="mt-4">
          <InfoTab id={id} />
        </TabsContent>
        <TabsContent value="billing" className="mt-4">
          <BillingTab id={id} />
        </TabsContent>
      </Tabs>
    </div>
  )
}
