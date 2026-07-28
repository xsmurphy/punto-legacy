"use client"

import * as React from "react"
import { useForm, Controller } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Loader2, ArrowRightCircle, Ban, Plus, Landmark } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { MoneyInput } from "@/components/ui/money-input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
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
import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { EmptyState } from "@/components/empty-state"
import { DatePicker } from "@/components/date-picker"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useFinanceAccounts } from "@/hooks/use-finance-accounts"
import { useFinanceCategories } from "@/hooks/use-finance-categories"
import {
  useFinanceChecks,
  useCreateFinanceCheck,
  useChangeFinanceCheckStatus,
  useDeleteFinanceCheck,
  type FinanceCheck,
  type CheckDirection,
  type CheckStatus,
} from "@/hooks/use-finance-checks"

const DIRECTION_LABELS: Record<CheckDirection, string> = {
  issued: "Emitido",
  received: "Recibido",
}

const STATUS_LABELS: Record<CheckStatus, string> = {
  pending: "Pendiente",
  deposited: "Depositado",
  cleared: "Cobrado/Debitado",
  bounced: "Rechazado",
  cancelled: "Anulado",
}

const STATUS_BADGE_VARIANT: Record<CheckStatus, "outline" | "secondary" | "default" | "destructive"> = {
  pending: "outline",
  deposited: "secondary",
  cleared: "default",
  bounced: "destructive",
  cancelled: "destructive",
}

// Transiciones de estado permitidas desde cada estado actual (la más usual primero).
const NEXT_STATUSES: Record<CheckStatus, CheckStatus[]> = {
  pending: ["deposited", "cleared", "bounced", "cancelled"],
  deposited: ["cleared", "bounced", "cancelled"],
  cleared: ["pending", "bounced"],
  bounced: ["pending", "cancelled"],
  cancelled: [],
}

