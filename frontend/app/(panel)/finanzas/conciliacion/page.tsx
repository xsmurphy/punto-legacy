"use client"

import * as React from "react"
import { useForm, Controller } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { ArrowLeft, CheckCircle2, Loader2, Plus, Scale } from "lucide-react"
import { toast } from "sonner"
import { cn } from "@/lib/utils"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Checkbox } from "@/components/ui/checkbox"
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
import { EmptyState } from "@/components/empty-state"
import { DatePicker } from "@/components/date-picker"
import { Skeleton } from "@/components/ui/skeleton"
import { formatMoney } from "@/lib/format"
import { formatDate } from "@/lib/format-date"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useFinanceAccounts } from "@/hooks/use-finance-accounts"
import {
  useFinanceReconciliations,
  useFinanceReconciliationDetail,
  useCreateFinanceReconciliation,
  useToggleReconciliationMovement,
  useCloseFinanceReconciliation,
  useCancelFinanceReconciliation,
  type FinanceReconciliation,
  type ReconciliationMovement,
} from "@/hooks/use-finance-reconciliation"

function todayISO(): string {
  const now = new Date()
  const p = (n: number) => String(n).padStart(2, "0")
  return `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`
}

export default function FinanzasConciliacionPage() {
  const [selectedId, setSelectedId] = React.useState<string | null>(null)

  if (selectedId) {
    return <ReconciliationDetailView id={selectedId} onBack={() => setSelectedId(null)} />
  }
  return <ReconciliationListView onOpen={setSelectedId} />
}

// ── Vista: lista de sesiones ──────────────────────────────────────────────────

