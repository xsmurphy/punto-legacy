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
import { resolveSingleBlockPreview } from "@/lib/hardware/printers/blocks"
import type { TicketData } from "@/lib/hardware/printers/build-ticket-data"
import { isReceipt, type PaperSize, type PrintBlock } from "@/lib/types/print-template"

interface Props {
  block: PrintBlock
  index: number
  selected: boolean
  paperSize: PaperSize
  /** ratio mm → px (calculado por el canvas con un div de 1mm). */
  mm: number
  /** Venta de demo compartida con la Vista Previa (`TemplateEditor` la arma
   *  una sola vez con `buildDemoTicketData()`) — el thumbnail del bloque
   *  resuelve contra los mismos datos, ver `resolveSingleBlockPreview`. */
  data: TicketData
  onSelect: () => void
  onChange: (patch: Partial<PrintBlock>) => void
  onDelete: () => void
  onClone: () => void
  /** Cuando se está arrastrando: notifica al editor para que muestre guides. */
  onDragGuides?: (info: { top: number; left: number; width: number; height: number } | null) => void
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
  data,
  onSelect,
  onChange,
  onDelete,
  onClone,
  onDragGuides,
}: Props) {
  const ticket = isReceipt(paperSize)
  const grid = Math.max(1, mm) // 1mm snap
  const [isDragging, setIsDragging] = React.useState(false)
  const [isResizing, setIsResizing] = React.useState(false)
  const moving = isDragging || isResizing
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
      // Excluye la toolbar flotante del área draggable — sin esto, el delete y
      // demás botones nunca disparan click porque react-rnd captura el mousedown.
      cancel=".block-toolbar"
      onDragStart={() => setIsDragging(true)}
      onDrag={(_, d) =>
        onDragGuides?.({
          top: d.y,
          left: d.x,
          width: block.width,
          height: block.height,
        })
      }
      onDragStop={(_, d) => {
        setIsDragging(false)
        onChange({ left: Math.round(d.x), top: Math.round(d.y) })
        onDragGuides?.(null)
      }}
      onResizeStart={() => setIsResizing(true)}
      onResize={(_e, _dir, ref, _delta, pos) =>
        onDragGuides?.({
          top: pos.y,
          left: pos.x,
          width: ref.offsetWidth,
          height: ref.offsetHeight,
        })
      }
      onResizeStop={(_e, _dir, ref, _delta, pos) => {
        setIsResizing(false)
        onChange({
          width: Math.round(ref.offsetWidth),
          height: Math.round(ref.offsetHeight),
          left: Math.round(pos.x),
          top: Math.round(pos.y),
        })
        onDragGuides?.(null)
      }}
      onMouseDown={(e) => {
        e.stopPropagation()
        onSelect()
      }}
      className={cn(
        // El bloque vive sobre el papel blanco — uso colores neutrales fijos
        // (zinc-*) en vez de muted-*, que en dark mode resuelven a tonos casi
        // invisibles contra blanco. El bloque seleccionado usa primary del tema.
        "group rounded-sm border bg-zinc-100/40 transition-colors",
        selected
          ? "border-primary ring-1 ring-primary/40"
          : "border-dashed border-zinc-400/70 hover:border-zinc-600",
      )}
      style={{
        zIndex: selected ? 50 : 1,
        // Translúcido durante drag/resize para poder superponer con otros bloques.
        opacity: moving ? 0.55 : 1,
        transition: moving ? "none" : "opacity 120ms ease-out",
        textAlign: block.align,
        fontSize: block.size !== "inherit" ? block.size : undefined,
        fontFamily: block.family !== "inherit" ? block.family : undefined,
        fontWeight: block.bold,
      }}
    >
      {/* El recorte de texto (`textwrap: "cut"`) va en este wrapper interno, NO
          en la raíz del bloque. Estaba en la raíz, y como la toolbar flotante es
          hija absolute a `-top-9`, el `overflow: hidden` la recortaba entera:
          todo bloque nuevo nace con textwrap "cut" (default en
          lib/types/print-template.ts), así que el botón de eliminar era
          literalmente invisible y no había forma de borrar un bloque recién
          agregado (bug 2026-07-30). La raíz queda como contexto de posición
          para el chrome; el clipping solo afecta al contenido. */}
      <div
        className="size-full"
        style={{
          overflow: block.textwrap === "cut" ? "hidden" : undefined,
          whiteSpace: block.textwrap === "cut" ? "nowrap" : "normal",
          textOverflow: block.textwrap === "cut" ? "clip" : undefined,
        }}
      >
        <BlockContent block={block} data={data} />
      </div>

      {selected && !moving && (
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

function BlockContent({ block, data }: { block: PrintBlock; data: TicketData }) {
  if (block.type === "hor_line") {
    // Línea sobre papel blanco — color fijo zinc para que se vea en ambos modos.
    return <div className="h-px w-full bg-zinc-800" />
  }
  if (block.type === "ver_line") {
    return <div className="h-full w-px bg-zinc-800" />
  }
  if (block.type === "company_logo") {
    return (
      <div className="flex h-full w-full items-center justify-center text-xs text-zinc-500">
        [Logo]
      </div>
    )
  }
  // El editor visual es para ver tamaño/grosor/familia — sin texto realista,
  // un bloque vacío esconde la tipografía. Si el bloque trae texto custom
  // (block.text), lo respetamos (el bloque `custom` es lo único que el
  // usuario tipea a mano). Sino, sustituimos por el valor real que resolvería
  // la venta de demo contra este bloque (mismo catálogo que la Vista Previa —
  // ver resolveSingleBlockPreview, blocks.ts). El texto en el editor se
  // muestra en zinc-500 (un poquito atenuado) para señalar que es preview, no
  // real.
  const preview = block.text || resolveSingleBlockPreview(block, data)
  return (
    <div className="h-full w-full px-1 leading-tight text-zinc-900">
      {preview ? (
        preview
      ) : (
        <span className="text-zinc-400">…</span>
      )}
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
        "block-toolbar absolute -top-9 left-1/2 z-50 flex -translate-x-1/2 items-center gap-0.5 rounded-md border bg-popover px-1 py-0.5 shadow-md",
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
