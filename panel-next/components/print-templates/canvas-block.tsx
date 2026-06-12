"use client"

import * as React from "react"
import { Rnd } from "react-rnd"
import {
  AlignCenter,
  AlignLeft,
  AlignRight,
  Bold,
  Copy,
  TextCursor,
  Trash2,
  TypeOutline,
  WrapText,
} from "lucide-react"
import { cn } from "@/lib/utils"
import { FONT_FAMILIES, FONT_SIZES } from "@/lib/print-template-palette"
import { isReceipt, type PaperSize, type PrintBlock } from "@/lib/types/print-template"

interface Props {
  block: PrintBlock
  index: number
  selected: boolean
  paperSize: PaperSize
  /** ratio mm → px (calculado por el canvas con un div de 1mm). */
  mm: number
  onSelect: () => void
  onChange: (patch: Partial<PrintBlock>) => void
  onDelete: () => void
  onClone: () => void
}

/**
 * Bloque individual en el canvas — drag + resize con react-rnd, snap a grid
 * de 1mm. El popover de ops (font, family, bold, align, clone, wrap, delete)
 * aparece solo cuando el bloque está seleccionado.
 */
export function CanvasBlock({
  block,
  selected,
  paperSize,
  mm,
  onSelect,
  onChange,
  onDelete,
  onClone,
}: Props) {
  const ticket = isReceipt(paperSize)
  const grid = Math.max(1, mm) // 1mm snap
  const enableResize = ticket
    ? { bottom: true, top: false, left: false, right: false, topRight: false, bottomRight: false, bottomLeft: false, topLeft: false }
    : { bottomRight: true, bottom: true, right: true, top: false, left: false, topRight: false, bottomLeft: false, topLeft: false }

  return (
    <Rnd
      size={{ width: block.width, height: block.height }}
      position={{ x: block.left, y: block.top }}
      bounds="parent"
      dragGrid={[grid, grid]}
      resizeGrid={[grid, grid]}
      enableResizing={enableResize}
      onDragStop={(_, d) => onChange({ left: Math.round(d.x), top: Math.round(d.y) })}
      onResizeStop={(_e, _dir, ref, _delta, pos) =>
        onChange({
          width: Math.round(ref.offsetWidth),
          height: Math.round(ref.offsetHeight),
          left: Math.round(pos.x),
          top: Math.round(pos.y),
        })
      }
      onMouseDown={(e) => {
        e.stopPropagation()
        onSelect()
      }}
      className={cn(
        "group rounded border bg-muted/30 transition-colors",
        selected ? "border-primary ring-1 ring-primary/40" : "border-dashed border-muted-foreground/30 hover:border-foreground/40",
      )}
      style={{
        zIndex: selected ? 50 : 1,
        textAlign: block.align,
        fontSize: block.size !== "inherit" ? block.size : undefined,
        fontFamily: block.family !== "inherit" ? block.family : undefined,
        fontWeight: block.bold,
        overflow: block.textwrap === "cut" ? "hidden" : undefined,
        whiteSpace: block.textwrap === "cut" ? "nowrap" : "normal",
        textOverflow: block.textwrap === "cut" ? "clip" : undefined,
      }}
    >
      <BlockContent block={block} />

      {selected && (
        <BlockToolbar
          block={block}
          onChange={onChange}
          onDelete={onDelete}
          onClone={onClone}
        />
      )}
    </Rnd>
  )
}

function BlockContent({ block }: { block: PrintBlock }) {
  if (block.type === "hor_line") {
    return <div className="h-px w-full bg-foreground/40" />
  }
  if (block.type === "ver_line") {
    return <div className="h-full w-px bg-foreground/40" />
  }
  if (block.type === "company_logo") {
    return (
      <div className="flex h-full w-full items-center justify-center text-xs text-muted-foreground">
        [Logo]
      </div>
    )
  }
  return (
    <div className="h-full w-full px-1 leading-tight">
      {block.text || <span className="text-muted-foreground/60">…</span>}
    </div>
  )
}

function BlockToolbar({
  block,
  onChange,
  onDelete,
  onClone,
}: {
  block: PrintBlock
  onChange: (patch: Partial<PrintBlock>) => void
  onDelete: () => void
  onClone: () => void
}) {
  const cycle = <T,>(arr: readonly T[], current: T): T => {
    const idx = arr.indexOf(current)
    return arr[(idx + 1) % arr.length]
  }
  const cycleAlign = () =>
    onChange({
      align:
        block.align === "left" ? "center" : block.align === "center" ? "right" : "left",
    })

  return (
    <div
      className={cn(
        "absolute -top-9 left-1/2 z-50 flex -translate-x-1/2 items-center gap-0.5 rounded-md border bg-popover px-1 py-0.5 shadow-md",
      )}
      onMouseDown={(e) => e.stopPropagation()}
    >
      <ToolbarBtn title="Eliminar" onClick={onDelete} danger>
        <Trash2 className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn title="Tamaño del texto" onClick={() => onChange({ size: cycle(FONT_SIZES, block.size as (typeof FONT_SIZES)[number]) })}>
        <TypeOutline className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn title="Tipografía" onClick={() => onChange({ family: cycle(FONT_FAMILIES, block.family as (typeof FONT_FAMILIES)[number]) })}>
        <TextCursor className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn
        title="Negrita"
        onClick={() => onChange({ bold: block.bold === "bold" ? "normal" : "bold" })}
        active={block.bold === "bold"}
      >
        <Bold className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn title="Alineación" onClick={cycleAlign}>
        {block.align === "left" ? (
          <AlignLeft className="size-3.5" />
        ) : block.align === "center" ? (
          <AlignCenter className="size-3.5" />
        ) : (
          <AlignRight className="size-3.5" />
        )}
      </ToolbarBtn>
      <ToolbarBtn title="Clonar" onClick={onClone}>
        <Copy className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn
        title="Wrap / cut"
        onClick={() => onChange({ textwrap: block.textwrap === "cut" ? "wrap" : "cut" })}
        active={block.textwrap === "wrap"}
      >
        <WrapText className="size-3.5" />
      </ToolbarBtn>
    </div>
  )
}

function ToolbarBtn({
  title,
  onClick,
  children,
  danger,
  active,
}: {
  title: string
  onClick: () => void
  children: React.ReactNode
  danger?: boolean
  active?: boolean
}) {
  return (
    <button
      type="button"
      title={title}
      onClick={onClick}
      className={cn(
        "flex size-6 items-center justify-center rounded hover:bg-accent",
        danger && "text-destructive hover:bg-destructive/10",
        active && !danger && "bg-accent",
      )}
    >
      {children}
    </button>
  )
}
