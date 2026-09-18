import { describe, expect, it } from "vitest"

import { canPlayUnknown, UNKNOWN_COOLDOWN_MS } from "@/lib/clock/sounds"

const NOW = Date.parse("2026-09-18T12:00:00.000Z")

describe("canPlayUnknown", () => {
  it("suena la primera vez", () => {
    expect(canPlayUnknown(null, NOW)).toBe(true)
  })

  it("no suena de nuevo dentro de la ventana", () => {
    // Es el caso real: el bucle de reconocimiento mira ~3 veces por segundo, así
    // que la misma persona parada delante dispara el aviso decenas de veces.
    expect(canPlayUnknown(NOW, NOW + 320)).toBe(false)
    expect(canPlayUnknown(NOW, NOW + UNKNOWN_COOLDOWN_MS - 1)).toBe(false)
  })

  it("vuelve a sonar al cumplirse la ventana", () => {
    expect(canPlayUnknown(NOW, NOW + UNKNOWN_COOLDOWN_MS)).toBe(true)
    expect(canPlayUnknown(NOW, NOW + UNKNOWN_COOLDOWN_MS * 3)).toBe(true)
  })

  it("no queda mudo si el reloj del aparato se corrige hacia atrás", () => {
    expect(canPlayUnknown(NOW, NOW - 60_000)).toBe(true)
  })
})
