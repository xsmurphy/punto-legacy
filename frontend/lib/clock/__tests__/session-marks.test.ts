import { describe, expect, it } from "vitest"

import {
  findRecentMark,
  rememberMark,
  REPEAT_WINDOW_MS,
  type SessionMark,
} from "@/lib/clock/session-marks"
import { lastKnownMark, proposedKind } from "@/lib/pos/attendance-kind"

const NOW = Date.parse("2026-09-18T12:00:00.000Z")

function mark(over: Partial<SessionMark> = {}): SessionMark {
  return {
    employeeId: "e1",
    name: "Ana",
    kind: "in",
    markedAt: new Date(NOW).toISOString(),
    ...over,
  }
}

describe("rememberMark", () => {
  it("no muta la lista original", () => {
    const list: SessionMark[] = []
    const next = rememberMark(list, mark())
    expect(list).toHaveLength(0)
    expect(next).toHaveLength(1)
  })

  it("recorta las más viejas al llegar al tope", () => {
    let list: SessionMark[] = []
    for (let i = 0; i < 60; i++) {
      list = rememberMark(list, mark({ employeeId: `e${i}` }))
    }
    expect(list).toHaveLength(50)
    // Se conservan las últimas, que son las que pueden estar en ventana.
    expect(list[list.length - 1].employeeId).toBe("e59")
  })
})

describe("findRecentMark", () => {
  it("encuentra la marcación de hace segundos", () => {
    const list = [mark({ markedAt: new Date(NOW - 5_000).toISOString() })]
    expect(findRecentMark(list, "e1", NOW)?.name).toBe("Ana")
  })

  it("ignora la que ya salió de la ventana", () => {
    const list = [mark({ markedAt: new Date(NOW - REPEAT_WINDOW_MS - 1).toISOString() })]
    expect(findRecentMark(list, "e1", NOW)).toBeNull()
  })

  it("no mezcla personas", () => {
    const list = [mark({ employeeId: "otro" })]
    expect(findRecentMark(list, "e1", NOW)).toBeNull()
  })

  it("devuelve la más reciente cuando hay varias", () => {
    const list = [
      mark({ kind: "in", markedAt: new Date(NOW - 40_000).toISOString() }),
      mark({ kind: "out", markedAt: new Date(NOW - 10_000).toISOString() }),
    ]
    expect(findRecentMark(list, "e1", NOW)?.kind).toBe("out")
  })

  it("ante una fecha ilegible deja marcar", () => {
    const list = [mark({ markedAt: "no es una fecha" })]
    expect(findRecentMark(list, "e1", NOW)).toBeNull()
  })
})

describe("la marcación de esta sesión alimenta la inferencia", () => {
  it("después de entrar, lo que sigue es salir — aunque el servidor no se enteró", () => {
    // El servidor todavía dice que la última marcación fue la salida de ayer:
    // es el caso ONLINE, donde el roster no se refresca en el instante en que
    // alguien ficha.
    const session = [mark({ kind: "in", markedAt: new Date(NOW - 30_000).toISOString() })]
    const last = lastKnownMark("out", new Date(NOW - 86_400_000).toISOString(), session, "e1")
    expect(last?.kind).toBe("in")
    expect(proposedKind(last)).toBe("out")
  })
})
