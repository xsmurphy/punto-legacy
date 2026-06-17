"use client"

/**
 * Página de Transacciones del POS.
 *
 * Layout 2 columnas (izquierda: lista; derecha: detalle).
 * En mobile: una columna, el detalle reemplaza la lista al seleccionar.
 *
 * Acciones por transacción:
 *   - DUPLICAR: carga los ítems en el carrito actual y navega a /pos.
 *     Si hay líneas en el carrito, pide confirmación antes.
 *   - REIMPRIMIR: llama a window.print() con los datos de la transacción
 *     (pipeline de impresión completo pendiente hasta que exista lib/print/).
 *
 * Ref. legacy: app/scripts/app.js L5314 (duplicateSale), L5723 (reprintSale),
 *              L10747–10802 (vista de transacciones desde POS).
 */

import * as React from "react"
import { useRouter } from "next/navigation"
import { Search, ArrowLeft, Printer, Copy } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
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
import { cn } from "@/lib/utils"
import { useCartStore } from "@/lib/cart/store"
import { useCatalogStore } from "@/lib/catalog/store"
import { formatAmount } from "@/lib/format-money"
import {
  useTransactionsList,
  useTransaction,
  type TransactionListItem,
  type TransactionDetail,
  type TransactionDataItem,
} from "@/hooks/use-transactions"

// ── Helpers ───────────────────────────────────────────────────────────────────

const TX_TYPE_LABELS: Record<string, string> = {
  "0": "Contado",
  "3": "Crédito",
  "9": "Cotización",
  "2": "Guardado",
  "12": "Mesa",
  "13": "Cita",
}

function txTypeLabel(type: string): string {
  return TX_TYPE_LABELS[type] ?? `Tipo ${type}`
}

function fmtDate(iso: string): string {
  if (!iso) return ""
  try {
    return new Intl.DateTimeFormat("es", {
      day: "numeric",
      month: "short",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso.replace(" ", "T")))
  } catch {
    return iso
  }
}

// ── Componente principal ──────────────────────────────────────────────────────

