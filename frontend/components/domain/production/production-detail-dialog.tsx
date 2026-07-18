"use client"

import * as React from "react"
import { Loader2 } from "lucide-react"
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
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { Separator } from "@/components/ui/separator"

import { useBootstrap } from "@/hooks/use-bootstrap"
import {
  useCancelProductionOrder,
  useProductionOrder,
  useStartProductionOrder,
} from "@/hooks/use-production"
import { formatMoney, formatInt } from "@/lib/format"
import { formatDateTime } from "@/lib/format-date"
import { PRODUCTION_STATUS_META } from "./status-meta"
import { CompleteProductionDialog } from "./complete-production-dialog"

interface Props {
  orderId: string
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function ProductionDetailDialog({ orderId, open, onOpenChange }: Props) {
  const { data: bootstrap } = useBootstrap()
  const { data: order, isLoading } = useProductionOrder(open ? orderId : null)
  const start = useStartProductionOrder()
  const cancel = useCancelProductionOrder()
  const [completeOpen, setCompleteOpen] = React.useState(false)

  async function handleStart() {
    if (!order) return
    try {
      await start.mutateAsync(order.id)
      toast.success("Orden iniciada")
    } catch (e) {
      toast.error("No se pudo iniciar", { description: e instanceof Error ? e.message : undefined })
    }
  }

  async function handleCancel() {
    if (!order) return
    try {
      await cancel.mutateAsync(order.id)
      toast.success("Orden cancelada")
    } catch (e) {
      toast.error("No se pudo cancelar", { description: e instanceof Error ? e.message : undefined })
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {order?.itemName ?? "Orden de producción"}
          </DialogTitle>
          {order && (
            <DialogDescription>
              Orden #{order.id.slice(0, 8)} — creada {formatDateTime(order.createdAt ?? "")}
            </DialogDescription>
          )}
        </DialogHeader>

        {isLoading || !order ? (
          <div className="flex h-32 items-center justify-center">
            <Loader2 className="size-5 animate-spin text-muted-foreground" />
          </div>
        ) : (
          <div className="space-y-4 py-2">
            <div className="flex items-center gap-2">
              <Badge variant={PRODUCTION_STATUS_META[order.status].variant}>
                {PRODUCTION_STATUS_META[order.status].label}
              </Badge>
              <span className="text-sm text-muted-foreground">
                {order.qtyPlanned} planeadas
                {order.qtyProduced !== null && <> · {order.qtyProduced} producidas</>}
                {order.qtyWaste > 0 && <> · {order.qtyWaste} con merma</>}
              </span>
            </div>

            {order.note && <p className="text-sm text-muted-foreground">{order.note}</p>}

            <Separator />

            {order.status === "completed" ? (
              <div className="grid grid-cols-2 gap-3">
                <Metric label="Costo de insumos" value={formatMoney(order.ingredientCost, bootstrap)} />
                <Metric label="Costo unitario" value={formatMoney(order.unitCogs, bootstrap)} />
                {order.recipeSnapshot && order.recipeSnapshot.length > 0 && (
                  <div className="col-span-2 space-y-1.5">
                    <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Insumos consumidos
                    </p>
                    {order.recipeSnapshot.map((line, i) => (
                      <div key={`${line.itemId}-${i}`} className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                          {formatInt(line.qty, bootstrap)} {line.tracked ? "" : "(sin control de stock)"}
                        </span>
                        <span className="tabular-nums">{formatMoney(line.lineCost, bootstrap)}</span>
                      </div>
                    ))}
                  </div>
                )}
                <Metric
                  label="Completada"
                  value={order.completedAt ? formatDateTime(order.completedAt) : "—"}
                />
              </div>
            ) : order.status === "cancelled" ? (
              <p className="text-sm text-muted-foreground">Esta orden fue cancelada.</p>
            ) : (
              <p className="text-sm text-muted-foreground">
                {order.status === "draft"
                  ? "Orden creada, todavía no iniciada."
                  : "Orden en curso — completá cuando termine el lote."}
              </p>
            )}
          </div>
        )}

        <DialogFooter className="gap-2 sm:gap-2">
          {order && order.status === "draft" && (
            <>
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="ghost" className="text-destructive hover:text-destructive">
                    Cancelar orden
                  </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>¿Cancelar esta orden?</AlertDialogTitle>
                    <AlertDialogDescription>
                      No se produjo stock todavía — se puede cancelar sin efectos en inventario.
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Volver</AlertDialogCancel>
                    <AlertDialogAction onClick={handleCancel}>Cancelar orden</AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
              <Button variant="outline" onClick={handleStart} disabled={start.isPending}>
                {start.isPending && <Loader2 className="size-4 animate-spin" />}
                Iniciar
              </Button>
              <Button onClick={() => setCompleteOpen(true)}>Completar</Button>
            </>
          )}
          {order && order.status === "in_progress" && (
            <>
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="ghost" className="text-destructive hover:text-destructive">
                    Cancelar orden
                  </Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                  <AlertDialogHeader>
                    <AlertDialogTitle>¿Cancelar esta orden?</AlertDialogTitle>
                    <AlertDialogDescription>
                      Todavía no se consumieron insumos ni se acreditó stock — se puede
                      cancelar sin efectos en inventario.
                    </AlertDialogDescription>
                  </AlertDialogHeader>
                  <AlertDialogFooter>
                    <AlertDialogCancel>Volver</AlertDialogCancel>
                    <AlertDialogAction onClick={handleCancel}>Cancelar orden</AlertDialogAction>
                  </AlertDialogFooter>
                </AlertDialogContent>
              </AlertDialog>
              <Button onClick={() => setCompleteOpen(true)}>Completar</Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>

      {order && (
        <CompleteProductionDialog open={completeOpen} onOpenChange={setCompleteOpen} order={order} />
      )}
    </Dialog>
  )
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-0.5">
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className="text-sm font-medium tabular-nums">{value}</p>
    </div>
  )
}
