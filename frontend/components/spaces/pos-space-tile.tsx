"use client"

/**
 * Tile de espacio del plano operativo del POS (context/15-espacios-module-plan.md
 * F2). Read-only — a diferencia de `CanvasSpaceBlock` (editor de layout,
 * drag+resize con react-rnd), acá solo se posiciona/colorea/clickea.
 *
 * Dos modos de render, decididos por el caller según si el sector tiene
 * layout custom:
 * - `position` presente → posicionamiento absoluto (posX/posY/width/height/
 *   rotation), para el canvas del layout custom.
 * - `position` ausente → tile de tamaño fijo dentro de una grilla CSS
 *   (fallback numerado cuando el espacio no tiene layout custom).
 *
 * Color por estado semántico — mapping centralizado en
 * `lib/pos/space-state-visuals.ts` (espejo de `lib/pos/mode-visuals.ts`,
 * Regla #5 context/14-ui-conventions.md; §7 context/20). El color es el canal
 * principal de lectura a distancia de cajero: borde 2px + fondo tintado + pill
 * de tiempo en el acento sólido.
 *
 * Dentro del tile va lo MÍNIMO (nombre + color de estado + tiempo abreviado si
 * hay sesión) en cualquier tamaño — el detalle (asientos, órdenes, tiempo
 * completo) vive en un `HoverCard` read-only, mejora para desktop/mouse. En
 * tablet (touch, sin hover) el HoverCard no dispara y el tap abre el diálogo
 * de sesión, que ya trae ese detalle.
 */

import * as React from "react"
import { Clock } from "lucide-react"
import { cn } from "@/lib/utils"
import { useElapsed } from "@/hooks/use-elapsed"
import { HoverCard, HoverCardTrigger, HoverCardContent } from "@/components/ui/hover-card"
import { SPACE_STATE_VISUALS, spaceTintBg } from "@/lib/pos/space-state-visuals"
import type { SpaceWithState } from "@/hooks/use-pos-spaces"

const DECOR_SHAPES = ["decor_wall", "decor_plant", "bar"]

interface Props {
  table: SpaceWithState
  onClick: () => void
  position?: { x: number; y: number; width: number; height: number; rotation: number }
}

function formatOpenedAt(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return "—"
  return d.toLocaleTimeString("es", { hour: "2-digit", minute: "2-digit" })
}

export function PosSpaceTile({ table, onClick, position }: Props) {
  const isDecor = DECOR_SHAPES.includes(table.shape)
  const isRound = table.shape === "round"
  const disabled = table.state === "disabled" || isDecor

  const elapsed = useElapsed(table.session?.openedAt ?? null, { warnMin: 45, lateMin: 90 })

  const visual = SPACE_STATE_VISUALS[table.state]
  const accent = disabled ? null : visual.accent
  const session = table.session

  const neutralClasses =
    table.state === "disabled"
      ? "border-border bg-muted text-muted-foreground/60 cursor-not-allowed"
      : "border-border bg-card text-foreground hover:border-muted-foreground/50"

  const tile = (
    <button
      type="button"
      onClick={disabled ? undefined : onClick}
      disabled={disabled}
      style={accent ? { borderColor: accent, backgroundColor: spaceTintBg(accent) } : undefined}
      className={cn(
        "flex h-full w-full flex-col items-center justify-center gap-1 overflow-hidden border-2 p-1.5 text-center transition select-none",
        isRound ? "rounded-full" : "rounded-lg",
        isDecor
          ? "border-dashed border-muted-foreground/30 bg-muted/40 text-muted-foreground pointer-events-none"
          : accent
            ? "text-foreground hover:brightness-105"
            : neutralClasses,
      )}
    >
      {isDecor ? (
        <span className="text-[10px] uppercase tracking-wide">
          {table.shape === "bar" ? "Barra" : table.shape === "decor_wall" ? "Pared" : "Planta"}
        </span>
      ) : (
        <>
          <span className="w-full truncate text-sm leading-tight font-semibold">{table.name}</span>
          {session && (
            <span
              style={accent ? { backgroundColor: accent } : undefined}
              className={cn(
                "flex items-center gap-0.5 rounded-full px-1.5 py-px text-[10px] font-semibold text-black tabular-nums",
                !accent && "bg-foreground/10 text-foreground",
                elapsed.tier === "late" && "font-bold ring-2 ring-black/25",
              )}
            >
              <Clock className="size-3" aria-hidden />
              {elapsed.label}
            </span>
          )}
        </>
      )}
    </button>
  )

  // Decor y tiles deshabilitados no tienen detalle que mostrar ni son
  // interactivos → sin HoverCard (además, un <button disabled> no recibe
  // eventos de puntero, así que el hover no dispararía de todos modos).
  const content =
    disabled || isDecor ? (
      tile
    ) : (
      <HoverCard openDelay={150} closeDelay={80}>
        <HoverCardTrigger asChild>{tile}</HoverCardTrigger>
        <HoverCardContent align="center" className="w-56 overflow-hidden rounded-2xl p-0">
          <div
            className={cn("h-1.5 w-full", !accent && "bg-border")}
            style={accent ? { backgroundColor: accent } : undefined}
          />
          <div className="space-y-2 p-3">
            <div className="flex items-center justify-between gap-2">
              <span className="truncate text-sm font-semibold">{table.name}</span>
              <span
                className={cn("shrink-0 text-xs font-medium", !accent && "text-muted-foreground")}
                style={accent ? { color: accent } : undefined}
              >
                {visual.label}
              </span>
            </div>
            <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
              <dt className="text-muted-foreground">Asientos</dt>
              <dd className="text-right tabular-nums">{table.seats}</dd>
              {session && (
                <>
                  <dt className="text-muted-foreground">Órdenes</dt>
                  <dd className="text-right tabular-nums">{session.orderCount}</dd>
                  <dt className="text-muted-foreground">Abierta hace</dt>
                  <dd className="text-right tabular-nums">{elapsed.label}</dd>
                  {session.openedAt && (
                    <>
                      <dt className="text-muted-foreground">Desde</dt>
                      <dd className="text-right tabular-nums">{formatOpenedAt(session.openedAt)}</dd>
                    </>
                  )}
                </>
              )}
            </dl>
          </div>
        </HoverCardContent>
      </HoverCard>
    )

  if (!position) return content

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
      {content}
    </div>
  )
}
