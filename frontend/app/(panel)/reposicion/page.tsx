"use client"

import * as React from "react"
import Link from "next/link"
import type { ColumnDef } from "@tanstack/react-table"
import { ArrowLeftRight, Factory, Loader2, PackageCheck, XCircle } from "lucide-react"
import { toast } from "sonner"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { Textarea } from "@/components/ui/textarea"
import { DataTable, FilterField } from "@/components/data-table/data-table"
import { RowActions, type RowAction } from "@/components/data-table/row-actions"
import { EmptyState } from "@/components/empty-state"

import { useBootstrap } from "@/hooks/use-bootstrap"
import { useOutlets } from "@/hooks/use-outlets"
import { usePermission } from "@/hooks/use-permissions"
import {
  useCloseReplenishment,
  useProduceReplenishment,
  useReplenishmentNeeds,
  useTransferReplenishment,
  type ReplenishmentCoverage,
  type ReplenishmentNeed,
  type ReplenishmentOrigin,
  type ReplenishmentStatus,
} from "@/hooks/use-replenishment-needs"
import { formatDateTime } from "@/lib/format-date"
import { formatQty } from "@/lib/format-qty"
import type { TenantLocaleConfig } from "@/lib/tenant-locale"

const ALL = "__all__"

const STATUS_LABEL: Record<ReplenishmentStatus, string> = {
  open: "Abierta",
  covered: "Cubierta",
  closed: "Cerrada",
}

const STATUS_VARIANT: Record<ReplenishmentStatus, "default" | "secondary" | "outline"> = {
  open: "default",
  covered: "secondary",
  closed: "outline",
}

const ORIGIN_LABEL: Record<ReplenishmentOrigin, string> = {
  min_stock: "Stock mínimo",
  count_panel: "Conteo",
  count_register: "Conteo en caja",
}

const SOURCE_STATUS_LABEL: Record<string, string> = {
  draft: "Borrador",
  in_progress: "En curso",
  completed: "Completada",
  cancelled: "Cancelada",
  done: "Realizada",
}

type Action = { kind: "produce" | "transfer" | "close"; need: ReplenishmentNeed }

function parseQty(value: string): number {
  return Number(value.replace(",", "."))
}

