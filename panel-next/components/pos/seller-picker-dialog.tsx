"use client"

import * as React from "react"
import { Search } from "lucide-react"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { EmptyState } from "@/components/empty-state"
import { useCatalogStore } from "@/lib/catalog/store"

interface SellerPickerDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSelect: (userId: string | null) => void
  currentUserId?: string
}

export function SellerPickerDialog({
  open,
  onOpenChange,
  onSelect,
  currentUserId,
}: SellerPickerDialogProps) {
  const [search, setSearch] = React.useState("")
  const users = useCatalogStore((s) => s.users)

  React.useEffect(() => {
    if (open) setSearch("")
  }, [open])

  const filtered = React.useMemo(() => {
    const q = search.trim().toLowerCase()
    if (!q) return users
    return users.filter((u) => u.name.toLowerCase().includes(q))
  }, [users, search])

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle className="text-2xl font-semibold">
            Seleccioná un vendedor
          </DialogTitle>
        </DialogHeader>
        <div className="relative mb-1">
          <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Buscar..."
            className="pl-9"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            autoFocus
          />
        </div>
        <div className="flex flex-col gap-1 py-1 max-h-72 overflow-y-auto">
          {filtered.length === 0 ? (
            <EmptyState
              icon={Search}
              title="Sin resultados"
              description={`No hay vendedores que coincidan con "${search}"`}
            />
          ) : (
            <>
              {filtered.map((u) => (
                <Button
                  key={u.id}
                  type="button"
                  variant={currentUserId === u.id ? "default" : "ghost"}
                  className="w-full justify-start gap-3"
                  onClick={() => { onSelect(u.id); onOpenChange(false) }}
                >
                  <span
                    className="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white"
                    style={{ backgroundColor: "#6b7280" }}
                  >
                    {u.name.charAt(0).toUpperCase()}
                  </span>
                  {u.name}
                </Button>
              ))}
              {currentUserId && (
                <Button
                  type="button"
                  variant="ghost"
                  className="w-full justify-start text-muted-foreground mt-1"
                  onClick={() => { onSelect(null); onOpenChange(false) }}
                >
                  Quitar asignación
                </Button>
              )}
            </>
          )}
        </div>
      </DialogContent>
    </Dialog>
  )
}
