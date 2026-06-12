"use client"

import * as React from "react"
import { toast } from "sonner"
import { useRouter } from "next/navigation"
import { Loader2, Save, ChevronLeft, ChevronDown, Eye } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { PaletteSidebar } from "@/components/print-templates/palette-sidebar"
import { BlockInspector } from "@/components/print-templates/block-inspector"
import { CanvasBlock } from "@/components/print-templates/canvas-block"
import { PreviewDialog } from "@/components/print-templates/preview-dialog"
import {
  useCreateDocumentTemplate,
  useUpdateDocumentTemplate,
} from "@/hooks/use-document-templates"
import type { PaletteItem } from "@/lib/print-template-palette"
import {
  PAPER_DIMENSIONS,
  defaultBlock,
  defaultTemplateConfig,
  isReceipt,
  paperSizeToBackend,
  type DocumentTemplateRow,
  type PaperSize,
  type PrintBlock,
  type PrintTemplateConfig,
} from "@/lib/types/print-template"

interface Props {
  /** Si viene `existing`, modo edición; sino, modo creación. */
  existing?: DocumentTemplateRow
}

const DOC_TYPES: Array<{ value: DocumentTemplateRow["docType"]; label: string }> = [
  { value: "receipt",   label: "Ticket POS" },
  { value: "invoice",   label: "Factura" },
  { value: "quote",     label: "Presupuesto" },
  { value: "workorder", label: "Orden de trabajo" },
  { value: "giftcard",  label: "Gift card" },
  { value: "delivery",  label: "Remito" },
]

const PAPER_SIZE_OPTIONS: Array<{ value: PaperSize; label: string }> = (
  Object.keys(PAPER_DIMENSIONS) as PaperSize[]
).map((k) => ({ value: k, label: PAPER_DIMENSIONS[k].label }))

/**
 * Editor visual de plantillas de impresión.
 *
 * Layout 3 columnas:
 *   - Izquierda: paleta de bloques (acordeón por categoría).
 *   - Centro: canvas con el papel a escala 1mm = `mm` px, bloques draggables.
 *   - Derecha: inspector del bloque seleccionado.
 *
 * Persistencia: el `config` se guarda con el shape EXACTO del legacy para que
 * el motor `ducumentPrintBuilder.js` del POS lo siga consumiendo sin cambios.
 */
