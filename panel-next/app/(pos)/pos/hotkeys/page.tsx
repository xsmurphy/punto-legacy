"use client"

/**
 * Hotkeys — grilla de accesos rápidos a productos.
 *
 * Legacy: el FAB "modulesMenu → HotKeys" (#selectCategory&i=all) abría una
 * grilla de botones rápidos configurables. Acá es una ruta propia del POS.
 *
 * Layout: 6 columnas, 50 filas por defecto (300 slots). Tiles cuadrados
 * (aspect-square) — con 6 por fila quedan compactos y la grilla scrollea
 * vertical. Los slots vacíos son placeholders hasta que se configure cada
 * hotkey (asignación producto→slot: TODO fase siguiente).
 */

import { Plus } from "lucide-react"
import { cn } from "@/lib/utils"

const COLS = 6
const ROWS = 50
const SLOTS = COLS * ROWS

export default function HotkeysPage() {
  return (
    <div className="flex h-full w-full flex-col overflow-hidden">
      <div className="flex-1 overflow-y-auto p-3">
        <div
          className="grid gap-2"
          style={{ gridTemplateColumns: `repeat(${COLS}, minmax(0, 1fr))` }}
        >
          {Array.from({ length: SLOTS }).map((_, i) => (
            <HotkeySlot key={i} index={i} />
          ))}
        </div>
      </div>
    </div>
  )
}

function HotkeySlot({ index }: { index: number }) {
  return (
    <button
      type="button"
      aria-label={`Hotkey ${index + 1} — vacío`}
      className={cn(
        "group relative flex aspect-square items-center justify-center rounded-xl border border-dashed border-border/60 bg-muted/30 transition-colors",
        "hover:border-border hover:bg-muted/60",
      )}
      onClick={() => {
        // TODO: asignar producto a este slot (picker producto → hotkey).
      }}
    >
      <Plus className="size-5 text-muted-foreground/40 transition-colors group-hover:text-muted-foreground" />
    </button>
  )
}
