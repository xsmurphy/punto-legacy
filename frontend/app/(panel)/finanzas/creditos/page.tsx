"use client"

import * as React from "react"
import { useForm, Controller } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Loader2, Ban, Eye, Landmark } from "lucide-react"
import { toast } from "sonner"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { DataTable } from "@/components/data-table/data-table"
import { RowActions } from "@/components/data-table/row-actions"
import { EmptyState } from "@/components/empty-state"
import { DatePicker } from "@/components/date-picker"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useFinanceAccounts } from "@/hooks/use-finance-accounts"
import {
  useFinanceLoans,
  useFinanceLoan,
  useCreateFinanceLoan,
  useCancelFinanceLoan,
  usePayLoanInstallment,
  useUnpayLoanInstallment,
  type FinanceLoan,
  type FinanceLoanInstallment,
  type LoanStatus,
} from "@/hooks/use-finance-loans"

const STATUS_LABELS: Record<LoanStatus, string> = {
  active: "Activo",
  settled: "Saldado",
  cancelled: "Anulado",
}

const STATUS_BADGE_VARIANT: Record<LoanStatus, "outline" | "secondary" | "default" | "destructive"> = {
  active: "default",
  settled: "secondary",
  cancelled: "destructive",
}

function todayISO(): string {
  const now = new Date()
  const p = (n: number) => String(n).padStart(2, "0")
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`
}

export default function FinanzasCreditosPage() {
  const { data: bootstrap } = useBootstrap()
  const { data, isLoading } = useFinanceLoans()
  const [createOpen, setCreateOpen] = React.useState(false)
  const [detailId, setDetailId] = React.useState<string | null>(null)
  const [cancelTarget, setCancelTarget] = React.useState<FinanceLoan | null>(null)

  const cancelLoan = useCancelFinanceLoan()

  const rows = data?.rows ?? []

  async function handleCancelConfirm() {
    if (!cancelTarget) return
    try {
      await cancelLoan.mutateAsync(cancelTarget.id)
      toast.success("Crédito anulado")
      setCancelTarget(null)
    } catch (err) {
      toast.error("No se pudo anular el crédito", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  const columns = React.useMemo<ColumnDef<FinanceLoan>[]>(
    () => [
      {
        accessorKey: "name",
        header: "Acreedor / Descripción",
        cell: ({ getValue }) => <span className="font-medium">{getValue() as string}</span>,
        meta: { label: "Acreedor" },
      },
      {
        accessorKey: "principal",
        header: "Total",
        cell: ({ getValue }) => (
          <span className="tabular-nums">{formatMoney(getValue() as number, bootstrap)}</span>
        ),
        meta: { label: "Total", className: "text-right tabular-nums" },
      },
      {
        id: "installments",
        header: "Cuotas",
        cell: ({ row }) => {
          const l = row.original
          return (
            <span className="tabular-nums">
              {l.paidCount ?? 0} / {l.installmentCount}
            </span>
          )
        },
        meta: { label: "Cuotas", className: "text-right tabular-nums" },
      },
      {
        accessorKey: "nextDueDate",
        header: "Próximo vencimiento",
        cell: ({ getValue }) => {
          const v = getValue() as string | null
          return <span className="tabular-nums whitespace-nowrap">{v ? formatDate(v) : "—"}</span>
        },
        meta: { label: "Próximo vencimiento", className: "whitespace-nowrap" },
      },
      {
        accessorKey: "status",
        header: "Estado",
        cell: ({ getValue }) => {
          const v = getValue() as LoanStatus
          return <Badge variant={STATUS_BADGE_VARIANT[v]}>{STATUS_LABELS[v]}</Badge>
        },
        meta: { label: "Estado", className: "w-28" },
      },
      {
        id: "actions",
        header: "",
        cell: ({ row }) => {
          const l = row.original
          return (
            <div onClick={(e) => e.stopPropagation()}>
              <RowActions
                actions={[
                  {
                    label: "Ver cuotas",
                    icon: Eye,
                    onSelect: () => setDetailId(l.id),
                  },
                  {
                    label: "Anular",
                    icon: Ban,
                    variant: "destructive" as const,
                    onSelect: () => setCancelTarget(l),
                    hidden: l.status === "cancelled",
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
          Créditos y préstamos del comercio — total, cuotas iguales, sin interés calculado.
        </p>
        <Button onClick={() => setCreateOpen(true)}>Nuevo crédito</Button>
      </header>

      <DataTable
        tableId="finanzas-creditos"
        data={rows}
        columns={columns}
        getRowId={(r) => r.id}
        isLoading={isLoading}
        onRowClick={(row) => setDetailId(row.id)}
        searchPlaceholder="Buscar por acreedor…"
        exportFileName="creditos"
        emptyMessage={
          <EmptyState
            icon={Landmark}
            title="Sin créditos todavía"
            description={
              <>
                Cargá el primero con el botón <strong>Nuevo crédito</strong> arriba a la derecha.
              </>
            }
          />
        }
      />

      <LoanFormDialog open={createOpen} onOpenChange={setCreateOpen} />

      <LoanDetailDialog loanId={detailId} onOpenChange={(open) => !open && setDetailId(null)} />

      <AlertDialog open={!!cancelTarget} onOpenChange={(o) => !o && setCancelTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Anular crédito</AlertDialogTitle>
            <AlertDialogDescription>
              Las cuotas ya pagadas conservan su movimiento — solo se anula el crédito. Esta acción no
              se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={handleCancelConfirm} disabled={cancelLoan.isPending}>
              {cancelLoan.isPending ? "Anulando…" : "Anular"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

// ── Dialog: nuevo crédito ──────────────────────────────────────────────────────

const loanFormSchema = z.object({
  name: z.string().min(1, "Ingresá el acreedor o una descripción"),
  principal: z.number({ error: "Ingresá un monto" }).positive("El monto debe ser mayor a 0"),
  installmentCount: z
    .number({ error: "Ingresá la cantidad de cuotas" })
    .int("Debe ser un número entero")
    .min(1, "Mínimo 1 cuota")
    .max(360, "Máximo 360 cuotas"),
  firstDueDate: z.string().min(1, "Seleccioná la primera fecha de vencimiento"),
})

type LoanFormValues = z.infer<typeof loanFormSchema>

function LoanFormDialog({
  open,
  onOpenChange,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const createLoan = useCreateFinanceLoan()

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<LoanFormValues>({
    resolver: zodResolver(loanFormSchema),
    defaultValues: {
      name: "",
      principal: 0,
      installmentCount: 1,
      firstDueDate: todayISO(),
    },
  })

  React.useEffect(() => {
    if (open) {
      reset({ name: "", principal: 0, installmentCount: 1, firstDueDate: todayISO() })
    }
  }, [open, reset])

  async function onSubmit(values: LoanFormValues) {
    try {
      await createLoan.mutateAsync(values)
      toast.success("Crédito cargado")
      onOpenChange(false)
    } catch (err) {
      toast.error("No se pudo cargar el crédito", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Nuevo crédito</DialogTitle>
          <DialogDescription>
            Genera las cuotas automáticamente — iguales, mensuales, sin interés calculado.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="loan-name">Acreedor / Descripción</Label>
            <Input id="loan-name" placeholder="Ej. Banco Continental — préstamo equipamiento" {...register("name")} />
            {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="loan-principal">Monto total</Label>
              <Controller
                name="principal"
                control={control}
                render={({ field }) => (
                  <MoneyInput id="loan-principal" value={field.value} onChange={(v) => field.onChange(v ?? 0)} />
                )}
              />
              {errors.principal && <p className="text-xs text-destructive">{errors.principal.message}</p>}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="loan-installments">Cantidad de cuotas</Label>
              <Controller
                name="installmentCount"
                control={control}
                render={({ field }) => (
                  <Input
                    id="loan-installments"
                    type="number"
                    min={1}
                    max={360}
                    step={1}
                    value={field.value}
                    onChange={(e) => field.onChange(Number(e.target.value) || 0)}
                  />
                )}
              />
              {errors.installmentCount && (
                <p className="text-xs text-destructive">{errors.installmentCount.message}</p>
              )}
            </div>

            <div className="space-y-1.5 sm:col-span-2">
              <Label htmlFor="loan-first-due">Primera fecha de vencimiento</Label>
              <Controller
                name="firstDueDate"
                control={control}
                render={({ field }) => (
                  <DatePicker id="loan-first-due" value={field.value} onChange={field.onChange} />
                )}
              />
              {errors.firstDueDate && (
                <p className="text-xs text-destructive">{errors.firstDueDate.message}</p>
              )}
              <p className="text-xs text-muted-foreground">
                Las cuotas siguientes se generan mensualmente a partir de esta fecha.
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              Cancelar
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="mr-2 size-4 animate-spin" />}
              Cargar crédito
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

// ── Dialog: detalle + cuotas ────────────────────────────────────────────────────

const INSTALLMENT_STATUS_LABELS: Record<"pending" | "paid", string> = {
  pending: "Pendiente",
  paid: "Pagada",
}

function LoanDetailDialog({
  loanId,
  onOpenChange,
}: {
  loanId: string | null
  onOpenChange: (open: boolean) => void
}) {
  const { data: bootstrap } = useBootstrap()
  const { data: loan, isLoading } = useFinanceLoan(loanId)
  const [payTarget, setPayTarget] = React.useState<FinanceLoanInstallment | null>(null)
  const unpay = useUnpayLoanInstallment()

  async function handleUnpay(installment: FinanceLoanInstallment) {
    try {
      await unpay.mutateAsync(installment.id)
      toast.success(`Cuota ${installment.seq} revertida a pendiente`)
    } catch (err) {
      toast.error("No se pudo revertir la cuota", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <>
      <Dialog open={!!loanId} onOpenChange={(o) => !o && onOpenChange(false)}>
        <DialogContent className="sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>{loan?.name ?? "Detalle del crédito"}</DialogTitle>
            <DialogDescription>
              {loan
                ? `${formatMoney(loan.principal, bootstrap)} en ${loan.installmentCount} cuota(s) mensuales.`
                : "Cargando…"}
            </DialogDescription>
          </DialogHeader>

          {isLoading ? (
            <div className="flex h-32 items-center justify-center text-muted-foreground">
              <Loader2 className="mr-2 size-5 animate-spin" />
              Cargando cuotas…
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-12">#</TableHead>
                  <TableHead>Vencimiento</TableHead>
                  <TableHead className="text-right">Monto</TableHead>
                  <TableHead>Estado</TableHead>
                  <TableHead className="w-32" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {(loan?.installments ?? []).map((i) => (
                  <TableRow key={i.id}>
                    <TableCell className="tabular-nums">{i.seq}</TableCell>
                    <TableCell className="tabular-nums whitespace-nowrap">{formatDate(i.dueDate)}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatMoney(i.amount, bootstrap)}
                    </TableCell>
                    <TableCell>
                      <Badge variant={i.status === "paid" ? "secondary" : "outline"}>
                        {INSTALLMENT_STATUS_LABELS[i.status]}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      {i.status === "pending" ? (
                        <Button size="sm" variant="outline" onClick={() => setPayTarget(i)}>
                          Marcar pagada
                        </Button>
                      ) : (
                        <Button
                          size="sm"
                          variant="ghost"
                          className="text-muted-foreground"
                          onClick={() => handleUnpay(i)}
                          disabled={unpay.isPending}
                        >
                          Revertir
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </DialogContent>
      </Dialog>

      {/* Sub-dialog fuera del principal (evita nesting de <Dialog>) */}
      <PayInstallmentDialog installment={payTarget} onClose={() => setPayTarget(null)} />
    </>
  )
}

function PayInstallmentDialog({
  installment,
  onClose,
}: {
  installment: FinanceLoanInstallment | null
  onClose: () => void
}) {
  const { data: bootstrap } = useBootstrap()
  const { data: accounts } = useFinanceAccounts()
  const payInstallment = usePayLoanInstallment()
  const [accountId, setAccountId] = React.useState("")

  React.useEffect(() => {
    if (installment) {
      setAccountId("")
    }
  }, [installment])

  const activeAccounts = (accounts ?? []).filter((a) => a.status === 1)

  async function handleConfirm() {
    if (!installment || !accountId) return
    try {
      await payInstallment.mutateAsync({ installmentId: installment.id, accountId })
      toast.success(`Cuota ${installment.seq} marcada como pagada`)
      onClose()
    } catch (err) {
      toast.error("No se pudo registrar el pago", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={!!installment} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Marcar cuota como pagada</DialogTitle>
          <DialogDescription>
            {installment
              ? `Cuota ${installment.seq} — ${formatMoney(installment.amount, bootstrap)}. Elegí la cuenta desde la que se paga.`
              : ""}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-1.5">
          <Label htmlFor="pay-installment-account">Cuenta</Label>
          <Select value={accountId} onValueChange={setAccountId}>
            <SelectTrigger id="pay-installment-account">
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
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={payInstallment.isPending}>
            Cancelar
          </Button>
          <Button type="button" onClick={handleConfirm} disabled={!accountId || payInstallment.isPending}>
            {payInstallment.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
            Confirmar pago
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
