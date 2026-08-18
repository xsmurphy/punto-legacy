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
import { FONT_FAMILIES, FONT_SIZES, getBlockTitle } from "@/lib/print-template-palette"
import { resolveSingleBlockPreview } from "@/lib/hardware/printers/blocks"
import type { TicketData } from "@/lib/hardware/printers/build-ticket-data"
import { isReceipt, type PaperSize, type PrintBlock } from "@/lib/types/print-template"
import type { Tax } from "@/lib/types/tax"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"

interface Props {
  block: PrintBlock
  index: number
  /** true si el bloque está en la selección actual (uno o varios). Controla
   *  el resaltado visual — NO implica que la toolbar flotante se muestre,
   *  ver `showToolbar`. */
  selected: boolean
  /** true solo cuando este es el ÚNICO bloque seleccionado — la toolbar
   *  flotante (delete/tamaño/familia/negrita/align/clonar/wrap) edita UN
   *  bloque a la vez; con selección múltiple se oculta a favor del panel de
   *  edición en conjunto del inspector (block-inspector.tsx). */
  showToolbar: boolean
  paperSize: PaperSize
  /** ratio mm → px (calculado por el canvas con un div de 1mm). */
  mm: number
  /** Venta de demo compartida con la Vista Previa (`TemplateEditor` la arma
   *  una sola vez con `buildDemoTicketData()`) — el thumbnail del bloque
   *  resuelve contra los mismos datos, ver `resolveSingleBlockPreview`. */
  data: TicketData
  /** Tasas del tenant — solo para armar el título de los bloques por-tasa
   *  (subtotal_by_rate/iva_by_rate/item_total_by_rate) en el tooltip, ver
   *  `getBlockTitle`. */
  taxes?: Tax[]
  /** Mousedown sobre el bloque — el editor decide qué hacer con el evento
   *  (click simple, Shift/Cmd para sumar/restar de la selección, o dejar
   *  pendiente el colapso a un solo bloque si ya es parte de un grupo
   *  seleccionado, ver `handleBlockMouseDown` en template-editor.tsx).
   *  `MouseEvent` nativo (no `React.MouseEvent`): así lo tipa react-rnd en
   *  su propio `onMouseDown`, que es de donde sale este evento. */
  onSelect: (e: MouseEvent) => void
  onChange: (patch: Partial<PrintBlock>) => void
  onDelete: () => void
  onClone: () => void
  /** Drag stop del bloque: delta en px respecto a su posición previa. El
   *  editor decide si mueve solo este bloque o todo el grupo seleccionado
   *  (`moveBlocksBy` en template-editor.tsx) — acá no se sabe cuántos
   *  bloques hay seleccionados. */
  onMoveBy: (dx: number, dy: number) => void
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
  showToolbar,
  paperSize,
  mm,
  data,
  taxes,
  onSelect,
  onChange,
  onDelete,
  onClone,
  onMoveBy,
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

  // Título legible del placeholder ("IVA 10%", "Razón social del cliente")
  // para el tooltip del bloque y el de cada ícono de la toolbar — ver
  // getBlockTitle (print-template-palette.ts), reusa el catálogo de la
  // paleta lateral en vez de duplicar nombres.
  const title = React.useMemo(() => getBlockTitle(block, taxes ?? []), [block, taxes])

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
        onDragGuides?.(null)
        // Delta, no posición absoluta — el editor lo aplica a TODO el grupo
        // seleccionado si este bloque es parte de una selección múltiple
        // (moveBlocksBy en template-editor.tsx), o solo a este bloque si no.
        // CanvasBlock no sabe cuántos bloques hay seleccionados.
        const dx = Math.round(d.x) - block.left
        const dy = Math.round(d.y) - block.top
        if (dx !== 0 || dy !== 0) onMoveBy(dx, dy)
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
        onSelect(e)
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
      {/* Tooltip con el título del placeholder (ej. "IVA 10%", "Razón social
          del cliente") — pedido del owner: con varios bloques superpuestos
          en el canvas ya no se distingue cuál es cuál con solo el valor de
          ejemplo. Se suprime mientras se arrastra/redimensiona (`moving`)
          para no dejar un tooltip fantasma pegado al cursor durante el
          drag — Radix no lo cierra solo porque el mouse "se mueva" si nunca
          sale del trigger, que es justo lo que pasa en un drag. */}
      {moving ? (
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
      ) : (
        <Tooltip>
          <TooltipTrigger asChild>
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
          </TooltipTrigger>
          <TooltipContent side="top">{title}</TooltipContent>
        </Tooltip>
      )}