export function TemplateEditor({ existing }: Props) {
  const router = useRouter()
  const create = useCreateDocumentTemplate()
  const update = useUpdateDocumentTemplate()

  const initialConfig: PrintTemplateConfig = React.useMemo(() => {
    if (existing?.config && typeof existing.config === "object" && "page_size" in existing.config) {
      // Shape legacy completo en el config — usarlo verbatim.
      return existing.config as PrintTemplateConfig
    }
    return defaultTemplateConfig("a4page")
  }, [existing])

  const [name, setName] = React.useState(existing?.name ?? "")
  const [docType, setDocType] = React.useState<DocumentTemplateRow["docType"]>(
    existing?.docType ?? "invoice",
  )
  const [config, setConfig] = React.useState<PrintTemplateConfig>(initialConfig)
  const [selectedIdx, setSelectedIdx] = React.useState<number | null>(null)
  const [previewOpen, setPreviewOpen] = React.useState(false)

  // Guides al arrastrar/redimensionar — top/mid/bottom + left/midX/right del bloque
  // siendo movido, atravesando todo el canvas para alinearlo visualmente con otros.
  const [guides, setGuides] = React.useState<{
    top: number
    left: number
    width: number
    height: number
  } | null>(null)

  // Sentinel para medir 1mm → px en el browser actual.
  const mmRef = React.useRef<HTMLDivElement>(null)
  const [mm, setMm] = React.useState<number>(initialConfig.mm || 3.78)
  React.useLayoutEffect(() => {
    const el = mmRef.current
    if (!el) return
    const measured = el.getBoundingClientRect().height
    if (measured > 0 && Math.abs(measured - mm) > 0.01) {
      setMm(measured)
      setConfig((c) => ({ ...c, mm: measured }))
    }
  }, [mm])

  const dim = PAPER_DIMENSIONS[config.page_size]
  const widthPx = dim.widthMm * mm
  const heightPx = dim.heightMm * mm
  const ticket = isReceipt(config.page_size)

  // ── Mutadores de config ────────────────────────────────────────────────────

  const setBlocks = React.useCallback((updater: (prev: PrintBlock[]) => PrintBlock[]) => {
    setConfig((c) => ({ ...c, data: updater(c.data) }))
  }, [])

  const handleAddBlock = (item: PaletteItem) => {
    const block = defaultBlock(item.type, item.defaultText)
    if (ticket) {
      // En tickets, los bloques ocupan toda la fila — left=0, width = canvas
      block.left = 0
      block.width = Math.round(widthPx)
    }
    setBlocks((prev) => [...prev, block])
    setSelectedIdx(config.data.length) // index del nuevo
  }

  const updateBlock = (idx: number, patch: Partial<PrintBlock>) => {
    setBlocks((prev) => prev.map((b, i) => (i === idx ? { ...b, ...patch } : b)))
  }

  const deleteBlock = (idx: number) => {
    setBlocks((prev) => prev.filter((_, i) => i !== idx))
    setSelectedIdx(null)
  }

  const cloneBlock = (idx: number) => {
    setBlocks((prev) => {
      const src = prev[idx]
      if (!src) return prev
      const clone: PrintBlock = { ...src, top: src.top + 10, left: src.left + 10 }
      return [...prev, clone]
    })
  }

  const handlePaperSize = (next: PaperSize) => {
    setConfig((c) => ({
      ...c,
      page_size: next,
      page_size_name: `Formato: ${PAPER_DIMENSIONS[next].label}`,
    }))
    setSelectedIdx(null)
  }

  // ── Save ───────────────────────────────────────────────────────────────────

  const handleSave = async () => {
    const trimmedName = name.trim()
    if (!trimmedName) {
      toast.error("Falta el nombre de la plantilla")
      return
    }
    const payload = {
      name: trimmedName,
      docType,
      pageSize: paperSizeToBackend(config.page_size),
      config: { ...config, page_name: trimmedName } as unknown as Record<string, unknown>,
    }
    try {
      if (existing) {
        await update.mutateAsync({ id: existing.templateId, values: payload })
        toast.success("Plantilla actualizada")
      } else {
        const created = await create.mutateAsync(payload)
        toast.success("Plantilla creada")
        router.replace(`/settings/print-templates?id=${created.templateId}`)
      }
    } catch (e) {
      toast.error("No se pudo guardar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  const saving = create.isPending || update.isPending
  const selected = selectedIdx !== null ? config.data[selectedIdx] ?? null : null

  return (
    <div className="flex h-[calc(100vh-3.5rem)] flex-col">
      {/* Sentinel para medir 1mm en px en el browser. */}
      <div ref={mmRef} style={{ height: "1mm", width: 0, position: "absolute", visibility: "hidden" }} />

      {/* Header */}
      <header className="flex items-center gap-3 border-b px-4 py-2.5">
        <Button variant="ghost" size="icon" className="size-8" onClick={() => router.push("/settings")}>
          <ChevronLeft className="size-4" />
        </Button>
        <Input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Nombre de la plantilla"
          className="h-9 max-w-md"
        />
        <Select value={docType} onValueChange={(v) => setDocType(v as DocumentTemplateRow["docType"])}>
          <SelectTrigger className="h-9 w-[170px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {DOC_TYPES.map((d) => (
              <SelectItem key={d.value} value={d.value}>
                {d.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select value={config.page_size} onValueChange={(v) => handlePaperSize(v as PaperSize)}>
          <SelectTrigger className="h-9 w-[200px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PAPER_SIZE_OPTIONS.map((p) => (
              <SelectItem key={p.value} value={p.value}>
                {p.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <div className="ml-auto flex items-center">
          {/* Split button: izq → guardar; der (chevron) → dropdown con Vista Previa. */}
          <Button
            onClick={handleSave}
            disabled={saving}
            className="rounded-r-none"
          >
            {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
            Guardar
          </Button>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                disabled={saving}
                className="rounded-l-none border-l border-primary-foreground/20 px-2"
                aria-label="Más opciones"
              >
                <ChevronDown className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onSelect={() => setPreviewOpen(true)}>
                <Eye className="size-4" />
                Vista previa
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>

      <PreviewDialog
        open={previewOpen}
        config={config}
        mm={mm}
        onClose={() => setPreviewOpen(false)}
      />

      {/* Body 3 columnas */}
      <div className="flex flex-1 overflow-hidden">
        {/* Paleta */}
        <aside className="w-[220px] shrink-0 border-r bg-muted/20">
          <PaletteSidebar
            paperSize={config.page_size}
            onAddBlock={handleAddBlock}
          />
        </aside>

        {/* Canvas */}
        <main
          className="relative flex-1 overflow-auto bg-muted/40 p-8"
          onMouseDown={() => {
            setSelectedIdx(null)
            setGuides(null)
          }}
        >
          <div
            className="relative mx-auto border border-dashed border-primary/50 bg-white"
            style={{
              width: `${widthPx}px`,
              height: `${heightPx}px`,
              fontFamily: config.page_font_family,
              fontSize: config.page_font_size,
              textTransform: config.page_font_case,
            }}
            onMouseDown={(e) => {
              e.stopPropagation()
              setSelectedIdx(null)
              setGuides(null)
            }}
          >
            {config.data.map((b, i) => (
              <CanvasBlock
                key={i}
                index={i}
                block={b}
                selected={selectedIdx === i}
                paperSize={config.page_size}
                mm={mm}
                onSelect={() => setSelectedIdx(i)}
                onChange={(patch) => updateBlock(i, patch)}
                onDelete={() => deleteBlock(i)}
                onClone={() => cloneBlock(i)}
                onDragGuides={setGuides}
              />
            ))}
            {guides && <DragGuides guides={guides} />}
          </div>
        </main>

        {/* Inspector */}
        <aside className="w-[260px] shrink-0 border-l bg-background">
          <BlockInspector
            block={selected}
            onChange={(patch) => {
              if (selectedIdx !== null) updateBlock(selectedIdx, patch)
            }}
          />
        </aside>
      </div>
    </div>
  )
}

/**
 * 6 líneas punteadas que cruzan el canvas durante un drag/resize — top/mid/
 * bottom y left/midX/right del bloque siendo movido. Sirven para alinearlo
 * visualmente con otros bloques. Réplica del comportamiento del legacy
 * (#guide-h, #guide2-h, #guide3-h y sus verticales).
 */
function DragGuides({ guides }: { guides: { top: number; left: number; width: number; height: number } }) {
  const { top, left, width, height } = guides
  return (
    <>
      {/* Horizontales: top / mid / bottom */}
      <div className="pointer-events-none absolute left-0 right-0 border-t border-dashed border-primary/70" style={{ top: `${top}px` }} />
      <div className="pointer-events-none absolute left-0 right-0 border-t border-dashed border-primary/40" style={{ top: `${top + height / 2}px` }} />
      <div className="pointer-events-none absolute left-0 right-0 border-t border-dashed border-primary/70" style={{ top: `${top + height}px` }} />
      {/* Verticales: left / midX / right */}
      <div className="pointer-events-none absolute top-0 bottom-0 border-l border-dashed border-primary/70" style={{ left: `${left}px` }} />
      <div className="pointer-events-none absolute top-0 bottom-0 border-l border-dashed border-primary/40" style={{ left: `${left + width / 2}px` }} />
      <div className="pointer-events-none absolute top-0 bottom-0 border-l border-dashed border-primary/70" style={{ left: `${left + width}px` }} />
    </>
  )
}
