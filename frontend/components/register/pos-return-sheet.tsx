"use client"

/**
 * PosReturnSheet — flujo de devolución de ventas en el POS.
 *
 * 3 pasos inline (no wizard con rutas):
 *   1. Buscar transacción (tipo 0 o 3 — contado / crédito)
 *   2. Seleccionar items, qty y reposición de stock por línea (D2,
 *      context/40-anulacion-y-nota-credito.md)
 *   3. Elegir refund mode (efectivo / crédito al cliente) según la política
 *      del comercio (D3) + nota + confirmar
 *
 * El paso 2 se alimenta de `useReturnOptions` (GET returnOptions) — reemplaza
 * el listado que antes armaba con `transactionDatas` de la venta original.
 * `availableQty` YA descuenta devoluciones previas, no se recalcula acá.
 *
 * Abierto desde el menú del POS (pos-main-menu.tsx) y desde el detalle de
 * transacción (`pos-transactions-dialog.tsx`, `transactions-list.tsx`) con
 * `parentTransactionId`.
 */

import * as React from "react"
import { RotateCcw, Search, ChevronLeft } from "lucide-react"
import { toast } from "sonner"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Badge } from "@/components/ui/badge"
import { Switch } from "@/components/ui/switch"
import { Label } from "@/components/ui/label"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from "@/components/ui/sheet"
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
import { Separator } from "@/components/ui/separator"

import { useTransactionsList, useTransaction } from "@/hooks/use-transactions"
import {
  useReturnOptions,
  useCreateReturn,
  ReturnError,
  type ReturnLine,
  type ReturnItem,
} from "@/hooks/use-returns"
import { formatMoney } from "@/lib/format-money"
import { formatDateTime } from "@/lib/format-date"
import { useCatalogStore } from "@/lib/catalog/store"

// ── Tipos internos ─────────────────────────────────────────────────────────────

interface SelectedLine extends ReturnLine {
  returnQty: number
  restock: boolean
}

type Step = "search" | "items" | "confirm"

// ── Props ──────────────────────────────────────────────────────────────────────

interface PosReturnSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Si viene definido, salta el paso de búsqueda y va directo a "items" con esta tx. */
  parentTransactionId?: string
}

// ── Helpers ────────────────────────────────────────────────────────────────────

/** Por qué no se puede reponer al stock, según cómo el ítem descontó al venderse (tabla D2, context/40). `null` = sí se puede (ownStock). */
function restockReason(kind: ReturnLine["kind"]): string | null {
  if (kind === "ingredientReversal") return "Los insumos ya se consumieron"
  if (kind === "service") return "No maneja stock"
  return null
}

// ── Componente principal ───────────────────────────────────────────────────────

