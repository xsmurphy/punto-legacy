"use client"

import * as React from "react"
import { Minus, Plus, Printer } from "lucide-react"

import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { buildTemplatePreviewHtml, simulateTemplatePrint } from "@/lib/hardware/printers"
import type { TicketData } from "@/lib/hardware/printers/build-ticket-data"
import {
  isReceipt,
  PAPER_DIMENSIONS,
  type PrintTemplateConfig,
} from "@/lib/types/print-template"
import { nearestReceiptPaperWidthMm } from "@/lib/hardware/printers/roll-grid"

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
 * guardar. Renderiza el HTML del motor de impresión REAL
 * (`buildTemplatePreviewHtml` → `renderTemplateToHtml`, el mismo que usan
 * `printSale`/`printTest`/`simulateTemplatePrint`) dentro de un `<iframe>`,
 * en vez de reimplementar el layout con CSS aparte.
 *
 * Antes este componente tenía SU PROPIA copia del algoritmo de posicionado
 * (`layoutPreviewEntries` — un `switch` de resolvers y un cálculo de
 * `top`/`left` en paralelo al de `html-renderer.ts`), y el botón "Imprimir"
 * imprimía el DOM del propio modal con hacks de `@media print`
 * (`visibility:hidden` global + `transform:scale` reseteado) en vez de pasar
 * por el motor real — la causa de que "Imprimir desde la vista previa"
 * saliera con una página en blanco y el contenido cortado, y de que el
 * bug de posicionado de filas de ítem (`html-renderer.ts`, `renderSheetBody`)
 * no se viera acá con la misma severidad: el fit-to-screen (`transform:
 * scale` capeado en 1) comprimía visualmente el mismo desfasaje que en papel
 * físico (100% de escala) se veía como un vacío enorme entre ítems. Con un
 * solo motor generando el HTML, ambos problemas dejan de poder divergir por
 * construcción — no hay una segunda implementación que mantener sincronizada
 * a mano (este módulo ya sufrió eso una vez con `lib/print-template-mock.ts`,
 * eliminado).
 *
 * El papel se auto-escala con CSS transform para entrar completo en el
 * viewport del modal (fit-to-screen), respetando aspect ratio — el iframe
 * interno usa el tamaño físico real en mm (mismo `@page`/`body` que
 * imprime), así que la escala es puramente visual, nunca de posición.
 */