function todayISO(): string {
  const now = new Date()
  const p = (n: number) => String(n).padStart(2, "0")
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`
}

export default function FinanzasChequesPage() {
  const { data: bootstrap } = useBootstrap()
  const [directionFilter, setDirectionFilter] = React.useState<CheckDirection | "all">("all")
  const [statusFilter, setStatusFilter] = React.useState<CheckStatus | "all">("all")
  const { data, isLoading } = useFinanceChecks({
    direction: directionFilter === "all" ? undefined : directionFilter,
    status: statusFilter === "all" ? undefined : statusFilter,
  })
  const [createOpen, setCreateOpen] = React.useState(false)
  const [cancelTarget, setCancelTarget] = React.useState<FinanceCheck | null>(null)

  const changeStatus = useChangeFinanceCheckStatus()
  const deleteCheck = useDeleteFinanceCheck()

  const rows = data?.rows ?? []

  async function handleStatusChange(check: FinanceCheck, status: CheckStatus) {
    try {
      await changeStatus.mutateAsync({ id: check.id, status })
      toast.success(`Cheque marcado como "${STATUS_LABELS[status]}"`)
    } catch (err) {
      toast.error("No se pudo cambiar el estado del cheque", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  async function handleCancelConfirm() {
    if (!cancelTarget) return
    try {
      await deleteCheck.mutateAsync(cancelTarget.id)
      toast.success("Cheque anulado")
      setCancelTarget(null)
    } catch (err) {
      toast.error("No se pudo anular el cheque", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  const columns = React.useMemo<ColumnDef<FinanceCheck>[]>(
    () => [
      {
        accessorKey: "direction",
        header: "Tipo",
        cell: ({ getValue }) => {
          const v = getValue() as CheckDirection
          return <Badge variant="outline">{DIRECTION_LABELS[v]}</Badge>
        },
        meta: { label: "Tipo", className: "w-24" },
      },
      {
        accessorKey: "dueDate",
        header: "Vencimiento",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return <span className="tabular-nums whitespace-nowrap">{v ? formatDate(v) : "—"}</span>
        },
        meta: { label: "Vencimiento", className: "whitespace-nowrap" },
      },
      {
        accessorKey: "partyName",
        header: "Tercero",
        cell: ({ getValue }) => (getValue() as string | null) ?? "—",
        meta: { label: "Tercero" },
      },
      {
        accessorKey: "bankName",
        header: "Banco",
        cell: ({ row }) => {
          const c = row.original
          return [c.bankName, c.checkNumber].filter(Boolean).join(" · ") || "—"
        },
        meta: { label: "Banco" },
      },
      {
        accessorKey: "categoryName",
        header: "Categoría",
        cell: ({ getValue }) => (getValue() as string | null) ?? "—",
        meta: { label: "Categoría" },
      },
      {
        accessorKey: "amount",
        header: "Monto",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatMoney(getValue() as number, bootstrap)}</span>
        ),
        meta: { label: "Monto", className: "text-right tabular-nums" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ getValue }) => {
          const v = getValue() as CheckStatus
          return <Badge variant={STATUS_BADGE_VARIANT[v]}>{STATUS_LABELS[v]}</Badge>
        },
        meta: { label: "Estado", className: "w-32" },
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => {
          const c = row.original
          const nextOptions = NEXT_STATUSES[c.status]
          return (
            <div onClick={(e) => e.stopPropagation()}>
              <RowActions
                actions={[
                  ...nextOptions.map((s) => ({
                    label: `Marcar como ${STATUS_LABELS[s]}`,
                    icon: ArrowRightCircle,
                    onSelect: () => handleStatusChange(c, s),
                  })),
                  {
                    label: "Anular",
                    icon: Ban,
                    variant: "destructive" as const,
                    onSelect: () => setCancelTarget(c),
                    hidden: c.status === "cancelled",
                  },
                ]}
              />
            </div>
          )
        },
        meta: { className: "w-12" },
      },
    ],
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <p className="text-sm text-muted-foreground">
          Cheques emitidos a proveedores y recibidos de clientes.
        </p>
        <div className="flex flex-wrap gap-2">
          <Select value={directionFilter} onValueChange={(v) => setDirectionFilter(v as CheckDirection | "all")}>
            <SelectTrigger className="w-40">
              <SelectValue placeholder="Tipo" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Todos los tipos</SelectItem>
              <SelectItem value="issued">Emitidos</SelectItem>
              <SelectItem value="received">Recibidos</SelectItem>
            </SelectContent>
          </Select>
          <Select value={statusFilter} onValueChange={(v) => setStatusFilter(v as CheckStatus | "all")}>
            <SelectTrigger className="w-44">
              <SelectValue placeholder="Estado" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">Todos los estados</SelectItem>
              {(Object.keys(STATUS_LABELS) as CheckStatus[]).map((s) => (
                <SelectItem key={s} value={s}>
                  {STATUS_LABELS[s]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Button onClick={() => setCreateOpen(true)}>
            <Plus className="size-4" />
            Nuevo cheque
          </Button>
        </div>
      </header>

      <DataTable
        tableId="finanzas-cheques"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por tercero, banco…"
        exportFileName="cheques"
        emptyMessage={
          <EmptyState
            icon={Landmark}
            title="Sin cheques todavía"
            description={
              <>
                Cargá el primero con el botón <strong>Nuevo cheque</strong> arriba a la derecha.
              </>
            }
          />
        }
      />

      <CheckFormDialog open={createOpen} onOpenChange={setCreateOpen} />

      <AlertDialog open={!!cancelTarget} onOpenChange={(o) => !o && setCancelTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Anular cheque</AlertDialogTitle>
            <AlertDialogDescription>
              Si el cheque ya generó un movimiento, se revertirá el saldo de la cuenta. Esta acción no
              se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={handleCancelConfirm} disabled={deleteCheck.isPending}>
              {deleteCheck.isPending ? "Anulando…" : "Anular"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

// ── Dialog: nuevo cheque ──────────────────────────────────────────────────────

const checkFormSchema = z.object({
  direction: z.enum(["issued", "received"]),
  accountId: z.string().optional(),
  categoryId: z.string().optional(),
  bankName: z.string().optional(),
  checkNumber: z.string().optional(),
  amount: z.number({ error: "Ingresá un monto" }).positive("El monto debe ser mayor a 0"),
  issueDate: z.string().min(1, "Seleccioná una fecha"),
  dueDate: z.string().optional(),
  partyName: z.string().optional(),
  description: z.string().optional(),
})

type CheckFormValues = z.infer<typeof checkFormSchema>

function CheckFormDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { data: accounts } = useFinanceAccounts()
  const { data: categories } = useFinanceCategories()
  const createCheck = useCreateFinanceCheck()

  const {
    register,
    handleSubmit,
    control,
    reset,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<CheckFormValues>({
    resolver: zodResolver(checkFormSchema),
    defaultValues: {
      direction: "received",
      accountId: "",
      categoryId: "",
      bankName: "",
      checkNumber: "",
      amount: 0,
      issueDate: todayISO(),
      dueDate: "",
      partyName: "",
      description: "",
    },
  })

  const direction = watch("direction")
  const kind = direction === "received" ? "income" : "expense"
  const filteredCategories = (categories ?? []).filter((c) => c.kind === kind && c.status === 1)
  const bankAccounts = (accounts ?? []).filter((a) => a.status === 1 && a.type !== "cash")

  React.useEffect(() => {
    if (open) {
      reset({
        direction: "received",
        accountId: "",
        categoryId: "",
        bankName: "",
        checkNumber: "",
        amount: 0,
        issueDate: todayISO(),
        dueDate: "",
        partyName: "",
        description: "",
      })
    }
  }, [open, reset])

  async function onSubmit(values: CheckFormValues) {
    try {
      await createCheck.mutateAsync({
        direction: values.direction,
        accountId: values.accountId || null,
        categoryId: values.categoryId || null,
        bankName: values.bankName?.trim() || undefined,
        checkNumber: values.checkNumber?.trim() || undefined,
        amount: values.amount,
        issueDate: values.issueDate,
        dueDate: values.dueDate || undefined,
        partyName: values.partyName?.trim() || undefined,
        description: values.description?.trim() || undefined,
      })
      toast.success("Cheque cargado")
      onOpenChange(false)
    } catch (err) {
      toast.error("No se pudo cargar el cheque", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Nuevo cheque</DialogTitle>
          <DialogDescription>
            Cargá un cheque emitido a un proveedor o recibido de un cliente.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <div className="space-y-1.5">
            <Label>Tipo</Label>
            <Controller
              name="direction"
              control={control}
              render={({ field }) => (
                <Select value={field.value} onValueChange={(v) => field.onChange(v as CheckDirection)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="received">Recibido (de un cliente)</SelectItem>
                    <SelectItem value="issued">Emitido (a un proveedor)</SelectItem>
                  </SelectContent>
                </Select>
              )}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="check-party">
                {direction === "received" ? "Cliente" : "Proveedor"}
              </Label>
              <Input id="check-party" placeholder="Nombre" {...register("partyName")} />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="check-amount">Monto</Label>
              <Controller
                name="amount"
                control={control}
                render={({ field }) => (
                  <MoneyInput id="check-amount" value={field.value} onChange={(v) => field.onChange(v ?? 0)} />
                )}
              />
              {errors.amount && <p className="text-xs text-destructive">{errors.amount.message}</p>}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="check-bank">Banco</Label>
              <Input id="check-bank" {...register("bankName")} />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="check-number">Número de cheque</Label>
              <Input id="check-number" {...register("checkNumber")} />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="check-issue-date">Fecha de emisión</Label>
              <Controller
                name="issueDate"
                control={control}
                render={({ field }) => (
                  <DatePicker id="check-issue-date" value={field.value} onChange={field.onChange} />
                )}
              />
              {errors.issueDate && <p className="text-xs text-destructive">{errors.issueDate.message}</p>}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="check-due-date">Fecha de vencimiento</Label>
              <Controller
                name="dueDate"
                control={control}
                render={({ field }) => (
                  <DatePicker id="check-due-date" value={field.value ?? ""} onChange={field.onChange} />
                )}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="check-account">Cuenta bancaria (al efectivizarse)</Label>
              <Controller
                name="accountId"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="check-account">
                      <SelectValue placeholder="Sin asignar todavía" />
                    </SelectTrigger>
                    <SelectContent>
                      {bankAccounts.map((a) => (
                        <SelectItem key={a.id} value={a.id}>
                          {a.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="check-category">Categoría</Label>
              <Controller
                name="categoryId"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="check-category">
                      <SelectValue placeholder="Seleccionar categoría" />
                    </SelectTrigger>
                    <SelectContent>
                      {filteredCategories.map((c) => (
                        <SelectItem key={c.id} value={c.id}>
                          {c.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            </div>

            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="check-description">Descripción</Label>
              <Textarea id="check-description" rows={2} {...register("description")} />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              Cancelar
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="mr-2 size-4 animate-spin" />}
              Cargar cheque
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
