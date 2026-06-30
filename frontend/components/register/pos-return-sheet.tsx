"use client"

/**
 * PosReturnSheet — flujo de devolución de ventas en el POS.
 *
 * 3 pasos inline (no wizard con rutas):
 *   1. Buscar transacción (tipo 0 o 3 — contado / crédito)
 *   2. Seleccionar items y qty a devolver
 *   3. Elegir refund mode (efectivo / crédito al cliente) + nota + confirmar
 *
 * Abierto desde el menú del POS (pos-main-menu.tsx).
 */

import * as React from "react"
import { RotateCcw, Search, ChevronLeft, X } from "lucide-react"
import { toast } from "sonner"

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { Badge } from "@/components/ui/badge"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetDescription,
} from "@/components/ui/sheet"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
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

import { useTransactionsList, useTransaction, type TransactionDataItem } from "@/hooks/use-transactions"
import { useCreateReturn, type ReturnItem } from "@/hooks/use-returns"
import { formatMoney } from "@/lib/format-money"
import { formatDateTime } from "@/lib/format-date"
import { useCatalogStore } from "@/lib/catalog/store"

// ── Tipos internos ─────────────────────────────────────────────────────────────

interface SelectedItem {
  itemId: string
  name: string
  soldQty: number
  unitPrice: number
  returnQty: number
}

type Step = "search" | "items" | "confirm"

// ── Props ──────────────────────────────────────────────────────────────────────

interface PosReturnSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Si viene definido, salta el paso de búsqueda y va directo a "items" con esta tx. */
  parentTransactionId?: string
}

// ── Componente principal ───────────────────────────────────────────────────────

export function PosReturnSheet({ open, onOpenChange, parentTransactionId }: PosReturnSheetProps) {
  const config = useCatalogStore((s) => s.config)

  const [step, setStep] = React.useState<Step>(parentTransactionId ? "items" : "search")
  const [search, setSearch] = React.useState("")
  const [selectedTransactionId, setSelectedTransactionId] = React.useState<string | null>(parentTransactionId ?? null)
  const [selectedItems, setSelectedItems] = React.useState<SelectedItem[]>([])
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

  const { data: txDetail, isLoading: detailLoading } = useTransaction(selectedTransactionId)

  // Al cargar el detalle, inicializar los items seleccionados con qty = 0
  React.useEffect(() => {
    if (!txDetail?.transactionDatas) return
    setSelectedItems(
      txDetail.transactionDatas.map((d: TransactionDataItem) => ({
        itemId:    d.itemId,
        name:      d.name,
        soldQty:   d.count,
        unitPrice: d.price,
        returnQty: 0,
      })),
    )
  }, [txDetail])

  function updateQty(itemId: string, qty: number) {
    setSelectedItems((prev) =>
      prev.map((it) =>
        it.itemId === itemId
          ? { ...it, returnQty: Math.min(Math.max(0, qty), it.soldQty) }
          : it,
      ),
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

  async function handleConfirm() {
    if (!selectedTransactionId) return
    setConfirmOpen(false)

    const items: ReturnItem[] = itemsToReturn.map((it) => ({
      itemId: it.itemId,
      qty:    it.returnQty,
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
      toast.error(err instanceof Error ? err.message : "Error al procesar la devolución.")
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
              {detailLoading && (
                <p className="px-6 py-8 text-center text-sm text-muted-foreground">
                  Cargando detalle…
                </p>
              )}
              {!detailLoading && txDetail && (
                <>
                  <div className="flex-1 overflow-y-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Ítem</TableHead>
                          <TableHead className="text-right">Vendido</TableHead>
                          <TableHead className="w-28 text-right">A devolver</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {selectedItems.map((it) => (
                          <TableRow key={it.itemId}>
                            <TableCell className="max-w-[180px]">
                              <p className="truncate text-sm font-medium">{it.name}</p>
                              <p className="text-xs text-muted-foreground tabular-nums">
                                {formatMoney(it.unitPrice, config)} c/u
                              </p>
                            </TableCell>
                            <TableCell className="text-right tabular-nums text-sm">
                              {it.soldQty}
                            </TableCell>
                            <TableCell className="text-right">
                              <Input
                                type="number"
                                min={0}
                                max={it.soldQty}
                                step={1}
                                value={it.returnQty === 0 ? "" : it.returnQty}
                                onChange={(e) =>
                                  updateQty(it.itemId, Number(e.target.value) || 0)
                                }
                                className="h-8 w-20 text-right tabular-nums"
                                placeholder="0"
                              />
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>

                  <div className="border-t bg-background px-6 py-4">
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
                        key={it.itemId}
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

                {/* Total */}
                <div className="flex items-center justify-between rounded-lg bg-muted/40 px-4 py-3">
                  <span className="text-sm font-medium">Total a devolver</span>
                  <span className="text-lg font-bold tabular-nums">
                    {formatMoney(returnTotal, config)}
                  </span>
                </div>

                {/* Modo de devolución */}
                <div>
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Forma de devolución
                  </p>
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
