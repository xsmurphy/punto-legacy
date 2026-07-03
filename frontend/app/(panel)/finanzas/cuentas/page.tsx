"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { useForm, Controller } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Landmark, Loader2, MoreVertical, Plus, Receipt } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
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
import { MoneyInput } from "@/components/ui/money-input"
import { EmptyState } from "@/components/empty-state"
import { Skeleton } from "@/components/ui/skeleton"
import { formatMoney } from "@/lib/format"
import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useFinanceAccounts,
  useCreateFinanceAccount,
  useUpdateFinanceAccount,
  useArchiveFinanceAccount,
  type FinanceAccount,
} from "@/hooks/use-finance-accounts"

const TYPE_LABELS: Record<FinanceAccount["type"], string> = {
  cash: "Efectivo",
  bank: "Banco",
  wallet: "Billetera",
}

export default function FinanzasCuentasPage() {
  const router = useRouter()
  const { data: bootstrap } = useBootstrap()
  const { data: accounts, isLoading } = useFinanceAccounts()
  const [createOpen, setCreateOpen] = React.useState(false)
  const [editTarget, setEditTarget] = React.useState<FinanceAccount | null>(null)
  const [archiveTarget, setArchiveTarget] = React.useState<FinanceAccount | null>(null)

  const archiveAccount = useArchiveFinanceAccount()

  const rows = (accounts ?? []).filter((a) => a.status === 1)

  async function handleArchiveConfirm() {
    if (!archiveTarget) return
    try {
      await archiveAccount.mutateAsync(archiveTarget.id)
      toast.success("Cuenta archivada")
      setArchiveTarget(null)
    } catch (err) {
      toast.error("No se pudo archivar la cuenta", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  if (isLoading) {
    return (
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-40 w-full" />
        ))}
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex items-center justify-end">
        <Button onClick={() => setCreateOpen(true)}>
          <Plus className="size-4" />
          Nueva cuenta
        </Button>
      </header>

      {rows.length === 0 ? (
        <EmptyState
          icon={Landmark}
          title="Sin cuentas todavía"
          description="Creá una cuenta bancaria o billetera con el botón Nueva cuenta arriba."
        />
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((account) => (
            <Card
              key={account.id}
              className="cursor-pointer transition-colors hover:border-primary/40"
              onClick={() => router.push(`/finanzas/movimientos?accountId=${account.id}`)}
            >
              <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0">
                <div className="flex flex-col gap-1.5">
                  <CardTitle className="text-base font-semibold tracking-tight">
                    {account.name}
                  </CardTitle>
                  <div className="flex gap-1.5">
                    <Badge variant="outline">{TYPE_LABELS[account.type]}</Badge>
                    {account.isSystem && <Badge variant="secondary">Sistema</Badge>}
                  </div>
                </div>
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button
                      variant="ghost"
                      size="icon"
                      aria-label="Acciones de la cuenta"
                      onClick={(e) => e.stopPropagation()}
                    >
                      <MoreVertical className="size-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" onClick={(e) => e.stopPropagation()}>
                    <DropdownMenuItem asChild>
                      <Link href={`/finanzas/movimientos?accountId=${account.id}`}>
                        <Receipt className="size-4" />
                        Ver movimientos
                      </Link>
                    </DropdownMenuItem>
                    {!account.isSystem && (
                      <>
                        <DropdownMenuItem onSelect={() => setEditTarget(account)}>
                          Editar
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          variant="destructive"
                          onSelect={() => setArchiveTarget(account)}
                        >
                          Archivar
                        </DropdownMenuItem>
                      </>
                    )}
                  </DropdownMenuContent>
                </DropdownMenu>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                <p className="text-2xl font-semibold tabular-nums">
                  {formatMoney(account.currentBalance, bootstrap)}
                </p>
                {(account.bankName || account.accountNumber) && (
                  <p className="text-sm text-muted-foreground">
                    {[account.bankName, account.accountNumber].filter(Boolean).join(" · ")}
                  </p>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <AccountFormDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        mode="create"
      />
      <AccountFormDialog
        open={!!editTarget}
        onOpenChange={(o) => !o && setEditTarget(null)}
        mode="edit"
        account={editTarget}
      />

      <AlertDialog open={!!archiveTarget} onOpenChange={(o) => !o && setArchiveTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Archivar cuenta</AlertDialogTitle>
            <AlertDialogDescription>
              &quot;{archiveTarget?.name}&quot; dejará de estar disponible para nuevos movimientos.
              Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction onClick={handleArchiveConfirm} disabled={archiveAccount.isPending}>
              {archiveAccount.isPending ? "Archivando…" : "Archivar"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}

// ── Dialog: crear/editar cuenta ──────────────────────────────────────────────

const accountFormSchema = z.object({
  name: z.string().min(1, "Ingresá un nombre"),
  type: z.enum(["bank", "wallet"]),
  openingBalance: z.number().nonnegative("El saldo no puede ser negativo").optional(),
  bankName: z.string().optional(),
  accountNumber: z.string().optional(),
})

type AccountFormValues = z.infer<typeof accountFormSchema>

function AccountFormDialog({
  open,
  onOpenChange,
  mode,
  account,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  mode: "create" | "edit"
  account?: FinanceAccount | null
}) {
  const createAccount = useCreateFinanceAccount()
  const updateAccount = useUpdateFinanceAccount()

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<AccountFormValues>({
    resolver: zodResolver(accountFormSchema),
    defaultValues: {
      name: "",
      type: "bank",
      openingBalance: 0,
      bankName: "",
      accountNumber: "",
    },
  })

  React.useEffect(() => {
    if (!open) return
    if (mode === "edit" && account) {
      reset({
        name: account.name,
        type: account.type === "cash" ? "bank" : account.type,
        openingBalance: account.openingBalance,
        bankName: account.bankName ?? "",
        accountNumber: account.accountNumber ?? "",
      })
    } else {
      reset({ name: "", type: "bank", openingBalance: 0, bankName: "", accountNumber: "" })
    }
  }, [open, mode, account, reset])

  async function onSubmit(values: AccountFormValues) {
    try {
      if (mode === "edit" && account) {
        await updateAccount.mutateAsync({
          id: account.id,
          values: {
            name: values.name,
            bankName: values.bankName?.trim() || null,
            accountNumber: values.accountNumber?.trim() || null,
          },
        })
        toast.success("Cuenta actualizada")
      } else {
        await createAccount.mutateAsync({
          name: values.name,
          type: values.type,
          openingBalance: values.openingBalance ?? 0,
          bankName: values.bankName?.trim() || null,
          accountNumber: values.accountNumber?.trim() || null,
        })
        toast.success("Cuenta creada")
      }
      onOpenChange(false)
    } catch (err) {
      toast.error(mode === "edit" ? "No se pudo actualizar la cuenta" : "No se pudo crear la cuenta", {
        description: err instanceof Error ? err.message : undefined,
      })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{mode === "edit" ? "Editar cuenta" : "Nueva cuenta"}</DialogTitle>
          <DialogDescription>
            {mode === "edit"
              ? "Actualizá el nombre y los datos bancarios de la cuenta."
              : "Creá una cuenta bancaria o billetera para registrar movimientos."}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
          <div className="space-y-1.5">
            <Label htmlFor="account-name">Nombre</Label>
            <Input id="account-name" aria-invalid={!!errors.name} {...register("name")} />
            {errors.name && <p className="text-xs text-destructive">{errors.name.message}</p>}
          </div>

          {mode === "create" && (
            <div className="space-y-1.5">
              <Label htmlFor="account-type">Tipo</Label>
              <Controller
                name="type"
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="account-type">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="bank">Banco</SelectItem>
                      <SelectItem value="wallet">Billetera</SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
            </div>
          )}

          {mode === "create" && (
            <div className="space-y-1.5">
              <Label htmlFor="account-opening-balance">Saldo inicial</Label>
              <Controller
                name="openingBalance"
                control={control}
                render={({ field }) => (
                  <MoneyInput
                    id="account-opening-balance"
                    value={field.value ?? 0}
                    onChange={(v) => field.onChange(v ?? 0)}
                  />
                )}
              />
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="account-bank-name">Banco</Label>
            <Input id="account-bank-name" {...register("bankName")} />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="account-number">Número de cuenta (opcional)</Label>
            <Input id="account-number" {...register("accountNumber")} />
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
              {mode === "edit" ? "Guardar" : "Crear cuenta"}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
