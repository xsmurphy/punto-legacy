"use client"

import * as React from "react"
import { Printer } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  BLOCK_VALUE_RESOLVERS,
  ITEM_FIELD_RESOLVERS,
  ITEM_LINE_TYPES,
  ITEM_TABLE_TYPES,
  formatMoney,
  itemTableColumns,
  sortBlocksForRender,
  ticketItemName,
} from "@/lib/hardware/printers/blocks"
import type { TicketData } from "@/lib/hardware/printers/build-ticket-data"
import {
  PAPER_DIMENSIONS,
  type PrintBlock,
  type PrintTemplateConfig,
} from "@/lib/types/print-template"

interface Props {
  open: boolean
  config: PrintTemplateConfig
  mm: number
  /** Venta de demo — construida UNA sola vez por `TemplateEditor` con
   *  `buildDemoTicketData()` (build-ticket-data.ts) y compartida con el
   *  thumbnail de bloque del canvas (canvas-block.tsx), para que ambas
   *  superficies muestren siempre el mismo dato. */
  data: TicketData
  onClose: () => void
}

/**
 * Vista previa de la plantilla — el usuario ve cómo queda impresa antes de
 * guardar. Cada bloque se resuelve contra `data` (una venta de demo) con el
 * MISMO catálogo de bloques (`BLOCK_VALUE_RESOLVERS`/`ITEM_FIELD_RESOLVERS`/
 * `ITEM_LINE_TYPES`/`ITEM_TABLE_TYPES` de lib/hardware/printers/blocks.ts)
 * que usan los renderers reales (`render-template.ts` ESC/POS,
 * `html-renderer.ts` HTML) — nunca un diccionario de texto aparte. Antes
 * (`lib/print-template-mock.ts`, eliminado) indexaba solo por `BlockType` e
 * ignoraba `block.text` (donde vive el `taxId` de los bloques por-tasa), así
 * que IVA 5% e IVA 10% mostraban el mismo número hardcodeado sin cerrar
 * contra subtotal/total.
 *
 * A diferencia de los renderers reales (que emiten un documento en FLUJO,
 * bloque tras bloque), el editor es un CANVAS con posición absoluta — así
 * que los bloques de `ITEM_LINE_TYPES` (una "fila plantilla" que el motor
 * real repite una vez por producto) se apilan verticalmente debajo de su
 * posición original, una copia por ítem de `data.items`, en vez de fluir
 * como en el HTML/ESC/POS real.
 *
 * El papel se auto-escala con CSS transform para entrar completo en el
 * viewport del modal (fit-to-screen), respetando aspect ratio.
 *
 * No reemplaza al motor de impresión real del POS — es solo para diseño.
 */
export function PreviewDialog({ open, config, mm, data, onClose }: Props) {
  const dim = PAPER_DIMENSIONS[config.page_size]
  const widthPx = dim.widthMm * mm
  const heightPx = dim.heightMm * mm

  const handlePrint = () => {
    window.print()
  }

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent
        showCloseButton
        className="w-[95vw] h-[95vh] max-w-none sm:max-w-none p-0 gap-0 overflow-hidden flex flex-col print:contents"
      >
        <DialogHeader className="flex-row items-center justify-between border-b px-4 py-3 pr-12 print:hidden">
          <div>
            <DialogTitle>Vista previa</DialogTitle>
            <DialogDescription>
              Bloques rellenados con una venta de demostración. Solo para revisar el diseño.
            </DialogDescription>
          </div>
          <Button variant="outline" size="sm" onClick={handlePrint}>
            <Printer className="size-4" />
            Imprimir
          </Button>
        </DialogHeader>

        <ScaledPaper widthPx={widthPx} heightPx={heightPx} config={config} data={data} />
      </DialogContent>
      {/*
        Reglas @media print scoped al body. Ocultar todo el viewport
        del browser y dejar visible SOLO el papel marcado con
        `data-print-root`. Resetea transform/border/shadow/posición
        para que el papel ocupe la hoja real con sus dimensiones en
        px (1mm = 3.78px) sin escalado del preview.
        Sin esto, window.print() imprime el modal completo (header,
        Imprimir, sidebar de fondo, URL del footer del browser).
      */}
      <style jsx global>{`
        @media print {
          body * {
            visibility: hidden !important;
          }
          [data-print-root],
          [data-print-root] * {
            visibility: visible !important;
          }
          [data-print-root] {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            transform: none !important;
            box-shadow: none !important;
            border: 0 !important;
            margin: 0 !important;
          }
          /* @page con tamaño y margen 0 para que no quede el header/footer
             del browser (URL, fecha) — eso ya el usuario lo controla desde
             "More settings" del print dialog. */
          @page {
            margin: 0;
          }
          html,
          body {
            background: white !important;
          }
        }
      `}</style>
    </Dialog>
  )
}