function ReconciliationListView({ onOpen }: { onOpen: (id: string) => void }) {
  const { data: bootstrap } = useBootstrap()
  const { data, isLoading } = useFinanceReconciliations()
  const [createOpen, setCreateOpen] = React.useState(false)

  const rows = data?.rows ?? []

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <p className="text-sm text-muted-foreground">
          Comparación del saldo del extracto bancario contra los movimientos del sistema.
        </p>
        <Button onClick={() => setCreateOpen(true)}>
          <Plus className="size-4" />
          Nueva conciliación
        </Button>
      </header>

      {isLoading ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-32 w-full" />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          icon={Scale}
          title="Sin conciliaciones todavía"
          description="Creá una con el botón Nueva conciliación arriba, eligiendo la cuenta y el saldo del extracto."
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((session) => (
            <Card
              key={session.id}
              className="cursor-pointer transition-colors hover:border-primary/40"
              onClick={() => onOpen(session.id)}
            >
              <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0">
                <CardTitle className="text-base font-semibold tracking-tight">
                  {session.accountName ?? "Cuenta"}
                </CardTitle>
                <Badge variant={session.status === "open" ? "outline" : "secondary"}>
                  {session.status === "open" ? "Abierta" : "Cerrada"}
                </Badge>
              </CardHeader>
              <CardContent className="flex flex-col gap-1">
                <p className="text-sm text-muted-foreground">
                  Extracto al {formatDate(session.statementDate)}
                </p>
                <p className="text-xl font-semibold tabular-nums">
                  {formatMoney(session.statementBalance, bootstrap)}
                </p>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <CreateReconciliationDialog open={createOpen} onOpenChange={setCreateOpen} onCreated={onOpen} />
    </div>
  )
}

const createFormSchema = z.object({
  accountId: z.string().min(1, "Seleccioná una cuenta"),
  statementDate: z.string().min(1, "Seleccioná una fecha"),
  statementBalance: z.number({ error: "Ingresá el saldo del extracto" }),
})

type CreateFormValues = z.infer<typeof createFormSchema>

function CreateReconciliationDialog({
  open,
  onOpenChange,
  onCreated,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreated: (id: string) => void
}) {
  const { data: accounts } = useFinanceAccounts()
  const createSession = useCreateFinanceReconciliation()
  const bankAccounts = (accounts ?? []).filter((a) => a.status === 1 && a.type !== "cash")

  const {
    handleSubmit,
    control,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<CreateFormValues>({
    resolver: zodResolver(createFormSchema),
    defaultValues: { accountId: "", statementDate: todayISO(), statementBalance: 0 },
  })

  React.useEffect(() => {
    if (open) reset({ accountId: "", statementDate: todayISO(), statementBalance: 0 })
  }, [open, reset])

  async function onSubmit(values: CreateFormValues) {
    try {
      const session = await createSession.mutateAsync(values)
      toast.success("Conciliación creada")
      onOpenChange(false)
      onCreated(session.id)
    } catch (err) {
      toast.error("No se pudo crear la conciliación", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Nueva conciliación</DialogTitle>
          <DialogDescription>
            Elegí la cuenta bancaria y cargá el saldo que muestra el extracto del banco.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="recon-account">Cuenta</Label>
            <Controller
              name="accountId"
              control={control}
              render={({ field }) => (
                <Select value={field.value} onValueChange={field.onChange}>
                  <SelectTrigger id="recon-account" aria-invalid={!!errors.accountId}>
                    <SelectValue placeholder="Seleccionar cuenta" />
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
            {errors.accountId && <p className="text-xs text-destructive">{errors.accountId.message}</p>}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="recon-date">Fecha del extracto</Label>
            <Controller
              name="statementDate"
              control={control}
              render={({ field }) => (
                <DatePicker id="recon-date" value={field.value} onChange={field.onChange} />
              )}
            />
            {errors.statementDate && (
              <p className="text-xs text-destructive">{errors.statementDate.message}</p>
            )}
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="recon-balance">Saldo del extracto</Label>
            <Controller
              name="statementBalance"
              control={control}
              render={({ field }) => (
                <MoneyInput id="recon-balance" value={field.value} onChange={(v) => field.onChange(v ?? 0)} />
              )}
            />
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isSubmitting}>
              Cancelar
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="mr-2 size-4 animate-spin" />}
              Crear
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

// ── Vista: detalle de sesión ──────────────────────────────────────────────────

function ReconciliationDetailView({ id, onBack }: { id: string; onBack: () => void }) {
  const { data: bootstrap } = useBootstrap()
  const { data, isLoading } = useFinanceReconciliationDetail(id)
  const toggleMovement = useToggleReconciliationMovement()
  const closeSession = useCloseFinanceReconciliation()
  const cancelSession = useCancelFinanceReconciliation()
  const [cancelOpen, setCancelOpen] = React.useState(false)
  const [adjustOpen, setAdjustOpen] = React.useState(false)

  if (isLoading || !data) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton className="h-10 w-40" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    )
  }

  const { session, movements, reconciledBalance, difference } = data
  const isOpen = session.status === "open"
  const isBalanced = Math.abs(difference) < 0.005

  async function handleToggle(m: ReconciliationMovement) {
    try {
      await toggleMovement.mutateAsync({ sessionId: id, movementId: m.id, reconciled: !m.reconciled })
    } catch (err) {
      toast.error("No se pudo actualizar el movimiento", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  async function handleClose(createAdjustment: boolean) {
    try {
      await closeSession.mutateAsync({ id, createAdjustment })
      toast.success(createAdjustment ? "Ajuste creado y conciliación cerrada" : "Conciliación cerrada")
      setAdjustOpen(false)
    } catch (err) {
      toast.error("No se pudo cerrar la conciliación", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  async function handleCancel() {
    try {
      await cancelSession.mutateAsync(id)
      toast.success("Conciliación cancelada")
      setCancelOpen(false)
      onBack()
    } catch (err) {
      toast.error("No se pudo cancelar la conciliación", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex items-center gap-3">
        <Button variant="ghost" size="icon" onClick={onBack} aria-label="Volver">
          <ArrowLeft className="size-4" />
        </Button>
        <div className="flex flex-col">
          <h2 className="text-lg font-semibold">{session.accountName ?? "Cuenta"}</h2>
          <p className="text-sm text-muted-foreground">Extracto al {formatDate(session.statementDate)}</p>
        </div>
        <div className="ml-auto">
          <Badge variant={isOpen ? "outline" : "secondary"}>{isOpen ? "Abierta" : "Cerrada"}</Badge>
        </div>
      </div>

      <Card className="sticky top-0 z-10">
        <CardContent className="grid grid-cols-1 gap-4 pt-6 sm:grid-cols-3">
          <div className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">Saldo extracto</span>
            <span className="text-xl font-semibold tabular-nums">
              {formatMoney(session.statementBalance, bootstrap)}
            </span>
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">Saldo conciliado</span>
            <span className="text-xl font-semibold tabular-nums">
              {formatMoney(reconciledBalance, bootstrap)}
            </span>
          </div>
          <div className="flex flex-col gap-1">
            <span className="text-xs text-muted-foreground">Diferencia</span>
            <span
              className={cn(
                "text-xl font-semibold tabular-nums",
                isBalanced ? "text-green-600 dark:text-green-500" : "text-destructive",
              )}
            >
              {formatMoney(difference, bootstrap)}
            </span>
          </div>
        </CardContent>
      </Card>

      {movements.length === 0 ? (
        <EmptyState
          icon={CheckCircle2}
          title="No hay movimientos para conciliar"
          description="Todos los movimientos activos de esta cuenta ya están conciliados o no hay ninguno."
        />
      ) : (
        <div className="flex flex-col divide-y rounded-md border">
          {movements.map((m) => (
            <div
              key={m.id}
              className="flex items-center gap-3 px-4 py-3 text-sm hover:bg-muted/50"
            >
              <Checkbox
                id={`recon-movement-${m.id}`}
                checked={m.reconciled}
                disabled={!isOpen || toggleMovement.isPending}
                onCheckedChange={() => handleToggle(m)}
              />
              <Label
                htmlFor={`recon-movement-${m.id}`}
                className="flex flex-1 cursor-pointer items-center gap-3 font-normal"
              >
                <span className="w-24 shrink-0 tabular-nums text-muted-foreground">{formatDate(m.date)}</span>
                <span className="flex-1 truncate">{m.description || m.categoryName || "—"}</span>
                <span
                  className={cn(
                    "w-32 shrink-0 text-right tabular-nums",
                    m.kind === "expense" ? "text-destructive" : "text-green-600 dark:text-green-500",
                  )}
                >
                  {m.kind === "expense" ? "-" : ""}
                  {formatMoney(m.amount, bootstrap)}
                </span>
              </Label>
            </div>
          ))}
        </div>
      )}

      {isOpen && (
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="outline" onClick={() => setCancelOpen(true)}>
            Cancelar conciliación
          </Button>
          {isBalanced ? (
            <Button onClick={() => handleClose(false)} disabled={closeSession.isPending}>
              {closeSession.isPending && <Loader2 className="mr-2 size-4 animate-spin" />}
              Cerrar conciliación
            </Button>
          ) : (
            <Button variant="secondary" onClick={() => setAdjustOpen(true)}>
              Crear ajuste y cerrar
            </Button>
          )}
        </div>
      )}

      <AlertDialog open={cancelOpen} onOpenChange={setCancelOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Cancelar conciliación</AlertDialogTitle>
            <AlertDialogDescription>
              Se destildarán todos los movimientos de esta sesión. Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Volver</AlertDialogCancel>
            <AlertDialogAction onClick={handleCancel} disabled={cancelSession.isPending}>
              {cancelSession.isPending ? "Cancelando…" : "Cancelar conciliación"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <AlertDialog open={adjustOpen} onOpenChange={setAdjustOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Crear ajuste y cerrar</AlertDialogTitle>
            <AlertDialogDescription>
              Se creará un movimiento de ajuste por {formatMoney(difference, bootstrap)} para que el
              saldo conciliado coincida con el extracto, y se cerrará la sesión. Esta acción no se
              puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Volver</AlertDialogCancel>
            <AlertDialogAction onClick={() => handleClose(true)} disabled={closeSession.isPending}>
              {closeSession.isPending ? "Cerrando…" : "Crear ajuste y cerrar"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
