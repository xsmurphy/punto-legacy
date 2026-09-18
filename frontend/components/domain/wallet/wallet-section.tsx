"use client"

import * as React from "react"
import Link from "next/link"
import { toast } from "sonner"
import { Loader2, Wallet } from "lucide-react"
import type { ColumnDef } from "@tanstack/react-table"

import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Label } from "@/components/ui/label"
import { MoneyInput } from "@/components/ui/money-input"
import { RadioGroup, RadioGroupItem } from "@/components/ui/radio-group"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Textarea } from "@/components/ui/textarea"
import {
  ResponsiveDialog,
  ResponsiveDialogContent,
  ResponsiveDialogFooter,
  ResponsiveDialogHeader,
  ResponsiveDialogTitle,
} from "@/components/ui/responsive-dialog"
import { DataTable } from "@/components/data-table/data-table"
import { EmptyState } from "@/components/empty-state"
import { StatTile } from "@/components/stat-tile"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useAdjustWallet, useWalletBalances, useWalletMovements } from "@/hooks/use-wallet"
import { formatMoney } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"
import type { WalletBalance, WalletMovement } from "@/lib/types/wallet"

/**
 * Saldo del cliente en la wallet (context/74): saldo por bolsillo, movimientos
 * y ajuste manual. Vive DENTRO de la pestaña "Financiero" de la ficha del
 * cliente, junto al crédito y la cuenta corriente — es plata del cliente en el
 * comercio, no una pantalla aparte.
 *
 * Solo panel: `/v1/wallet` es realm `panel`. En la caja (F2) el saldo se va a
 * ver por sus propios endpoints.
 */
export function WalletSection({
  contactId,
  canManage,
}: {
  contactId: string
  canManage: boolean
}) {
  const { data: bootstrap } = useBootstrap()
  const balances = useWalletBalances(contactId)
  const movements = useWalletMovements(contactId)
  const [adjustOpen, setAdjustOpen] = React.useState(false)

  const rows = React.useMemo(
    () => movements.data?.pages.flatMap((p) => p.movements) ?? [],
    [movements.data],
  )
  const pocketBalances = balances.data?.balances ?? []

  const columns: ColumnDef<WalletMovement, unknown>[] = React.useMemo(
    () => [
      {
        id: "createdAt",
        accessorKey: "createdAt",
        header: "Fecha",
        cell: ({ row }) => (
          <span className="whitespace-nowrap text-muted-foreground">
            {formatDateTime(row.original.createdAt)}
          </span>
        ),
        meta: { label: "Fecha" },
      },
      {
        accessorKey: "pocketName",
        header: "Bolsillo",
        meta: { label: "Bolsillo" },
      },
      {
        id: "type",
        header: "Movimiento",
        accessorFn: (m) => movementLabel(m),
        cell: ({ row }) => <span className="font-medium">{movementLabel(row.original)}</span>,
        meta: { label: "Movimiento" },
      },
      {
        id: "detail",
        header: "Detalle",
        accessorFn: (m) => m.reason ?? "",
        cell: ({ row }) => (
          <span className="text-muted-foreground">{row.original.reason ?? "—"}</span>
        ),
        meta: { label: "Detalle" },
      },
      {
        accessorKey: "amount",
        header: () => <div className="text-right">Monto</div>,
        cell: ({ row }) => (
          <div className="text-right tabular-nums">
            {row.original.amount > 0 ? "+" : "−"}
            {formatMoney(Math.abs(row.original.amount), bootstrap)}
          </div>
        ),
        meta: { label: "Monto" },
      },
      {
        accessorKey: "balanceAfter",
        header: () => <div className="text-right">Saldo</div>,
        cell: ({ row }) => (
          <div className="text-right tabular-nums">{formatMoney(row.original.balanceAfter, bootstrap)}</div>
        ),
        meta: { label: "Saldo" },
      },
      {
        id: "actor",
        header: "Usuario",
        accessorFn: (m) => m.actorName ?? "",
        meta: { label: "Usuario" },
      },
    ],
    [bootstrap],
  )

  // Saldos por bolsillo = números → StatTile gris, en grilla porque la
  // cantidad de bolsillos varía (context/20 2026-09-09). Los movimientos son
  // contenido → card blanca. Antes los saldos iban como tiles blancos DENTRO
  // de la card de movimientos (owner 2026-09-18: nada de KPIs anidados).
  return (
    <>
      {balances.isLoading ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <StatTile label="Saldo" value={null} isLoading />
        </div>
      ) : pocketBalances.length === 0 ? (
        <p className="text-sm text-muted-foreground">
          Sin bolsillos.{" "}
          <Link href="/settings/catalog?tab=wallet-pockets" className="text-foreground underline-offset-4 hover:underline">
            Crear bolsillos
          </Link>
        </p>
      ) : (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          {pocketBalances.map((b) => (
            <StatTile
              key={b.pocketId}
              label={b.active ? b.name : `${b.name} (inactivo)`}
              value={formatMoney(b.balance, bootstrap)}
            />
          ))}
        </div>
      )}

    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2">
        <CardTitle>Movimientos de saldo</CardTitle>
        {canManage && pocketBalances.length > 0 && (
          <Button size="sm" variant="outline" onClick={() => setAdjustOpen(true)}>
            Ajustar
          </Button>
        )}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <DataTable
          tableId="contact-wallet-movements"
          data={rows}
          columns={columns}
          getRowId={(m) => m.id}
          isLoading={movements.isLoading}
          searchPlaceholder="Buscar movimientos…"
          exportFileName="saldo-movimientos"
          emptyMessage={
            <EmptyState
              icon={Wallet}
              title="Sin movimientos"
              description="Las cargas, pagos y ajustes de este cliente van a aparecer acá."
              showMarquee={false}
              className="border-0 py-6"
            />
          }
        />
        {movements.hasNextPage && (
          <div className="flex justify-center">
            <Button
              variant="outline"
              size="sm"
              onClick={() => movements.fetchNextPage()}
              disabled={movements.isFetchingNextPage}
            >
              {movements.isFetchingNextPage && <Loader2 className="size-4 animate-spin" />}
              Ver más
            </Button>
          </div>
        )}
      </CardContent>

      {canManage && (
        <AdjustDialog
          open={adjustOpen}
          onOpenChange={setAdjustOpen}
          contactId={contactId}
          pockets={pocketBalances}
        />
      )}
    </Card>
    </>
  )
}

