"use client"

import * as React from "react"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { FONT_FAMILIES, FONT_SIZES } from "@/lib/print-template-palette"
import { lineGeometry } from "@/lib/hardware/printers/blocks"
import type { PrintBlock } from "@/lib/types/print-template"

interface Props {
  /** Bloques seleccionados en el canvas — 0 (sin selección), 1 (edición
   *  completa de un bloque) o varios (edición en conjunto de las
   *  propiedades de formato compartidas, ver MultiBlockInspector). */
  blocks: PrintBlock[]
  /** Con 1 bloque seleccionado, el patch se aplica a ESE bloque. Con varios,
   *  el mismo patch se aplica a TODOS por igual (template-editor.tsx decide
   *  el fan-out según la selección — acá no importa cuántos hay). */
  onChange: (patch: Partial<PrintBlock>) => void
}

/**
 * Panel derecho — inspector del/los bloque(s) seleccionado(s). Con selección
 * múltiple delega en `MultiBlockInspector` (edición en conjunto de
 * alineación/tamaño/tipografía/negrita/wrap — no de posición/tamaño en px,
 * que se edita moviendo el grupo en el canvas). Mientras no haya selección,
 * muestra hint.
 */
export function BlockInspector({ blocks, onChange }: Props) {
  if (blocks.length === 0) {
    return (
      <div className="flex h-full flex-col items-center justify-center px-4 text-center text-xs text-muted-foreground">
        <p>Elegí un bloque del canvas para editarlo.</p>
        <p className="mt-2">O sumá uno desde la paleta de la izquierda.</p>
      </div>
    )
  }

  if (blocks.length > 1) {
    return <MultiBlockInspector blocks={blocks} onChange={onChange} />
  }

  const block = blocks[0]
  const editable = block.type === "custom"
  // hor_line/ver_line: `text` no es contenido, es el GROSOR de la línea (ver
  // `lineGeometry` en blocks.ts — el mismo mecanismo de "text como metadato
  // según el tipo" que ya usan `tax_single` y los bloques por-tasa). Mostrar
  // ahí el campo "Texto — (dinámico)" era engañoso: una línea no se rellena
  // con ningún dato al imprimir.
  const line = lineGeometry(block)

  return (
    <div className="space-y-4 overflow-y-auto px-4 py-4 text-sm">
      <div>
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Bloque seleccionado
        </h3>
        <p className="mt-1 font-medium">{block.type}</p>
      </div>

      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <NumberField label="Izquierda (px)" value={block.left} onChange={(v) => onChange({ left: v })} />
        <NumberField label="Arriba (px)" value={block.top} onChange={(v) => onChange({ top: v })} />
        <NumberField label="Ancho" value={block.width} onChange={(v) => onChange({ width: v })} />
        <NumberField label="Alto" value={block.height} onChange={(v) => onChange({ height: v })} />
      </div>

      {line ? (
        <div className="space-y-1.5">
          <NumberField
            label="Grosor (px)"
            value={line.thickness}
            onChange={(v) => onChange({ text: String(Math.max(1, v)) })}
          />
          <p className="text-[11px] text-muted-foreground">
            La línea se dibuja centrada en el bloque. El grosor nunca supera el{" "}
            {line.orientation === "horizontal" ? "alto" : "ancho"} del bloque.
          </p>
        </div>
      ) : (
        <div className="space-y-1.5">
          <Label>Texto</Label>
          {editable ? (
            <Textarea
              value={block.text}
              onChange={(e) => onChange({ text: e.target.value })}
              rows={3}
              placeholder="Texto personalizado"
            />
          ) : (
            <p className="rounded-md border bg-muted/30 px-2 py-1.5 text-xs text-muted-foreground">
              {block.text || "(dinámico)"}
            </p>
          )}
          {!editable && (
            <p className="text-[11px] text-muted-foreground">
              Este bloque se rellena automáticamente con datos reales al imprimir.
            </p>
          )}
        </div>
      )}

      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label>Tamaño</Label>
          <Select value={block.size} onValueChange={(v) => onChange({ size: v })}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {FONT_SIZES.map((s) => (
                <SelectItem key={s} value={s}>
                  {s === "inherit" ? "Heredado" : s}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label>Tipografía</Label>
          <Select value={block.family} onValueChange={(v) => onChange({ family: v })}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {FONT_FAMILIES.map((f) => (
                <SelectItem key={f} value={f}>
                  {f === "inherit" ? "Heredada" : f}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label>Alineación</Label>
          <Select
            value={block.align}
            onValueChange={(v) => onChange({ align: v as PrintBlock["align"] })}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="left">Izquierda</SelectItem>
              <SelectItem value="center">Centro</SelectItem>
              <SelectItem value="right">Derecha</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label>Peso</Label>
          <Select
            value={block.bold}
            onValueChange={(v) => onChange({ bold: v as PrintBlock["bold"] })}
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="normal">Normal</SelectItem>
              <SelectItem value="bold">Negrita</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="space-y-1.5">
        <Label>Wrap del texto</Label>
        <Select
          value={block.textwrap}
          onValueChange={(v) => onChange({ textwrap: v as PrintBlock["textwrap"] })}
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="cut">Cortar (1 línea)</SelectItem>
            <SelectItem value="wrap">Envolver (multilínea)</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </div>
  )
}

/**
 * Valor común a TODOS los bloques para un campo dado, o `undefined` si
 * difieren — mismo patrón que cualquier editor de diseño con selección
 * múltiple (Figma, Illustrator): un campo mixto se muestra vacío en vez de
 * asumir el valor del primero.
 */
function commonValue<K extends keyof PrintBlock>(blocks: PrintBlock[], key: K): PrintBlock[K] | undefined {
  const first = blocks[0][key]
  return blocks.every((b) => b[key] === first) ? first : undefined
}

/**
 * Edición en conjunto para 2+ bloques seleccionados. Solo expone las
 * propiedades de FORMATO que tienen sentido aplicadas por igual a varios
 * bloques a la vez (tamaño/tipografía/alineación/negrita/wrap — el mismo
 * set que ya existía en la toolbar flotante de un bloque suelto,
 * canvas-block.tsx). Deliberadamente NO incluye:
 *  - Posición/tamaño en px: mover el grupo ya se hace arrastrándolo en el
 *    canvas (mantiene posiciones relativas, ver moveBlocksBy en
 *    template-editor.tsx); un campo numérico que fije la MISMA posición
 *    absoluta a todos los seleccionados los apilaría uno sobre el otro.
 *  - Texto: es específico de cada bloque (metadato para bloques por-tasa,
 *    contenido libre solo en `custom`) — no hay una operación "en conjunto"
 *    predecible ahí.
 * Un campo con valores distintos entre los bloques seleccionados se muestra
 * vacío ("Mixto"); elegir un valor lo aplica a TODOS por igual — la
 * resolución predecible que pide el brief para "una propiedad que no aplica
 * a todos".
 */
function MultiBlockInspector({
  blocks,
  onChange,
}: {
  blocks: PrintBlock[]
  onChange: (patch: Partial<PrintBlock>) => void
}) {
  const size = commonValue(blocks, "size")
  const family = commonValue(blocks, "family")
  const align = commonValue(blocks, "align")
  const bold = commonValue(blocks, "bold")
  const textwrap = commonValue(blocks, "textwrap")

  return (
    <div className="space-y-4 overflow-y-auto px-4 py-4 text-sm">
      <div>
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {blocks.length} bloques seleccionados
        </h3>
        <p className="mt-1 text-xs text-muted-foreground">
          Los cambios de formato se aplican a todos. &ldquo;Mixto&rdquo; indica que
          difieren entre sí.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label>Tamaño</Label>
          <Select value={size ?? ""} onValueChange={(v) => onChange({ size: v })}>
            <SelectTrigger>
              <SelectValue placeholder="Mixto" />
            </SelectTrigger>
            <SelectContent>
              {FONT_SIZES.map((s) => (
                <SelectItem key={s} value={s}>
                  {s === "inherit" ? "Heredado" : s}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label>Tipografía</Label>
          <Select value={family ?? ""} onValueChange={(v) => onChange({ family: v })}>
            <SelectTrigger>
              <SelectValue placeholder="Mixto" />
            </SelectTrigger>
            <SelectContent>
              {FONT_FAMILIES.map((f) => (
                <SelectItem key={f} value={f}>
                  {f === "inherit" ? "Heredada" : f}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label>Alineación</Label>
          <Select
            value={align ?? ""}
            onValueChange={(v) => onChange({ align: v as PrintBlock["align"] })}
          >
            <SelectTrigger>
              <SelectValue placeholder="Mixto" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="left">Izquierda</SelectItem>
              <SelectItem value="center">Centro</SelectItem>
              <SelectItem value="right">Derecha</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label>Peso</Label>
          <Select
            value={bold ?? ""}
            onValueChange={(v) => onChange({ bold: v as PrintBlock["bold"] })}
          >
            <SelectTrigger>
              <SelectValue placeholder="Mixto" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="normal">Normal</SelectItem>
              <SelectItem value="bold">Negrita</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="space-y-1.5">
        <Label>Wrap del texto</Label>
        <Select
          value={textwrap ?? ""}
          onValueChange={(v) => onChange({ textwrap: v as PrintBlock["textwrap"] })}
        >
          <SelectTrigger>
            <SelectValue placeholder="Mixto" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="cut">Cortar (1 línea)</SelectItem>
            <SelectItem value="wrap">Envolver (multilínea)</SelectItem>
          </SelectContent>
        </Select>
      </div>
    </div>
  )
}

function NumberField({
  label,
  value,
  onChange,
}: {
  label: string
  value: number
  onChange: (v: number) => void
}) {
  return (
    <div className="space-y-1.5">
      <Label className="text-[11px] uppercase text-muted-foreground">{label}</Label>
      <Input
        type="number"
        value={Number.isFinite(value) ? value : 0}
        onChange={(e) => {
          const v = Number(e.target.value)
          if (Number.isFinite(v)) onChange(Math.round(v))
        }}
      />
    </div>
  )
}
