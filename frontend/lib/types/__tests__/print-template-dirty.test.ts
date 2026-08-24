import { describe, it, expect } from "vitest"

import {
  canonicalizeTemplateForCompare,
  defaultBlock,
  defaultTemplateConfig,
  type PrintTemplateConfig,
} from "@/lib/types/print-template"

/**
 * El guard de cambios sin guardar (hooks/use-unsaved-changes-guard.ts) se arma
 * con el resultado de comparar estas formas canónicas. Un falso positivo acá
 * hace que el editor pregunte "¿hay cambios sin guardar?" al abrir y salir sin
 * tocar nada — y un aviso que salta siempre entrena al usuario a ignorarlo,
 * que es exactamente lo que el pedido buscaba evitar.
 */
describe("canonicalizeTemplateForCompare", () => {
  const sheet = (): PrintTemplateConfig => ({
    ...defaultTemplateConfig("a4page"),
    data: [{ ...defaultBlock("company_name", "Mi Empresa"), top: 40, left: 30, width: 200, height: 24 }],
  })

  it("ignora el `mm` guardado: re-medir el sentinel no es una edición", () => {
    const stored: PrintTemplateConfig = { ...sheet(), mm: 3.78 }
    const remeasured: PrintTemplateConfig = { ...sheet(), mm: 3.7795275590551185 }
    // Misma escala en los dos lados — así compara el editor.
    expect(canonicalizeTemplateForCompare(remeasured, 3.7795275590551185)).toBe(
      canonicalizeTemplateForCompare(stored, 3.7795275590551185),
    )
  })

  it("ignora el orden de claves de un bloque (JSONB del backend vs defaultBlock)", () => {
    const a = sheet()
    // Mismo contenido, claves en otro orden — como puede volver de un JSONB.
    const reordered: PrintTemplateConfig = {
      ...a,
      data: [
        {
          height: 24,
          width: 200,
          left: 30,
          top: 40,
          url: "",
          textwrap: "cut",
          bold: "normal",
          align: "left",
          family: "inherit",
          size: "inherit",
          text: "Mi Empresa",
          type: "company_name",
        },
      ],
    }
    expect(canonicalizeTemplateForCompare(reordered, 3.78)).toBe(canonicalizeTemplateForCompare(a, 3.78))
  })

  it("ignora el auto-clamp de una plantilla heredada de otro papel", () => {
    // Bloque que se sale del papel: el editor lo corrige solo al montar
    // (clampBlockToPaper desde canvas-block.tsx). No es una edición del usuario.
    const stored: PrintTemplateConfig = {
      ...defaultTemplateConfig("a4page"),
      data: [{ ...defaultBlock("total"), top: 10, left: 5000, width: 200, height: 24 }],
    }
    const clamped: PrintTemplateConfig = {
      ...stored,
      data: stored.data.map((b) => ({ ...b, left: 210 * 3.78 - 200 })),
    }
    expect(canonicalizeTemplateForCompare(clamped, 3.78)).toBe(canonicalizeTemplateForCompare(stored, 3.78))
  })

  it("ignora el ancho forzado a 100% en ticket (applyReceiptWidthRule)", () => {
    const stored: PrintTemplateConfig = {
      ...defaultTemplateConfig("receipt80"),
      data: [{ ...defaultBlock("total"), top: 10, left: 12, width: 60, height: 24 }],
    }
    const normalized: PrintTemplateConfig = {
      ...stored,
      data: stored.data.map((b) => ({ ...b, left: 0, width: 80 * 3.78 })),
    }
    expect(canonicalizeTemplateForCompare(normalized, 3.78)).toBe(
      canonicalizeTemplateForCompare(stored, 3.78),
    )
  })

  it("SÍ detecta un bloque movido por el usuario", () => {
    const before = sheet()
    const after: PrintTemplateConfig = {
      ...before,
      data: before.data.map((b) => ({ ...b, top: b.top + 20 })),
    }
    expect(canonicalizeTemplateForCompare(after, 3.78)).not.toBe(
      canonicalizeTemplateForCompare(before, 3.78),
    )
  })

  it("SÍ detecta un bloque agregado y un cambio de tipografía", () => {
    const before = sheet()
    expect(
      canonicalizeTemplateForCompare({ ...before, data: [...before.data, defaultBlock("total")] }, 3.78),
    ).not.toBe(canonicalizeTemplateForCompare(before, 3.78))
    expect(canonicalizeTemplateForCompare({ ...before, page_font_size: "12pt" }, 3.78)).not.toBe(
      canonicalizeTemplateForCompare(before, 3.78),
    )
  })
})
