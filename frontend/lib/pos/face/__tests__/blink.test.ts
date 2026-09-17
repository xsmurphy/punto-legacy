/**
 * Tests de la prueba de vida por parpadeo (RRHH F2, context/83 D4).
 *
 * El parpadeo es lo único que separa una cara de una FOTO de esa cara, así que
 * lo que se prueba acá es que no se pueda acreditar con un cuadro suelto ni con
 * ruido de medición — y que la falta de parpadeo no rompa nada, porque marcar
 * por código siempre tiene que seguir funcionando.
 */

import { describe, expect, it } from "vitest"

import {
  BLINK_MAX_MS,
  BLINK_MIN_MS,
  BlinkDetector,
  EAR_CLOSED,
  EAR_OPEN,
  blinkRatio,
  eyeAspectRatio,
  eyesFromLandmarks,
  type FacePoint,
} from "@/lib/pos/face/blink"

/**
 * Los 6 puntos de un ojo con una apertura dada.
 *
 * Ancho fijo de 20 y alto = `openness * 20`, así que el EAR resultante es
 * exactamente `openness`. Eso hace que cada test diga qué apertura simula en vez
 * de traer números mágicos.
 */
function eye(openness: number): FacePoint[] {
  const h = (openness * 20) / 2
  return [
    { x: 0, y: 0 },    // p0 esquina izquierda
    { x: 6, y: -h },   // p1 párpado superior
    { x: 14, y: -h },  // p2 párpado superior
    { x: 20, y: 0 },   // p3 esquina derecha
    { x: 14, y: h },   // p4 párpado inferior
    { x: 6, y: h },    // p5 párpado inferior
  ]
}

describe("eyeAspectRatio", () => {
  it("mide la apertura como proporción alto/ancho", () => {
    expect(eyeAspectRatio(eye(0.3))).toBeCloseTo(0.3, 6)
    expect(eyeAspectRatio(eye(0.1))).toBeCloseTo(0.1, 6)
  })

  it("no depende de la distancia a la cámara", () => {
    // Es el punto de usar una proporción: nadie controla a qué distancia se
    // para la persona.
    const cerca = eye(0.3)
    const lejos = cerca.map((p) => ({ x: p.x * 3, y: p.y * 3 }))
    expect(eyeAspectRatio(lejos)).toBeCloseTo(eyeAspectRatio(cerca)!, 6)
  })

  it("devuelve null si los puntos no sirven", () => {
    expect(eyeAspectRatio([])).toBeNull()
    expect(eyeAspectRatio(eye(0.3).slice(0, 5))).toBeNull()
    expect(eyeAspectRatio([{ x: Number.NaN, y: 0 }, ...eye(0.3).slice(1)])).toBeNull()
  })

  it("y null —no infinito— con el ojo sin ancho", () => {
    // Un ancho de cero es un ojo que el modelo no vio. Dividir daría infinito, y
    // el infinito pasaría por "ojo muy abierto".
    const colapsado: FacePoint[] = Array.from({ length: 6 }, () => ({ x: 5, y: 0 }))
    expect(eyeAspectRatio(colapsado)).toBeNull()
  })
})

describe("blinkRatio", () => {
  it("promedia los dos ojos", () => {
    expect(blinkRatio(eye(0.2), eye(0.4))).toBeCloseTo(0.3, 6)
  })

  it("usa el ojo medible cuando el otro no lo es", () => {
    // La cara casi nunca está de frente; el ojo escorzado da lecturas bajas sin
    // que nadie haya parpadeado.
    expect(blinkRatio(eye(0.3), [])).toBeCloseTo(0.3, 6)
    expect(blinkRatio([], eye(0.25))).toBeCloseTo(0.25, 6)
  })

  it("devuelve null si no se vio ninguno", () => {
    expect(blinkRatio([], [])).toBeNull()
  })
})

