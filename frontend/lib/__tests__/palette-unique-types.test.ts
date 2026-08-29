import { describe, it, expect } from "vitest"

import { PALETTE } from "@/lib/print-template-palette"

/**
 * INVARIANTE: cada `BlockType` aparece UNA sola vez en la paleta del editor.
 *
 * `PrintBlock` guarda el `type`, no de qué ítem de la paleta salió: dos
 * entradas con el mismo `type` son indistinguibles apenas el operador suelta
 * el bloque en el canvas, así que ofrecerlas promete una elección que el
 * modelo no puede sostener.
 *
 * El caso real fue "Logo" / "Logo (B&W)" (eliminado 2026-08-29): el blanco y
 * negro del logo lo decide el TRANSPORTE del binding, no el bloque — ESC/POS
 * siempre dithera a B&W puro (`renderGraphic`, render-template.ts), porque la
 * térmica no tiene grises. La segunda entrada no cambiaba un solo byte del
 * ticket.
 *
 * Sin este guard el invariante vive solo en un comentario, y `BLOCK_TYPE_LABELS`
 * vuelve a tener que descartar etiquetas en silencio.
 */
describe("PALETTE", () => {
  it("no ofrece dos entradas para el mismo BlockType", () => {
    const seen = new Map<string, string[]>()
    for (const section of PALETTE) {
      for (const item of section.items) {
        seen.set(item.type, [...(seen.get(item.type) ?? []), `${section.id}/${item.label}`])
      }
    }

    const duplicados = [...seen.entries()].filter(([, labels]) => labels.length > 1)
    expect(duplicados).toEqual([])
  })

  it("no vuelve a ofrecer un bloque de logo en blanco y negro", () => {
    const labels = PALETTE.flatMap((s) => s.items).map((i) => i.label)
    expect(labels).toContain("Logo")
    expect(labels.filter((l) => /b&w|blanco y negro/i.test(l))).toEqual([])
  })
})
