import { describe, expect, it, vi } from "vitest"

import { mapWithConcurrency, splitTextForTts } from "@/lib/ai/tts-chunk"

describe("splitTextForTts", () => {
  it("texto vacío o solo espacios → sin pedazos (nada que leer, nada que cobrar)", () => {
    expect(splitTextForTts("")).toEqual([])
    expect(splitTextForTts("   \n  ")).toEqual([])
  })

  it("un texto corto queda entero en un solo pedazo", () => {
    expect(splitTextForTts("Hola, ¿cómo estás?")).toEqual(["Hola, ¿cómo estás?"])
  })

  it("el primer pedazo es más corto que el resto — es la espera del usuario", () => {
    const sentences = Array.from({ length: 30 }, (_, i) => `Esta es la oración número ${i} del informe.`)
    const chunks = splitTextForTts(sentences.join(" "))
    expect(chunks.length).toBeGreaterThan(2)
    expect(chunks[0].length).toBeLessThanOrEqual(180)
    for (const c of chunks.slice(1)) expect(c.length).toBeLessThanOrEqual(300)
  })

  it("no corta a mitad de palabra y conserva todas las palabras y su orden", () => {
    const text = Array.from({ length: 40 }, (_, i) => `Venta ${i} registrada correctamente hoy.`).join(" ")
    const chunks = splitTextForTts(text)
    expect(chunks.join(" ").split(/\s+/)).toEqual(text.split(/\s+/))
  })

  it("corta preferentemente en límites de oración", () => {
    const text =
      "Las ventas de hoy fueron quinientos mil. El producto más vendido fue la milanesa con papas fritas y ensalada. " +
      "Quedan tres órdenes pendientes de entrega para esta tarde. El stock de pan está por agotarse según el conteo."
    for (const chunk of splitTextForTts(text)) {
      // Cada pedazo termina donde terminaba una oración del original.
      expect(chunk).toMatch(/[.!?…]$/)
    }
  })

  it("los saltos de línea también cortan — las listas del agente no traen puntos", () => {
    const text = "Resumen del día:\n- Milanesa: 18 unidades vendidas\n- Hamburguesa: 12 unidades"
    const chunks = splitTextForTts(text)
    expect(chunks.join(" ")).toContain("Milanesa: 18 unidades vendidas")
  })

  it("una 'oración' interminable (sin puntuación) igual se trocea por palabras", () => {
    const text = Array.from({ length: 200 }, () => "palabra").join(" ")
    const chunks = splitTextForTts(text)
    expect(chunks.length).toBeGreaterThan(1)
    for (const c of chunks) expect(c.length).toBeLessThanOrEqual(300)
  })
})

describe("mapWithConcurrency", () => {
  it("conserva el orden de entrada en las promesas de salida", async () => {
    const delays = [30, 5, 15]
    const results = mapWithConcurrency(delays, 2, async (d, i) => {
      await new Promise((r) => setTimeout(r, d))
      return `r${i}`
    })
    expect(await Promise.all(results)).toEqual(["r0", "r1", "r2"])
  })

  it("nunca pasa del tope de requests en vuelo", async () => {
    let inFlight = 0
    let peak = 0
    const results = mapWithConcurrency(Array.from({ length: 8 }, (_, i) => i), 3, async () => {
      inFlight++
      peak = Math.max(peak, inFlight)
      await new Promise((r) => setTimeout(r, 5))
      inFlight--
    })
    await Promise.all(results)
    expect(peak).toBeLessThanOrEqual(3)
  })

  it("un fallo rechaza SU promesa sin voltear a las demás", async () => {
    const results = mapWithConcurrency([1, 2, 3], 2, async (n) => {
      if (n === 2) throw new Error("boom")
      return n
    })
    await expect(results[0]).resolves.toBe(1)
    await expect(results[1]).rejects.toThrow("boom")
    await expect(results[2]).resolves.toBe(3)
  })

  it("la primera promesa resuelve sin esperar al resto — es lo que arranca el audio", async () => {
    const order: number[] = []
    const results = mapWithConcurrency([10, 100, 100], 3, async (d, i) => {
      await new Promise((r) => setTimeout(r, d))
      order.push(i)
      return i
    })
    await results[0]
    expect(order).toEqual([0])
  })
})
