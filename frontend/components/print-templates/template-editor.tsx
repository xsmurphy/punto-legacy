"use client"

import * as React from "react"
import { toast } from "sonner"
import { useRouter } from "next/navigation"
import {
  AlignVerticalSpaceAround,
  ChevronDown,
  ChevronLeft,
  Eye,
  Loader2,
  Printer,
  Save,
  Trash2,
} from "lucide-react"

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
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog"
import { PaletteSidebar } from "@/components/print-templates/palette-sidebar"
import { BlockInspector } from "@/components/print-templates/block-inspector"
import { CanvasBlock } from "@/components/print-templates/canvas-block"
import { PreviewDialog } from "@/components/print-templates/preview-dialog"
import {
  useCreateDocumentTemplate,
  useDeleteDocumentTemplate,
  useUpdateDocumentTemplate,
} from "@/hooks/use-document-templates"
import { useTaxes } from "@/hooks/use-taxes"
import { buildDemoTicketData, buildTemplateTestData } from "@/lib/hardware/printers/build-ticket-data"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { simulateTemplatePrint } from "@/lib/hardware/printers"
import {
  rollFontSizeFor,
  rollGeometry,
  ROLL_FONT_STACK,
  snapBlockToRollRows,
} from "@/lib/hardware/printers/roll-grid"
import { getBlockPlaceholder, type PaletteItem } from "@/lib/print-template-palette"
import { useUnsavedChangesGuard } from "@/hooks/use-unsaved-changes-guard"
import {
  MIN_BLOCK_SIZE,
  PAPER_DIMENSIONS,
  canonicalizeTemplateForCompare,
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
  const del = useDeleteDocumentTemplate()
  // F3c (context/38 §D): tasas del tenant para la sección "Impuestos" de la
  // paleta (una entrada por tasa) — ver PaletteSidebar/print-template-palette.ts.
  const taxesQuery = useTaxes()

  // Venta de demo — ÚNICA fuente para "qué muestra un bloque" en el editor:
  // el thumbnail de cada bloque en el canvas (CanvasBlock) y la Vista Previa
  // completa (PreviewDialog) resuelven contra los mismos datos, con el mismo
  // catálogo de bloques que usan los renderers reales (blocks.ts). Antes cada
  // superficie tenía su propio diccionario de texto hardcodeado — ver
  // buildDemoTicketData (build-ticket-data.ts) para el porqué.
  // La moneda/separadores del demo salen del tenant REAL: el comercio está
  // diseñando el ticket que va a imprimir y tiene que verlo con su propio
  // símbolo, no con uno de otro país (antes era "Gs" fijo).
  const bootstrapQuery = useBootstrap()
  const demoData = React.useMemo(
    () => buildDemoTicketData(taxesQuery.data?.taxes ?? [], bootstrapQuery.data),
    [taxesQuery.data, bootstrapQuery.data],
  )

  const initialConfig: PrintTemplateConfig = React.useMemo(() => {
    return parseStoredConfig(existing?.config)
  }, [existing])

  const [name, setName] = React.useState(existing?.name ?? "")
  const [docType, setDocType] = React.useState<DocumentTemplateRow["docType"]>(
    existing?.docType ?? "invoice",
  )
  const [config, setConfig] = React.useState<PrintTemplateConfig>(initialConfig)
  // Selección múltiple (marquee, Shift/Cmd+click) — reemplaza el índice único
  // que tenía el editor antes. Un solo bloque seleccionado sigue siendo el
  // caso común (array de largo 1); ver `selectedSet`/`selectedBlocks` abajo
  // y `handleBlockMouseDown`/`handlePaperMouseDown` para cómo se puebla.
  const [selectedIndices, setSelectedIndices] = React.useState<number[]>([])
  const [previewOpen, setPreviewOpen] = React.useState(false)

  const selectedSet = React.useMemo(() => new Set(selectedIndices), [selectedIndices])
  const selectedBlocks = React.useMemo(
    () => selectedIndices.map((i) => config.data[i]).filter((b): b is PrintBlock => b !== undefined),
    [selectedIndices, config.data],
  )

  // Guides al arrastrar/redimensionar — top/mid/bottom + left/midX/right del bloque
  // siendo movido, atravesando todo el canvas para alinearlo visualmente con otros.
  const [guides, setGuides] = React.useState<{
    top: number
    left: number
    width: number
    height: number
  } | null>(null)

  // Rectángulo de selección por arrastre (marquee) — coordenadas en px
  // LOCALES al papel (no a la pantalla), ver `handlePaperMouseDown`.
  const [marquee, setMarquee] = React.useState<{
    x0: number
    y0: number
    x1: number
    y1: number
  } | null>(null)
  const paperRef = React.useRef<HTMLDivElement>(null)

  // El marquee y el "click vs. drag de grupo" (handleBlockMouseDown más
  // abajo) enganchan listeners de `window` de forma imperativa en el
  // mousedown, fuera del ciclo de vida normal de React — si el componente
  // se desmonta a mitad de un drag (navegación, remount por cambio de
  // `existing`) esos listeners quedan colgados referenciando closures de un
  // componente muerto. Este registro + el useEffect de abajo garantizan que
  // se limpien igual al desmontar.
  const activeListenersRef = React.useRef<Set<() => void>>(new Set())
  React.useEffect(() => {
    const active = activeListenersRef.current
    return () => {
      active.forEach((cleanup) => cleanup())
      active.clear()
    }
  }, [])

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
  // Geometría del rollo (null en hoja) — la MISMA que usan la vista previa y
  // el emisor ESC/POS, así que el canvas no puede quedar desincronizado.
  const rollGeo = React.useMemo(
    () => (isReceipt(config.page_size) ? rollGeometry(config.page_size, mm) : null),
    [config.page_size, mm],
  )
  const heightPx = dim.heightMm * mm
  const ticket = isReceipt(config.page_size)

  // ── Cambios sin guardar ────────────────────────────────────────────────────
  // El editor es trabajo de precisión y no tiene autoguardado: salir sin
  // guardar tira todo el posicionamiento (pedido owner 2026-08-24). El
  // baseline es lo ÚLTIMO que se persistió — arranca en lo que vino del
  // backend y se re-basa en cada guardado exitoso, no en cada edición.
  //
  // La comparación va contra la forma CANÓNICA (`canonicalizeTemplateForCompare`
  // en lib/types/print-template.ts): el `mm` re-medido al montar y el
  // auto-clamp de bloques contra el papel mueven el config sin que el usuario
  // toque nada, y marcar eso como "sin guardar" sería el falso positivo que
  // hace que el aviso se ignore.
  // El baseline se guarda CRUDO (no canonicalizado): la canonicalización
  // depende de `mm`, que se re-mide después del primer render, así que los dos
  // lados tienen que pasar por la misma escala en el mismo momento. Guardar el
  // string ya canonicalizado con el `mm` viejo marcaría todo como sucio apenas
  // el sentinel corrige la medición.
  //
  // No hace falta re-basar cuando cambia `existing`: la página monta el editor
  // con `key={templateId}` (ver app/(panel)/settings/print-templates/page.tsx),
  // así que cambiar de plantilla es un remount y este state nace de nuevo.
  const [saved, setSaved] = React.useState(() => ({
    name: existing?.name ?? "",
    docType: (existing?.docType ?? "invoice") as DocumentTemplateRow["docType"],
    config: initialConfig,
  }))

  const dirty =
    name !== saved.name ||
    docType !== saved.docType ||
    canonicalizeTemplateForCompare(config, mm) !==
      canonicalizeTemplateForCompare(saved.config, mm)

  // Cubre cerrar/recargar la pestaña y los clicks a links dentro de la app.
  // La navegación por código (el botón "volver" de acá abajo) se cubre
  // llamando a `confirmDiscard()` — ver docblock del hook.
  const guard = useUnsavedChangesGuard(dirty)

  // ── Mutadores de config ────────────────────────────────────────────────────

  const setBlocks = React.useCallback((updater: (prev: PrintBlock[]) => PrintBlock[]) => {
    setConfig((c) => ({ ...c, data: updater(c.data) }))
  }, [])

  const handleAddBlock = (item: PaletteItem) => {
    const block = defaultBlock(item.type, item.defaultText)
    if (ticket) {
      // En tickets, los bloques ocupan toda la fila — left=0, width = canvas
      // (regla owner 2026-08-18: 100% del ancho siempre, sin excepción). El
      // efecto de `applyReceiptWidthRule` en canvas-block.tsx re-aplica esto
      // en cada render, así que fijarlo acá también es solo evitar el
      // parpadeo de un bloque angosto en el primer frame.
      block.left = 0
      block.width = Math.round(widthPx)
      // Alto: UNA fila de caracteres. El default de `defaultBlock` son 24px,
      // que sobre filas de ~12px son DOS filas — un bloque nuevo nacía con un
      // renglón en blanco pegado abajo (ver `snapBlockToRollRows`).
      if (rollGeo) Object.assign(block, snapBlockToRollRows({ ...block, height: 1 }, rollGeo))
    } else if (block.type !== "company_logo" && block.type !== "hor_line" && block.type !== "ver_line") {
      // En papel, el bloque nace ajustado al tamaño de su contenido en vez
      // del ancho fijo de 100px de siempre (cabo pendiente de una tanda
      // anterior, ver comentario en `clampBlockToPaper` sobre no inventar
      // cuentas paralelas). Aproximación con Canvas 2D sobre el placeholder
      // del catálogo (`getBlockPlaceholder`, mismo texto que se ve en el
      // bloque antes de resolver contra la venta de demo) con la tipografía
      // de la página — el dato real varía por venta, así que esto es un
      // punto de partida razonable, no una medida exacta: el resize está
      // habilitado en papel, el usuario ajusta después. `company_logo` no
      // tiene texto (mantiene el ancho por defecto) y las líneas
      // hor_line/ver_line no tienen "contenido" que medir — su ancho por
      // defecto ya es intencional (línea decorativa), no un cabo pendiente.
      const placeholder = getBlockPlaceholder(block, taxesQuery.data?.taxes ?? [])
      // Se mide con la tipografía REAL del papel: en rollo es la monoespaciada
      // de la grilla, no la que diga la plantilla (ver ROLL_FONT_STACK). Con la
      // fuente de la plantilla, un bloque nuevo nacía con un ancho que no
      // correspondía a ninguna cantidad de columnas.
      const estimated = estimateContentWidth(
        placeholder,
        rollGeo ? ROLL_FONT_STACK : config.page_font_family,
        rollGeo ? `${rollFontSizeFor(widthPx, rollGeo.columns).toFixed(2)}px` : config.page_font_size,
      )
      block.width = Math.max(MIN_BLOCK_SIZE, Math.min(estimated, Math.round(widthPx)))
    }
    setBlocks((prev) => [...prev, block])
    setSelectedIndices([config.data.length]) // index del nuevo
  }

  const updateBlock = (idx: number, patch: Partial<PrintBlock>) => {
    setBlocks((prev) => prev.map((b, i) => (i === idx ? { ...b, ...patch } : b)))
  }

  const deleteBlock = (idx: number) => {
    setBlocks((prev) => prev.filter((_, i) => i !== idx))
    setSelectedIndices([])
  }

  const deleteSelected = () => {
    if (selectedIndices.length === 0) return
    const toDelete = new Set(selectedIndices)
    setBlocks((prev) => prev.filter((_, i) => !toDelete.has(i)))
    setSelectedIndices([])
  }

  // Delta (no posición absoluta) aplicado a TODOS los bloques del grupo
  // seleccionado — mantiene las posiciones relativas entre ellos. `anchorIdx`
  // es el bloque que el usuario efectivamente arrastró con el mouse (el
  // único que react-rnd mueve de verdad, ver canvas-block.tsx); si no forma
  // parte de una selección múltiple, el "grupo" es él solo — mismo
  // comportamiento que un drag suelto de siempre. Clampea cada bloque a los
  // bordes del papel individualmente (igual que react-rnd hace con
  // `bounds="parent"` para un bloque solo) — si el grupo se arrastra hasta
  // el borde, el bloque que llega primero se frena ahí mientras el resto
  // sigue el delta pedido; no es la física de un rectángulo rígido, pero
  // evita que un bloque del grupo termine con coordenadas negativas o fuera
  // de la hoja.
  const moveBlocksBy = (anchorIdx: number, dx: number, dy: number) => {
    const group =
      selectedIndices.includes(anchorIdx) && selectedIndices.length > 1 ? selectedIndices : [anchorIdx]
    const groupSet = new Set(group)
    setBlocks((prev) =>
      prev.map((b, i) => {
        if (!groupSet.has(i)) return b
        const maxLeft = Math.max(0, widthPx - b.width)
        const maxTop = Math.max(0, heightPx - b.height)
        return {
          ...b,
          left: Math.min(Math.max(0, b.left + dx), maxLeft),
          top: Math.min(Math.max(0, b.top + dy), maxTop),
        }
      }),
    )
  }

  // Mousedown sobre un bloque — decide qué hace la selección ANTES de que
  // react-rnd arranque su propio drag (mismo onMouseDown que ya dispara la
  // selección de siempre, extendido acá en vez de en paralelo):
  //  - Shift/Cmd: suma o resta este bloque de la selección, al toque.
  //  - Click simple sobre un bloque que YA es parte de un grupo (>1): no
  //    colapsa la selección todavía — si lo que sigue es un drag, tiene que
  //    mover TODO el grupo (comportamiento Finder/Explorer: arrastrar un
  //    ítem de una selección múltiple mueve la selección entera). Si en vez
  //    de arrastrar el mouse sube sin moverse (un click de verdad), recién
  //    ahí colapsa a este bloque solo.
  //  - Cualquier otro caso: selección directa de este bloque nomás.
  const handleBlockMouseDown = (idx: number, e: MouseEvent) => {
    const toggle = e.shiftKey || e.metaKey || e.ctrlKey
    if (toggle) {
      setSelectedIndices((prev) =>
        prev.includes(idx) ? prev.filter((i) => i !== idx) : [...prev, idx],
      )
      return
    }
    const alreadyInGroup = selectedIndices.length > 1 && selectedIndices.includes(idx)
    if (alreadyInGroup) {
      const startX = e.clientX
      const startY = e.clientY
      const onUp = (ev: MouseEvent) => {
        cleanup()
        const moved = Math.hypot(ev.clientX - startX, ev.clientY - startY) > 3
        if (!moved) setSelectedIndices([idx])
      }
      const cleanup = () => {
        window.removeEventListener("mouseup", onUp)
        activeListenersRef.current.delete(cleanup)
      }
      window.addEventListener("mouseup", onUp)
      activeListenersRef.current.add(cleanup)
      return
    }
    setSelectedIndices([idx])
  }

  // Mousedown sobre el área vacía del papel — solo llega acá cuando el
  // origen del drag NO fue un bloque (CanvasBlock hace stopPropagation en su
  // propio onMouseDown, ver canvas-block.tsx), así que esto es justo
  // "arrastre desde vacío = marquee, arrastre desde un bloque = mover ese
  // bloque". Un click sin arrastre (mousedown+mouseup sin moverse) limpia la
  // selección, igual que antes.
  const handlePaperMouseDown = (e: React.MouseEvent) => {
    e.stopPropagation()
    if (e.button !== 0) return
    const rect = paperRef.current?.getBoundingClientRect()
    if (!rect) return
    const startX = e.clientX - rect.left
    const startY = e.clientY - rect.top
    const additive = e.shiftKey || e.metaKey || e.ctrlKey
    const baseline = additive ? selectedIndices : []
    let moved = false
    if (!additive) setSelectedIndices([])
    setGuides(null)

    const onMove = (ev: MouseEvent) => {
      const r = paperRef.current?.getBoundingClientRect()
      if (!r) return
      const x = ev.clientX - r.left
      const y = ev.clientY - r.top
      if (!moved && Math.hypot(x - startX, y - startY) < 4) return
      moved = true
      const x0 = Math.min(startX, x)
      const x1 = Math.max(startX, x)
      const y0 = Math.min(startY, y)
      const y1 = Math.max(startY, y)
      setMarquee({ x0, y0, x1, y1 })
      const hits: number[] = []
      config.data.forEach((b, i) => {
        const intersects = b.left < x1 && b.left + b.width > x0 && b.top < y1 && b.top + b.height > y0
        if (intersects) hits.push(i)
      })
      setSelectedIndices(additive ? Array.from(new Set([...baseline, ...hits])) : hits)
    }
    const onUp = () => {
      cleanup()
      setMarquee(null)
    }
    const cleanup = () => {
      window.removeEventListener("mousemove", onMove)
      window.removeEventListener("mouseup", onUp)
      activeListenersRef.current.delete(cleanup)
    }
    window.addEventListener("mousemove", onMove)
    window.addEventListener("mouseup", onUp)
    activeListenersRef.current.add(cleanup)
  }

  // Supr/Backspace borra la selección (uno o varios). Escape la limpia. La
  // toolbar flotante y el click en vacío siguen siendo el camino visible,
  // pero el teclado es el reflejo de cualquier editor de layout y no
  // depende de que ese chrome se vea (ver bug 2026-07-30 documentado en
  // canvas-block.tsx: el overflow:hidden lo recortaba).
  React.useEffect(() => {
    function onKeyDown(e: KeyboardEvent) {
      // No robar la tecla mientras se escribe en el inspector.
      const el = e.target as HTMLElement | null
      const tag = el?.tagName
      if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT" || el?.isContentEditable) return
      if (e.key === "Escape") {
        setSelectedIndices([])
        return
      }
      if (e.key !== "Delete" && e.key !== "Backspace") return
      if (selectedIndices.length === 0) return
      e.preventDefault()
      deleteSelected()
    }
    window.addEventListener("keydown", onKeyDown)
    return () => window.removeEventListener("keydown", onKeyDown)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedIndices])

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
    setSelectedIndices([])
  }

  // ── Save ───────────────────────────────────────────────────────────────────

  const handleSave = async () => {
    const trimmedName = name.trim()
    if (!trimmedName) {
      toast.error("Falta el nombre de la plantilla")
      return
    }
    // `page_name` se sincroniza con el nombre AL GUARDAR — se persiste dentro
    // del config aunque el input viva aparte. El mismo objeto que se manda es
    // el que queda como baseline y como state local, así los tres coinciden y
    // el guard de cambios sin guardar no queda sucio para siempre por un campo
    // que el usuario nunca ve.
    const savedConfig: PrintTemplateConfig = { ...config, page_name: trimmedName }
    const payload = {
      name: trimmedName,
      docType,
      pageSize: paperSizeToBackend(config.page_size),
      config: savedConfig as unknown as Record<string, unknown>,
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
      // Guardado OK → esto es lo persistido. Desarma el aviso de cambios sin
      // guardar hasta la próxima edición.
      setName(trimmedName)
      setConfig(savedConfig)
      setSaved({ name: trimmedName, docType, config: savedConfig })
    } catch (e) {
      // Logueamos a consola con el payload completo para diagnóstico — el toast
      // solo muestra el message del backend, que puede ser corto.
      console.error("[print-templates] save failed", { payload, error: e })
      const description = e instanceof Error ? e.message : "Error desconocido"
      toast.error("No se pudo guardar", {
        description,
        duration: 10_000,
      })
    }
  }

  const handleDelete = async () => {
    if (!existing) return
    try {
      await del.mutateAsync(existing.templateId)
      toast.success("Plantilla eliminada")
      router.push("/settings")
    } catch (e) {
      toast.error("No se pudo eliminar", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  /**
   * Alinea TODOS los bloques a la grilla de caracteres del rollo.
   *
   * Para plantillas que ya existen: se diseñaron con snap de 1mm, así que sus
   * `top`/`height` caen entre filas y el renderer los redondea — de ahí los
   * renglones en blanco entre bloques que en el canvas se tocan. Los bloques
   * nuevos ya nacen alineados y el drag/resize ahora snapea, así que esto es
   * para el diseño heredado, una vez.
   */
  const handleSnapToGrid = () => {
    if (!rollGeo) return
    setBlocks((prev) => prev.map((b) => snapBlockToRollRows(b, rollGeo)))
    toast.success("Bloques alineados a la grilla del papel")
  }

  // "Simular impresión" — dispara el diálogo de impresión REAL del browser
  // (mismo camino que el botón "Probar" de Ajustes → Impresoras en transport
  // native, y que el botón "Imprimir" de PreviewDialog — los tres llaman
  // `simulateTemplatePrint`/`renderTemplateToHtml`, un solo motor). Usa
  // `config` TAL CUAL está en el editor en este momento — incluye cambios
  // sin guardar, porque es el mismo state de React, no una relectura del
  // backend. Los datos son la MISMA venta de demo que ya alimenta el
  // canvas/PreviewDialog (`buildDemoTicketData`, memoizada en `demoData`),
  // pasada por `buildTemplateTestData` para enmascarar cliente + forzar el
  // correlativo de ejemplo — mismo tratamiento que `buildTicketDataForTest`
  // le da al ticket de prueba del POS (build-ticket-data.ts).
  const handleSimulatePrint = () => {
    const testData = buildTemplateTestData(taxesQuery.data?.taxes ?? [], bootstrapQuery.data)
    simulateTemplatePrint(config, testData)
  }

  const saving = create.isPending || update.isPending

  return (
    <div className="flex h-[calc(100vh-3.5rem)] flex-col">
      {/* Sentinel para medir 1mm en px en el browser. */}
      <div ref={mmRef} style={{ height: "1mm", width: 0, position: "absolute", visibility: "hidden" }} />

      {/* Header */}
      <header className="flex items-center gap-3 border-b px-4 py-2.5">
        {/* Navegación por CÓDIGO: el listener de clicks del guard solo ve
            `<a href>`, así que este call site pregunta por su cuenta (ver
            docblock de useUnsavedChangesGuard). Sin cambios pendientes
            `confirmDiscard()` devuelve true sin molestar. */}
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          aria-label="Volver a Ajustes"
          onClick={() => {
            if (guard.confirmDiscard()) router.push("/settings")
          }}
        >
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

        <div className="ml-auto flex items-center gap-2">
          {existing && (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button
                  type="button"
                  variant="destructive"
                  size="icon"
                  className="size-9"
                  disabled={saving || del.isPending}
                  aria-label="Eliminar plantilla"
                >
                  <Trash2 className="size-4" />
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>Eliminar plantilla</AlertDialogTitle>
                  <AlertDialogDescription>
                    ¿Eliminar la plantilla <strong>{existing.name}</strong>? Esta acción no se puede deshacer.
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancelar</AlertDialogCancel>
                  <AlertDialogAction onClick={handleDelete}>Eliminar</AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}
          {/* Split button: izq → guardar; der (chevron) → dropdown con Vista Previa.
              El wrapper `inline-flex` NO es decorativo: sin él las dos mitades
              son hijas directas del contenedor `gap-2` y quedaba un hueco entre
              el botón y el chevron, que es justo lo que un split button no
              puede tener (bug 2026-07-30). Mismo patrón que order-detail-view. */}
          <div className="inline-flex">
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
                <DropdownMenuItem onSelect={handleSimulatePrint}>
                  <Printer className="size-4" />
                  Simular impresión
                </DropdownMenuItem>
                {/* Solo en ticket: en hoja la geometría es continua y no hay
                    grilla a la que alinear. Es una acción EXPLÍCITA y no un
                    arreglo al abrir la plantilla — reacomodar el diseño de
                    alguien sin que lo haya pedido es peor que el renglón de
                    más. Ver `snapBlockToRollRows`. */}
                {rollGeo && (
                  <DropdownMenuItem onSelect={handleSnapToGrid}>
                    <AlignVerticalSpaceAround className="size-4" />
                    Alinear a la grilla
                  </DropdownMenuItem>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
      </header>

      <PreviewDialog
        open={previewOpen}
        config={config}
        mm={mm}
        data={demoData}
        onClose={() => setPreviewOpen(false)}
      />

      {/* Body 3 columnas */}
      <div className="flex flex-1 overflow-hidden">
        {/* Paleta */}
        <aside className="w-[220px] shrink-0 border-r bg-muted/20">
          <PaletteSidebar
            paperSize={config.page_size}
            taxes={taxesQuery.data?.taxes}
            onAddBlock={handleAddBlock}
          />
        </aside>

        {/* Canvas — el área alrededor del papel usa un gris más oscuro en
            dark mode (zinc-900) para destacar el papel blanco como una "hoja
            real" sobre la mesa. En light, mantiene el muted suave. */}
        <main
          className="relative flex-1 overflow-auto bg-muted/40 p-8 dark:bg-zinc-900"
          onMouseDown={() => {
            setSelectedIndices([])
            setGuides(null)
          }}
        >
          <div
            ref={paperRef}
            // Borde del papel: dashed zinc (visible en cualquier modo sobre
            // fondo blanco del papel). Antes era border-primary/50 que en
            // dark mode con primary brand verde se mezclaba.
            className="relative mx-auto border border-dashed border-zinc-400 bg-white shadow-md dark:shadow-zinc-950/50"
            style={{
              // El padding es el MARGEN del papel (un carácter por lado en
              // ticket, ver ROLL_MARGIN_COLS): el área blanca de adentro es
              // exactamente donde la impresora deja poner texto, así que lo que
              // se ve acá es lo que entra en el papel.
              width: `${widthPx}px`,
              height: `${heightPx}px`,
              paddingLeft: rollGeo ? `${rollGeo.charWidthPx}px` : undefined,
              paddingRight: rollGeo ? `${rollGeo.charWidthPx}px` : undefined,
              boxSizing: "content-box",
              // En ROLLO la tipografía la manda el papel, no la plantilla: la
              // térmica imprime celdas de ancho fijo y toda la geometría son
              // columnas de caracteres. Mostrar acá la fuente elegida hacía que
              // el canvas dibujara una densidad de caracteres distinta a la que
              // sale impresa — el editor centraba bien y la vista previa salía
              // corrida y desbordada (reporte del owner 2026-08-28). En HOJA sí
              // manda la plantilla: ahí imprime el navegador.
              fontFamily: rollGeo ? ROLL_FONT_STACK : config.page_font_family,
              fontSize: rollGeo
                ? `${rollFontSizeFor(widthPx, rollGeo.columns).toFixed(2)}px`
                : config.page_font_size,
              textTransform: config.page_font_case,
            }}
            onMouseDown={handlePaperMouseDown}
          >
            {config.data.map((b, i) => (
              <CanvasBlock
                key={i}
                index={i}
                block={b}
                selected={selectedSet.has(i)}
                showToolbar={selectedIndices.length === 1 && selectedIndices[0] === i}
                paperSize={config.page_size}
                mm={mm}
                data={demoData}
                taxes={taxesQuery.data?.taxes}
                onSelect={(e) => handleBlockMouseDown(i, e)}
                onChange={(patch) => updateBlock(i, patch)}
                onMoveBy={(dx, dy) => moveBlocksBy(i, dx, dy)}
                onDelete={() => deleteBlock(i)}
                onClone={() => cloneBlock(i)}
                onDragGuides={setGuides}
              />
            ))}
            {guides && <DragGuides guides={guides} />}
            {marquee && <MarqueeBox marquee={marquee} />}
          </div>
        </main>

        {/* Inspector */}
        <aside className="w-[260px] shrink-0 border-l bg-background">
          <BlockInspector
            blocks={selectedBlocks}
            onChange={(patch) => {
              if (selectedIndices.length === 0) return
              setBlocks((prev) => prev.map((b, i) => (selectedSet.has(i) ? { ...b, ...patch } : b)))
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
  // Sobre fondo blanco del papel — uso zinc-700 sólido (no opacidad baja)
  // para que las guías se vean nítidas. El mid usa intensidad ligeramente
  // menor para distinguirlo del top/bottom sin perder contraste.
  return (
    <>
      {/* Horizontales: top / mid / bottom */}
      <div className="pointer-events-none absolute left-0 right-0 border-t border-dashed border-zinc-700" style={{ top: `${top}px` }} />
      <div className="pointer-events-none absolute left-0 right-0 border-t border-dashed border-zinc-500" style={{ top: `${top + height / 2}px` }} />
      <div className="pointer-events-none absolute left-0 right-0 border-t border-dashed border-zinc-700" style={{ top: `${top + height}px` }} />
      {/* Verticales: left / midX / right */}
      <div className="pointer-events-none absolute top-0 bottom-0 border-l border-dashed border-zinc-700" style={{ left: `${left}px` }} />
      <div className="pointer-events-none absolute top-0 bottom-0 border-l border-dashed border-zinc-500" style={{ left: `${left + width / 2}px` }} />
      <div className="pointer-events-none absolute top-0 bottom-0 border-l border-dashed border-zinc-700" style={{ left: `${left + width}px` }} />
    </>
  )
}

/**
 * Rectángulo de selección por arrastre (marquee) — se dibuja mientras el
 * usuario arrastra desde un punto vacío del canvas (handlePaperMouseDown).
 * `pointer-events-none` porque la selección se calcula por intersección de
 * coordenadas en el `mousemove` del listener en window, no por hit-testing
 * del propio rectángulo.
 */
function MarqueeBox({ marquee }: { marquee: { x0: number; y0: number; x1: number; y1: number } }) {
  const { x0, y0, x1, y1 } = marquee
  return (
    <div
      className="pointer-events-none absolute z-40 border border-primary bg-primary/10"
      style={{
        left: `${x0}px`,
        top: `${y0}px`,
        width: `${x1 - x0}px`,
        height: `${y1 - y0}px`,
      }}
    />
  )
}

/** Canvas 2D reusado entre llamadas — medir texto no toca el DOM visible,
 *  así que un único contexto offscreen alcanza para todas las estimaciones
 *  de la sesión del editor (ver `estimateContentWidth`). */
let measureCanvasCtx: CanvasRenderingContext2D | null | undefined

/**
 * Ancho aproximado (px) del `text` con la tipografía de página dada — usa
 * Canvas 2D `measureText`, la única forma de medir texto sin montarlo (ver
 * `handleAddBlock`: el bloque nuevo en modo papel nace ajustado a esto en
 * vez del ancho fijo de 100px de siempre). Es una APROXIMACIÓN a propósito,
 * no una medida exacta — el texto real de la venta varía; el resize queda
 * habilitado en papel para que el usuario la ajuste. `fontSizePt` viene en
 * el formato del config (`"8pt"`, `"inherit"`) — pt→px a 96dpi, mismo ratio
 * que usa el sentinel de 1mm del propio editor.
 */
function estimateContentWidth(text: string, fontFamily: string, fontSizePt: string): number {
  if (measureCanvasCtx === undefined) {
    measureCanvasCtx = typeof document !== "undefined" ? document.createElement("canvas").getContext("2d") : null
  }
  if (!measureCanvasCtx || !text) return 100
  const sizePt = parseFloat(fontSizePt) || 8
  const sizePx = Math.round(sizePt * (96 / 72))
  measureCanvasCtx.font = `${sizePx}px ${fontFamily === "inherit" ? "Arial" : fontFamily}`
  // + padding horizontal del contenido (`px-1` = 4px por lado en
  // canvas-block.tsx) + un margen chico para no dejarlo al ras del texto.
  return Math.round(measureCanvasCtx.measureText(text).width) + 16
}

/**
 * Normaliza el config que viene del backend. Acepta:
 *  - shape legacy completo (`{page_size, data:[...], page_font_*, ...}`)
 *  - string JSON (algunos drivers PG sin auto-decode)
 *  - objeto parcial (campos faltantes → defaults)
 *  - null/undefined/{} → defaults
 *
 * Garantiza que el resultado tenga TODOS los campos del PrintTemplateConfig
 * para que el editor renderice sin guards adicionales.
 */
function parseStoredConfig(raw: unknown): PrintTemplateConfig {
  const fallback = defaultTemplateConfig("a4page")

  // 1) Decodificar si vino como string JSON (defense contra driver PG sin auto-decode).
  let parsed: unknown = raw
  if (typeof parsed === "string" && parsed.trim() !== "") {
    try {
      parsed = JSON.parse(parsed)
    } catch {
      // String no es JSON válido — caemos a fallback.
      return fallback
    }
  }

  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
    return fallback
  }

  const obj = parsed as Record<string, unknown>

  // Merge campo por campo. Si no hay `page_size` válido, usamos default
  // pero PRESERVAMOS data si existe (caso: row con sólo data:[...]).
  const VALID_SIZES: PrintTemplateConfig["page_size"][] = [
    "a4page", "a4page-h", "legalpage", "legalpage-h",
    "letterpage", "letterpage-h", "receipt80", "receipt76", "receipt57",
  ]
  const pageSizeRaw = typeof obj.page_size === "string" ? obj.page_size : null
  const page_size = (pageSizeRaw && (VALID_SIZES as string[]).includes(pageSizeRaw))
    ? (pageSizeRaw as PrintTemplateConfig["page_size"])
    : fallback.page_size

  return {
    page_size,
    page_size_name: typeof obj.page_size_name === "string" ? obj.page_size_name : fallback.page_size_name,
    page_name: typeof obj.page_name === "string" ? obj.page_name : fallback.page_name,
    page_font_family: typeof obj.page_font_family === "string" ? obj.page_font_family : fallback.page_font_family,
    page_font_size: typeof obj.page_font_size === "string" ? obj.page_font_size : fallback.page_font_size,
    page_font_case: obj.page_font_case === "uppercase" ? "uppercase" : "none",
    receipt_left_margin: typeof obj.receipt_left_margin === "string" ? obj.receipt_left_margin : fallback.receipt_left_margin,
    mm: typeof obj.mm === "number" && obj.mm > 0 ? obj.mm : fallback.mm,
    data: Array.isArray(obj.data) ? (obj.data as PrintBlock[]) : [],
  }
}