/**
 * Auto-fit del papel: mide el contenedor disponible y aplica transform
 * scale para que el papel entre completo (sin cortar arriba/abajo ni
 * izquierda/derecha), preservando aspect ratio. Si el papel es más chico
 * que el contenedor (ticket en pantalla grande), no upscalea — capeada en 1.
 */
function ScaledPaper({
  widthPx,
  heightPx,
  config,
  data,
}: {
  widthPx: number
  heightPx: number
  config: PrintTemplateConfig
  data: TicketData
}) {
  const containerRef = React.useRef<HTMLDivElement>(null)
  const [scale, setScale] = React.useState(1)

  React.useLayoutEffect(() => {
    const el = containerRef.current
    if (!el) return
    const compute = () => {
      const padding = 48 // 24px arriba/abajo/izq/der
      const aw = el.clientWidth - padding
      const ah = el.clientHeight - padding
      if (aw <= 0 || ah <= 0) return
      const sx = aw / widthPx
      const sy = ah / heightPx
      const next = Math.min(1, sx, sy)
      setScale((prev) => (Math.abs(prev - next) > 0.005 ? next : prev))
    }
    compute()
    const obs = new ResizeObserver(compute)
    obs.observe(el)
    return () => obs.disconnect()
  }, [widthPx, heightPx])

  const entries = React.useMemo(() => layoutPreviewEntries(config.data ?? [], data), [config.data, data])

  // Wrapper con dimensiones VISUALES (escaladas) para que el flex padre
  // centre/scrollee según el tamaño aparente del papel — `transform: scale`
  // no afecta layout, por eso el papel adentro va `position: absolute` con
  // origen top-left y el wrapper exterior reserva su tamaño escalado.
  return (
    <div
      ref={containerRef}
      className="flex flex-1 items-start justify-center overflow-auto bg-muted/40 p-6"
    >
      {/* Wrapper con dimensiones VISUALES (escaladas) — `transform: scale` no
          afecta layout, así que el padre flex centra usando este wrapper. */}
      <div
        className="relative shrink-0"
        style={{ width: `${widthPx * scale}px`, height: `${heightPx * scale}px` }}
      >
        <div
          data-print-root
          className="absolute left-0 top-0 border border-dashed border-muted-foreground/30 bg-white shadow-sm print:border-0 print:shadow-none"
          style={{
            width: `${widthPx}px`,
            height: `${heightPx}px`,
            transform: `scale(${scale})`,
            transformOrigin: "top left",
            fontFamily: config.page_font_family,
            fontSize: config.page_font_size,
            textTransform: config.page_font_case,
          }}
        >
          {entries.map((entry) => (
            <PreviewBlock key={entry.key} entry={entry} data={data} />
          ))}
        </div>
      </div>
    </div>
  )
}

interface PreviewEntry {
  key: string
  block: PrintBlock
  /** Posición vertical efectiva — igual a `block.top` salvo para copias de
   *  `ITEM_LINE_TYPES` repetidas por ítem (ver `layoutPreviewEntries`). */
  top: number
  kind: "text" | "table" | "hor_line" | "ver_line" | "company_logo" | "payment_methods"
  text?: string
}

/**
 * Resuelve `template.data` contra `data` (la venta de demo) y decide DÓNDE
 * pintar cada copia. Camina los bloques en el MISMO orden que usan los
 * renderers reales (`sortBlocksForRender`, que también normaliza aliases
 * legacy de `BlockType`) y agrupa corridas consecutivas de
 * `ITEM_LINE_TYPES` exactamente como `html-renderer.ts` — la única
 * diferencia es que acá cada copia necesita una posición `top` propia
 * (canvas absoluto) en vez de simplemente aparecer a continuación (flujo
 * HTML/ESC-POS).
 */
