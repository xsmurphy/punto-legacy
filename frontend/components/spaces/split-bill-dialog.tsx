"use client"

/**
 * Split de cuenta de un espacio (context/15-espacios-module-plan.md §F3).
 * Se abre desde "Cobrar" en `space-session-dialog.tsx`.
 *
 * Cuatro modos de cobro sobre el saldo de la sesión:
 *   - `total`  — la mesa entera (camino caliente, default). Sin pagos previos
 *                usa el flujo de siempre (`loadFromSession`); con pagos
 *                previos cobra el saldo restante como parcial.
 *   - `items`  — se eligen ítems; los ya cobrados quedan bloqueados.
 *   - `amount` — monto libre (adelanto).
 *   - `share`  — partes iguales; la última absorbe el resto del redondeo.
 *
 * Este diálogo NO cobra: devuelve la selección al caller, que arma el carrito
 * y abre el `PayDialog`. El cierre de la mesa lo decide el SERVIDOR cuando el
 * saldo llega a 0 (`SpaceSettlementService::settleIfCovered`) — acá no se
 * cierra ni se marca nada.
 *
 * Toda validación de monto se hace ANTES de cobrar (nunca se ofrece cobrar
 * más que el saldo): un rechazo del backend llegaría después de la venta, con
 * la plata ya cobrada y sin renglón en el ledger.
 */

