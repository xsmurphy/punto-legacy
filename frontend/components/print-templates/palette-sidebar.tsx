"use client"

import * as React from "react"
import { ChevronDown } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible"
import { cn } from "@/lib/utils"
import {
  filterPaletteForSize,
  substituteLabels,
  type PaletteItem,
} from "@/lib/print-template-palette"
import type { PaperSize } from "@/lib/types/print-template"
import type { Tax } from "@/lib/types/tax"

interface Props {
  paperSize: PaperSize
  /** Etiqueta del documento fiscal del país del tenant (RUC, CUIT, CNPJ…). */
  tinName?: string
  /** Etiqueta del documento personal del país del tenant (Cédula, DNI, CPF…). */
  docName?: string
  taxName?: string
  /** F3c (context/38 §D): tasas del tenant — generan la sección "Impuestos"
   *  de la paleta (una entrada por tasa). Sin esto (todavía cargando/error),
   *  la sección no aparece. */
  taxes?: Tax[]
  /** Módulo `einvoicePy` activo — habilita la sección "Factura electrónica"
   *  (QR del KuDE + CDC). Sin el módulo, esos bloques ni se ofrecen. */
  einvoiceEnabled?: boolean
  onAddBlock: (item: PaletteItem) => void
}

/**
 * Paleta lateral del editor — réplica de panel/views/settings.html (acordeón
 * por sección: Herramientas / Empresa / Cliente / Transacción / Artículos).
 *
 * Hacer click en un item agrega el bloque al canvas. El comportamiento de
 * drag-from-palette del legacy lo simplificamos: click → drop en (0,0). El
 * usuario lo mueve después.
 */
export function PaletteSidebar({ paperSize, tinName, docName, taxName, taxes, einvoiceEnabled, onAddBlock }: Props) {
  const sections = React.useMemo(
    () => filterPaletteForSize(paperSize, taxes ?? [], { einvoiceEnabled }),
    [paperSize, taxes, einvoiceEnabled],
  )

  return (
    <div className="flex h-full flex-col gap-1 overflow-y-auto px-3 py-3 text-sm">
      {sections.map((sec) => (
        <Collapsible key={sec.id} defaultOpen={sec.id === "tools"}>
          <CollapsibleTrigger asChild>
            <button className="flex w-full items-center justify-between rounded-md bg-muted/50 px-3 py-2 text-xs font-semibold uppercase tracking-wide hover:bg-muted">
              <span>{sec.label}</span>
              <ChevronDown className="size-3.5 transition-transform data-[state=open]:rotate-180" />
            </button>
          </CollapsibleTrigger>
          <CollapsibleContent className="mt-1 space-y-1 pb-2">
            {sec.items.map((it, idx) => (
              <Button
                key={`${it.type}-${idx}`}
                variant="ghost"
                size="sm"
                className={cn(
                  "w-full justify-start h-8 px-3 text-xs font-normal",
                  "hover:bg-accent",
                )}
                onClick={() => onAddBlock(it)}
              >
                {substituteLabels(it.label, { tin: tinName, doc: docName, tax: taxName })}
              </Button>
            ))}
          </CollapsibleContent>
        </Collapsible>
      ))}
    </div>
  )
}
