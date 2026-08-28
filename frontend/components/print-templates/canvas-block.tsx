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
import { FONT_FAMILIES, FONT_SIZES, getBlockPlaceholder, getBlockTitle } from "@/lib/print-template-palette"
import { lineGeometry, resolveSingleBlockPreview } from "@/lib/hardware/printers/blocks"
import type { TicketData } from "@/lib/hardware/printers/build-ticket-data"
import {
  applyReceiptWidthRule,
  clampBlockToPaper,
  isReceipt,
  MIN_BLOCK_SIZE,
  PAPER_DIMENSIONS,
  type PaperSize,
  type PrintBlock,
} from "@/lib/types/print-template"
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
  // Alias local: en rollo la tipografía la manda el papel, no el bloque.
  const isRoll = ticket
  const grid = Math.max(1, mm) // 1mm snap
  const [isDragging, setIsDragging] = React.useState(false)
  const [isResizing, setIsResizing] = React.useState(false)
  const moving = isDragging || isResizing
  const enableResize = ticket
    ? { bottom: true, top: false, left: false, right: false, topRight: false, bottomRight: false, bottomLeft: false, topLeft: false }
    : { bottomRight: true, bottom: true, right: true, top: false, left: false, topRight: false, bottomLeft: false, topLeft: false }

  // Ancho/alto imprimible del papel en px — MISMA cuenta que moveBlocksBy en
  // template-editor.tsx (PAPER_DIMENSIONS[size].xMm * mm). Fuente única para
  // "esto entra en el papel", ver clampBlockToPaper (lib/types/print-template.ts).
  const paperDim = PAPER_DIMENSIONS[paperSize]
  const widthPx = paperDim.widthMm * mm
  const heightPx = paperDim.heightMm * mm

  // Título legible del placeholder ("IVA 10%", "Razón social del cliente")
  // para el tooltip del bloque y el de cada ícono de la toolbar — ver
  // getBlockTitle (print-template-palette.ts), reusa el catálogo de la
  // paleta lateral en vez de duplicar nombres.
  const title = React.useMemo(() => getBlockTitle(block, taxes ?? []), [block, taxes])

  // Ningún bloque puede quedar fuera del área imprimible (pedido owner,
  // screenshot con bloques cruzando el borde del papel). react-rnd YA
  // clampea el drag/resize EN VIVO (`bounds="parent"`, ver onDragStart/
  // onResizeStart de la librería) y `moveBlocksBy` YA clampea top/left en
  // cada move — lo que ninguno de los dos cubre es un bloque que YA estaba
  // fuera de rango sin que nadie lo esté tocando: la plantilla se diseñó
  // para OTRO tamaño de papel (ej. Carta) y después alguien cambió
  // `page_size` a un rollo angosto (57mm) — ni la creación del bloque ni
  // moveBlocksBy se disparan solo porque cambió el papel, así que ese
  // bloque queda con `left`/`width` inválidos hasta que el operador lo
  // vuelve a tocar. Este efecto corrige eso apenas cambian el tamaño del
  // bloque o el del papel.
  //
  // En ticket usa `applyReceiptWidthRule` en vez de `clampBlockToPaper`:
  // ahí el ancho no se CLAMPEA (corrige solo si excede), se FUERZA siempre
  // a 100% del papel (regla owner 2026-08-18) — necesario porque el resize
  // horizontal está deshabilitado en ticket, así que un bloque angosto
  // (plantilla vieja, o recién cambiado de papel a ticket) no tiene otra
  // forma de corregirse. En papel sigue siendo CLAMP puro, nunca RESCALE
  // (nunca reduce proporcionalmente ni reacomoda el diseño para que "quepa
  // lindo" — decisión del owner de no arriesgar destruir un layout armado a
  // mano con un reescalado automático). Se suprime mientras se
  // arrastra/redimensiona (`moving`) para no pelear con el propio clamp en
  // vivo de react-rnd.
  React.useEffect(() => {
    if (moving) return
    const fix = ticket
      ? applyReceiptWidthRule(block, widthPx, heightPx)
      : clampBlockToPaper(block, widthPx, heightPx)
    if (fix) onChange(fix)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [block.left, block.top, block.width, block.height, widthPx, heightPx, moving, ticket])

  return (
    <Rnd
      size={{ width: block.width, height: block.height }}
      position={{ x: block.left, y: block.top }}
      bounds="parent"
      // Ticket: el ancho es siempre 100% del papel y no se mueve en
      // horizontal — no tiene sentido arrastrarlo de lado si ya ocupa todo
      // el ancho (regla owner 2026-08-18). El arrastre vertical (reordenar
      // filas del ticket) sigue intacto; `dx` en `onDragStop` da 0 solo
      // porque react-rnd nunca mueve `x`, no por un chequeo manual acá.
      dragAxis={ticket ? "y" : "both"}
      dragGrid={[grid, grid]}
      resizeGrid={[grid, grid]}
      enableResizing={enableResize}
      // Mínimo agarrable con el mouse (pedido owner 2026-08-18): en papel el
      // ancho nace del tamaño del contenido y el resize está habilitado, así
      // que sin piso el usuario podría achicarlo hasta 1-2px e inutilizarlo.
      // En ticket el ancho está forzado a 100% (nunca baja de acá), así que
      // `minWidth` no aplica — solo `minHeight`, que SÍ es resizeable en los
      // dos modos.
      minWidth={ticket ? undefined : MIN_BLOCK_SIZE}
      minHeight={MIN_BLOCK_SIZE}
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
        // En ROLLO la tipografía por bloque no existe: la térmica imprime en
        // modo texto con una celda de ancho fijo, y los renderers ya la ignoran
        // (la grilla de caracteres es la geometría). Pintarla acá solo lograba
        // que el canvas mostrara algo que el papel no puede hacer. En hoja sí
        // manda el bloque — ahí imprime el navegador. Ver ROLL_FONT_STACK
        // (roll-grid.ts).
        fontSize: !isRoll && block.size !== "inherit" ? block.size : undefined,
        fontFamily: !isRoll && block.family !== "inherit" ? block.family : undefined,
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
          <BlockContent block={block} data={data} taxes={taxes} />
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
              <BlockContent block={block} data={data} taxes={taxes} />
            </div>
          </TooltipTrigger>
          <TooltipContent side="top">{title}</TooltipContent>
        </Tooltip>
      )}

      {/* Título visible sin hover al seleccionar UN bloque — pedido owner:
          "al seleccionar su título tiene que ser visible sin necesidad de
          hover". Vive DENTRO de la toolbar (mismo `-top-9` flotante que ya
          usa BlockToolbar) en vez de un segundo elemento flotante: dos
          bloques flotantes en la misma franja se solaparían, y la toolbar ya
          es "lo que aparece arriba del bloque cuando está seleccionado".
          Con selección MÚLTIPLE (`selected && !showToolbar`) no hay toolbar
          (se cede el paso al inspector, ver comentario de `showToolbar` en
          los Props) y TAMPOCO se agrega un título acá — ver justificación en
          BlockToolbar. */}
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