function layoutPreviewEntries(rawBlocks: PrintBlock[], data: TicketData): PreviewEntry[] {
  const blocks = sortBlocksForRender(rawBlocks)
  const entries: PreviewEntry[] = []
  let i = 0
  while (i < blocks.length) {
    const block = blocks[i]

    if (ITEM_LINE_TYPES.has(block.type)) {
      const start = i
      while (i < blocks.length && ITEM_LINE_TYPES.has(blocks[i].type)) i++
      const rowBlocks = blocks.slice(start, i)
      data.items.forEach((item, itemIdx) => {
        for (const rb of rowBlocks) {
          const resolver = ITEM_FIELD_RESOLVERS[rb.type]
          entries.push({
            key: `${start}-${itemIdx}-${rb.type}-${rb.left}`,
            block: rb,
            top: rb.top + itemIdx * rb.height,
            kind: "text",
            text: resolver ? resolver(item, data) ?? "" : "",
          })
        }
      })
      continue
    }

    if (ITEM_TABLE_TYPES.has(block.type)) {
      entries.push({ key: `${i}-table`, block, top: block.top, kind: "table" })
      i++
      continue
    }

    if (block.type === "hor_line" || block.type === "ver_line" || block.type === "company_logo") {
      entries.push({ key: `${i}-${block.type}`, block, top: block.top, kind: block.type })
      i++
      continue
    }

    if (block.type === "payment_methods") {
      entries.push({ key: `${i}-payments`, block, top: block.top, kind: "payment_methods" })
      i++
      continue
    }

    const resolver = BLOCK_VALUE_RESOLVERS[block.type]
    entries.push({
      key: `${i}-${block.type}`,
      block,
      top: block.top,
      kind: "text",
      text: resolver ? resolver(data, block) ?? "" : "",
    })
    i++
  }
  return entries
}

function PreviewBlock({ entry, data }: { entry: PreviewEntry; data: TicketData }) {
  const { block, top } = entry
  const style: React.CSSProperties = {
    position: "absolute",
    top: `${top}px`,
    left: `${block.left}px`,
    width: `${block.width}px`,
    height: `${block.height}px`,
    textAlign: block.align,
    fontSize: block.size !== "inherit" ? block.size : undefined,
    fontFamily: block.family !== "inherit" ? block.family : undefined,
    fontWeight: block.bold,
    overflow: block.textwrap === "cut" ? "hidden" : undefined,
    whiteSpace: block.textwrap === "cut" ? "nowrap" : "pre-wrap",
    textOverflow: block.textwrap === "cut" ? "clip" : undefined,
    color: "#000",
  }

  if (entry.kind === "hor_line") {
    return (
      <div style={style}>
        <div className="h-px w-full bg-black" style={{ marginTop: block.height / 2 }} />
      </div>
    )
  }
  if (entry.kind === "ver_line") {
    return (
      <div style={style}>
        <div className="h-full w-px bg-black mx-auto" />
      </div>
    )
  }
  if (entry.kind === "company_logo") {
    return (
      <div
        style={style}
        className="flex items-center justify-center border border-dashed border-muted-foreground/40 text-xs text-muted-foreground"
      >
        LOGO
      </div>
    )
  }
  if (entry.kind === "payment_methods") {
    const text = data.payments.map((p) => `${p.method}: ${formatMoney(p.amount, data)}`).join("\n")
    return (
      <div style={{ ...style, whiteSpace: "pre-wrap" }}>{text}</div>
    )
  }
  if (entry.kind === "table") {
    return (
      <div style={{ ...style, overflow: "visible", whiteSpace: "normal" }}>
        <PreviewItemTable block={block} data={data} />
      </div>
    )
  }
  return <div style={style}>{entry.text}</div>
}

/** Listado completo de ítems para `item_receipt*` — mismas columnas que
 *  `renderItemTable` de html-renderer.ts (`itemTableColumns`/`formatMoney`/
 *  `ticketItemName`, todos de blocks.ts), pintadas como tabla HTML real en
 *  vez de texto plano monoespaciado (el canvas no está limitado a un rollo
 *  térmico). */
function PreviewItemTable({ block, data }: { block: PrintBlock; data: TicketData }) {
  const cols = itemTableColumns(block.type)
  return (
    <table className="w-full border-collapse" style={{ fontSize: "inherit" }}>
      <thead>
        <tr>
          <th className="text-left">Ítem</th>
          {cols.qty && <th className="text-right">Cant.</th>}
          {cols.unitPrice && <th className="text-right">P.Unit</th>}
          {cols.total && <th className="text-right">Total</th>}
        </tr>
      </thead>
      <tbody>
        {data.items.map((item, idx) => (
          <tr key={idx}>
            <td className="whitespace-pre-wrap">{ticketItemName(item)}</td>
            {cols.qty && <td className="text-right">{item.qty}</td>}
            {cols.unitPrice && <td className="text-right">{formatMoney(item.unitPrice, data)}</td>}
            {cols.total && <td className="text-right">{formatMoney(item.total, data)}</td>}
          </tr>
        ))}
      </tbody>
    </table>
  )
}
