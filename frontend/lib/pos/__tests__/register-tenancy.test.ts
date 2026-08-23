/**
 * Política de vigencia de la tenencia local del POS (incidente 2026-08-23).
 *
 * `evaluateGrant()` es la función que decide, SIN RED, si este dispositivo
 * tiene derecho a emitir un comprobante con la numeración de esta caja. Es
 * pura a propósito — toda la política vive en un solo lugar y se puede
 * verificar sin IndexedDB, sin servidor y sin browser.
 *
 * Lo que se prueba acá es exactamente lo que el incidente demostró que faltaba:
 * que "no sé si tengo la caja" y "sé que NO la tengo" bloqueen la emisión
 * igual que un 409, en vez de dejar vender y descubrirlo al sincronizar.
 */

import { describe, expect, it } from "vitest"
import { evaluateGrant, TENANCY_TTL_MS } from "@/lib/pos/register-tenancy"
import type { TenancyGrantRow } from "@/lib/pos/offline-db"

const REG = "11111111-1111-1111-1111-111111111111"
const OTHER_REG = "22222222-2222-2222-2222-222222222222"
const NOW = new Date("2026-08-23T12:00:00.000Z").getTime()

function grant(over: Partial<TenancyGrantRow> = {}): TenancyGrantRow {
  return {
    key: "register-tenancy",
    registerId: REG,
    status: "held",
    confirmedAt: new Date(NOW - 60_000).toISOString(),
    registerLeaseId: "lease-1",
    denyReason: null,
    holderDeviceId: null,
    holderDeviceName: null,
    ...over,
  }
}

describe("evaluateGrant", () => {
  it("sin grant no deja emitir — un device que nunca confirmó no tiene derecho", () => {
    const v = evaluateGrant(null, REG, NOW)
    expect(v.canIssue).toBe(false)
    expect(v.kind).toBe("never")
  })

  it("grant held y fresco deja emitir — el caso offline legítimo", () => {
    const v = evaluateGrant(grant(), REG, NOW)
    expect(v.canIssue).toBe(true)
    expect(v.kind).toBe("ok")
  })

  it("grant denied NO deja emitir, aunque el device esté sin red", () => {
    // ESTE es el incidente: el claim del arranque trajo 409, el POS lo ignoró
    // y dejó vender offline igual.
    const v = evaluateGrant(
      grant({
        status: "denied",
        denyReason: "taken_by_other",
        holderDeviceId: "dev-2",
        holderDeviceName: "Tablet Barra",
      }),
      REG,
      NOW,
    )
    expect(v.canIssue).toBe(false)
    expect(v.kind).toBe("denied")
    expect(v.holderDeviceName).toBe("Tablet Barra")
  })

  it("justo antes del TTL sigue valiendo; justo después, no", () => {
    const almost = grant({
      confirmedAt: new Date(NOW - TENANCY_TTL_MS + 60_000).toISOString(),
    })
    expect(evaluateGrant(almost, REG, NOW).canIssue).toBe(true)

    const expired = grant({
      confirmedAt: new Date(NOW - TENANCY_TTL_MS - 60_000).toISOString(),
    })
    const v = evaluateGrant(expired, REG, NOW)
    expect(v.canIssue).toBe(false)
    expect(v.kind).toBe("stale")
  })

  it("un grant de OTRA caja no autoriza esta", () => {
    const v = evaluateGrant(grant({ registerId: OTHER_REG }), REG, NOW)
    expect(v.canIssue).toBe(false)
    expect(v.kind).toBe("other-register")
  })

  it("reloj adelantado: confirmedAt en el futuro vence, no vale para siempre", () => {
    // Fail-closed ante un reloj corrido. El modo de fallo seguro es no emitir.
    const v = evaluateGrant(
      grant({ confirmedAt: new Date(NOW + 60 * 60_000).toISOString() }),
      REG,
      NOW,
    )
    expect(v.canIssue).toBe(false)
    expect(v.kind).toBe("stale")
  })

  it("confirmedAt corrupto no habilita la emisión", () => {
    const v = evaluateGrant(grant({ confirmedAt: "no-es-una-fecha" }), REG, NOW)
    expect(v.canIssue).toBe(false)
  })

  it("el TTL son 12 horas — cubre una jornada de corte, no una tablet olvidada", () => {
    expect(TENANCY_TTL_MS).toBe(12 * 60 * 60 * 1000)
  })
})