      {showToolbar && !moving && (
        <BlockToolbar
          block={block}
          title={title}
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
  // un bloque vacío esconde la tipografía, así que siempre resolvemos contra
  // la venta de demo con el mismo catálogo que la Vista Previa
  // (resolveSingleBlockPreview, blocks.ts) — NUNCA `block.text` como fallback
  // directo acá: en `subtotal_by_rate`/`iva_by_rate`/`item_total_by_rate`/
  // `tax_single`, `block.text` no es contenido a mostrar, es METADATO
  // (taxId o tasa tipeada — ver print-template-palette.ts `defaultText: tax.id`
  // y blocks.ts `tax_single`), y mostrarlo tal cual pintaba el taxId crudo en
  // el casillero de IVA (bug 2026-08-18). El bloque `custom` sigue mostrando
  // lo que el usuario tipeó: su resolver (`BLOCK_VALUE_RESOLVERS.custom` en
  // blocks.ts) ya interpola `block.text` — el catálogo compartido es la única
  // fuente de qué tipo trata `text` como contenido vs. metadato, no un `if`
  // de tipos acá. El texto en el editor se muestra en zinc-500 (atenuado)
  // para señalar que es preview, no real.
  const preview = resolveSingleBlockPreview(block, data)
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
  title,
  onChange,
  onDelete,
  onClone,
}: {
  block: PrintBlock
  /** Título legible del placeholder (getBlockTitle) — se antepone al label
   *  de cada ícono en su tooltip, así con varios bloques en pantalla se sabe
   *  a cuál corresponde cada botón antes de hacer click (pedido owner). */
  title: string
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
      <ToolbarBtn blockTitle={title} action="Eliminar" onClick={onDelete} danger>
        <Trash2 className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn
        blockTitle={title}
        action="Tamaño del texto"
        onClick={() => onChange({ size: cycle(FONT_SIZES, block.size as (typeof FONT_SIZES)[number]) })}
      >
        <TypeOutline className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn
        blockTitle={title}
        action="Tipografía"
        onClick={() => onChange({ family: cycle(FONT_FAMILIES, block.family as (typeof FONT_FAMILIES)[number]) })}
      >
        <TextCursor className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn
        blockTitle={title}
        action="Negrita"
        onClick={() => onChange({ bold: block.bold === "bold" ? "normal" : "bold" })}
        active={block.bold === "bold"}
      >
        <Bold className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn blockTitle={title} action="Alineación" onClick={cycleAlign}>
        {block.align === "left" ? (
          <AlignLeft className="size-3.5" />
        ) : block.align === "center" ? (
          <AlignCenter className="size-3.5" />
        ) : (
          <AlignRight className="size-3.5" />
        )}
      </ToolbarBtn>
      <ToolbarBtn blockTitle={title} action="Clonar" onClick={onClone}>
        <Copy className="size-3.5" />
      </ToolbarBtn>
      <ToolbarBtn
        blockTitle={title}
        action="Wrap / cut"
        onClick={() => onChange({ textwrap: block.textwrap === "cut" ? "wrap" : "cut" })}
        active={block.textwrap === "wrap"}
      >
        <WrapText className="size-3.5" />
      </ToolbarBtn>
    </div>
  )
}

function ToolbarBtn({
  blockTitle,
  action,
  onClick,
  children,
  danger,
  active,
}: {
  /** Título del bloque dueño de esta toolbar (getBlockTitle) — ver comentario
   *  en BlockToolbar. */
  blockTitle: string
  /** Qué hace este ícono puntual (antes iba en el `title=""` nativo). */
  action: string
  onClick: () => void
  children: React.ReactNode
  danger?: boolean
  active?: boolean
}) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          onClick={onClick}
          className={cn(
            "flex size-6 items-center justify-center rounded hover:bg-accent",
            danger && "text-destructive hover:bg-destructive/10",
            active && !danger && "bg-accent",
          )}
        >
          {children}
        </button>
      </TooltipTrigger>
      <TooltipContent side="top">
        {blockTitle} · {action}
      </TooltipContent>
    </Tooltip>
  )
}
