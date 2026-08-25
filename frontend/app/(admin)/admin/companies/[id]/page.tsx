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
  ShieldCheck,
  Loader2,
  RefreshCw,
  HeartPulse,
  CalendarClock,
  ChevronLeft,
  ChevronRight,
  ScrollText,
  StickyNote,
  Receipt,
  Sparkles,
} from "lucide-react"
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip as RechartsTooltip,
  ResponsiveContainer,
} from "recharts"
import { toast } from "sonner"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { Progress } from "@/components/ui/progress"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Separator } from "@/components/ui/separator"
import { EmptyState } from "@/components/empty-state"
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
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
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@/components/ui/tooltip"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { MODULES_CATALOG } from "@/lib/modules-catalog"

import {
  useAdminCompany,
  useAdminMe,
  useAdminPlans,
  useAdminBilling,
  useAdminUpdateCompany,
  useAdminGrantAiCredits,
  useAdminSetAddons,
  useAdminSoftDelete,
  useAdminHardDelete,
  useAdminEnterCompany,
  useAdminResolveRequest,
  useAdminHealthDetail,
  useAdminRecomputeHealth,
  useAdminModules,
  useAdminToggleModule,
  useAdminSuspend,
  useAdminUnsuspend,
  useAdminExtendTrial,
  useAdminInvoices,
  useAdminTenantAudit,
  useAdminTenantNotes,
  useAdminCreateTenantNote,
  useAdminDeleteTenantNote,
  useAdminEmitSaasInvoice,
  adminRoleAtLeast,
  type AdminHealthChecklistItem,
} from "@/hooks/use-admin"
import { AdminApiError } from "@/lib/api-admin"
import { formatPhone } from "@/lib/phone"
import { useAdminContext } from "@/components/admin/admin-auth-guard"

// ── Schemas ───────────────────────────────────────────────────────────────────

const planSchema = z.object({
  plan: z.string().min(1, "Elegí un plan"),
})

const aiCreditsSchema = z.object({
  delta: z.number().int("Debe ser entero").refine((v) => v !== 0, "El monto no puede ser 0"),
  reason: z.string().min(1, "Motivo requerido"),
})

const addonsSchema = z.object({
  extraUsers: z.number().int().min(0).optional(),
  extraRegisters: z.number().int().min(0).optional(),
  extraItems: z.number().int().min(0).optional(),
})

const extendTrialSchema = z.object({
  days: z.number().int().positive("Tiene que ser mayor a 0"),
})

const noteSchema = z.object({
  body: z.string().trim().min(1, "Escribí algo antes de guardar"),
})

type AiCreditsValues = z.infer<typeof aiCreditsSchema>
type AddonsValues = z.infer<typeof addonsSchema>
type ExtendTrialValues = z.infer<typeof extendTrialSchema>
type NoteValues = z.infer<typeof noteSchema>

// ── Helpers ───────────────────────────────────────────────────────────────────

function statusBadge(status: string, blocked: number, suspended: number) {
  if (blocked) return <Badge variant="destructive">Bloqueada</Badge>
  if (suspended || status === "suspended")
    return <Badge variant="outline" className="text-amber-600 border-amber-600">Suspendida</Badge>
  if (status === "active") return <Badge className="bg-green-600 text-white border-0">Activa</Badge>
  if (status === "cancelled") return <Badge variant="destructive">Cancelada</Badge>
  return <Badge variant="secondary">{status || "—"}</Badge>
}

function fmtDate(v: string | null | undefined): string {
  if (!v) return "—"
  return new Date(v).toLocaleDateString("es-PY")
}

function fmtDateTime(v: string | null | undefined): string {
  if (!v) return "—"
  return new Date(v).toLocaleString("es-PY", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  })
}

function fmtMoney(n: number | null | undefined): string {
  if (n == null) return "—"
  return new Intl.NumberFormat("es-PY", { maximumFractionDigits: 2 }).format(n)
}

const INVOICE_STATUS_VARIANT: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  paid: "default",
  pending: "secondary",
  failed: "destructive",
  refunded: "outline",
  cancelled: "destructive",
}

