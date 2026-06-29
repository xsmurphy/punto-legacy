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

export function GroupItemsDialog({ group, items, onClose, onPick }: Props) {
  const config = useCatalogStore((s) => s.config)
  return (
    <Dialog open={!!group} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>{group?.name ?? ""}</DialogTitle>
        </DialogHeader>
        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
          {items.map((it) => (
            <Button
              key={it.id}
              variant="outline"
              className="h-auto flex flex-col items-start gap-1 p-3 text-left"
              onClick={() => { onPick(it); onClose() }}
            >
              <span className="text-sm font-medium line-clamp-2">{it.name}</span>
              <span className="text-xs text-muted-foreground tabular-nums">
                {formatMoney(it.price, config)}
              </span>
            </Button>
          ))}
          {items.length === 0 && (
            <p className="col-span-full text-center text-sm text-muted-foreground py-8">
              Este grupo no tiene artículos asociados.
            </p>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
