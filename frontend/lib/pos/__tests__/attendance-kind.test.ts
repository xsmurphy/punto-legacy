import { describe, expect, it } from "vitest"

import { lastKnownMark, proposedKind, type QueuedMark } from "@/lib/pos/attendance-kind"

/**
 * Qué se le propone marcar a una persona (context/83 F1).
 *
 * El caso que este test existe para proteger es el de la caja SIN RED: el dato
 * del servidor es el del último bootstrap, así que si la persona entró hace tres
 * horas y esa entrada sigue en la cola del dispositivo, proponerle "Entrada" de
 * nuevo es proponerle exactamente lo que no quiere.
 */
describe("lastKnownMark", () => {
  const EMP = "emp-1"

  it("sin marcaciones devuelve null", () => {
    expect(lastKnownMark(null, null, [], EMP)).toBeNull()
  })

  it("usa la del servidor cuando la cola está vacía", () => {
    const last = lastKnownMark("in", "2026-09-17T11:00:00Z", [], EMP)
    expect(last).toEqual({ kind: "in", markedAt: "2026-09-17T11:00:00Z" })
  })

  it("la de la COLA gana si es más reciente que la del servidor", () => {
    const queued: QueuedMark[] = [
      { employeeId: EMP, kind: "out", markedAt: "2026-09-17T18:00:00Z" },
    ]
    const last = lastKnownMark("in", "2026-09-17T09:00:00Z", queued, EMP)
    expect(last?.kind).toBe("out")
  })

  it("la del SERVIDOR gana si la de la cola es más vieja", () => {
    // Pasa de verdad: el device sincronizó y el bootstrap trajo una marcación
    // posterior hecha en otra tablet, mientras una vieja seguía en esta cola.
    const queued: QueuedMark[] = [
      { employeeId: EMP, kind: "in", markedAt: "2026-09-17T08:00:00Z" },
    ]
    const last = lastKnownMark("out", "2026-09-17T17:00:00Z", queued, EMP)
    expect(last?.kind).toBe("out")
  })

  it("ignora las marcaciones de OTRA persona que están en la misma cola", () => {
    const queued: QueuedMark[] = [
      { employeeId: "otra", kind: "out", markedAt: "2026-09-17T23:00:00Z" },
    ]
    const last = lastKnownMark("in", "2026-09-17T09:00:00Z", queued, EMP)
    expect(last?.kind).toBe("in")
  })

  it("una fecha ilegible no puede ganar", () => {
    // Un snapshot viejo o un reloj raro no pueden hacer que la sugerencia salga
    // al azar: se queda la fecha que sí se entiende.
    const queued: QueuedMark[] = [
      { employeeId: EMP, kind: "out", markedAt: "no-es-una-fecha" },
    ]
    const last = lastKnownMark("in", "2026-09-17T09:00:00Z", queued, EMP)
    expect(last?.kind).toBe("in")
  })
})

describe("proposedKind", () => {
  it("sin marcación previa propone ENTRADA", () => {
    // El único default que no puede cerrar un turno que nunca se abrió.
    expect(proposedKind(null)).toBe("in")
  })

  it("después de una entrada propone salida, y al revés", () => {
    expect(proposedKind({ kind: "in", markedAt: "2026-09-17T09:00:00Z" })).toBe("out")
    expect(proposedKind({ kind: "out", markedAt: "2026-09-17T18:00:00Z" })).toBe("in")
  })
})