const INVOICE_STATUS_LABEL: Record<string, string> = {
  paid: "Pagada",
  pending: "Pendiente",
  failed: "Fallida",
  refunded: "Reembolsada",
  cancelled: "Cancelada",
}

const MODULES_TITLE_BY_KEY: Record<string, string> = Object.fromEntries(
  MODULES_CATALOG.map((m) => [m.key, m.title]),
)

// ── Header: acciones (impersonar / suspender / extender trial) ────────────────

function HeaderActions({ id }: { id: string }) {
  const { data: company } = useAdminCompany(id)

  const enterCompany = useAdminEnterCompany()
  const suspend = useAdminSuspend()
  const unsuspend = useAdminUnsuspend()
  const extendTrial = useAdminExtendTrial()
  const [extendOpen, setExtendOpen] = React.useState(false)

  const extendForm = useForm<ExtendTrialValues>({
    resolver: zodResolver(extendTrialSchema),
    defaultValues: { days: 7 },
  })

  if (!company) return null

  const isCancelled = company.status === "cancelled"
  const isSuspended = company.status === "suspended"

  const handleEnter = () => {
    enterCompany.mutate(id, {
      onSuccess: (res) => {
        toast.success("Ingresando como empresa…")
        window.open(res?.redirectUrl ?? "/", "_blank", "noopener,noreferrer")
      },
      onError: (err) => {
        toast.error("No se pudo ingresar como empresa", {
          description: err instanceof AdminApiError ? err.message : String(err),
        })
      },
    })
  }

  const handleSuspend = () => {
    suspend.mutate(id, {
      onSuccess: () => toast.success("Empresa suspendida"),
      onError: (err) => toast.error(err instanceof AdminApiError ? err.message : "Error"),
    })
  }

  const handleUnsuspend = () => {
    unsuspend.mutate(id, {
      onSuccess: () => toast.success("Empresa reactivada"),
      onError: (err) => toast.error(err instanceof AdminApiError ? err.message : "Error"),
    })
  }

  const onExtendSubmit = (values: ExtendTrialValues) => {
    extendTrial.mutate(
      { id, days: values.days },
      {
        onSuccess: () => {
          toast.success(`Vencimiento extendido ${values.days} días`)
          setExtendOpen(false)
          extendForm.reset({ days: 7 })
        },
        onError: (err) => toast.error(err instanceof AdminApiError ? err.message : "Error"),
      },
    )
  }

  return (
    <TooltipProvider>
      <div className="flex flex-wrap items-center gap-2">
        <Button onClick={handleEnter} disabled={enterCompany.isPending} size="sm" className="gap-2">
          {enterCompany.isPending ? <Loader2 className="size-4 animate-spin" /> : <LogIn className="size-4" />}
          Ingresar como empresa
        </Button>

        <Dialog open={extendOpen} onOpenChange={setExtendOpen}>
          <Tooltip>
            <TooltipTrigger asChild>
              <span>
                <DialogTrigger asChild>
                  <Button variant="outline" size="sm" className="gap-2" disabled={isCancelled}>
                    <CalendarClock className="size-4" />
                    Extender trial
                  </Button>
                </DialogTrigger>
              </span>
            </TooltipTrigger>
            {isCancelled && <TooltipContent>La empresa está cancelada</TooltipContent>}
          </Tooltip>
          <DialogContent className="sm:max-w-2xl">
            <DialogHeader>
              <DialogTitle>Extender vencimiento del plan</DialogTitle>
              <DialogDescription>
                Suma días al vencimiento actual de <strong>{company.settingName || company.name}</strong>{" "}
                y limpia el flag de plan vencido. Si el plan ya venció, cuenta desde hoy — nunca resta días.
              </DialogDescription>
            </DialogHeader>
            <Form {...extendForm}>
              <form onSubmit={extendForm.handleSubmit(onExtendSubmit)} className="space-y-4">
                <FormField
                  control={extendForm.control}
                  name="days"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Días a extender</FormLabel>
                      <FormControl>
                        <Input
                          type="number"
                          min={1}
                          {...field}
                          onChange={(e) => field.onChange(parseInt(e.target.value, 10) || 0)}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <DialogFooter>
                  <Button type="submit" disabled={extendTrial.isPending}>
                    {extendTrial.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                    Extender
                  </Button>
                </DialogFooter>
              </form>
            </Form>
          </DialogContent>
        </Dialog>

        {isSuspended ? (
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button variant="outline" size="sm" className="gap-2 text-green-600 border-green-600 hover:bg-green-600 hover:text-white">
                <ShieldCheck className="size-4" />
                Reactivar
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent className="sm:max-w-md">
              <AlertDialogHeader>
                <AlertDialogTitle>¿Reactivar esta empresa?</AlertDialogTitle>
                <AlertDialogDescription>
                  <strong>{company.settingName || company.name}</strong> vuelve a tener acceso normal al
                  panel y al POS de inmediato.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancelar</AlertDialogCancel>
                <AlertDialogAction onClick={handleUnsuspend} disabled={unsuspend.isPending}>
                  {unsuspend.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                  Reactivar
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        ) : (
          <Tooltip>
            <TooltipTrigger asChild>
              <span>
                <AlertDialog>
                  <AlertDialogTrigger asChild>
                    <Button
                      variant="outline"
                      size="sm"
                      className="gap-2 text-amber-600 border-amber-600 hover:bg-amber-600 hover:text-white"
                      disabled={isCancelled}
                    >
                      <ShieldOff className="size-4" />
                      Suspender
                    </Button>
                  </AlertDialogTrigger>
                  <AlertDialogContent className="sm:max-w-md">
                    <AlertDialogHeader>
                      <AlertDialogTitle>¿Suspender esta empresa?</AlertDialogTitle>
                      <AlertDialogDescription>
                        Los usuarios de <strong>{company.settingName || company.name}</strong> no van a poder
                        acceder al panel ni al POS hasta que la reactivés. Es reversible desde este mismo botón.
                      </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                      <AlertDialogCancel>Cancelar</AlertDialogCancel>
                      <AlertDialogAction
                        onClick={handleSuspend}
                        className="bg-amber-600 hover:bg-amber-600/90"
                        disabled={suspend.isPending}
                      >
                        {suspend.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                        Suspender
                      </AlertDialogAction>
                    </AlertDialogFooter>
                  </AlertDialogContent>
                </AlertDialog>
              </span>
            </TooltipTrigger>
            {isCancelled && <TooltipContent>La empresa está cancelada</TooltipContent>}
          </Tooltip>
        )}
      </div>
    </TooltipProvider>
  )
}

// ── Tab: Resumen ────────────────────────────────────────────────────────────────

function ResumenTab({ id }: { id: string }) {
  const { data: company, isLoading } = useAdminCompany(id)
  const { data: health } = useAdminHealthDetail(id)
  const softDelete = useAdminSoftDelete()
  const hardDelete = useAdminHardDelete()
  const router = useRouter()
  const [hardDeleteName, setHardDeleteName] = React.useState("")
  const [hardDeleteOpen, setHardDeleteOpen] = React.useState(false)

  const handleSoftDelete = () => {
    softDelete.mutate(id, {
      onSuccess: () => {
        toast.success("Suscripción cancelada")
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
    ["Estado", statusBadge(company.status, company.blocked, company.suspended)],
    ["Plan (código)", company.plan ?? "—"],
    ["Plan nombre", company.planName || "—"],
    ["Bloqueada", company.blocked ? "Sí" : "No"],
    ["Suspendida", company.suspended ? "Sí" : "No"],
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
          // formatPhone: la BD guarda E.164 sin '+'; la ficha lo pintaba crudo.
          ["Teléfono propietario", formatPhone(company.owner.phone) || "—"] as [string, React.ReactNode],
        ]
      : []),
  ]

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle className="text-base">Información general</CardTitle>
          {health && (
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground">Salud</span>
              <span className="text-sm font-bold tabular-nums">{health.score}</span>
              {health.level === "green" && <Badge className="bg-emerald-600 text-white border-0">Verde</Badge>}
              {health.level === "yellow" && <Badge className="bg-amber-500 text-white border-0">Amarillo</Badge>}
              {health.level === "red" && <Badge variant="destructive">Rojo</Badge>}
            </div>
          )}
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

      {/* Zona de peligro */}
      <Card className="border-destructive/50">
        <CardHeader>
          <CardTitle className="text-base text-destructive">Zona de peligro</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Cancelar suscripción (soft-delete: distinto de Suspender del header, que es reversible) */}
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-sm font-medium">Cancelar suscripción</p>
              <p className="text-xs text-muted-foreground">
                Marca la empresa como cancelada y bloquea el acceso. Distinto de &quot;Suspender&quot;
                (arriba, reversible con un click) — esto es un paso previo a dar de baja definitivamente.
              </p>
            </div>
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="outline" className="border-destructive text-destructive hover:bg-destructive hover:text-destructive-foreground gap-2 shrink-0">
                  <ShieldOff className="size-4" />
                  Cancelar
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>¿Cancelar la suscripción?</AlertDialogTitle>
                  <AlertDialogDescription>
                    La empresa <strong>{company.settingName || company.name}</strong> queda cancelada
                    y bloqueada. No borra datos.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Volver</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={handleSoftDelete}
                    className="bg-destructive hover:bg-destructive/90"
                    disabled={softDelete.isPending}
                  >
                    {softDelete.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                    Cancelar suscripción
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

// ── Tab: Config ───────────────────────────────────────────────────────────────

function ConfigTab({ id }: { id: string }) {
  const { data: company } = useAdminCompany(id)
  const { data: billing, isLoading: loadingBilling } = useAdminBilling(id)
  const { data: plansData } = useAdminPlans()
  const { data: modules, isLoading: loadingModules } = useAdminModules(id)
  const updateCompany = useAdminUpdateCompany()
  const toggleModule = useAdminToggleModule()
  const grantAi = useAdminGrantAiCredits()
  const setAddons = useAdminSetAddons()
  const [aiDialogOpen, setAiDialogOpen] = React.useState(false)

  const plans = plansData?.rows ?? []

  const planForm = useForm<z.infer<typeof planSchema>>({
    resolver: zodResolver(planSchema),
    values: { plan: company?.plan != null ? String(company.plan) : "" },
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

  const onPlanSubmit = (values: z.infer<typeof planSchema>) => {
    updateCompany.mutate(
      { id, data: { plan: parseInt(values.plan, 10) } },
      {
        onSuccess: () => toast.success("Plan actualizado"),
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
          aiForm.reset({ delta: 0, reason: "" })
          setAiDialogOpen(false)
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

  const handleToggleModule = (key: string, enabled: boolean) => {
    toggleModule.mutate(
      { id, key, enabled },
      {
        onSuccess: () => toast.success(enabled ? "Módulo activado" : "Módulo desactivado"),
        onError: (err) => toast.error(err instanceof AdminApiError ? err.message : "Error"),
      },
    )
  }

  const moduleEntries = modules ? Object.entries(modules) : []

  return (
    <div className="space-y-6">
      {/* Plan actual */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Plan</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <p className="text-sm">
            Plan vigente: <strong>{company?.planName || "—"}</strong>{" "}
            {company?.planPrice != null && (
              <span className="text-muted-foreground">({fmtMoney(company.planPrice)}/mes)</span>
            )}
          </p>
          <Form {...planForm}>
            <form onSubmit={planForm.handleSubmit(onPlanSubmit)} className="flex gap-3 items-end flex-wrap">
              <FormField
                control={planForm.control}
                name="plan"
                render={({ field }) => (
                  <FormItem className="w-56">
                    <FormLabel>Cambiar plan manualmente</FormLabel>
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
              <Button type="submit" variant="outline" disabled={updateCompany.isPending}>
                {updateCompany.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Guardar
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>

      {/* Módulos */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Módulos</CardTitle>
        </CardHeader>
        <CardContent>
          {loadingModules ? (
            <div className="grid gap-3 sm:grid-cols-2">
              {[...Array(6)].map((_, i) => <Skeleton key={i} className="h-8 w-full" />)}
            </div>
          ) : moduleEntries.length === 0 ? (
            <EmptyState
              icon={Sparkles}
              title="Sin módulos"
              description="No se pudo leer el estado de módulos de este tenant."
              ghost={false}
            />
          ) : (
            <div className="grid gap-3 sm:grid-cols-2">
              {moduleEntries.map(([key, state]) => (
                <div key={key} className="flex items-center justify-between gap-3 rounded-md border px-3 py-2">
                  <Label className="text-sm font-normal">{MODULES_TITLE_BY_KEY[key] ?? key}</Label>
                  <Switch
                    checked={state.enabled}
                    disabled={toggleModule.isPending}
                    onCheckedChange={(checked) => handleToggleModule(key, checked)}
                  />
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Créditos IA */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle className="text-base">Créditos IA</CardTitle>
          <Dialog open={aiDialogOpen} onOpenChange={setAiDialogOpen}>
            <DialogTrigger asChild>
              <Button variant="outline" size="sm">Ajustar créditos</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
              <DialogHeader>
                <DialogTitle>Ajustar créditos IA</DialogTitle>
                <DialogDescription>
                  Positivo otorga créditos, negativo descuenta. Queda registrado en el historial
                  de esta empresa con el motivo que pongas.
                </DialogDescription>
              </DialogHeader>
              <Form {...aiForm}>
                <form onSubmit={aiForm.handleSubmit(onAiSubmit)} className="space-y-4">
                  <FormField
                    control={aiForm.control}
                    name="delta"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Cantidad (+/-)</FormLabel>
                        <FormControl>
                          <Input
                            type="number"
                            {...field}
                            onChange={(e) => field.onChange(parseInt(e.target.value, 10) || 0)}
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
                      <FormItem>
                        <FormLabel>Motivo</FormLabel>
                        <FormControl>
                          <Input placeholder="Ej: Promoción junio 2026" {...field} />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <DialogFooter>
                    <Button type="submit" disabled={grantAi.isPending}>
                      {grantAi.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                      Aplicar
                    </Button>
                  </DialogFooter>
                </form>
              </Form>
            </DialogContent>
          </Dialog>
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
    </div>
  )
}

// ── Tab: Facturación ────────────────────────────────────────────────────────────

function FacturacionTab({ id }: { id: string }) {
  const { data, isLoading } = useAdminInvoices(id)
  const { data: billing } = useAdminBilling(id)
  const resolveRequest = useAdminResolveRequest()
  const emitSaasInvoice = useAdminEmitSaasInvoice(id)
  const admin = useAdminContext()
  const canEmitSaasInvoice = adminRoleAtLeast(admin.role, "support")

  const invoices = data?.invoices ?? []
  const requests = data?.requests ?? []

  if (isLoading) {
    return (
      <div className="space-y-4">
        {[...Array(4)].map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Facturas</CardTitle>
        </CardHeader>
        <CardContent>
          {invoices.length === 0 ? (
            <EmptyState
              icon={Receipt}
              title="Sin facturas"
              description="Todavía no hay facturas registradas para esta empresa."
              ghost={false}
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Fecha</TableHead>
                  <TableHead>Concepto</TableHead>
                  <TableHead>Monto</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead>Punto SaaS</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invoices.map((inv) => (
                  <TableRow key={inv.id}>
                    <TableCell className="text-sm tabular-nums">{fmtDate(inv.createdAt)}</TableCell>
                    <TableCell className="text-sm capitalize">{inv.type}</TableCell>
                    <TableCell className="text-sm tabular-nums font-medium">
                      {fmtMoney(inv.amountUsd)} {inv.currency}
                    </TableCell>
                    <TableCell>
                      <Badge variant={INVOICE_STATUS_VARIANT[inv.status] ?? "secondary"}>
                        {INVOICE_STATUS_LABEL[inv.status] ?? inv.status}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {inv.saasTransactionId ? (
                        <Badge variant="outline" className="font-mono text-[10px]">
                          Emitida — {inv.saasTransactionId.slice(0, 8)}
                        </Badge>
                      ) : inv.status !== "paid" ? (
                        <span className="text-xs text-muted-foreground">—</span>
                      ) : canEmitSaasInvoice ? (
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={emitSaasInvoice.isPending}
                          onClick={() =>
                            emitSaasInvoice.mutate(
                              { invoiceId: inv.id },
                              {
                                onSuccess: () => toast.success("Factura Punto emitida"),
                                onError: (err) => toast.error(err.message ?? "No se pudo emitir"),
                              },
                            )
                          }
                        >
                          {emitSaasInvoice.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                          Emitir factura Punto
                        </Button>
                      ) : (
                        <Badge variant="secondary">Pendiente</Badge>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {requests.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Solicitudes de cambio de plan</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {requests.map((req) => (
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

      {billing?.payments && billing.payments.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Historial de pagos (legacy)</CardTitle>
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
      )}
    </div>
  )
}

// ── Tab: Actividad ──────────────────────────────────────────────────────────────

function ActividadTab({ id }: { id: string }) {
  const [page, setPage] = React.useState(1)
  const pageSize = 30
  const { data, isLoading } = useAdminTenantAudit(id, page, pageSize)

  const rows = data?.rows ?? []
  const total = data?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / pageSize))

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Actividad del tenant</CardTitle>
      </CardHeader>
      <CardContent className="p-0">
        {isLoading ? (
          <div className="p-4 space-y-2">
            {[...Array(6)].map((_, i) => <Skeleton key={i} className="h-9 w-full" />)}
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={ScrollText}
            title="Sin actividad registrada"
            description="Todavía no hay acciones de usuarios de este tenant en el log."
            ghost={false}
          />
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-40">Fecha</TableHead>
                  <TableHead className="w-20">Realm</TableHead>
                  <TableHead>Endpoint</TableHead>
                  <TableHead className="w-28">IP</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.id}>
                    <TableCell className="text-xs tabular-nums text-muted-foreground whitespace-nowrap">
                      {fmtDateTime(row.createdAt)}
                    </TableCell>
                    <TableCell>
                      <Badge variant="secondary">{row.realm || "—"}</Badge>
                    </TableCell>
                    <TableCell className="text-xs font-mono truncate max-w-[280px]">
                      <span className="text-muted-foreground">{row.method}</span> {row.endpoint || "—"}
                    </TableCell>
                    <TableCell className="text-xs font-mono text-muted-foreground">{row.ip || "—"}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}

        <div className="flex items-center justify-between px-4 py-3 border-t">
          <p className="text-xs text-muted-foreground">
            Página {page} de {totalPages} ({total} registros)
          </p>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1 || isLoading}
            >
              <ChevronLeft className="size-4" />
              Anterior
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              disabled={page >= totalPages || isLoading}
            >
              Siguiente
              <ChevronRight className="size-4" />
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

// ── Tab: Notas ──────────────────────────────────────────────────────────────────

function NotasTab({ id }: { id: string }) {
  const { data: me } = useAdminMe()
  const [page, setPage] = React.useState(1)
  const pageSize = 20
  const { data, isLoading } = useAdminTenantNotes(id, page, pageSize)
  const createNote = useAdminCreateTenantNote()
  const deleteNote = useAdminDeleteTenantNote()

  const noteForm = useForm<NoteValues>({
    resolver: zodResolver(noteSchema),
    defaultValues: { body: "" },
  })

  const rows = data?.rows ?? []
  const total = data?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / pageSize))

  const onSubmit = (values: NoteValues) => {
    createNote.mutate(
      { companyId: id, body: values.body },
      {
        onSuccess: () => {
          toast.success("Nota agregada")
          noteForm.reset({ body: "" })
          setPage(1)
        },
        onError: (err) => toast.error(err instanceof AdminApiError ? err.message : "Error"),
      },
    )
  }

  const handleDelete = (noteId: string) => {
    deleteNote.mutate(
      { id: noteId, companyId: id },
      {
        onSuccess: () => toast.success("Nota borrada"),
        onError: (err) => toast.error(err instanceof AdminApiError ? err.message : "Error"),
      },
    )
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Nueva nota</CardTitle>
        </CardHeader>
        <CardContent>
          <Form {...noteForm}>
            <form onSubmit={noteForm.handleSubmit(onSubmit)} className="space-y-3">
              <FormField
                control={noteForm.control}
                name="body"
                render={({ field }) => (
                  <FormItem>
                    <FormControl>
                      <Textarea placeholder="Contexto de soporte, seguimiento comercial, incidentes…" rows={3} {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <Button type="submit" disabled={createNote.isPending}>
                {createNote.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
                Agregar nota
              </Button>
            </form>
          </Form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Historial</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {isLoading ? (
            [...Array(3)].map((_, i) => <Skeleton key={i} className="h-16 w-full" />)
          ) : rows.length === 0 ? (
            <EmptyState
              icon={StickyNote}
              title="Sin notas"
              description="Todavía no hay notas internas para este tenant."
              ghost={false}
            />
          ) : (
            <>
              {rows.map((note) => (
                <div key={note.id} className="rounded-md border px-3 py-2 space-y-1">
                  <div className="flex items-start justify-between gap-3">
                    <p className="text-sm whitespace-pre-wrap">{note.body}</p>
                    {me?.id === note.authorId && (
                      <AlertDialog>
                        <AlertDialogTrigger asChild>
                          <Button variant="ghost" size="icon" className="size-7 shrink-0 text-muted-foreground hover:text-destructive">
                            <Trash2 className="size-3.5" />
                          </Button>
                        </AlertDialogTrigger>
                        <AlertDialogContent className="sm:max-w-md">
                          <AlertDialogHeader>
                            <AlertDialogTitle>¿Borrar esta nota?</AlertDialogTitle>
                            <AlertDialogDescription>Esta acción no se puede deshacer.</AlertDialogDescription>
                          </AlertDialogHeader>
                          <AlertDialogFooter>
                            <AlertDialogCancel>Cancelar</AlertDialogCancel>
                            <AlertDialogAction
                              onClick={() => handleDelete(note.id)}
                              className="bg-destructive hover:bg-destructive/90"
                            >
                              Borrar
                            </AlertDialogAction>
                          </AlertDialogFooter>
                        </AlertDialogContent>
                      </AlertDialog>
                    )}
                  </div>
                  <p className="text-xs text-muted-foreground">
                    {note.authorName || note.authorEmail || "Admin"} · {fmtDateTime(note.createdAt)}
                  </p>
                </div>
              ))}
              <div className="flex items-center justify-between pt-2">
                <p className="text-xs text-muted-foreground">Página {page} de {totalPages}</p>
                <div className="flex items-center gap-2">
                  <Button variant="outline" size="sm" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}>
                    <ChevronLeft className="size-4" />
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={page >= totalPages}>
                    <ChevronRight className="size-4" />
                  </Button>
                </div>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

// ── Tab: Salud (F2, sin cambios) ─────────────────────────────────────────────

function healthLevelBadge(level: string) {
  if (level === "green") return <Badge className="bg-emerald-600 text-white border-0">Verde</Badge>
  if (level === "yellow") return <Badge className="bg-amber-500 text-white border-0">Amarillo</Badge>
  if (level === "red") return <Badge variant="destructive">Rojo</Badge>
  return <Badge variant="secondary">—</Badge>
}

function priorityBadge(priority: AdminHealthChecklistItem["priority"]) {
  if (priority === "high") return <Badge variant="destructive">Alta</Badge>
  if (priority === "medium")
    return <Badge variant="outline" className="text-amber-600 border-amber-600">Media</Badge>
  return <Badge variant="secondary">Baja</Badge>
}

const DIMENSION_LABELS: Record<string, string> = {
  activity: "Actividad de ventas",
  breadth: "Amplitud de uso",
  depth: "Profundidad de config.",
  team: "Equipo",
  ai: "Uso de IA",
  commercial: "Comercial / pagos",
}

function fmtWeek(week: string): string {
  if (!week) return "—"
  const d = new Date(week + "T00:00:00")
  return d.toLocaleDateString("es-PY", { day: "2-digit", month: "2-digit" })
}

function HealthTab({ id }: { id: string }) {
  const { data: health, isLoading } = useAdminHealthDetail(id)
  const recompute = useAdminRecomputeHealth()

  const handleRecompute = () => {
    recompute.mutate(id, {
      onSuccess: () => toast.success("Salud recalculada"),
      onError: (err) => toast.error(err instanceof AdminApiError ? err.message : "Error"),
    })
  }

  if (isLoading) {
    return (
      <div className="space-y-4">
        {[...Array(4)].map((_, i) => <Skeleton key={i} className="h-24 w-full" />)}
      </div>
    )
  }
  if (!health) return <p className="text-muted-foreground">Sin datos de salud todavía</p>

  const dimensions = Object.entries(health.signals).filter(([k]) => k in DIMENSION_LABELS) as [
    keyof typeof DIMENSION_LABELS,
    { subscore: number },
  ][]

  const chartData = health.history.map((h) => ({
    semana: fmtWeek(h.week),
    score: h.score,
  }))

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle className="text-base">Score de salud</CardTitle>
          <Button variant="outline" size="sm" onClick={handleRecompute} disabled={recompute.isPending} className="gap-2">
            {recompute.isPending ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
            Recalcular
          </Button>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center gap-4">
            <span className="text-5xl font-bold tabular-nums">{health.score}</span>
            {healthLevelBadge(health.level)}
            {health.computedAt && (
              <span className="text-xs text-muted-foreground ml-auto">
                Calculado {fmtDate(health.computedAt)}
              </span>
            )}
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            {dimensions.map(([key, sig]) => (
              <div key={key} className="space-y-1">
                <div className="flex items-center justify-between text-xs">
                  <span className="text-muted-foreground">{DIMENSION_LABELS[key]}</span>
                  <span className="tabular-nums font-medium">{sig.subscore}</span>
                </div>
                <Progress value={sig.subscore} />
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {chartData.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Histórico (últimas {chartData.length} semanas)</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="h-56 w-full">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={chartData}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="semana" fontSize={12} />
                  <YAxis domain={[0, 100]} fontSize={12} width={30} />
                  <RechartsTooltip />
                  <Line type="monotone" dataKey="score" stroke="#3b82f6" strokeWidth={2} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Checklist de adopción</CardTitle>
        </CardHeader>
        <CardContent>
          {health.checklist.length === 0 ? (
            <EmptyState
              icon={HeartPulse}
              title="Todo en verde"
              description="No hay acciones de adopción pendientes para este tenant."
              ghost={false}
            />
          ) : (
            <div className="space-y-2">
              {health.checklist.map((item) => (
                <div key={item.key} className="flex items-start justify-between gap-4 rounded-md border px-3 py-2">
                  <div>
                    <p className="text-sm font-medium">{item.title}</p>
                    <p className="text-xs text-muted-foreground">{item.detail}</p>
                  </div>
                  <div className="shrink-0">{priorityBadge(item.priority)}</div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
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
      <div className="flex flex-wrap items-center gap-3">
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
          <div className="ml-1">{statusBadge(company.status, company.blocked, company.suspended)}</div>
        )}
        <div className="ml-auto">
          <HeaderActions id={id} />
        </div>
      </div>

      <Tabs defaultValue="resumen">
        <TabsList>
          <TabsTrigger value="resumen">Resumen</TabsTrigger>
          <TabsTrigger value="health">Salud</TabsTrigger>
          <TabsTrigger value="config">Config</TabsTrigger>
          <TabsTrigger value="billing">Facturación</TabsTrigger>
          <TabsTrigger value="activity">Actividad</TabsTrigger>
          <TabsTrigger value="notes">Notas</TabsTrigger>
        </TabsList>
        <TabsContent value="resumen" className="mt-4">
          <ResumenTab id={id} />
        </TabsContent>
        <TabsContent value="health" className="mt-4">
          <HealthTab id={id} />
        </TabsContent>
        <TabsContent value="config" className="mt-4">
          <ConfigTab id={id} />
        </TabsContent>
        <TabsContent value="billing" className="mt-4">
          <FacturacionTab id={id} />
        </TabsContent>
        <TabsContent value="activity" className="mt-4">
          <ActividadTab id={id} />
        </TabsContent>
        <TabsContent value="notes" className="mt-4">
          <NotasTab id={id} />
        </TabsContent>
      </Tabs>
    </div>
  )
}
