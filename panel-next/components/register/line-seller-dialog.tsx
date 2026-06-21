"use client"

import * as React from "react"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { Button } from "@/components/ui/button"
import { useCatalogStore } from "@/lib/catalog/store"

interface Props {
  open: boolean
  currentSellerId: string | undefined
  onSelect: (userId: string | null) => void
  onClose: () => void
}

export function LineSellerDialog({ open, currentSellerId, onSelect, onClose }: Props) {
  const users = useCatalogStore((s) => s.users)

  return (
    <Dialog open={open} onOpenChange={(v) => { if (!v) onClose() }}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>Asignar usuario</DialogTitle>
        </DialogHeader>
        <div className="flex flex-col gap-2 py-2">
          {users.length === 0 && (
            <p className="text-sm text-muted-foreground text-center py-4">
              No hay usuarios activos
            </p>
          )}
          {users.map((u) => (
            <Button
              key={u.id}
              variant={currentSellerId === u.id ? "default" : "outline"}
              className="justify-start"
              onClick={() => { onSelect(u.id); onClose() }}
            >
              {u.name}
            </Button>
          ))}
          {currentSellerId && (
            <Button
              variant="ghost"
              className="justify-start text-muted-foreground mt-1"
              onClick={() => { onSelect(null); onClose() }}
            >
              Quitar asignación
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