function BlockContent({
  block,
  data,
  taxes,
}: {
  block: PrintBlock
  data: TicketData
  /** Tasas del tenant — solo para el fallback de `getBlockPlaceholder` en
   *  los bloques por-tasa (subtotal_by_rate/iva_by_rate/item_total_by_rate),
   *  mismo uso que en `getBlockTitle` (ver Props de CanvasBlock). */
  taxes?: Tax[]
}) {
  // hor_line/ver_line: MISMA geometría que el papel (`lineGeometry` en
  // blocks.ts). Antes el canvas las pintaba como la caja entera pegada al
  // borde (`h-px w-full` / `h-full w-px`) y cada renderer las dibujaba a su
  // manera, así que el editor mentía sobre dónde y con qué grosor salía la
  // línea impresa. El grosor se edita desde el inspector (block-inspector.tsx)
  // y se persiste en `block.text`.
  const line = lineGeometry(block)
  if (line) {
    const horizontal = line.orientation === "horizontal"
    return (
      <div className="relative size-full">
        {/* Línea sobre papel blanco — color fijo zinc para que se vea en ambos modos. */}
        <div
          className="absolute bg-zinc-800"
          style={{
            top: horizontal ? line.crossOffset : 0,
            left: horizontal ? 0 : line.crossOffset,
            width: horizontal ? line.length : line.thickness,
            height: horizontal ? line.thickness : line.length,
          }}
        />
      </div>
    )
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
  // Nunca vacío ni identificador interno (pedido owner): cuando la venta de
  // ejemplo no puebla este campo (transfer_reason fuera de remisión,
  // nums_to_words sin implementar, una tasa "Exento" sin línea de ejemplo
  // que la matchee), en vez de un "…" genérico se muestra el MOLDE del dato
  // que va a ir ahí (getBlockPlaceholder — reusa el mismo `defaultText` de
  // PALETTE que ya usa el editor al insertar el bloque, nunca una segunda
  // lista de textos de ejemplo).
  // SIN padding horizontal: el `px-1` que tenía acá corría el texto ~1mm a la
  // derecha en el editor y en ningún renderer, así que el canvas mentía sobre
  // dónde empieza el contenido de un bloque (misma clase de divergencia que la
  // geometría del rollo). El borde de la caja ES el borde del contenido.
  return (
    <div className="h-full w-full leading-tight text-zinc-900">
      {preview ? preview : <span className="text-zinc-400">{getBlockPlaceholder(block, taxes ?? [])}</span>}
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
   *  a cuál corresponde cada botón antes de hacer click (pedido owner).
   *  También se pinta como texto plano al inicio de la toolbar (ver abajo):
   *  con un solo bloque seleccionado el título tiene que verse SIN hover. */
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
      {/* Título del bloque, siempre visible mientras la toolbar está abierta
          (un único bloque seleccionado) — pedido owner: "al seleccionar su
          título tiene que ser visible sin necesidad de hover". Con selección
          múltiple esta toolbar no se monta (ver `showToolbar` en
          CanvasBlock), así que acá no hay ambigüedad de "cuál de los varios
          títulos mostrar". `max-w-32 truncate`: algunos títulos por-tasa
          (ej. "Subtotal IVA 10%") son largos — se corta antes de que la
          toolbar empuje fuera del canvas angosto (roll 57mm). */}
      <span className="max-w-32 truncate px-1 text-xs font-medium text-muted-foreground">{title}</span>
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
