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
      <DialogContent className="max-w-[min(95vw,900px)] max-h-[95vh] overflow-hidden p-0">
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

        <div className="overflow-auto bg-muted/40 p-6 max-h-[80vh]">
          <div
            className="relative mx-auto border border-dashed border-muted-foreground/30 bg-white shadow-sm print:border-0 print:shadow-none"
            style={{
              width: `${widthPx}px`,
              height: `${heightPx}px`,
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
      </DialogContent>
    </Dialog>
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
