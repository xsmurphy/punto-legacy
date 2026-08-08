"use client"
import * as React from "react"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { formatMoney } from "@/lib/format-money"
import { useCatalogStore } from "@/lib/catalog/store"
import type { PosItem } from "@/lib/types/pos-bootstrap"

interface Props {
  group: PosItem | null
  items: PosItem[]
  onClose: () => void
  onPick: (item: PosItem) => void
}

/**
 * Selector de artículo dentro de un grupo/variante.
 *
 * Listado vertical, NO grilla de tiles: las variantes de un mismo producto
 * comparten prefijo ("EP Carne", "EP 4 Quesos"…) y en tiles quedaban con
 * anchos desparejos y el precio suelto abajo — ruido para escanear. En filas
 * el nombre arranca siempre en la misma x y los precios quedan alineados a la
 * derecha, que es lo que el cajero compara (reporte del owner 2026-08-08).
 */
export function GroupItemsDialog({ group, items, onClose, onPick }: Props) {
  const config = useCatalogStore((s) => s.config)
  return (
    <Dialog open={!!group} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{group?.name ?? ""}</DialogTitle>
        </DialogHeader>
        {/* Alto acotado + scroll: un grupo con muchas variantes no debe
            empujar el diálogo fuera del viewport de la tablet. */}
        <div className="-mx-1 max-h-[60vh] space-y-1 overflow-y-auto px-1">
          {items.map((it) => (
            <Button
              key={it.id}
              variant="outline"
              className="h-12 w-full justify-between gap-3 px-3 text-left"
              onClick={() => { onPick(it); onClose() }}
            >
              <span className="min-w-0 flex-1 truncate text-sm font-medium">{it.name}</span>
              <span className="shrink-0 text-sm tabular-nums text-muted-foreground">
                {formatMoney(it.price, config)}
              </span>
            </Button>
          ))}
          {items.length === 0 && (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Este grupo no tiene artículos asociados.
            </p>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