export function PreviewDialog({ open, config, mm, data, onClose }: Props) {
  const dim = PAPER_DIMENSIONS[config.page_size]
  // El papel del preview es el del DISPOSITIVO, no el del diseño. El HTML se
  // genera para la térmica real (`buildTemplatePreviewHtml` pasa
  // `nearestReceiptPaperWidthMm`: un diseño de 76mm se imprime en la térmica de
  // 80), así que el body del documento mide 80mm — y el iframe medía los 76 del
  // diseño: los 4mm de diferencia quedaban CORTADOS contra el borde derecho,
  // comiéndose el margen y el final de cada línea alineada a la derecha
  // (reporte del owner 2026-08-28, dos veces: la segunda con el fix de celdas
  // ya deployado, que fue la pista de que el corte no era de la grilla).
  const paperWidthMm = isReceipt(config.page_size)
    ? nearestReceiptPaperWidthMm(config.page_size)
    : dim.widthMm
  const widthPx = paperWidthMm * mm
  const heightPx = dim.heightMm * mm

  // Mismo HTML, mismo motor, mismo camino que "Simular impresión" — ver
  // docblock de `buildTemplatePreviewHtml` (lib/hardware/printers/index.ts).
  const html = React.useMemo(() => buildTemplatePreviewHtml(config, data), [config, data])

  const handlePrint = () => {
    // MISMO código que "Simular impresión" (TemplateEditor.handleSimulatePrint):
    // dispara el diálogo nativo del browser vía iframe oculto
    // (`triggerWindowPrint`), nunca `window.print()` sobre el DOM del modal.
    simulateTemplatePrint(config, data)
  }

  return (
    <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
      <DialogContent
        showCloseButton
        className="w-[95vw] h-[95vh] max-w-none sm:max-w-none p-0 gap-0 overflow-hidden flex flex-col"
      >
        <DialogHeader className="flex-row items-center justify-between border-b px-6 py-4 pr-12">
          <div>
            <DialogTitle>Vista previa</DialogTitle>
            <DialogDescription>
              Bloques rellenados con una venta de demostración. Mismo resultado que va a papel.
            </DialogDescription>
          </div>
          <Button variant="outline" size="sm" onClick={handlePrint}>
            <Printer className="size-4" />
            Imprimir
          </Button>
        </DialogHeader>

        <ScaledPaper widthPx={widthPx} heightPx={heightPx} html={html} />
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
  html,
}: {
  widthPx: number
  heightPx: number
  html: string
}) {
  const containerRef = React.useRef<HTMLDivElement>(null)
  const [scale, setScale] = React.useState(1)
  /**
   * Zoom manual. `null` = "encajar en la ventana", que es el default y lo que
   * calcula el efecto de abajo. Apenas el usuario toca +/− pasa a mandar él:
   * un ticket largo entra entero pero queda ilegible, y hasta ahora no había
   * forma de acercarlo (pedido del owner 2026-08-28).
   */
  const [zoom, setZoom] = React.useState<number | null>(null)
  const ZOOM_STEPS = [0.5, 0.75, 1, 1.5, 2, 3]
  /** Próximo/anterior paso a partir de la escala VISIBLE, así el primer click
   *  parte de lo que el usuario está viendo y no de un valor abstracto. */
  const nextStep = (from: number) => ZOOM_STEPS.find((z) => z > from + 0.001) ?? ZOOM_STEPS[ZOOM_STEPS.length - 1]
  const prevStep = (from: number) =>
    [...ZOOM_STEPS].reverse().find((z) => z < from - 0.001) ?? ZOOM_STEPS[0]

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
      const fit = Math.min(1, sx, sy)
      const next = zoom ?? fit
      setScale((prev) => (Math.abs(prev - next) > 0.005 ? next : prev))
    }
    compute()
    const obs = new ResizeObserver(compute)
    obs.observe(el)
    return () => obs.disconnect()
  }, [widthPx, heightPx, zoom])

  // Wrapper con dimensiones VISUALES (escaladas) para que el flex padre
  // centre/scrollee según el tamaño aparente del papel — `transform: scale`
  // no afecta layout, por eso el papel adentro va `position: absolute` con
  // origen top-left y el wrapper exterior reserva su tamaño escalado.
  return (
    <div ref={containerRef} className="relative flex flex-1 flex-col overflow-hidden bg-muted/40">
      {/* Controles de zoom: un ticket largo entra entero en la ventana pero
          queda ilegible, y no había forma de acercarlo (pedido del owner
          2026-08-28). "Ajustar" vuelve al auto-fit. */}
      <div className="absolute right-4 top-4 z-10 flex items-center gap-1 rounded-2xl border bg-background/90 p-1 shadow-sm backdrop-blur">
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          aria-label="Alejar"
          onClick={() => setZoom(prevStep(scale))}
        >
          <Minus className="size-4" />
        </Button>
        <span className="min-w-12 text-center text-xs tabular-nums text-muted-foreground">
          {Math.round(scale * 100)}%
        </span>
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          aria-label="Acercar"
          onClick={() => setZoom(nextStep(scale))}
        >
          <Plus className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          className="h-8 px-2 text-xs"
          onClick={() => setZoom(null)}
          disabled={zoom === null}
        >
          Ajustar
        </Button>
      </div>
      <div className="flex flex-1 items-start justify-center overflow-auto p-6">
      <div
        className="relative shrink-0"
        style={{ width: `${widthPx * scale}px`, height: `${heightPx * scale}px` }}
      >
        {/* El borde del papel va en ESTE wrapper y no en el iframe.
            Con `box-sizing: border-box` (global de Tailwind), un borde en el
            iframe le come 2px de VIEWPORT: el documento de adentro mide el ancho
            exacto del papel, así que sobraban ~2px y la última columna de
            caracteres quedaba cortada contra el borde — se veía como "el texto
            se sale del papel" (reporte del owner 2026-08-28). Acá el borde es
            decoración alrededor y el iframe conserva su ancho completo. */}
        <div
          aria-hidden
          className="pointer-events-none absolute left-0 top-0 border border-dashed border-muted-foreground/30"
          style={{ width: `${widthPx * scale}px`, height: `${heightPx * scale}px` }}
        />
        <iframe
          title="Vista previa del documento"
          srcDoc={html}
          className="absolute left-0 top-0 bg-white shadow-sm"
          style={{
            width: `${widthPx}px`,
            height: `${heightPx}px`,
            transform: `scale(${scale})`,
            transformOrigin: "top left",
            border: "none",
          }}
        />
        </div>
      </div>
    </div>
  )
}