import * as React from "react"
import { Loader2, Minus, Plus, CreditCard, ClipboardList } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Checkbox } from "@/components/ui/checkbox"
import { Label } from "@/components/ui/label"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { MoneyInput } from "@/components/ui/money-input"
import { EmptyState } from "@/components/empty-state"
import { cn } from "@/lib/utils"
import { formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import { useSessionBalance, type SessionBalance } from "@/hooks/use-space-settlement"
import { currencyDecimals, splitShares, MONEY_EPSILON } from "@/lib/spaces/settlement-lines"
import type { SpaceWithState } from "@/hooks/use-pos-spaces"

const MAX_SHARES = 12

/** Mensajes de por qué un modo quedó bloqueado (ver `lockedFamily`). */
const LOCKED_ITEMS_HINT  = "Esta mesa ya se está cobrando por ítems."
const LOCKED_AMOUNT_HINT = "Esta mesa ya se está cobrando por monto o por partes."

export type SplitSelection =
  | { mode: "total" }
  | { mode: "items"; orderItemIds: string[] }
  | { mode: "amount"; amount: number }
  | { mode: "share"; shareCount: number; shareIndex: number }

interface Props {
  table: SpaceWithState | null
  onOpenChange: (open: boolean) => void
  onCharge: (selection: SplitSelection) => void
  /** true mientras el caller arma el carrito — bloquea el doble tap en Cobrar. */
  preparing: boolean
}

export function SplitBillDialog({ table, onOpenChange, onCharge, preparing }: Props) {
  const sessionId = table?.session?.id ?? null

  return (
    <Dialog open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">{table?.name}</DialogTitle>
          <DialogDescription>Cobrar la mesa completa o dividir la cuenta</DialogDescription>
        </DialogHeader>

        {/* key=sessionId: cambiar de mesa REMONTA el cuerpo y resetea modo y
            selección. Sin esto, los ítems tildados de la mesa anterior
            sobreviven y se cobrarían contra la sesión equivocada. */}
        <SplitBillBody
          key={sessionId ?? "none"}
          sessionId={sessionId}
          onCharge={onCharge}
          onCancel={() => onOpenChange(false)}
          preparing={preparing}
        />
      </DialogContent>
    </Dialog>
  )
}

function SplitBillBody({
  sessionId,
  onCharge,
  onCancel,
  preparing,
}: {
  sessionId: string | null
  onCharge: (selection: SplitSelection) => void
  onCancel: () => void
  preparing: boolean
}) {
  const config = useCatalogStore((s) => s.config)
  const decimals = currencyDecimals(config)
  const { data: balance, isLoading, refetch: refetchBalance } = useSessionBalance(sessionId)

  // `useSessionBalance` cachea con staleTime 5s — si otro dispositivo cobró
  // por ítems justo antes de que el cajero reabra este diálogo (o lo abre
  // dos veces seguidas en menos de 5s), `lockedFamily` puede llegar
  // desactualizado y dejar habilitado un modo que el backend va a rechazar.
  // Mismo motivo que `fetchSessionBalance` en use-space-settlement.ts: para
  // DECIDIR (acá, qué modos mostrar habilitados) hace falta el saldo AHORA,
  // no el cacheado. Se fuerza un refetch de red al abrir/cambiar de mesa —
  // `key={sessionId}` en el padre ya remonta este componente por mesa, así
  // que este efecto corre una vez por apertura.
  React.useEffect(() => {
    if (sessionId) void refetchBalance()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessionId])

  const [mode, setMode] = React.useState<SplitSelection["mode"]>("total")
  // Modos bloqueados por familia: una mesa se cobra por ítems O por monto/
  // partes, nunca mezclando (ver `lockedFamily` en use-space-settlement.ts —
  // mezclar descuenta stock dos veces del mismo ítem). El backend lo rechaza
  // igual; esto evita que el cajero llegue hasta el cobro para enterarse.
  const lockedFamily = balance?.lockedFamily ?? null
  const itemsLocked  = lockedFamily === "amount"
  const amountLocked = lockedFamily === "items"

  // Si el modo activo quedó bloqueado (otro mozo cobró primero y el saldo se
  // refrescó), volver a "total" en vez de dejar seleccionada una pestaña
  // deshabilitada.
  React.useEffect(() => {
    if ((mode === "items" && itemsLocked) || ((mode === "amount" || mode === "share") && amountLocked)) {
      setMode("total")
    }
  }, [mode, itemsLocked, amountLocked])
  const [selectedIds, setSelectedIds] = React.useState<string[]>([])
  const [freeAmount, setFreeAmount] = React.useState<number | null>(null)
  const [shareCount, setShareCount] = React.useState(2)
  const [shareIndex, setShareIndex] = React.useState(1)

  const pending = balance?.balance ?? 0
  const items = balance?.items ?? []
  // Selección EFECTIVA: se descartan los ítems que pasaron a saldados
  // mientras el diálogo estaba abierto (otra caja cobró esa parte). El
  // checkbox se deshabilita solo, pero el id ya tildado sobrevive en el
  // estado local — mandarlo igual haría que el CAS del backend rechace el
  // pago DESPUÉS de cobrar. Se deriva en render en vez de sincronizar con un
  // effect: el saldo es la fuente de verdad, no el estado local.
  const effectiveIds = items.filter((i) => !i.settled && selectedIds.includes(i.id)).map((i) => i.id)
  const selectedTotal = items
    .filter((i) => effectiveIds.includes(i.id))
    .reduce((s, i) => s + i.total, 0)
  const shareParts = React.useMemo(
    () => (balance ? splitShares(balance.total, shareCount, decimals) : []),
    [balance, shareCount, decimals],
  )
  const sharePart = shareParts[shareIndex - 1] ?? 0

  // Monto que va a cobrar la caja según el modo activo.
  const chargeAmount =
    mode === "total" ? pending
    : mode === "items" ? selectedTotal
    : mode === "amount" ? (freeAmount ?? 0)
    : sharePart

  const exceedsBalance = chargeAmount - pending > MONEY_EPSILON
  const canCharge =
    !!balance &&
    !preparing &&
    pending > 0 &&
    chargeAmount > 0 &&
    !exceedsBalance &&
    (mode !== "items" || effectiveIds.length > 0)

  function toggleItem(id: string, checked: boolean) {
    setSelectedIds((prev) => (checked ? [...prev, id] : prev.filter((x) => x !== id)))
  }

  function adjustShareCount(next: number) {
    const clamped = Math.min(MAX_SHARES, Math.max(2, next))
    setShareCount(clamped)
    setShareIndex((idx) => Math.min(idx, clamped))
  }

  function handleCharge() {
    if (!balance || !canCharge) return
    const selection: SplitSelection =
      mode === "total" ? { mode: "total" }
      : mode === "items" ? { mode: "items", orderItemIds: effectiveIds }
      : mode === "amount" ? { mode: "amount", amount: chargeAmount }
      : { mode: "share", shareCount, shareIndex }
    onCharge(selection)
  }

  if (isLoading || !balance) {
    return (
      <div className="flex justify-center py-10">
        <Loader2 className="size-5 animate-spin text-muted-foreground" />
      </div>
    )
  }

  return (
    <>
      <BalanceSummary balance={balance} />

      <Tabs value={mode} onValueChange={(v) => setMode(v as SplitSelection["mode"])}>
        {/* h-11: touch target del POS (§7) sobre el h-8 default de shadcn. */}
        <TabsList className="grid h-11 w-full grid-cols-4">
          <TabsTrigger value="total">Total</TabsTrigger>
          <TabsTrigger
            value="items"
            disabled={itemsLocked}
            title={itemsLocked ? LOCKED_ITEMS_HINT : undefined}
          >
            Por ítems
          </TabsTrigger>
          <TabsTrigger
            value="amount"
            disabled={amountLocked}
            title={amountLocked ? LOCKED_AMOUNT_HINT : undefined}
          >
            Monto
          </TabsTrigger>
          <TabsTrigger
            value="share"
            disabled={amountLocked}
            title={amountLocked ? LOCKED_AMOUNT_HINT : undefined}
          >
            Partes
          </TabsTrigger>
        </TabsList>
        {lockedFamily !== null && (
          <p className="pt-2 text-xs text-muted-foreground">
            {lockedFamily === "items" ? LOCKED_AMOUNT_HINT : LOCKED_ITEMS_HINT}
          </p>
        )}

        <div className="max-h-[40vh] overflow-y-auto pt-3">
          <TabsContent value="total">
            <p className="text-sm text-muted-foreground">
              {balance.paid > 0
                ? "Se cobra el saldo pendiente de la mesa. Al quedar en cero, el espacio se libera solo."
                : "Se cobra la cuenta completa, con todas las órdenes de la sesión."}
            </p>
          </TabsContent>

          <TabsContent value="items">
            <ItemPicker balance={balance} selectedIds={effectiveIds} onToggle={toggleItem} />
          </TabsContent>

          <TabsContent value="amount" className="flex flex-col gap-2">
            <Label htmlFor="split-free-amount">Monto a cobrar</Label>
            <MoneyInput
              id="split-free-amount"
              value={freeAmount}
              onChange={setFreeAmount}
              className="h-12 text-lg"
            />
            <p className="text-sm text-muted-foreground">
              Máximo: <span className="tabular-nums">{formatMoney(pending, config)}</span>
            </p>
          </TabsContent>

          <TabsContent value="share" className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
              <Label>Cantidad de partes</Label>
              <div className="flex items-center gap-3">
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="size-11"
                  aria-label="Quitar una parte"
                  disabled={shareCount <= 2}
                  onClick={() => adjustShareCount(shareCount - 1)}
                >
                  <Minus className="size-4" />
                </Button>
                <span className="w-10 text-center text-2xl font-semibold tabular-nums">
                  {shareCount}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  className="size-11"
                  aria-label="Agregar una parte"
                  disabled={shareCount >= MAX_SHARES}
                  onClick={() => adjustShareCount(shareCount + 1)}
                >
                  <Plus className="size-4" />
                </Button>
              </div>
            </div>

            <div className="flex flex-col gap-2">
              <Label>Parte a cobrar</Label>
              <div className="flex flex-wrap gap-2">
                {shareParts.map((_part, i) => {
                  const index = i + 1
                  const active = index === shareIndex
                  return (
                    <Button
                      key={index}
                      type="button"
                      variant={active ? "default" : "outline"}
                      className="h-11 min-w-11"
                      aria-pressed={active}
                      onClick={() => setShareIndex(index)}
                    >
                      <span className="tabular-nums">{index}</span>
                    </Button>
                  )
                })}
              </div>
              <p className="text-sm text-muted-foreground">
                Parte {shareIndex} de {shareCount}:{" "}
                <span className="font-medium tabular-nums text-foreground">
                  {formatMoney(sharePart, config)}
                </span>
                {shareIndex === shareCount && " (absorbe el resto del redondeo)"}
              </p>
            </div>
          </TabsContent>
        </div>
      </Tabs>

      {exceedsBalance && (
        <p className="text-sm font-medium text-destructive">
          El monto supera el saldo pendiente de la mesa.
        </p>
      )}

      <DialogFooter className="gap-2 sm:justify-between">
        <Button size="lg" variant="outline" onClick={onCancel}>
          Volver
        </Button>
        <Button size="lg" onClick={handleCharge} disabled={!canCharge} className="gap-1.5">
          {preparing ? (
            <Loader2 className="size-4 animate-spin" />
          ) : (
            <CreditCard className="size-4" />
          )}
          {preparing ? "Preparando cobro..." : `Cobrar ${formatMoney(chargeAmount, config)}`}
        </Button>
      </DialogFooter>
    </>
  )
}

/** Total / Pagado / Saldo. El saldo es el número que manda: va más grande. */
function BalanceSummary({ balance }: { balance: SessionBalance }) {
  const config = useCatalogStore((s) => s.config)
  return (
    <div className="grid grid-cols-3 gap-2 rounded-lg border border-border px-3 py-2">
      <div>
        <p className="text-xs text-muted-foreground">Total</p>
        <p className="text-sm font-medium tabular-nums">{formatMoney(balance.total, config)}</p>
      </div>
      <div>
        <p className="text-xs text-muted-foreground">Pagado</p>
        <p className="text-sm font-medium tabular-nums">{formatMoney(balance.paid, config)}</p>
      </div>
      <div>
        <p className="text-xs text-muted-foreground">Saldo</p>
        <p className="text-lg font-semibold tabular-nums">{formatMoney(balance.balance, config)}</p>
      </div>
    </div>
  )
}

function ItemPicker({
  balance,
  selectedIds,
  onToggle,
}: {
  balance: SessionBalance
  selectedIds: string[]
  onToggle: (id: string, checked: boolean) => void
}) {
  const config = useCatalogStore((s) => s.config)
  if (balance.items.length === 0) {
    return (
      <EmptyState icon={ClipboardList} title="La mesa no tiene ítems para cobrar" className="py-8" />
    )
  }
  const selectedTotal = balance.items
    .filter((i) => selectedIds.includes(i.id))
    .reduce((s, i) => s + i.total, 0)

  return (
    <div className="flex flex-col gap-1">
      {balance.items.map((item) => {
        const checked = selectedIds.includes(item.id)
        return (
          <Label
            key={item.id}
            htmlFor={`split-item-${item.id}`}
            className={cn(
              // py-2.5 + texto: fila de ~44px, touch target del POS (§7).
              "flex items-center gap-3 rounded-lg border border-border px-3 py-2.5 font-normal",
              item.settled ? "opacity-60" : "cursor-pointer hover:bg-accent",
            )}
          >
            <Checkbox
              id={`split-item-${item.id}`}
              checked={checked}
              disabled={item.settled}
              onCheckedChange={(v) => onToggle(item.id, v === true)}
            />
            <span
              className={cn(
                "min-w-0 flex-1 text-sm",
                item.settled && "text-muted-foreground line-through",
              )}
            >
              <span className="tabular-nums">{item.qty}×</span> {item.name}
            </span>
            {item.settled && <Badge variant="outline">Cobrado</Badge>}
            <span
              className={cn(
                "shrink-0 text-sm tabular-nums",
                item.settled ? "text-muted-foreground line-through" : "text-foreground",
              )}
            >
              {formatMoney(item.total, config)}
            </span>
          </Label>
        )
      })}
      <div className="flex items-center justify-between border-t border-border pt-2 text-sm">
        <span className="text-muted-foreground">Seleccionado</span>
        <span className="font-semibold tabular-nums">{formatMoney(selectedTotal, config)}</span>
      </div>
    </div>
  )
}
