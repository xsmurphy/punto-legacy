"use client"

/**
 * Dialog rápido para abrir una mesa libre (context/15-mesas-module-plan.md
 * F2). Comensales opcional — el owner lo pidió explícitamente opcional, así
 * que confirma sin bloquear si queda vacío.
 */

import * as React from "react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import type { DiningTableWithState } from "@/hooks/use-pos-tables"

interface Props {
  table: DiningTableWithState | null
  onOpenChange: (open: boolean) => void
  onConfirm: (guests: number | undefined) => void
  submitting: boolean
}

export function OpenTableDialog({ table, onOpenChange, onConfirm, submitting }: Props) {
  const [guests, setGuests] = React.useState("")

  React.useEffect(() => {
    if (table) setGuests("")
  }, [table])

  return (
    <Dialog open={table !== null} onOpenChange={(v) => !v && onOpenChange(false)}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>Abrir Mesa {table?.name}</DialogTitle>
        </DialogHeader>
        <div className="grid gap-2 py-2">
          <Label htmlFor="table-guests">Comensales (opcional)</Label>
          <Input
            id="table-guests"
            type="number"
            inputMode="numeric"
            min={1}
            placeholder="Cantidad de personas"
            value={guests}
            onChange={(e) => setGuests(e.target.value)}
            autoFocus
          />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
            Cancelar
          </Button>
          <Button
            onClick={() => onConfirm(guests.trim() === "" ? undefined : Number(guests))}
            disabled={submitting}
          >
            {submitting ? "Abriendo..." : "Abrir mesa"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
