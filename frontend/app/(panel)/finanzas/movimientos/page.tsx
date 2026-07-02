"use client"

import * as React from "react"
import { useForm, Controller } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { ArrowLeftRight, Loader2, MoreVertical, Plus, Receipt } from "lucide-react"
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
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useFinanceAccounts } from "@/hooks/use-finance-accounts"
import { useFinanceCategories } from "@/hooks/use-finance-categories"
import {
  useFinanceMovements,
  useCreateFinanceMovement,
  useCreateFinanceTransfer,
  useVoidFinanceMovement,
  type FinanceMovement,
} from "@/hooks/use-finance-movements"

export default function FinanzasMovimientosPage() {
  const { data: bootstrap } = useBootstrap()
  const { data, isLoading } = useFinanceMovements()
  const [movementDialogOpen, setMovementDialogOpen] = React.useState(false)
  const [transferDialogOpen, setTransferDialogOpen] = React.useState(false)
  const [voidTarget, setVoidTarget] = React.useState<FinanceMovement | null>(null)

  const voidMovement = useVoidFinanceMovement()

  const rows = data?.rows ?? []

  async function handleVoidConfirm() {
    if (!voidTarget) return
    try {
      await voidMovement.mutateAsync(voidTarget.id)
      toast.success("Movimiento anulado")
      setVoidTarget(null)
    } catch (err) {
      toast.error("No se pudo anular el movimiento", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  const columns = React.useMemo<ColumnDef<FinanceMovement>[]>(
    () => [
      {
        accessorKey: "date",
        header: "Fecha",
        cell: ({ getValue }) => (
          <span className="tabular-nums whitespace-nowrap">{formatDate(getValue() as string)}</span>
        ),
        meta: { label: "Fecha", className: "whitespace-nowrap" },
      },
      {
        accessorKey: "accountName",
        header: "Cuenta",
        cell: ({ getValue }) => (getValue() as string | null) ?? "—",
        meta: { label: "Cuenta" },
      },
      {
        accessorKey: "categoryName",
        header: "Categoría",
        cell: ({ getValue }) => (getValue() as string | null) ?? "—",
        meta: { label: "Categoría" },
      },
      {
        accessorKey: "description",
        header: "Descripción",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? <span>{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Descripción" },
      },
      {
        accessorKey: "paymentMethod",
        header: "Medio de pago",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return v ? <span className="text-muted-foreground">{v}</span> : <span className="opacity-40">—</span>
        },
        meta: { label: "Medio de pago" },
      },
      {
        accessorKey: "amount",
        header: "Monto",
        cell: ({ row }) => {
          const m = row.original
          return (
            <span className="tabular-nums">
              {m.kind === "expense" ? "-" : ""}
              {formatMoney(m.amount, bootstrap)}
            </span>
          )
        },
        meta: { label: "Monto", className: "text-right tabular-nums" },
      },
      {
        accessorKey: "source",
        header: "Origen",
        cell: ({ getValue }) => {
          const source = getValue() as string
          const variant = source === "manual" || source === "transfer" ? "outline" : "secondary"
          return <Badge variant={variant}>{source}</Badge>
        },
        meta: { label: "Origen", className: "w-28" },
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => {
          const m = row.original
          const isVoided = m.status !== 1
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  onClick={(e) => e.stopPropagation()}
                  aria-label="Acciones"
                >
                  <MoreVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" onClick={(e) => e.stopPropagation()}>
                <DropdownMenuItem
                  disabled={isVoided}
                  onSelect={() => setVoidTarget(m)}
                >
                  Anular
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          )
        },
        meta: { className: "w-12" },
      },
    ],
    [bootstrap],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <p className="text-sm text-muted-foreground">
          Entradas y salidas de dinero de todas las cuentas.
        </p>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={() => setTransferDialogOpen(true)}>
            <ArrowLeftRight className="size-4" />
            Transferencia entre cuentas
          </Button>
          <Button onClick={() => setMovementDialogOpen(true)}>
            <Plus className="size-4" />
            Registrar movimiento
          </Button>
        </div>
      </header>

      <DataTable
        tableId="finanzas-movimientos"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        searchPlaceholder="Buscar por descripción, cuenta, categoría…"
        exportFileName="movimientos"
        emptyMessage={
          <EmptyState
            icon={Receipt}
            title="Sin movimientos todavía"
            description={
              <>
                Registrá el primero con el botón{" "}
                <strong>Registrar movimiento</strong> arriba a la derecha.
              </>
            }
          />
        }
      />

      <MovementDialog open={movementDialogOpen} onOpenChange={setMovementDialogOpen} />
      <TransferDialog open={transferDialogOpen} onOpenChange={setTransferDialogOpen} />

      <AlertDialog open={!!voidTarget} onOpenChange={(o) => !o && setVoidTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Anular movimiento</AlertDialogTitle>
            <AlertDialogDescription>
              Se revertirá el saldo de la cuenta afectada. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={handleVoidConfirm} disabled={voidMovement.isPending}>
              {voidMovement.isPending ? "Anulando…" : "Anular"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

// ── Dialog: registrar movimiento manual ──────────────────────────────────────

const movementFormSchema = z.object({
  accountId: z.string().min(1, "Seleccioná una cuenta"),
  categoryId: z.string().min(1, "Seleccioná una categoría"),
  kind: z.enum(["income", "expense"]),
  amount: z
    .number({ error: "Ingresá un monto" })
    .positive("El monto debe ser mayor a 0"),
  date: z.string().min(1, "Seleccioná una fecha"),
  description: z.string().optional(),
  paymentMethod: z.string().optional(),
})

type MovementFormValues = z.infer<typeof movementFormSchema>

function todayISO(): string {
  const now = new Date()
  const p = (n: number) => String(n).padStart(2, "0")
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`
}

function MovementDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { data: accounts } = useFinanceAccounts()
  const { data: categories } = useFinanceCategories()
  const createMovement = useCreateFinanceMovement()

  const {
    register,
    handleSubmit,
    control,
    reset,
    watch,
    formState: { errors, isSubmitting },
  } = useForm<MovementFormValues>({
    resolver: zodResolver(movementFormSchema),
    defaultValues: {
      accountId: "",
      categoryId: "",
      kind: "expense",
      amount: 0,
      date: todayISO(),
      description: "",
      paymentMethod: "",
    },
  })

  const kind = watch("kind")
  const filteredCategories = (categories ?? []).filter((c) => c.kind === kind && c.status === 1)
  const activeAccounts = (accounts ?? []).filter((a) => a.status === 1)

  React.useEffect(() => {
    if (open) {
      reset({
        accountId: "",
        categoryId: "",
        kind: "expense",
        amount: 0,
        date: todayISO(),
        description: "",
        paymentMethod: "",
      })
    }
  }, [open, reset])

  async function onSubmit(values: MovementFormValues) {
    try {
      await createMovement.mutateAsync({
        accountId: values.accountId,
        categoryId: values.categoryId,
        kind: values.kind,
        amount: values.amount,
        date: values.date,
        description: values.description?.trim() || undefined,
        paymentMethod: values.paymentMethod?.trim() || undefined,
      })
      toast.success("Movimiento registrado")
      onOpenChange(false)
    } catch (err) {
      toast.error("No se pudo registrar el movimiento", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Registrar movimiento</DialogTitle>
          <DialogDescription>
            Cargá una entrada o salida de dinero manual en una cuenta.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <div className="space-y-1.5">
            <Label>Tipo de movimiento</Label>
            <Controller
              name="kind"
              control={control}
              render={({ field }) => (
                <Select
                  value={field.value}
                  onValueChange={(v) => field.onChange(v as "income" | "expense")}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="income">Ingreso</SelectItem>
                    <SelectItem value="expense">Egreso</SelectItem>
                  </SelectContent>
                </Select>
              )}
            />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="movement-account">Cuenta</Label>
              <Controller
                name="accountId"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="movement-account" aria-invalid={!!errors.accountId}>
                      <SelectValue placeholder="Seleccionar cuenta" />
                    </SelectTrigger>
                    <SelectContent>
                      {activeAccounts.map((a) => (
                        <SelectItem key={a.id} value={a.id}>
                          {a.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              {errors.accountId && (
                <p className="text-xs text-destructive">{errors.accountId.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="movement-category">Categoría</Label>
              <Controller
                name="categoryId"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="movement-category" aria-invalid={!!errors.categoryId}>
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
              {errors.categoryId && (
                <p className="text-xs text-destructive">{errors.categoryId.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="movement-amount">Monto</Label>
              <Controller
                name="amount"
                control={control}
                render={({ field }) => (
                  <MoneyInput
                    id="movement-amount"
                    value={field.value}
                    onChange={(v) => field.onChange(v ?? 0)}
                  />
                )}
              />
              {errors.amount && (
                <p className="text-xs text-destructive">{errors.amount.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="movement-date">Fecha</Label>
              {/* input nativo date — razón: fecha simple, Calendar+Popover es para date RANGE según context/14 */}
              <Input id="movement-date" type="date" {...register("date")} aria-invalid={!!errors.date} />
              {errors.date && <p className="text-xs text-destructive">{errors.date.message}</p>}
            </div>

            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="movement-payment-method">Medio de pago</Label>
              <Input
                id="movement-payment-method"
                placeholder="Efectivo, transferencia, tarjeta…"
                {...register("paymentMethod")}
              />
            </div>

            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="movement-description">Descripción</Label>
              <Textarea id="movement-description" rows={2} {...register("description")} />
            </div>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
            >
              Cancelar
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="mr-2 size-4 animate-spin" />}
              Registrar
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

// ── Dialog: transferencia entre cuentas ──────────────────────────────────────

const transferFormSchema = z
  .object({
    fromAccountId: z.string().min(1, "Seleccioná la cuenta de origen"),
    toAccountId: z.string().min(1, "Seleccioná la cuenta de destino"),
    amount: z
      .number({ error: "Ingresá un monto" })
      .positive("El monto debe ser mayor a 0"),
    date: z.string().min(1, "Seleccioná una fecha"),
    description: z.string().optional(),
  })
  .refine((v) => v.fromAccountId !== v.toAccountId, {
    message: "La cuenta de origen y destino deben ser distintas",
    path: ["toAccountId"],
  })

type TransferFormValues = z.infer<typeof transferFormSchema>

function TransferDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { data: accounts } = useFinanceAccounts()
  const createTransfer = useCreateFinanceTransfer()
  const activeAccounts = (accounts ?? []).filter((a) => a.status === 1)

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<TransferFormValues>({
    resolver: zodResolver(transferFormSchema),
    defaultValues: {
      fromAccountId: "",
      toAccountId: "",
      amount: 0,
      date: todayISO(),
      description: "",
    },
  })

  React.useEffect(() => {
    if (open) {
      reset({
        fromAccountId: "",
        toAccountId: "",
        amount: 0,
        date: todayISO(),
        description: "",
      })
    }
  }, [open, reset])

  async function onSubmit(values: TransferFormValues) {
    try {
      await createTransfer.mutateAsync({
        fromAccountId: values.fromAccountId,
        toAccountId: values.toAccountId,
        amount: values.amount,
        date: values.date,
        description: values.description?.trim() || undefined,
      })
      toast.success("Transferencia registrada")
      onOpenChange(false)
    } catch (err) {
      toast.error("No se pudo registrar la transferencia", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Transferencia entre cuentas</DialogTitle>
          <DialogDescription>
            Movés dinero de una cuenta propia a otra. Genera dos movimientos vinculados.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="transfer-from">Cuenta origen</Label>
              <Controller
                name="fromAccountId"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="transfer-from" aria-invalid={!!errors.fromAccountId}>
                      <SelectValue placeholder="Seleccionar cuenta" />
                    </SelectTrigger>
                    <SelectContent>
                      {activeAccounts.map((a) => (
                        <SelectItem key={a.id} value={a.id}>
                          {a.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              {errors.fromAccountId && (
                <p className="text-xs text-destructive">{errors.fromAccountId.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="transfer-to">Cuenta destino</Label>
              <Controller
                name="toAccountId"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="transfer-to" aria-invalid={!!errors.toAccountId}>
                      <SelectValue placeholder="Seleccionar cuenta" />
                    </SelectTrigger>
                    <SelectContent>
                      {activeAccounts.map((a) => (
                        <SelectItem key={a.id} value={a.id}>
                          {a.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              {errors.toAccountId && (
                <p className="text-xs text-destructive">{errors.toAccountId.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="transfer-amount">Monto</Label>
              <Controller
                name="amount"
                control={control}
                render={({ field }) => (
                  <MoneyInput
                    id="transfer-amount"
                    value={field.value}
                    onChange={(v) => field.onChange(v ?? 0)}
                  />
                )}
              />
              {errors.amount && (
                <p className="text-xs text-destructive">{errors.amount.message}</p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="transfer-date">Fecha</Label>
              {/* input nativo date — razón: fecha simple, Calendar+Popover es para date RANGE según context/14 */}
              <Input id="transfer-date" type="date" {...register("date")} aria-invalid={!!errors.date} />
              {errors.date && <p className="text-xs text-destructive">{errors.date.message}</p>}
            </div>

            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="transfer-description">Descripción</Label>
              <Textarea id="transfer-description" rows={2} {...register("description")} />
            </div>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
            >
              Cancelar
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="mr-2 size-4 animate-spin" />}
              Transferir
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
