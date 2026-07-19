"use client"

import * as React from "react"
import { Rnd } from "react-rnd"
import { RotateCw, Users, Trash2 } from "lucide-react"
import { cn } from "@/lib/utils"
import type { Space, SpaceShape } from "@/hooks/use-spaces"

interface Props {
  table: Space
  selected: boolean
  onSelect: () => void
  onChange: (patch: Partial<Pick<Space, "posX" | "posY" | "width" | "height" | "rotation">>) => void
  onRotate: () => void
  onRemoveFromLayout: () => void
}

const GRID = 10 // px de snap del canvas — salón/local real, no impresión (sin unidad mm)

const DECOR_SHAPES: SpaceShape[] = ["decor_wall", "decor_plant", "bar"]

/**
 * Bloque individual del editor de layout — drag + resize con react-rnd
 * (mismo patrón que `components/print-templates/canvas-block.tsx`, único
 * precedente in-repo de canvas con posicionamiento absoluto). Diferencias
 * con el editor de plantillas: acá se agrega rotación en pasos de 45°
 * (espacios reales del local, no texto) y la forma determina el render
 * (redonda = círculo, espacio = caja con label, decorativo = bloque neutro
 * sin covers).
 */
export function CanvasSpaceBlock({ table, selected, onSelect, onChange, onRotate, onRemoveFromLayout }: Props) {
  const [isMoving, setIsMoving] = React.useState(false)
  const isDecor = DECOR_SHAPES.includes(table.shape)
  const isRound = table.shape === "round"

  const width = table.width ?? defaultSize(table.shape).width
  const height = table.height ?? defaultSize(table.shape).height

  return (
    <Rnd
      size={{ width, height }}
      position={{ x: table.posX ?? 0, y: table.posY ?? 0 }}
      bounds="parent"
      dragGrid={[GRID, GRID]}
      resizeGrid={[GRID, GRID]}
      cancel=".table-block-toolbar"
      onDragStart={() => setIsMoving(true)}
      onDragStop={(_, d) => {
        setIsMoving(false)
        onChange({ posX: Math.round(d.x), posY: Math.round(d.y) })
      }}
      onResizeStart={() => setIsMoving(true)}
      onResizeStop={(_e, _dir, ref, _delta, pos) => {
        setIsMoving(false)
        onChange({
          width: Math.round(ref.offsetWidth),
          height: Math.round(ref.offsetHeight),
          posX: Math.round(pos.x),
          posY: Math.round(pos.y),
        })
      }}
      onMouseDown={(e) => {
        e.stopPropagation()
        onSelect()
      }}
      style={{
        zIndex: selected ? 50 : 1,
        transform: `rotate(${table.rotation}deg)`,
        transition: isMoving ? "none" : "opacity 120ms ease-out",
        opacity: isMoving ? 0.6 : 1,
      }}
    >
      <div
        className={cn(
          "flex h-full w-full flex-col items-center justify-center gap-0.5 border-2 text-xs font-medium select-none",
          isRound ? "rounded-full" : "rounded-md",
          isDecor
            ? "border-dashed border-muted-foreground/40 bg-muted/50 text-muted-foreground"
            : "border-primary/50 bg-card text-foreground",
          selected && "border-primary ring-2 ring-primary/40",
        )}
      >
        {!isDecor && (
          <>
            <span className="font-semibold">{table.name}</span>
            <span className="flex items-center gap-0.5 text-[10px] text-muted-foreground">
              <Users className="size-3" />
              {table.seats}
            </span>
          </>
        )}
        {isDecor && <span className="text-[10px] uppercase tracking-wide">{decorLabel(table.shape)}</span>}
      </div>

      {selected && !isMoving && (
        <div
          className="table-block-toolbar absolute -top-9 left-1/2 z-50 flex -translate-x-1/2 items-center gap-0.5 rounded-md border bg-popover px-1 py-0.5 shadow-md"
          onMouseDown={(e) => e.stopPropagation()}
        >
          <button
            type="button"
            title="Rotar 45°"
            onClick={onRotate}
            className="flex size-6 items-center justify-center rounded hover:bg-accent"
          >
            <RotateCw className="size-3.5" />
          </button>
          <button
            type="button"
            title="Quitar del layout"
            onClick={onRemoveFromLayout}
            className="flex size-6 items-center justify-center rounded text-destructive hover:bg-destructive/10"
          >
            <Trash2 className="size-3.5" />
          </button>
        </div>
      )}
    </Rnd>
  )
}

export function defaultSize(shape: SpaceShape): { width: number; height: number } {
  switch (shape) {
    case "round":       return { width: 70, height: 70 }
    case "rect":         return { width: 110, height: 70 }
    case "bar":           return { width: 160, height: 45 }
    case "decor_wall":   return { width: 120, height: 20 }
    case "decor_plant":  return { width: 40, height: 40 }
    case "square":
    default:              return { width: 70, height: 70 }
  }
}

function decorLabel(shape: SpaceShape): string {
  switch (shape) {
    case "bar":          return "Barra"
    case "decor_wall":  return "Pared"
    case "decor_plant": return "Planta"
    default:              return ""
  }
}