export default function ReposicionPage() {
  const { data: bootstrap } = useBootstrap()
  const { data: outletsData } = useOutlets()
  const outlets = outletsData?.rows ?? []

  const canProduce = usePermission("production.manage")
  const canTransfer = usePermission("inventory.transfer")

  const [status, setStatus] = React.useState<ReplenishmentStatus | typeof ALL>("open")
  const [outletId, setOutletId] = React.useState<string>(ALL)

  const { data: needs = [], isLoading } = useReplenishmentNeeds({
    status: status === ALL ? undefined : status,
    outletId: outletId === ALL ? undefined : outletId,
  })

  const [detailId, setDetailId] = React.useState<string | null>(null)
  const [action, setAction] = React.useState<Action | null>(null)
  const detail = needs.find((n) => n.needId === detailId) ?? null

  const activeFilterCount = (status !== ALL ? 1 : 0) + (outletId !== ALL ? 1 : 0)
  const clearFilters = () => {
    setStatus(ALL)
    setOutletId(ALL)
  }

  const actionsFor = React.useCallback(
    (need: ReplenishmentNeed): RowAction[] => {
      const open = need.status === "open"
      return [
        {
          label: "Producir",
          icon: Factory,
          onSelect: () => setAction({ kind: "produce", need }),
          hidden: !open || !canProduce,
          disabled: !need.producible || need.pending <= 0,
          reason: !need.producible ? "No tiene receta" : "Ya hay órdenes en curso por lo pendiente",
        },
        {
          label: "Transferir",
          icon: ArrowLeftRight,
          onSelect: () => setAction({ kind: "transfer", need }),
          hidden: !open || !canTransfer,
          disabled: need.pending <= 0,
          reason: "Ya hay órdenes en curso por lo pendiente",
        },
        {
          label: "Cerrar",
          icon: XCircle,
          variant: "destructive",
          onSelect: () => setAction({ kind: "close", need }),
          hidden: need.status === "closed" || (!canProduce && !canTransfer),
        },
      ]
    },
    [canProduce, canTransfer],
  )

  const columns = React.useMemo(
    () => buildColumns(bootstrap, actionsFor),
    [bootstrap, actionsFor],
  )

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Reposición</h1>
        <p className="text-sm text-muted-foreground">
          Artículos que llegaron a su stock mínimo, listos para producir o transferir.
        </p>
      </header>

      {!isLoading && needs.length === 0 && activeFilterCount === 0 ? (
        <EmptyState
          icon={PackageCheck}
          title="Sin reposiciones"
          description="Definí el stock mínimo y la cantidad a reponer en la ficha del artículo."
        />
      ) : (
        <DataTable
          tableId="replenishment-needs"
          columns={columns}
          data={needs}
          isLoading={isLoading}
          getRowId={(r) => r.needId}
          onRowClick={(r) => setDetailId(r.needId)}
          searchPlaceholder="Buscar por artículo…"
          exportFileName="reposicion"
          activeFilterCount={activeFilterCount}
          onClearFilters={clearFilters}
          filtersSlot={
            <>
              <FilterField label="Estado">
                <Select value={status} onValueChange={(v) => setStatus(v as ReplenishmentStatus | typeof ALL)}>
                  <SelectTrigger>
                    <SelectValue placeholder="Todos" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL}>Todos</SelectItem>
                    <SelectItem value="open">Abiertas</SelectItem>
                    <SelectItem value="covered">Cubiertas</SelectItem>
                    <SelectItem value="closed">Cerradas</SelectItem>
                  </SelectContent>
                </Select>
              </FilterField>
              <FilterField label="Sucursal">
                <Select value={outletId} onValueChange={setOutletId}>
                  <SelectTrigger>
                    <SelectValue placeholder="Todas" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={ALL}>Todas</SelectItem>
                    {outlets.map((o) => (
                      <SelectItem key={o.id} value={o.id}>
                        {o.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </FilterField>
            </>
          }
        />
      )}

      <NeedDetailDialog
        need={detail}
        config={bootstrap}
        actions={detail ? actionsFor(detail) : []}
        onOpenChange={(open) => !open && setDetailId(null)}
      />

      <ProduceDialog
        need={action?.kind === "produce" ? action.need : null}
        config={bootstrap}
        onClose={() => setAction(null)}
      />
      <TransferDialog
        need={action?.kind === "transfer" ? action.need : null}
        config={bootstrap}
        outlets={outlets}
        onClose={() => setAction(null)}
      />
      <CloseDialog need={action?.kind === "close" ? action.need : null} onClose={() => setAction(null)} />
    </div>
  )
}

function buildColumns(
  config: TenantLocaleConfig | null | undefined,
  actionsFor: (need: ReplenishmentNeed) => RowAction[],
): ColumnDef<ReplenishmentNeed>[] {
  return [
    {
      accessorKey: "createdAt",
      header: "Fecha",
      cell: ({ row }) => (
        <span className="whitespace-nowrap tabular-nums text-sm">{formatDateTime(row.original.createdAt)}</span>
      ),
    },
    {
      accessorKey: "itemName",
      header: "Artículo",
      cell: ({ row }) => <span className="font-medium">{row.original.itemName}</span>,
    },
    { accessorKey: "outletName", header: "Sucursal" },
    {
      accessorKey: "origin",
      header: "Origen",
      cell: ({ row }) => ORIGIN_LABEL[row.original.origin] ?? row.original.origin,
    },
    {
      accessorKey: "quantity",
      header: "A reponer",
      cell: ({ row }) => <span className="tabular-nums">{formatQty(row.original.quantity, config)}</span>,
    },
    {
      accessorKey: "covered",
      header: "Cubierto",
      cell: ({ row }) => <span className="tabular-nums">{formatQty(row.original.covered, config)}</span>,
    },
    {
      accessorKey: "pending",
      header: "Pendiente",
      cell: ({ row }) => (
        <span className="tabular-nums">
          {row.original.status === "open" ? formatQty(row.original.pending, config) : "—"}
        </span>
      ),
    },
    {
      accessorKey: "status",
      header: "Estado",
      cell: ({ row }) => (
        <Badge variant={STATUS_VARIANT[row.original.status]}>{STATUS_LABEL[row.original.status]}</Badge>
      ),
    },
    {
      id: "actions",
      header: "",
      enableSorting: false,
      cell: ({ row }) => (
        <div className="flex justify-end" onClick={(e) => e.stopPropagation()}>
          <RowActions actions={actionsFor(row.original)} />
        </div>
      ),
    },
  ]
}

function coverageHref(c: ReplenishmentCoverage): string {
  return c.sourceType === "stock_transfer" ? `/stock-transfer/${c.sourceId}` : "/produccion"
}

function NeedDetailDialog({
  need,
  config,
  actions,
  onOpenChange,
}: {
  need: ReplenishmentNeed | null
  config: TenantLocaleConfig | null | undefined
  actions: RowAction[]
  onOpenChange: (open: boolean) => void
}) {
  const visible = actions.filter((a) => !a.hidden)

  return (
    <Dialog open={!!need} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        {need && (
          <>
            <DialogHeader>
              <DialogTitle>{need.itemName}</DialogTitle>
              <DialogDescription>
                {need.outletName} · {ORIGIN_LABEL[need.origin]} · {formatDateTime(need.createdAt)}
              </DialogDescription>
            </DialogHeader>

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
              <Stat label="A reponer" value={formatQty(need.quantity, config)} />
              <Stat label="Cubierto" value={formatQty(need.covered, config)} />
              <Stat label="En curso" value={formatQty(need.inFlight, config)} />
              <Stat
                label="Pendiente"
                value={need.status === "open" ? formatQty(need.pending, config) : "—"}
              />
            </div>

            {need.status === "closed" && need.closeReason && (
              <div className="flex flex-col gap-1 text-sm">
                <span className="text-muted-foreground">
                  Cerrada{need.closedByName ? ` por ${need.closedByName}` : ""}
                </span>
                <span>{need.closeReason}</span>
              </div>
            )}

            {need.coverages.length > 0 && (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Documento</TableHead>
                    <TableHead>Estado</TableHead>
                    <TableHead className="text-right">Pedido</TableHead>
                    <TableHead className="text-right">Cubierto</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {need.coverages.map((c) => (
                    <TableRow key={c.coverageId}>
                      <TableCell>
                        <Link href={coverageHref(c)} className="hover:underline">
                          {c.sourceType === "stock_transfer" ? "Transferencia" : "Producción"}
                          {c.docNumber != null ? ` Nº ${c.docNumber}` : ""}
                        </Link>
                      </TableCell>
                      <TableCell>{SOURCE_STATUS_LABEL[c.sourceStatus] ?? c.sourceStatus}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatQty(c.quantity, config)}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatQty(c.effective, config)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}

            {visible.length > 0 && (
              <DialogFooter>
                {visible.map((a) => (
                  <Button
                    key={a.label}
                    variant={a.variant === "destructive" ? "outline" : "default"}
                    disabled={a.disabled}
                    title={a.disabled ? a.reason : undefined}
                    onClick={() => {
                      onOpenChange(false)
                      a.onSelect?.()
                    }}
                  >
                    <a.icon />
                    {a.label}
                  </Button>
                ))}
              </DialogFooter>
            )}
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="text-lg font-semibold tabular-nums">{value}</span>
    </div>
  )
}

function ProduceDialog({
  need,
  config,
  onClose,
}: {
  need: ReplenishmentNeed | null
  config: TenantLocaleConfig | null | undefined
  onClose: () => void
}) {
  const produce = useProduceReplenishment()
  const [qty, setQty] = React.useState("")

  React.useEffect(() => {
    if (need) setQty(String(need.pending))
  }, [need])

  const qtyNum = parseQty(qty)
  const valid = Number.isFinite(qtyNum) && qtyNum > 0

  async function submit() {
    if (!need || !valid) return
    try {
      await produce.mutateAsync({ id: need.needId, qty: qtyNum })
      toast.success("Orden de producción creada")
      onClose()
    } catch (e) {
      toast.error("No se pudo crear la orden", { description: e instanceof Error ? e.message : undefined })
    }
  }

  return (
    <Dialog open={!!need} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Producir</DialogTitle>
          <DialogDescription>
            {need ? `${need.itemName} · ${need.outletName}` : ""}
          </DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-3">
          <Label htmlFor="produce-qty">Cantidad</Label>
          <Input
            id="produce-qty"
            type="number"
            min="0"
            step="any"
            inputMode="decimal"
            className="tabular-nums"
            value={qty}
            onChange={(e) => setQty(e.target.value)}
            autoFocus
          />
          {need && (
            <span className="text-xs text-muted-foreground">
              Pendiente {formatQty(need.pending, config)}
            </span>
          )}
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={produce.isPending}>
            Cancelar
          </Button>
          <Button onClick={submit} disabled={!valid || produce.isPending}>
            {produce.isPending && <Loader2 className="size-4 animate-spin" />}
            Crear orden
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function TransferDialog({
  need,
  config,
  outlets,
  onClose,
}: {
  need: ReplenishmentNeed | null
  config: TenantLocaleConfig | null | undefined
  outlets: { id: string; name: string }[]
  onClose: () => void
}) {
  const transfer = useTransferReplenishment()
  const [qty, setQty] = React.useState("")
  const [fromOutletId, setFromOutletId] = React.useState("")

  React.useEffect(() => {
    if (need) {
      setQty(String(need.pending))
      setFromOutletId("")
    }
  }, [need])

  const origins = outlets.filter((o) => o.id !== need?.outletId)
  const qtyNum = parseQty(qty)
  const valid = Number.isFinite(qtyNum) && qtyNum > 0 && fromOutletId !== ""

  async function submit() {
    if (!need || !valid) return
    try {
      await transfer.mutateAsync({
        id: need.needId,
        fromOutletId,
        fromLocationId: null,
        toLocationId: null,
        qty: qtyNum,
      })
      toast.success("Transferencia realizada")
      onClose()
    } catch (e) {
      toast.error("No se pudo transferir", { description: e instanceof Error ? e.message : undefined })
    }
  }

  return (
    <Dialog open={!!need} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Transferir</DialogTitle>
          <DialogDescription>
            {need ? `${need.itemName} → ${need.outletName}` : ""}
          </DialogDescription>
        </DialogHeader>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="flex flex-col gap-3">
            <Label>Desde</Label>
            <Select value={fromOutletId} onValueChange={setFromOutletId}>
              <SelectTrigger>
                <SelectValue placeholder="Elegí la sucursal" />
              </SelectTrigger>
              <SelectContent>
                {origins.map((o) => (
                  <SelectItem key={o.id} value={o.id}>
                    {o.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-col gap-3">
            <Label htmlFor="transfer-qty">Cantidad</Label>
            <Input
              id="transfer-qty"
              type="number"
              min="0"
              step="any"
              inputMode="decimal"
              className="tabular-nums"
              value={qty}
              onChange={(e) => setQty(e.target.value)}
            />
            {need && (
              <span className="text-xs text-muted-foreground">
                Pendiente {formatQty(need.pending, config)}
              </span>
            )}
          </div>
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={transfer.isPending}>
            Cancelar
          </Button>
          <Button onClick={submit} disabled={!valid || transfer.isPending}>
            {transfer.isPending && <Loader2 className="size-4 animate-spin" />}
            Transferir
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function CloseDialog({ need, onClose }: { need: ReplenishmentNeed | null; onClose: () => void }) {
  const close = useCloseReplenishment()
  const [reason, setReason] = React.useState("")

  React.useEffect(() => {
    if (need) setReason("")
  }, [need])

  const valid = reason.trim() !== ""

  async function submit() {
    if (!need || !valid) return
    try {
      await close.mutateAsync({ id: need.needId, reason: reason.trim() })
      toast.success("Reposición cerrada")
      onClose()
    } catch (e) {
      toast.error("No se pudo cerrar", { description: e instanceof Error ? e.message : undefined })
    }
  }

  return (
    <Dialog open={!!need} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Cerrar reposición</DialogTitle>
          <DialogDescription>
            {need ? `${need.itemName} · ${need.outletName}` : ""}
          </DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-3">
          <Label htmlFor="close-reason">Motivo</Label>
          <Textarea
            id="close-reason"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            autoFocus
          />
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={close.isPending}>
            Cancelar
          </Button>
          <Button variant="destructive" onClick={submit} disabled={!valid || close.isPending}>
            {close.isPending && <Loader2 className="size-4 animate-spin" />}
            Cerrar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