export default function TransactionsPage() {
  const router = useRouter()
  const [search, setSearch] = React.useState("")
  const [selectedId, setSelectedId] = React.useState<string | null>(null)
  const [confirmDuplicateOpen, setConfirmDuplicateOpen] = React.useState(false)
  const [pendingDuplicate, setPendingDuplicate] = React.useState<TransactionDetail | null>(null)

  const config = useCatalogStore((s) => s.config)
  const cartLines = useCartStore((s) => s.lines)
  const clearCart = useCartStore((s) => s.clear)
  const addItem = useCartStore((s) => s.addItem)

  const { data: listRaw, isLoading: listLoading } = useTransactionsList({ limit: 100 })
  const { data: detail, isLoading: detailLoading } = useTransaction(selectedId)

  // Filtrar localmente por nombre/comprobante
  const list = React.useMemo(() => {
    if (!listRaw) return []
    if (!search.trim()) return listRaw
    const q = search.toLowerCase()
    return listRaw.filter(
      (t) =>
        t.name.toLowerCase().includes(q) ||
        t.documentNo.toLowerCase().includes(q),
    )
  }, [listRaw, search])

  // ── Duplicar ──────────────────────────────────────────────────────────────

  function requestDuplicate(tx: TransactionDetail) {
    if (cartLines.length > 0) {
      setPendingDuplicate(tx)
      setConfirmDuplicateOpen(true)
    } else {
      executeDuplicate(tx)
    }
  }

  function executeDuplicate(tx: TransactionDetail) {
    clearCart()
    const items = (tx.transactionDatas ?? []).filter((i) => i.status !== 0)
    for (const item of items) {
      addItem({
        id: item.itemId,
        name: item.name,
        price: item.price,
      })
    }
    router.push("/pos")
  }

  // ── Reimprimir ────────────────────────────────────────────────────────────

  function handleReprint(tx: TransactionDetail) {
    // Pipeline completo de impresión (QZ Tray) pendiente hasta que exista lib/print/.
    // Por ahora: window.print() con el contenido renderizado.
    // TODO (print-pipeline): reusar el builder de ticket del flujo normal de venta.
    window.print()
  }

  // ── Mobile: si hay detalle seleccionado, mostrar solo el detalle ──────────
  const [mobileShowDetail, setMobileShowDetail] = React.useState(false)

  function handleSelectTransaction(id: string) {
    setSelectedId(id)
    setMobileShowDetail(true)
  }

  return (
    <div className="flex h-full min-h-0 flex-col">
      {/* Confirm de reemplazar carrito */}
      <AlertDialog open={confirmDuplicateOpen} onOpenChange={setConfirmDuplicateOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Reemplazar venta actual</AlertDialogTitle>
            <AlertDialogDescription>
              El carrito tiene ítems. Al duplicar esta venta se reemplazarán.
              Esta acción no se puede deshacer.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancelar</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (pendingDuplicate) executeDuplicate(pendingDuplicate)
                setConfirmDuplicateOpen(false)
                setPendingDuplicate(null)
              }}
            >
              Reemplazar venta
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Layout 2 cols */}
      <div className="flex min-h-0 flex-1 overflow-hidden">

        {/* ── Columna izquierda: lista ── */}
        <div
          className={cn(
            "flex w-full flex-col border-r border-border sm:w-72 sm:shrink-0",
            mobileShowDetail && "hidden sm:flex",
          )}
        >
          {/* Header */}
          <div className="flex items-center gap-2 border-b border-border p-3">
            <Button
              variant="ghost"
              size="icon"
              className="size-8 shrink-0"
              onClick={() => router.push("/pos")}
              aria-label="Volver al POS"
            >
              <ArrowLeft className="size-4" />
            </Button>
            <h1 className="text-sm font-semibold">Transacciones</h1>
          </div>

          {/* Búsqueda */}
          <div className="p-2">
            <div className="relative">
              <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Buscar cliente o comprobante..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-8 text-sm"
              />
            </div>
          </div>

          {/* Lista */}
          <div className="flex-1 overflow-y-auto">
            {listLoading && (
              <div className="p-4 text-center text-sm text-muted-foreground">
                Cargando...
              </div>
            )}
            {!listLoading && list.length === 0 && (
              <div className="p-4 text-center text-sm text-muted-foreground">
                Sin resultados
              </div>
            )}
            {list.map((tx) => (
              <TransactionRow
                key={tx.transactionId}
                tx={tx}
                selected={tx.transactionId === selectedId}
                config={config}
                onSelect={() => handleSelectTransaction(tx.transactionId)}
              />
            ))}
          </div>
        </div>

        {/* ── Columna derecha: detalle ── */}
        <div
          className={cn(
            "flex min-w-0 flex-1 flex-col",
            !mobileShowDetail && "hidden sm:flex",
          )}
        >
          {/* Back en mobile */}
          <div className="flex items-center border-b border-border p-3 sm:hidden">
            <Button
              variant="ghost"
              size="icon"
              className="size-8"
              onClick={() => setMobileShowDetail(false)}
              aria-label="Volver a la lista"
            >
              <ArrowLeft className="size-4" />
            </Button>
          </div>

          {!selectedId && (
            <div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">
              Seleccioná una transacción para ver el detalle
            </div>
          )}
          {selectedId && detailLoading && (
            <div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">
              Cargando detalle...
            </div>
          )}
          {selectedId && !detailLoading && detail && (
            <TransactionDetailPanel
              tx={detail}
              config={config}
              onDuplicate={() => requestDuplicate(detail)}
              onReprint={() => handleReprint(detail)}
            />
          )}
        </div>
      </div>
    </div>
  )
}

// ── Fila de transacción en la lista ──────────────────────────────────────────

function TransactionRow({
  tx,
  selected,
  config,
  onSelect,
}: {
  tx: TransactionListItem
  selected: boolean
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onSelect: () => void
}) {
  return (
    <button
      type="button"
      onClick={onSelect}
      className={cn(
        "flex w-full items-start gap-3 px-3 py-3 text-left transition-colors hover:bg-muted/50",
        selected && "bg-accent",
      )}
    >
      {/* Avatar inicial */}
      <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
        {(tx.name || "?").charAt(0).toUpperCase()}
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-1">
          <p className="truncate text-sm font-medium text-foreground">
            {tx.name || "Sin nombre"}
          </p>
          <span className="shrink-0 text-sm font-semibold tabular-nums">
            {formatAmount(parseFloat(tx.total), config)}
          </span>
        </div>
        <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
          <span className="truncate">{fmtDate(tx.date)}</span>
          {tx.documentNo && (
            <>
              <span>·</span>
              <span className="shrink-0">#{tx.documentNo}</span>
            </>
          )}
          <span>·</span>
          <span
            className={cn(
              "shrink-0 rounded-full px-1.5 py-0.5 font-medium",
              tx.type === "3"
                ? "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"
                : "bg-muted text-muted-foreground",
            )}
          >
            {txTypeLabel(tx.type)}
          </span>
        </div>
      </div>
    </button>
  )
}

