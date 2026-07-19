"use client"

/**
 * Tile de mesa del plano operativo del POS (context/15-mesas-module-plan.md
 * F2). Read-only — a diferencia de `CanvasTableBlock` (editor de layout,
 * drag+resize con react-rnd), acá solo se posiciona/colorea/clickea.
 *
 * Dos modos de render, decididos por el caller según si el sector tiene
 * layout custom:
 * - `position` presente → posicionamiento absoluto (posX/posY/width/height/
 *   rotation), para el canvas del layout custom.
 * - `position` ausente → tile de tamaño fijo dentro de una grilla CSS
 *   (fallback numerado cuando la mesa no tiene layout custom, F1 "toggle
 *   Layout/Grilla").
 *
 * Color por estado semántico (Regla #5, context/14-ui-conventions.md):
 * libre = neutro (card/border), ocupada = primary, pagando = ámbar
 * (mismo amber-500 que el chip de sync pendiente en cart-panel.tsx),
 * deshabilitada = muted, no clickeable.
 */

import * as React from "react"
import { Users, Clock } from "lucide-react"
import { cn } from "@/lib/utils"
import { useElapsed } from "@/hooks/use-elapsed"
import type { DiningTableWithState } from "@/hooks/use-pos-tables"

const DECOR_SHAPES = ["decor_wall", "decor_plant", "bar"]

interface Props {
  table: DiningTableWithState
  onClick: () => void
  position?: { x: number; y: number; width: number; height: number; rotation: number }
}

export function PosTableTile({ table, onClick, position }: Props) {
  const isDecor = DECOR_SHAPES.includes(table.shape)
  const isRound = table.shape === "round"
  const disabled = table.state === "disabled" || isDecor

  const elapsed = useElapsed(table.session?.openedAt ?? null, { warnMin: 45, lateMin: 90 })

  const stateClasses: Record<DiningTableWithState["state"], string> = {
    free: "border-border bg-card text-foreground hover:border-muted-foreground/50",
    occupied: "border-primary bg-primary/10 text-foreground hover:bg-primary/15",
    bill_requested: "border-amber-500 bg-amber-500/10 text-foreground hover:bg-amber-500/15",
    disabled: "border-border bg-muted text-muted-foreground/60 cursor-not-allowed",
  }

  const tile = (
    <button
      type="button"
      onClick={disabled ? undefined : onClick}
      disabled={disabled}
      className={cn(
        "flex h-full w-full flex-col items-center justify-center gap-1 border-2 p-1.5 text-center transition-colors select-none",
        isRound ? "rounded-full" : "rounded-lg",
        isDecor
          ? "border-dashed border-muted-foreground/30 bg-muted/40 text-muted-foreground pointer-events-none"
          : stateClasses[table.state],
      )}
    >
      {isDecor ? (
        <span className="text-[10px] uppercase tracking-wide">{table.shape === "bar" ? "Barra" : table.shape === "decor_wall" ? "Pared" : "Planta"}</span>
      ) : (
        <>
          <span className="text-sm font-semibold">{table.name}</span>
          <span className="flex items-center gap-1 text-[10px] text-muted-foreground">
            <Users className="size-3" aria-hidden />
            {table.seats}
          </span>
          {table.session && (
            <div className="flex items-center gap-1.5 text-[10px] font-medium">
              {table.session.orderCount > 0 && (
                <span className="rounded-full bg-foreground/10 px-1.5 py-px tabular-nums">
                  {table.session.orderCount} orden{table.session.orderCount !== 1 ? "es" : ""}
                </span>
              )}
              <span className="flex items-center gap-0.5 text-muted-foreground">
                <Clock className="size-3" aria-hidden />
                {elapsed.label}
              </span>
            </div>
          )}
        </>
      )}
    </button>
  )

  if (!position) return tile

  return (
    <div
      className="absolute"
      style={{
        left: position.x,
        top: position.y,
        width: position.width,
        height: position.height,
        transform: `rotate(${position.rotation}deg)`,
      }}
    >
      {tile}
    </div>
  )
}
