"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { Plus } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import { KIND_META, type ItemKind } from "@/lib/types/item"
import { cn } from "@/lib/utils"

type GroupKey = "Items de venta" | "Insumos" | "Producción" | "Otros"

const GROUPS: Array<{ key: GroupKey; kinds: ItemKind[] }> = [
  { key: "Items de venta", kinds: ["producto", "servicio", "servicio_sesiones"] },
  { key: "Insumos", kinds: ["insumo_stock", "insumo_sin_stock", "insumo_control"] },
  { key: "Producción", kinds: ["produccion_directa", "produccion_previa"] },
  { key: "Otros", kinds: ["combo_fijo", "combo_dinamico", "descuento", "giftcard"] },
]

export function NewItemKindDialog() {
  const router = useRouter()
  const [open, setOpen] = React.useState(false)

  const pick = (kind: ItemKind) => {
    setOpen(false)
    router.push(`/items/new?kind=${kind}`)
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>
        <Button>
          <Plus className="size-4" />
          Nuevo artículo
        </Button>
      </DialogTrigger>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <DialogTitle>¿Qué tipo de artículo querés crear?</DialogTitle>
          <DialogDescription>
            Elegí la categoría que mejor describa lo que vas a vender o usar
            como insumo. Vas a poder editar los detalles después.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-5">
          {GROUPS.map((g) => (
            <div key={g.key} className="flex flex-col gap-2">
              <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {g.key}
              </p>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {g.kinds.map((k) => {
                  const meta = KIND_META[k]
                  return (
                    <button
                      key={k}
                      type="button"
                      onClick={() => pick(k)}
                      className={cn(
                        "flex flex-col items-start gap-1 rounded-md border bg-card p-3 text-left transition",
                        "hover:border-primary hover:bg-primary/5 hover:shadow-sm",
                        "focus:outline-none focus:ring-2 focus:ring-primary/40",
                      )}
                    >
                      <span className="text-sm font-medium leading-tight">
                        {meta.label}
                      </span>
                      <span className="text-xs leading-snug text-muted-foreground">
                        {meta.description}
                      </span>
                    </button>
                  )
                })}
              </div>
            </div>
          ))}
        </div>
      </DialogContent>
    </Dialog>
  )
}