describe("BlinkDetector", () => {
  const CERRADO = EAR_CLOSED - 0.05
  const ABIERTO = EAR_OPEN + 0.05

  it("cuenta la secuencia abierto → cerrado → abierto", () => {
    const d = new BlinkDetector()
    expect(d.push(ABIERTO, 0)).toBe(false)
    expect(d.push(CERRADO, 100)).toBe(false)
    expect(d.push(ABIERTO, 250)).toBe(true)
    expect(d.alive).toBe(true)
    expect(d.count).toBe(1)
  })

  it("avisa UNA sola vez por parpadeo", () => {
    // El llamador reacciona en ese cuadro; repetirlo dispararía la marcación dos
    // veces con la misma cara.
    const d = new BlinkDetector()
    d.push(ABIERTO, 0)
    d.push(CERRADO, 100)
    expect(d.push(ABIERTO, 250)).toBe(true)
    expect(d.push(ABIERTO, 300)).toBe(false)
    expect(d.push(ABIERTO, 350)).toBe(false)
    expect(d.count).toBe(1)
  })

  it("NO acredita una foto de alguien con los ojos cerrados", () => {
    // Arranca en `unknown` justamente por esto: si asumiera el ojo abierto, la
    // primera lectura de alguien ya entrecerrado completaría medio parpadeo que
    // nunca ocurrió.
    const d = new BlinkDetector()
    d.push(CERRADO, 0)
    d.push(CERRADO, 100)
    expect(d.push(ABIERTO, 200)).toBe(false)
    expect(d.alive).toBe(false)
  })

  it("NO acredita una foto con los ojos abiertos, por mucho que se mire", () => {
    const d = new BlinkDetector()
    for (let t = 0; t < 5000; t += 50) d.push(ABIERTO, t)
    expect(d.alive).toBe(false)
  })

  it("ignora el ruido alrededor del umbral", () => {
    // Con un solo umbral, una lectura que oscila en el límite contaría diez
    // parpadeos por segundo. La banda muerta es lo que lo evita.
    const d = new BlinkDetector()
    d.push(ABIERTO, 0)
    const medio = (EAR_CLOSED + EAR_OPEN) / 2
    for (let t = 50; t < 2000; t += 50) {
      expect(d.push(t % 100 === 0 ? medio : medio + 0.01, t)).toBe(false)
    }
    expect(d.count).toBe(0)
  })

  it("descarta un cierre demasiado corto para ser un parpadeo", () => {
    const d = new BlinkDetector()
    d.push(ABIERTO, 0)
    d.push(CERRADO, 100)
    expect(d.push(ABIERTO, 100 + BLINK_MIN_MS - 10)).toBe(false)
    expect(d.alive).toBe(false)
  })

  it("y uno demasiado largo: eso es una persona esperando, no un parpadeo", () => {
    const d = new BlinkDetector()
    d.push(ABIERTO, 0)
    d.push(CERRADO, 100)
    expect(d.push(ABIERTO, 100 + BLINK_MAX_MS + 10)).toBe(false)
    expect(d.alive).toBe(false)
  })

  it("una cara que sale del cuadro no rompe ni avanza el estado", () => {
    const d = new BlinkDetector()
    d.push(ABIERTO, 0)
    d.push(CERRADO, 100)
    expect(d.push(null, 150)).toBe(false)
    expect(d.push(null, 200)).toBe(false)
    // Al volver, el parpadeo que estaba en curso se completa normalmente.
    expect(d.push(ABIERTO, 250)).toBe(true)
  })

  it("reset() borra la prueba de vida", () => {
    // El parpadeo de una persona no puede acreditar a la que viene después.
    const d = new BlinkDetector()
    d.push(ABIERTO, 0)
    d.push(CERRADO, 100)
    d.push(ABIERTO, 250)
    expect(d.alive).toBe(true)
    d.reset()
    expect(d.alive).toBe(false)
    expect(d.count).toBe(0)
    // Y queda en `unknown`: ver el ojo abierto no completa nada por sí solo.
    expect(d.push(ABIERTO, 300)).toBe(false)
  })
})

describe("eyesFromLandmarks", () => {
  it("saca los dos ojos de los 68 puntos", () => {
    const landmarks: FacePoint[] = Array.from({ length: 68 }, (_, i) => ({ x: i, y: i }))
    const eyes = eyesFromLandmarks(landmarks)
    expect(eyes).not.toBeNull()
    expect(eyes!.left).toHaveLength(6)
    expect(eyes!.right).toHaveLength(6)
    expect(eyes!.left[0]).toEqual({ x: 36, y: 36 })
    expect(eyes!.right[0]).toEqual({ x: 42, y: 42 })
  })

  it("devuelve null si la lista no tiene el largo esperado", () => {
    // Preferimos no medir a medir sobre índices que apuntan a otra cosa.
    expect(eyesFromLandmarks([])).toBeNull()
    expect(eyesFromLandmarks(Array.from({ length: 5 }, () => ({ x: 0, y: 0 })))).toBeNull()
  })
})
