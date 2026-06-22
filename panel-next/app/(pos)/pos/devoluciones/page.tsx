"use client"

/**
 * /pos/devoluciones — página de devoluciones del POS.
 *
 * Abre el PosReturnSheet directamente al montar la página, de modo que el
 * item del sidebar nav lleve directo al flujo de devolución sin un paso
 * intermedio. Al cerrar el Sheet, el usuario queda en esta página (puede
 * volver al POS via el logo o el nav).
 */

import * as React from "react"
import { PosReturnSheet } from "@/components/register/pos-return-sheet"
import { RotateCcw } from "lucide-react"

export default function DevolucionesPage() {
  const [open, setOpen] = React.useState(true)

  return (
    <div className="flex h-full flex-col">
      <div className="border-b px-4 py-3">
        <h2 className="flex items-center gap-2 text-sm font-semibold">
          <RotateCcw className="size-4 text-muted-foreground" />
          Devoluciones
        </h2>
      </div>

      <div className="flex flex-1 items-center justify-center text-sm text-muted-foreground">
        {!open && (
          <button
            type="button"
            className="underline underline-offset-4 hover:text-foreground"
            onClick={() => setOpen(true)}
          >
            Nueva devolución
          </button>
        )}
      </div>

      <PosReturnSheet open={open} onOpenChange={setOpen} />
    </div>
  )
}