export function PosReturnSheet({ open, onOpenChange, parentTransactionId }: PosReturnSheetProps) {
  const config = useCatalogStore((s) => s.config)

  const [step, setStep] = React.useState<Step>(parentTransactionId ? "items" : "search")
  const [search, setSearch] = React.useState("")
  const [selectedTransactionId, setSelectedTransactionId] = React.useState<string | null>(parentTransactionId ?? null)
  const [selectedItems, setSelectedItems] = React.useState<SelectedLine[]>([])
  const [refundMode, setRefundMode] = React.useState<"cash" | "credit">("cash")
  const [note, setNote] = React.useState("")
  const [confirmOpen, setConfirmOpen] = React.useState(false)

  const createReturn = useCreateReturn()

  // Sincronizar estado cuando cambia el parentTransactionId con el sheet ya montado.
  // Cubre el caso: cerrar devolución de txA → abrir devolución de txB sin desmontar.
  React.useEffect(() => {
    if (open) {
      setStep(parentTransactionId ? "items" : "search")
      setSelectedTransactionId(parentTransactionId ?? null)
      setSelectedItems([])
      setSearch("")
      setRefundMode("cash")
      setNote("")
      setConfirmOpen(false)
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, parentTransactionId])

  // Reset al cerrar
  function handleOpenChange(val: boolean) {
    onOpenChange(val)
    if (!val) resetAll()
  }

  function resetAll() {
    setStep(parentTransactionId ? "items" : "search")
    setSearch("")
    setSelectedTransactionId(parentTransactionId ?? null)
    setSelectedItems([])
    setRefundMode("cash")
    setNote("")
    setConfirmOpen(false)
  }

  // ── Paso 1: búsqueda ────────────────────────────────────────────────────────

  const { data: txList, isLoading: txLoading } = useTransactionsList({
    limit: 50,
  })

  // Filtrar solo ventas (type 0 o 3) y aplicar búsqueda de texto
  const filtered = React.useMemo(() => {
    if (!txList) return []
    const sales = txList.filter((t) => t.type === "0" || t.type === "3")
    const q = search.trim().toLowerCase()
    if (!q) return sales
    return sales.filter(
      (t) =>
        t.name?.toLowerCase().includes(q) ||
        t.documentNo?.toLowerCase().includes(q) ||
        t.transactionId?.toLowerCase().includes(q),
    )
  }, [txList, search])

  function selectTransaction(id: string) {
    setSelectedTransactionId(id)
    setStep("items")
  }

  // ── Paso 2: selección de items ──────────────────────────────────────────────

  // Solo para `customerId` (D3 — gating de "Crédito al cliente"); el listado
  // de ítems viene de `useReturnOptions`, no de `transactionDatas`.
  const { data: txDetail } = useTransaction(selectedTransactionId)
  const {
    data: optionsData,
    isLoading: optionsLoading,
    isError: optionsError,
  } = useReturnOptions(selectedTransactionId)

  // Al cargar las opciones, inicializar selección con qty=0 y el default de reposición.
  React.useEffect(() => {
    if (!optionsData) return
    setSelectedItems(
      optionsData.map((line) => ({
        ...line,
        returnQty: 0,
        restock: line.defaultRestock,
      })),
    )
  }, [optionsData])

  function updateQty(itemSoldId: string, qty: number) {
    setSelectedItems((prev) =>
      prev.map((it) =>
        it.itemSoldId === itemSoldId
          ? { ...it, returnQty: Math.min(Math.max(0, qty), it.availableQty) }
          : it,
      ),
    )
  }

  function updateRestock(itemSoldId: string, value: boolean) {
    setSelectedItems((prev) =>
      prev.map((it) => (it.itemSoldId === itemSoldId ? { ...it, restock: value } : it)),
    )
  }

  const itemsToReturn = selectedItems.filter((it) => it.returnQty > 0)

  const returnTotal = itemsToReturn.reduce(
    (sum, it) => sum + it.unitPrice * it.returnQty,
    0,
  )

  function goToConfirm() {
    if (itemsToReturn.length === 0) {
      toast.error("Seleccioná al menos un ítem para devolver.")
      return
    }
    setStep("confirm")
  }

  // ── Paso 3: confirmar ───────────────────────────────────────────────────────

  const hasCustomer = Boolean(txDetail?.customerId)

  // D3: la política del comercio (`settingReturnRefund`) filtra qué modos se
  // ofrecen. 'ask' (default) ofrece los dos, sujeto a la regla existente de
  // "sin cliente no hay crédito". Con 'cash'/'credit' fijo, la otra opción NO
  // se ofrece — si la política dice 'credit' pero la venta no tiene cliente,
  // cae a efectivo (no hay a quién acreditarle, ver context/40 D3).
  const refundPolicy = config?.settingReturnRefund ?? "ask"
  const availableModes = React.useMemo<Array<"cash" | "credit">>(() => {
    if (refundPolicy === "cash") return ["cash"]
    if (refundPolicy === "credit") return hasCustomer ? ["credit"] : ["cash"]
    return hasCustomer ? ["cash", "credit"] : ["cash"]
  }, [refundPolicy, hasCustomer])

  React.useEffect(() => {
    if (!availableModes.includes(refundMode)) {
      setRefundMode(availableModes[0])
    }
  }, [availableModes, refundMode])

  const policyLocked = refundPolicy !== "ask"
  const policyNote = !policyLocked
    ? null
    : refundPolicy === "credit" && !hasCustomer
      ? "La venta no tiene cliente — se reintegra en efectivo."
      : "Definido por el comercio."

  const restockCount = itemsToReturn.filter((it) => it.restock && it.canRestock).length
  const wasteCount = itemsToReturn.filter(
    (it) => it.kind !== "service" && !(it.restock && it.canRestock),
  ).length

  async function handleConfirm() {
    if (!selectedTransactionId) return
    setConfirmOpen(false)

    const items: ReturnItem[] = itemsToReturn.map((it) => ({
      itemId: it.itemId,
      itemSoldId: it.itemSoldId,
      qty: it.returnQty,
      restock: it.canRestock ? it.restock : false,
    }))

    try {
      await createReturn.mutateAsync({
        parentTransactionId: selectedTransactionId,
        items,
        refundMode,
        note: note.trim() || undefined,
      })
      toast.success("Devolución procesada correctamente.")
      handleOpenChange(false)
    } catch (err) {
      const message =
        err instanceof ReturnError || err instanceof Error
          ? err.message
          : "Error al procesar la devolución."
      toast.error(message)
    }
  }

  // ── Render ──────────────────────────────────────────────────────────────────

  return (
    <>
      <Sheet open={open} onOpenChange={handleOpenChange}>
        <SheetContent
          side="right"
          className="flex w-full max-w-lg flex-col gap-0 p-0 sm:max-w-lg"
        >
          <SheetHeader className="border-b px-6 py-4">
            <div className="flex items-center gap-3">
              {step !== "search" && (
                <Button
                  variant="ghost"
                  size="icon"
                  className="size-8 shrink-0"
                  onClick={() => {
                    if (step === "confirm") {
                      setStep("items")
                    } else if (parentTransactionId) {
                      // Vinimos de un detalle — cerrar el sheet en vez de volver a search
                      handleOpenChange(false)
                    } else {
                      setStep("search")
                    }
                  }}
                >
                  <ChevronLeft className="size-4" />
                </Button>
              )}
              <div className="flex min-w-0 flex-1 items-center gap-2">
                <RotateCcw className="size-4 shrink-0 text-muted-foreground" />
                <SheetTitle className="text-base">
                  {step === "search" && "Devolución — Buscar venta"}
                  {step === "items" && "Devolución — Seleccionar ítems"}
                  {step === "confirm" && "Devolución — Confirmar"}
                </SheetTitle>
              </div>
            </div>
            <SheetDescription className="sr-only">
              Procesar devolución de una venta existente.
            </SheetDescription>
          </SheetHeader>

          {/* ── Paso 1: buscar transacción ───────────────────────────── */}
          {step === "search" && (
            <div className="flex min-h-0 flex-1 flex-col">
              <div className="border-b px-6 py-3">
                <div className="relative">
                  <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    autoFocus
                    placeholder="Buscar por cliente, nro. comprobante…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="pl-9"
                  />
                </div>
              </div>

              <div className="flex-1 overflow-y-auto">
                {txLoading && (
                  <p className="px-6 py-8 text-center text-sm text-muted-foreground">
                    Cargando transacciones…
                  </p>
                )}
                {!txLoading && filtered.length === 0 && (
                  <p className="px-6 py-8 text-center text-sm text-muted-foreground">
                    No se encontraron ventas.
                  </p>
                )}
                <div className="divide-y">
                  {filtered.map((tx) => (
                    <Button
                      key={tx.transactionId}
                      variant="ghost"
                      onClick={() => selectTransaction(tx.transactionId)}
                      className="flex h-auto w-full items-center gap-3 rounded-none px-6 py-3 text-left text-sm"
                    >
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium">
                          {tx.invoicePrefix
                            ? `${tx.invoicePrefix}-${tx.documentNo}`
                            : `#${tx.documentNo}`}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                          {tx.name || "Sin cliente"} · {formatDate(tx.date)}
                        </p>
                      </div>
                      <div className="shrink-0 text-right">
                        <p className="tabular-nums font-medium">
                          {formatMoney(Number(tx.total), config)}
                        </p>
                        <Badge variant={tx.type === "3" ? "secondary" : "outline"} className="text-xs">
                          {tx.type === "3" ? "Crédito" : "Contado"}
                        </Badge>
                      </div>
                    </Button>
                  ))}
                </div>
              </div>
            </div>
          )}

          {/* ── Paso 2: seleccionar items ────────────────────────────── */}
          {step === "items" && (
            <div className="flex min-h-0 flex-1 flex-col">
              {optionsLoading && (
                <div className="flex flex-col gap-3 px-6 py-4">
                  <Skeleton className="h-16 w-full" />
                  <Skeleton className="h-16 w-full" />
                  <Skeleton className="h-16 w-full" />
                </div>
              )}

              {!optionsLoading && optionsError && (
                <p className="px-6 py-8 text-center text-sm text-muted-foreground">
                  No se pudo consultar la devolución. Cerrá e intentá de nuevo.
                </p>
              )}

              {!optionsLoading && !optionsError && (
                <>
                  <div className="flex-1 overflow-y-auto">
                    <div className="divide-y">
                      {selectedItems.map((it) => {
                        const disabled = it.availableQty === 0
                        const reason = restockReason(it.kind)
                        return (
                          <div
                            key={it.itemSoldId}
                            className={cn(
                              "flex flex-col gap-2 px-6 py-3",
                              disabled && "opacity-50",
                            )}
                          >
                            <div className="flex items-center justify-between gap-3">
                              <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">{it.name}</p>
                                <p className="text-xs text-muted-foreground tabular-nums">
                                  Vendido {it.soldQty}
                                  {it.alreadyReturned > 0 && ` · Devuelto ${it.alreadyReturned}`}
                                  {" · "}
                                  {formatMoney(it.unitPrice, config)} c/u
                                </p>
                                {disabled && (
                                  <p className="text-xs text-muted-foreground">Ya devuelto</p>
                                )}
                              </div>
                              <Input
                                type="number"
                                min={0}
                                max={it.availableQty}
                                step={1}
                                disabled={disabled}
                                value={it.returnQty === 0 ? "" : it.returnQty}
                                onChange={(e) =>
                                  updateQty(it.itemSoldId, Number(e.target.value) || 0)
                                }
                                className="h-8 w-20 shrink-0 text-right tabular-nums"
                                placeholder="0"
                              />
                            </div>

                            {it.returnQty > 0 && (
                              <div className="flex items-center justify-between gap-2 rounded-md bg-muted/40 px-3 py-2">
                                <div className="min-w-0">
                                  <Label
                                    htmlFor={`restock-${it.itemSoldId}`}
                                    className="text-xs text-muted-foreground"
                                  >
                                    Vuelve al stock
                                  </Label>
                                  {!it.canRestock && reason && (
                                    <p className="text-xs text-muted-foreground">{reason}</p>
                                  )}
                                </div>
                                <Switch
                                  id={`restock-${it.itemSoldId}`}
                                  checked={it.canRestock && it.restock}
                                  disabled={!it.canRestock}
                                  onCheckedChange={(v) => updateRestock(it.itemSoldId, v)}
                                />
                              </div>
                            )}
                          </div>
                        )
                      })}
                    </div>
                  </div>

                  <div className="border-t bg-background px-6 py-4">
                    <p className="mb-3 text-xs text-muted-foreground">
                      Lo que no vuelve al stock se registra como pérdida (merma).
                    </p>
                    <div className="mb-3 flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">
                        {itemsToReturn.length} ítem{itemsToReturn.length !== 1 ? "s" : ""} seleccionado{itemsToReturn.length !== 1 ? "s" : ""}
                      </span>
                      <span className="text-base font-bold tabular-nums">
                        {formatMoney(returnTotal, config)}
                      </span>
                    </div>
                    <Button
                      className="w-full"
                      onClick={goToConfirm}
                      disabled={itemsToReturn.length === 0}
                    >
                      Continuar
                    </Button>
                  </div>
                </>
              )}
            </div>
          )}

          {/* ── Paso 3: confirmar ─────────────────────────────────────── */}
          {step === "confirm" && (
            <div className="flex min-h-0 flex-1 flex-col">
              <div className="flex-1 overflow-y-auto px-6 py-5 space-y-5">

                {/* Resumen de items */}
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Ítems a devolver
                  </p>
                  <div className="divide-y rounded-md border">
                    {itemsToReturn.map((it) => (
                      <div
                        key={it.itemSoldId}
                        className="flex items-center justify-between px-3 py-2.5 text-sm"
                      >
                        <span className="min-w-0 truncate">{it.name}</span>
                        <span className="ml-4 shrink-0 tabular-nums text-muted-foreground">
                          {it.returnQty} × {formatMoney(it.unitPrice, config)}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>

                <Separator />

                {/* Total + resumen de stock/merma (D2) */}
                <div className="flex flex-col gap-2 rounded-lg bg-muted/40 px-4 py-3">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-medium">Total a devolver</span>
                    <span className="text-lg font-bold tabular-nums">
                      {formatMoney(returnTotal, config)}
                    </span>
                  </div>
                  <div className="flex items-center justify-between text-xs text-muted-foreground">
                    <span>
                      {restockCount} línea{restockCount !== 1 ? "s" : ""} vuelve
                      {restockCount === 1 ? "" : "n"} al stock
                    </span>
                    <span>
                      {wasteCount} línea{wasteCount !== 1 ? "s" : ""} a merma
                    </span>
                  </div>
                </div>

                {/* Modo de devolución (D3) */}
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Forma de devolución
                  </p>
                  {!policyLocked ? (
                    <>
                      <div className="grid grid-cols-2 gap-2">
                        <Button
                          variant="outline"
                          onClick={() => setRefundMode("cash")}
                          className={cn(
                            "h-auto py-3 text-sm font-medium",
                            refundMode === "cash" && "border-primary bg-primary/5 text-primary",
                          )}
                        >
                          Efectivo
                        </Button>
                        <Button
                          variant="outline"
                          onClick={() => setRefundMode("credit")}
                          disabled={!hasCustomer}
                          className={cn(
                            "h-auto py-3 text-sm font-medium",
                            refundMode === "credit" && "border-primary bg-primary/5 text-primary",
                          )}
                        >
                          Crédito al cliente
                        </Button>
                      </div>
                      {!hasCustomer && (
                        <p className="mt-1.5 text-xs text-muted-foreground">
                          La venta no tiene cliente — solo disponible devolución en efectivo.
                        </p>
                      )}
                    </>
                  ) : (
                    <>
                      <Button
                        variant="outline"
                        disabled
                        className="h-auto w-full border-primary bg-primary/5 py-3 text-sm font-medium text-primary"
                      >
                        {refundMode === "cash" ? "Efectivo" : "Crédito al cliente"}
                      </Button>
                      {policyNote && (
                        <p className="mt-1.5 text-xs text-muted-foreground">{policyNote}</p>
                      )}
                    </>
                  )}
                </div>

                {/* Nota */}
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Nota (opcional)
                  </p>
                  <Textarea
                    placeholder="Motivo de la devolución…"
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    rows={2}
                    className="resize-none"
                  />
                </div>
              </div>

              <div className="border-t bg-background px-6 py-4">
                <Button
                  className="w-full"
                  onClick={() => setConfirmOpen(true)}
                  disabled={createReturn.isPending}
                >
                  {createReturn.isPending ? "Procesando…" : "Confirmar devolución"}
                </Button>
              </div>
            </div>
          )}
        </SheetContent>
      </Sheet>

      {/* Diálogo de confirmación final */}
      <AlertDialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Confirmar devolución</AlertDialogTitle>
            <AlertDialogDescription>
              Se procesará una devolución de{" "}
              <strong>{formatMoney(returnTotal, config)}</strong> en{" "}
              {refundMode === "cash" ? "efectivo" : "crédito al cliente"}.{" "}
              Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction autoFocus onClick={handleConfirm}>
              Confirmar
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  )
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function formatDate(iso: string): string {
  if (!iso) return ""
  return formatDateTime(iso, "d MMM, HH:mm")
}
