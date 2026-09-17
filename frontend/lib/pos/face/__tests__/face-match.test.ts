/**
 * Tests de la comparación de rostros (RRHH F2, context/83 D4/D5).
 *
 * Lo que se prueba acá es la parte que decide A QUIÉN se le acredita una jornada
 * de trabajo. Por eso la lógica está separada de la cámara: sin esa separación
 * esto solo se podría verificar poniéndose delante de una webcam, que es como no
 * verificarlo.
 */

import { describe, expect, it } from "vitest"

import {
  averageEmbedding,
  cosineDistance,
  FACE_EMBEDDING_DIMS,
  FACE_MAX_DISTANCE,
  FACE_MIN_MARGIN,
  FACE_MODEL_VERSION,
  norm,
  pickBestMatch,
  toUnit,
  type FaceCandidate,
} from "@/lib/pos/face/face-match"

/**
 * Vector determinístico de 128 dimensiones. `drift` lo aleja de su original.
 *
 * Pseudoaleatorio y no una sinusoide: dos sinusoides con frecuencias parecidas
 * quedan CERCA en distancia coseno, así que "otra persona" salía a 0,1 del
 * original y el test medía otra cosa que la que decía medir. Con ruido
 * pseudoaleatorio, dos semillas distintas quedan casi ortogonales (distancia
 * ≈ 1), que es como se comportan los vectores reales de dos caras distintas.
 */
function vec(seed: number, drift = 0): number[] {
  // LCG chico: determinístico, suficiente para generar ruido reproducible.
  let s = (seed * 2654435761) >>> 0
  const rand = () => {
    s = (s * 1664525 + 1013904223) >>> 0
    return s / 0xffffffff - 0.5
  }
  const base = Array.from({ length: FACE_EMBEDDING_DIMS }, () => rand())
  if (drift === 0) return base
  // El ruido del drift sale de OTRA secuencia, para que no sea el mismo vector
  // escalado (que daría distancia 0 y no probaría nada).
  let n = (seed * 40503 + 7) >>> 0
  const noise = () => {
    n = (n * 1664525 + 1013904223) >>> 0
    return n / 0xffffffff - 0.5
  }
  return base.map((v) => v + drift * noise())
}

function candidate(employeeId: string, embedding: number[], modelVersion = FACE_MODEL_VERSION): FaceCandidate {
  return { employeeId, embedding, modelVersion }
}

describe("toUnit / norm", () => {
  it("deja el vector con longitud 1", () => {
    const u = toUnit(vec(1))
    expect(u).not.toBeNull()
    expect(norm(u!)).toBeCloseTo(1, 10)
  })

  it("devuelve null —no tira— cuando no hay nada que normalizar", () => {
    // Un dato roto significa "no se pudo comparar", que es un resultado
    // legítimo de esta pantalla y no un error del programa.
    expect(toUnit([])).toBeNull()
    expect(toUnit([0, 0, 0])).toBeNull()
    expect(toUnit([1, Number.NaN, 3])).toBeNull()
    expect(toUnit([1, Number.POSITIVE_INFINITY])).toBeNull()
  })
})

describe("cosineDistance", () => {
  it("da 0 para el mismo vector y crece al alejarse", () => {
    expect(cosineDistance(vec(3), vec(3))).toBeCloseTo(0, 10)
    const cerca = cosineDistance(vec(3), vec(3, 0.05))
    const lejos = cosineDistance(vec(3), vec(9))
    expect(cerca).toBeLessThan(lejos)
  })

  it("no depende de la escala del vector", () => {
    // Es lo que permite que el umbral sea un número fijo: comparar direcciones,
    // no intensidades.
    const a = vec(4)
    const escalado = a.map((n) => n * 17)
    expect(cosineDistance(a, escalado)).toBeCloseTo(0, 10)
  })

  it("da infinito —nunca un número plausible— si los largos no coinciden", () => {
    // Largos distintos son modelos distintos. Un número acá sería una distancia
    // sin sentido que igual se compararía contra el umbral.
    expect(cosineDistance(vec(1), vec(1).slice(0, 64))).toBe(Number.POSITIVE_INFINITY)
    expect(cosineDistance(vec(1), [0, 0, 0])).toBe(Number.POSITIVE_INFINITY)
  })
})

describe("averageEmbedding", () => {
  it("promedia varias tomas de la misma cara y normaliza", () => {
    const avg = averageEmbedding([vec(5), vec(5, 0.02), vec(5, 0.04)])
    expect(avg).not.toBeNull()
    expect(norm(avg!)).toBeCloseTo(1, 10)
    // El promedio queda más cerca del original que la toma más desviada.
    expect(cosineDistance(avg!, vec(5))).toBeLessThan(cosineDistance(vec(5, 0.04), vec(5)))
  })

  it("descarta todo si se mezclan largos distintos", () => {
    // Promediarlos daría un vector que no es de nadie.
    expect(averageEmbedding([vec(5), vec(5).slice(0, 64)])).toBeNull()
  })

  it("ignora las tomas inservibles y sigue con las buenas", () => {
    const avg = averageEmbedding([vec(6), [], vec(6, 0.02)])
    expect(avg).not.toBeNull()
  })

  it("devuelve null cuando no queda ninguna toma utilizable", () => {
    expect(averageEmbedding([])).toBeNull()
    expect(averageEmbedding([[], [0, 0, 0]])).toBeNull()
  })
})

