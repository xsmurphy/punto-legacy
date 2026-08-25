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
import { resolveColorBg } from "@/lib/ui/color-palette"
import { useCatalogStore } from "@/lib/catalog/store"
import { SpaceActionsMenu, type SpaceTileActions } from "@/components/spaces/space-actions-menu"
import type { SpaceWithState } from "@/hooks/use-pos-spaces"

const DECOR_SHAPES = ["decor_wall", "decor_plant", "bar"]

interface Props {
  table: SpaceWithState
  onClick: () => void
  position?: { x: number; y: number; width: number; height: number; rotation: number }
  /**
   * Handlers del menú de tres puntos. Ausente = tile sin menú (el editor de
   * layout y cualquier uso read-only siguen funcionando igual que antes).
   *
   * Es UN objeto y no seis props porque el caller lo arma una sola vez con
   * `useMemo` y lo pasa a los dos call-sites (mapa y grilla): seis funciones
   * inline por tile se recrearían en cada repintado de la pantalla, que en este
   * módulo ocurre con cada evento de realtime.
   */
  actions?: SpaceTileActions
}

function formatOpenedAt(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return "—"
  return d.toLocaleTimeString("es", { hour: "2-digit", minute: "2-digit" })
}

export function PosSpaceTile({ table, onClick, position, actions }: Props) {
  const isDecor = DECOR_SHAPES.includes(table.shape)
  // La forma física (redonda) solo aplica en el MAPA (position presente) —
  // en la grilla numerada todos los tiles son uniformes (rounded-lg): la
  // forma es información espacial del plano, no del listado.
  const isRound = table.shape === "round" && position !== undefined
  const disabled = table.state === "disabled" || isDecor

  const elapsed = useElapsed(table.session?.openedAt ?? null, { warnMin: 45, lateMin: 90 })

  const visual = SPACE_STATE_VISUALS[table.state]
  const accent = disabled ? null : visual.accent
  // Color de demora del pill del tiempo. Canal SEPARADO del estado
  // (context/27 §A.4): el fondo/borde del tile dice el estado, el pill dice
  // si va en hora. Por eso amber/rose están reservados y no se usan para
  // estados de espacio.
  const tierAccent = disabled
    ? null
    : elapsed.tier === "late"
      ? resolveColorBg("rose")
      : elapsed.tier === "warn"
        ? resolveColorBg("amber")
        : null
  const session = table.session

  // Nombre del mozo desde los usuarios ya precacheados en el bootstrap — el
  // payload del mapa trae el `waiterId` crudo y resolverlo con una request por
  // tile sería un N+1 sobre una pantalla que se repinta con cada evento de
  // realtime.
  const users = useCatalogStore((s) => s.users)
  const waiterName = React.useMemo(() => {
    if (!session?.waiterId) return null
    return users.find((u) => u.id === session.waiterId)?.name ?? null
  }, [session, users])

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
          {/* Grilla: el número/nombre es EL identificador — grande para leerse
              de un vistazo a distancia de cajero. En el mapa los tiles pueden
              ser chicos (70px) → tamaño contenido. */}
          <span
            className={cn(
              "w-full truncate leading-tight font-semibold",
              position ? "text-sm" : "text-3xl font-bold tabular-nums",
            )}
          >
            {table.name}
          </span>
          {/* Alias de la ocupación ("los del cumpleaños", mig 163). Solo en la
              grilla: en el mapa los tiles bajan a 70px y una tercera línea de
              texto no se lee. El propósito del alias es reconocer la mesa SIN
              abrirla, así que tiene que estar en el tile y no solo en el
              HoverCard (que además no dispara en tablet, que es donde se opera).
              `truncate` acota siempre a una línea: un alias largo no puede
              cambiar la altura del tile ni empujar el pill del tiempo. */}
          {session?.alias && !position && (
            <span className="w-full truncate text-[10px] leading-tight font-medium opacity-80">
              {session.alias}
            </span>
          )}
          {/* Timer solo en la grilla (position ausente): la señal completa
              vive en el HoverCard al pasar el mouse — el mapa queda visualmente
              limpio y el número/estado ya son suficiente identificación.
              Decisión owner 2026-07-19. */}
          {session && !position && (
            <span
              // El pill del tiempo ES el canal de demora (context/27 §A.4):
              // fresh toma el acento del estado, warn/late toman su propio
              // color. Antes la demora se marcaba con un `ring` gris encima
              // del acento — ruido visual que no comunicaba nada a distancia,
              // que es donde esta pantalla se lee.
              style={{ backgroundColor: tierAccent ?? accent ?? undefined }}
              className={cn(
                "flex items-center gap-0.5 rounded-full px-1.5 py-px text-[10px] font-semibold text-black tabular-nums",
                !tierAccent && !accent && "bg-foreground/10 text-foreground",
                elapsed.tier === "late" && "font-bold",
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
            {session?.alias && (
              <p className="truncate text-sm font-semibold">{session.alias}</p>
            )}
            <div className="flex items-center justify-between gap-2">
              <span
                className={cn(
                  "truncate text-sm font-semibold",
                  // Con alias, el nombre del espacio pasa a ser la referencia
                  // secundaria — el alias de arriba es cómo la llama el mozo.
                  session?.alias && "font-normal text-muted-foreground",
                )}
              >
                {table.name}
              </span>
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
                  {waiterName && (
                    <>
                      <dt className="text-muted-foreground">Mozo</dt>
                      <dd className="truncate text-right">{waiterName}</dd>
                    </>
                  )}
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

  /**
   * El tile ES un `<button>`, así que el trigger del menú NO puede ir adentro:
   * un botón dentro de otro es HTML inválido y, peor, el tap se lo quedaría el
   * tile. Va como HERMANO dentro de un wrapper posicionado — el menú se ubica
   * solo (`absolute right-0 top-0`, ver `space-actions-menu.tsx`).
   *
   * El botón está SIEMPRE, haya sesión o no: geometría estable, memoria
   * muscular del cajero (Regla #10). Lo único que no lo lleva es la decoración
   * (barra/pared/planta) — no son espacios, no hay nada que gestionar.
   */
  const withMenu = (
    <div className="relative h-full w-full">
      {content}
      {actions && !isDecor && (
        <SpaceActionsMenu table={table} actions={actions} compact={position !== undefined} />
      )}
    </div>
  )

  if (!position) return withMenu

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
      {withMenu}
    </div>
  )
}