function movementLabel(m: WalletMovement): string {
  switch (m.type) {
    case "load":
      return "Carga"
    case "load_reversal":
      // La reversa nace de anular la venta de carga o de devolverla con
      // nota de crédito (context/74 §13).
      return m.sourceType === "sale_void" ? "Anulación de carga" : "Devolución de carga"
    case "spend":
      return "Pago"
    case "refund":
      return "Reversa de pago"
    case "adjust":
      return "Ajuste"
    case "transfer":
      if (!m.counterpartName) return "Transferencia"
      return m.amount < 0 ? `Transferencia a ${m.counterpartName}` : `Transferencia de ${m.counterpartName}`
    default:
      // Un tipo que este panel todavía no conoce (uno nuevo del backend) se
      // muestra como movimiento genérico en vez de una celda vacía.
      return "Movimiento"
  }
}

function AdjustDialog({
  open,
  onOpenChange,
  contactId,
  pockets,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  contactId: string
  pockets: WalletBalance[]
}) {
  return (
    <ResponsiveDialog open={open} onOpenChange={onOpenChange}>
      <ResponsiveDialogContent className="sm:max-w-2xl">
        {/* El form se monta al abrir: cada apertura arranca limpia sin
            resetear estado desde un effect. */}
        {open && (
          <AdjustForm contactId={contactId} pockets={pockets} onDone={() => onOpenChange(false)} />
        )}
      </ResponsiveDialogContent>
    </ResponsiveDialog>
  )
}

function AdjustForm({
  contactId,
  pockets,
  onDone,
}: {
  contactId: string
  pockets: WalletBalance[]
  onDone: () => void
}) {
  const adjust = useAdjustWallet()
  const [pocketId, setPocketId] = React.useState<string>(() => pockets[0]?.pocketId ?? "")
  const [direction, setDirection] = React.useState<"add" | "subtract">("add")
  const [amount, setAmount] = React.useState<number | null>(null)
  const [reason, setReason] = React.useState("")

  const canSubmit = pocketId !== "" && (amount ?? 0) > 0 && reason.trim() !== "" && !adjust.isPending

  const submit = async () => {
    if (!canSubmit || amount === null) return
    try {
      await adjust.mutateAsync({
        contactId,
        pocketId,
        amount: direction === "add" ? amount : -amount,
        reason: reason.trim(),
      })
      toast.success("Saldo ajustado")
      onDone()
    } catch (e) {
      toast.error("No se pudo ajustar el saldo", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  return (
    <>
      <ResponsiveDialogHeader>
        <ResponsiveDialogTitle>Ajustar saldo</ResponsiveDialogTitle>
      </ResponsiveDialogHeader>

      <div className="flex flex-col gap-4 py-2">
        <div className="flex flex-col gap-2">
          <Label htmlFor="wallet-adjust-pocket">Bolsillo</Label>
          <Select value={pocketId} onValueChange={setPocketId}>
            <SelectTrigger id="wallet-adjust-pocket" className="w-full">
              <SelectValue placeholder="Elegí un bolsillo" />
            </SelectTrigger>
            <SelectContent>
              {pockets.map((p) => (
                <SelectItem key={p.pocketId} value={p.pocketId}>
                  {p.active ? p.name : `${p.name} (inactivo)`}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="flex flex-col gap-2">
          <Label>Operación</Label>
          <RadioGroup
            value={direction}
            onValueChange={(v) => setDirection(v as "add" | "subtract")}
            className="flex gap-6"
          >
            <div className="flex items-center gap-2">
              <RadioGroupItem value="add" id="wallet-adjust-add" />
              <Label htmlFor="wallet-adjust-add" className="font-normal">
                Sumar
              </Label>
            </div>
            <div className="flex items-center gap-2">
              <RadioGroupItem value="subtract" id="wallet-adjust-subtract" />
              <Label htmlFor="wallet-adjust-subtract" className="font-normal">
                Restar
              </Label>
            </div>
          </RadioGroup>
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="wallet-adjust-amount">Monto</Label>
          <MoneyInput id="wallet-adjust-amount" value={amount} onChange={setAmount} />
        </div>

        <div className="flex flex-col gap-2">
          <Label htmlFor="wallet-adjust-reason">Motivo</Label>
          <Textarea
            id="wallet-adjust-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={500}
            rows={3}
          />
        </div>
      </div>

      <ResponsiveDialogFooter>
        <Button variant="outline" onClick={onDone}>
          Cancelar
        </Button>
        <Button onClick={submit} disabled={!canSubmit}>
          {adjust.isPending && <Loader2 className="size-4 animate-spin" />}
          Ajustar
        </Button>
      </ResponsiveDialogFooter>
    </>
  )
}
