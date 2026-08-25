import { describe, expect, it } from "vitest"
import { existsSync, readFileSync } from "node:fs"
import path from "node:path"

/**
 * Guard de la SUPERFICIE de captura numérica del POS.
 *
 * REGLA (owner, vigente desde hace meses, re-afirmada el 2026-08-25): montos,
 * moneda, cantidades y porcentajes se capturan SIEMPRE con el `NumericPad` en
 * pantalla — nunca con un `<input>` y el teclado del sistema, tampoco en
 * teléfono. Los inputs quedan para texto y configuración.
 *
 * El 2026-08-25 se introdujo `components/pos/numeric-field.tsx`, que bajo
 * 768px cambiaba el pad por un campo nativo, y se revirtió el mismo día. Este
 * archivo existe para que la rama no vuelva por descuido: es el mismo tipo de
 * regresión que ya volvió dos veces, y en un diff grande no se ve.
 *
 * Es un chequeo de código, no de render.
 */

const ROOT = path.resolve(import.meta.dirname, "../../..")

function read(rel: string): string {
  return readFileSync(path.join(ROOT, rel), "utf8")
}

describe("el pad es la única superficie de captura numérica", () => {
  it("no existe una superficie alternativa (`numeric-field`)", () => {
    expect(existsSync(path.join(ROOT, "components/pos/numeric-field.tsx"))).toBe(
      false,
    )
  })

  it.each([
    // El wrapper por el que pasan precio, descuento, cantidad y apertura.
    "components/pos/numeric-pad-dialog.tsx",
    // Movimiento de caja: monta el pad inline, al lado de la nota.
    "components/register/cash-movement-dialog.tsx",
    // Arqueo por medio de pago.
    "components/pos/drawer-count-dialog.tsx",
  ])("%s captura con el NumericPad", (rel) => {
    const src = read(rel)
    expect(src).toContain('from "@/components/pos/numeric-pad"')
    expect(src).toContain("<NumericPad")
  })

  it("ningún fuente ramifica la captura por tamaño de pantalla", () => {
    // `useIsMobile` es legítimo para elegir Dialog vs drawer; lo que no puede
    // pasar es que el PAD lo use para decidir si se dibuja.
    expect(read("components/pos/numeric-pad.tsx")).not.toMatch(/useIsMobile/)
  })
})

describe("el visor del pad", () => {
  const src = read("components/pos/numeric-pad.tsx")

  it("mantiene el monto en tipo grande", () => {
    // Se lee de un vistazo desde el otro lado del mostrador. `text-4xl` (36px)
    // es el piso en teléfono; `sm:` sube al `text-5xl` de siempre.
    expect(src).toMatch(/text-4xl[^"]*sm:text-5xl/)
  })

  it("el segundo toque BAJA el teclado del sistema", () => {
    // El visor abre el teclado del OS como el PIN del lock screen. Si el toque
    // solo enfoca, con el teclado arriba no hay forma de bajarlo sin cerrar el
    // modal — el teclado tapa el pad.
    expect(src).toMatch(/document\.activeElement === el/)
    expect(src).toMatch(/el\.blur\(\)/)
  })
})
