"use client"

import * as React from "react"
import { Check, Loader2 } from "lucide-react"
import { toast } from "sonner"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogBody,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useUpdateItemPrice } from "@/hooks/use-items"
import type { MarginAlertRow } from "@/hooks/use-purchases"
import { formatMoney } from "@/lib/format"
import { resolveNumberLocale } from "@/lib/tenant-locale"

type RowState = "pending" | "applying" | "applied"

/**
 * "N artículos quedaron bajo tu margen" — se muestra al guardar una compra
 * (alta manual o aprobación de borrador) cuando el backend devolvió
 * `marginAlerts`. Los números vienen calculados del servidor (`MarginAlert`);
 * acá solo se muestran y, si el usuario quiere, se aplica el precio sugerido
 * por el PUT normal del artículo (`useUpdateItemPrice`).
 *
 * Solo el precio base: las listas de precio no se tocan.
 *
 * `onDone` corre al cerrar (con o sin cambios): el caller navega recién ahí,
 * así el diálogo no desaparece con la página.
 */
export function MarginAlertDialog({
  alerts,
  onDone,
}: {
  alerts: MarginAlertRow[]
  onDone: () => void
}) {
  const { data: bootstrap } = useBootstrap()
  const updatePrice = useUpdateItemPrice()
  const [states, setStates] = React.useState<Record<string, RowState>>({})
  const [bulkRunning, setBulkRunning] = React.useState(false)

  const percent = React.useMemo(
    () =>
      new Intl.NumberFormat(resolveNumberLocale(bootstrap), {
        maximumFractionDigits: 1,
      }),
    [bootstrap],
  )

  const pending = alerts.filter((a) => (states[a.itemId] ?? "pending") === "pending")
  const busy = bulkRunning || alerts.some((a) => states[a.itemId] === "applying")

  const apply = async (row: MarginAlertRow): Promise<boolean> => {
    setStates((s) => ({ ...s, [row.itemId]: "applying" }))
    try {
      await updatePrice.mutateAsync({ itemId: row.itemId, price: row.suggestedPrice })
      setStates((s) => ({ ...s, [row.itemId]: "applied" }))
      return true
    } catch {
      setStates((s) => ({ ...s, [row.itemId]: "pending" }))
      return false
    }
  }

  const applyOne = async (row: MarginAlertRow) => {
    if (!(await apply(row))) {
      toast.error(`No se pudo cambiar el precio de ${row.name}. Probá de nuevo.`)
    }
  }

  const applyAll = async () => {
    setBulkRunning(true)
    let failed = 0
    // En serie: son pocos artículos y así no se disparan N escrituras a la vez
    // contra el mismo catálogo.
    for (const row of pending) {
      if (!(await apply(row))) failed++
    }
    setBulkRunning(false)
    if (failed > 0) {
      toast.error(
        failed === 1
          ? "Un precio no se pudo cambiar. Probá de nuevo."
          : `${failed} precios no se pudieron cambiar. Probá de nuevo.`,
      )
    } else {
      toast.success("Precios actualizados")
    }
  }

  const title =
    alerts.length === 1
      ? "1 artículo quedó bajo tu margen"
      : `${alerts.length} artículos quedaron bajo tu margen`

  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) onDone()
      }}
    >
      <DialogContent sectioned className="max-h-[85vh] sm:max-w-4xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>
            La compra subió el costo de estos artículos. Podés aplicar el precio sugerido o dejarlos como están.
          </DialogDescription>
        </DialogHeader>
        <DialogBody>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Artículo</TableHead>
                <TableHead className="text-right">Costo nuevo</TableHead>
                <TableHead className="text-right">Precio actual</TableHead>
                <TableHead className="text-right">Margen actual</TableHead>
                <TableHead className="text-right">Precio sugerido</TableHead>
                <TableHead className="w-28" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {alerts.map((row) => {
                const state = states[row.itemId] ?? "pending"
                return (
                  <TableRow key={row.itemId}>
                    <TableCell className="font-medium">{row.name}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatMoney(row.cost, bootstrap)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatMoney(row.price, bootstrap)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-destructive">
                      {percent.format(row.marginPct)}%
                    </TableCell>
                    <TableCell className="text-right tabular-nums font-medium">
                      {formatMoney(row.suggestedPrice, bootstrap)}
                    </TableCell>
                    <TableCell className="text-right">
                      {state === "applied" ? (
                        <span className="inline-flex items-center gap-1 text-sm text-muted-foreground">
                          <Check className="size-4" />
                          Aplicado
                        </span>
                      ) : (
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={busy}
                          onClick={() => applyOne(row)}
                        >
                          {state === "applying" && <Loader2 className="size-4 animate-spin" />}
                          Aplicar
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
        </DialogBody>
        <DialogFooter>
          <Button type="button" variant="outline" disabled={busy} onClick={onDone}>
            Cerrar
          </Button>
          <Button type="button" disabled={busy || pending.length === 0} onClick={applyAll}>
            {bulkRunning && <Loader2 className="size-4 animate-spin" />}
            Aplicar todos
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
