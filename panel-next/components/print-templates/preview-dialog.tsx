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
import { getBlockMockText } from "@/lib/print-template-mock"
import {
  PAPER_DIMENSIONS,
  type PrintTemplateConfig,
} from "@/lib/types/print-template"

interface Props {
  open: boolean
  config: PrintTemplateConfig
  mm: number
  onClose: () => void
}

/**
 * Vista previa de la plantilla con mock data — el usuario ve cómo queda
 * impresa antes de guardar. Los bloques con `type` tipado se rellenan con
 * texto de demo (ver lib/print-template-mock.ts). `custom` mantiene el
 * texto del usuario; `hor_line`/`ver_line` y `company_logo` se renderizan
 * visualmente.
 *
 * El papel se auto-escala con CSS transform para entrar completo en el
 * viewport del modal (fit-to-screen), respetando aspect ratio.
 *
 * No reemplaza al motor de impresión real del POS — es solo para diseño.
 */
export function PreviewDialog({ open, config, mm, onClose }: Props) {
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
        className="w-[95vw] h-[95vh] max-w-none sm:max-w-none p-0 gap-0 overflow-hidden flex flex-col"
      >
        <DialogHeader className="flex-row items-center justify-between border-b px-4 py-3 pr-12">
          <div>
            <DialogTitle>Vista previa</DialogTitle>
            <DialogDescription>
              Bloques rellenados con datos de demostración. Solo para revisar el diseño.
            </DialogDescription>
          </div>
          <Button variant="outline" size="sm" onClick={handlePrint}>
            <Printer className="size-4" />
            Imprimir
          </Button>
        </DialogHeader>

        <ScaledPaper widthPx={widthPx} heightPx={heightPx} config={config} />
      </DialogContent>
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
}: {
  widthPx: number
  heightPx: number
  config: PrintTemplateConfig
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
          {config.data.map((b, i) => (
            <PreviewBlock key={i} block={b} />
          ))}
        </div>
      </div>
    </div>
  )
}

function PreviewBlock({ block }: { block: import("@/lib/types/print-template").PrintBlock }) {
  const mockText = getBlockMockText(block.type, block.text)

  const style: React.CSSProperties = {
    position: "absolute",
    top: `${block.top}px`,
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

  if (block.type === "hor_line") {
    return (
      <div style={style}>
        <div className="h-px w-full bg-black" style={{ marginTop: block.height / 2 }} />
      </div>
    )
  }
  if (block.type === "ver_line") {
    return (
      <div style={style}>
        <div className="h-full w-px bg-black mx-auto" />
      </div>
    )
  }
  if (block.type === "company_logo") {
    return (
      <div
        style={style}
        className="flex items-center justify-center border border-dashed border-muted-foreground/40 text-xs text-muted-foreground"
      >
        LOGO
      </div>
    )
  }
  return <div style={style}>{mockText}</div>
}