// ── Panel de detalle ──────────────────────────────────────────────────────────

function TransactionDetailPanel({
  tx,
  config,
  onDuplicate,
  onReprint,
}: {
  tx: TransactionDetail
  config: ReturnType<typeof useCatalogStore.getState>["config"]
  onDuplicate: () => void
  onReprint: () => void
}) {
  const docLabel = tx.invoicePrefix
    ? `${tx.invoicePrefix}-${tx.documentNo}`
    : tx.documentNo

  return (
    <div className="flex h-full flex-col overflow-hidden">
      {/* Header del detalle */}
      <div className="flex items-start justify-between gap-3 border-b border-border p-4">
        <div>
          <p className="text-lg font-bold text-foreground">
            {tx.name || "Sin nombre"}
          </p>
          <p className="text-sm text-muted-foreground">{txTypeLabel(tx.type)}</p>
          {docLabel && (
            <p className="mt-1 text-xs text-muted-foreground">
              Comprobante #{docLabel}
            </p>
          )}
          <p className="text-xs text-muted-foreground">{fmtDate(tx.date)}</p>
        </div>

        {/* Acciones */}
        <div className="flex shrink-0 gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={onDuplicate}
            className="gap-1.5"
          >
            <Copy className="size-4" />
            Duplicar
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={onReprint}
            className="gap-1.5"
          >
            <Printer className="size-4" />
            Reimprimir
          </Button>
        </div>
      </div>

      {/* Contenido scrolleable */}
      <div className="flex-1 space-y-4 overflow-y-auto p-4">

        {/* Ítems */}
        {(tx.transactionDatas ?? []).filter((i) => i.status !== 0).length > 0 && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Ítems
            </p>
            <div className="divide-y divide-border rounded-lg border border-border">
              {tx.transactionDatas!.filter((i) => i.status !== 0).map((item, idx) => (
                <ItemRow key={idx} item={item} config={config} />
              ))}
            </div>
          </section>
        )}

        {/* Totales */}
        <section>
          <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Totales
          </p>
          <div className="space-y-1.5 rounded-lg border border-border p-3">
            {parseFloat(tx.discount) > 0 && (
              <div className="flex justify-between text-sm">
                <span className="text-muted-foreground">Descuento</span>
                <span className="tabular-nums text-yellow-600">
                  -{formatAmount(parseFloat(tx.discount), config)}
                </span>
              </div>
            )}
            <div className="flex justify-between font-bold">
              <span>Total</span>
              <span className="tabular-nums text-lg">
                {formatAmount(parseFloat(tx.total), config)}
              </span>
            </div>
          </div>
        </section>

        {/* Pagos */}
        {tx.pMethods.length > 0 && (
          <section>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Pagos
            </p>
            <div className="divide-y divide-border rounded-lg border border-border">
              {tx.pMethods.map((pm, idx) => (
                <div
                  key={idx}
                  className="flex items-center justify-between px-3 py-2.5 text-sm"
                >
                  <span className="text-foreground">{pm.name}</span>
                  <span className="tabular-nums font-medium">
                    {formatAmount(pm.amount, config)}
                  </span>
                </div>
              ))}
            </div>
          </section>
        )}

        {/* Nota */}
        {tx.note && (
          <section>
            <p className="mb-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Nota
            </p>
            <p className="text-sm text-foreground">{tx.note}</p>
          </section>
        )}
      </div>
    </div>
  )
}

// ── Fila de ítem en el detalle ────────────────────────────────────────────────

function ItemRow({
  item,
  config,
}: {
  item: TransactionDataItem
  config: ReturnType<typeof useCatalogStore.getState>["config"]
}) {
  const hasDiscount = item.discount > 0
  return (
    <div className="flex items-start gap-2.5 px-3 py-2.5">
      <span
        className={cn(
          "flex size-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-semibold tabular-nums",
          hasDiscount
            ? "border-yellow-500/40 bg-yellow-500/15 text-yellow-500"
            : "border-border bg-muted/40 text-muted-foreground",
        )}
      >
        {item.count}
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium text-foreground">{item.name}</p>
        {item.note && (
          <p className="truncate text-[11px] text-muted-foreground">{item.note}</p>
        )}
        {hasDiscount && (
          <p className="text-[11px] text-yellow-600">-{Math.round(item.discount)}%</p>
        )}
      </div>
      <span className="shrink-0 text-sm font-semibold tabular-nums text-foreground">
        {formatAmount(item.total, config)}
      </span>
    </div>
  )
}