describe("pickBestMatch — a quién se parece", () => {
  it("reconoce a la persona correcta entre varias", () => {
    const res = pickBestMatch(vec(10, 0.01), [
      candidate("ana", vec(10)),
      candidate("beto", vec(20)),
      candidate("caro", vec(30)),
    ])
    expect(res.matched).toBe(true)
    expect(res.matched && res.match.employeeId).toBe("ana")
  })

  it("no reconoce a nadie si ninguna cara se parece lo suficiente", () => {
    const res = pickBestMatch(vec(99), [candidate("ana", vec(10)), candidate("beto", vec(20))])
    expect(res.matched).toBe(false)
    // El mejor igual se informa: sirve para saber que HABÍA caras y ninguna dio.
    expect(res.matched === false && res.reason).toBe("too_far")
    expect(res.matched === false && res.best?.employeeId).toBeTruthy()
  })

  it("distingue 'nadie tiene rostro registrado' de 'no te reconocí'", () => {
    // La pantalla dice cosas distintas en cada caso, así que el resultado tiene
    // que distinguirlos.
    const vacio = pickBestMatch(vec(1), [])
    expect(vacio.matched === false && vacio.reason).toBe("no_candidates")
    expect(vacio.matched === false && vacio.best).toBeNull()
  })

  it("IGNORA los vectores de otra versión de modelo", () => {
    // La invariante central: compararlos no fallaría, devolvería un número sin
    // sentido — y así es como se le acredita la entrada de una persona a otra.
    const res = pickBestMatch(vec(10), [candidate("ana", vec(10), "otro-modelo-9")])
    expect(res.matched).toBe(false)
    expect(res.matched === false && res.reason).toBe("no_candidates")
  })

  it("y si hay de las dos versiones, solo compite la nuestra", () => {
    const res = pickBestMatch(vec(20, 0.01), [
      // El de otro modelo es "idéntico" al probe y aun así no puede ganar.
      candidate("intruso", vec(20), "otro-modelo-9"),
      candidate("beto", vec(20, 0.02)),
    ])
    expect(res.matched).toBe(true)
    expect(res.matched && res.match.employeeId).toBe("beto")
  })

  it("no elige cuando dos caras están demasiado parejas", () => {
    // Hermanos, dos caras mal iluminadas. Con diferencias mínimas el ganador
    // cambia de un cuadro al otro; la respuesta honesta es pedir el código.
    const base = vec(40)
    const res = pickBestMatch(base, [
      candidate("ana", base.map((n) => n + 1e-9)),
      candidate("clon", base.map((n) => n + 2e-9)),
    ])
    expect(res.matched).toBe(false)
    expect(res.matched === false && res.reason).toBe("ambiguous")
  })

  it("el margen se mide contra el SEGUNDO, no contra el resto", () => {
    const res = pickBestMatch(vec(50, 0.01), [
      candidate("ana", vec(50)),
      candidate("lejano1", vec(60)),
      candidate("lejano2", vec(70)),
    ])
    expect(res.matched).toBe(true)
    expect(res.matched && res.match.employeeId).toBe("ana")
  })

  it("los umbrales son configurables, y el default es el conservador", () => {
    const probe = vec(80, 0.1)
    const candidatos = [candidate("ana", vec(80))]
    const distancia = cosineDistance(probe, vec(80))

    // Con un umbral holgado entra; con el default —más estricto— no.
    expect(pickBestMatch(probe, candidatos, { maxDistance: distancia + 0.01 }).matched).toBe(true)
    expect(pickBestMatch(probe, candidatos, { maxDistance: distancia - 0.01 }).matched).toBe(false)
  })

  it("el umbral por defecto es más estricto que el default de la librería", () => {
    // La decisión de producto, escrita como test: el costo de no reconocer a
    // alguien es que tipee cuatro dígitos; el de reconocer mal es acreditarle la
    // jornada a otro. El equivalente al default publicado ronda 0,18.
    expect(FACE_MAX_DISTANCE).toBeLessThan(0.18)
    expect(FACE_MIN_MARGIN).toBeGreaterThan(0)
  })

  it("un candidato con el vector roto no gana por ser 'infinitamente cercano'", () => {
    const res = pickBestMatch(vec(90, 0.01), [
      candidate("roto", []),
      candidate("ana", vec(90)),
    ])
    expect(res.matched).toBe(true)
    expect(res.matched && res.match.employeeId).toBe("ana")
  })
})
