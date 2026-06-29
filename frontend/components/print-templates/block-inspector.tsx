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
import type { PrintBlock } from "@/lib/types/print-template"

interface Props {
  block: PrintBlock | null
  onChange: (patch: Partial<PrintBlock>) => void
}

/**
 * Panel derecho — inspector del bloque seleccionado. Sus inputs reflejan y
 * editan posición, tamaño y formato. Mientras no haya selección, muestra hint.
 */
export function BlockInspector({ block, onChange }: Props) {
  if (!block) {
    return (
      <div className="flex h-full flex-col items-center justify-center px-4 text-center text-xs text-muted-foreground">
        <p>Elegí un bloque del canvas para editarlo.</p>
        <p className="mt-2">O sumá uno desde la paleta de la izquierda.</p>
      </div>
    )
  }

  const editable = block.type === "custom"

  return (
    <div className="space-y-4 overflow-y-auto px-4 py-4 text-sm">
      <div>
        <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          Bloque seleccionado
        </h3>
        <p className="mt-1 font-medium">{block.type}</p>
      </div>

      <div className="grid grid-cols-2 gap-2">
        <NumberField label="Izquierda (px)" value={block.left} onChange={(v) => onChange({ left: v })} />
        <NumberField label="Arriba (px)" value={block.top} onChange={(v) => onChange({ top: v })} />
        <NumberField label="Ancho" value={block.width} onChange={(v) => onChange({ width: v })} />
        <NumberField label="Alto" value={block.height} onChange={(v) => onChange({ height: v })} />
      </div>

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

      <div className="grid grid-cols-2 gap-2">
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

      <div className="grid grid-cols-2 gap-2">
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
