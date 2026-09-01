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
    // Y no se le ofrece tomarla: es el único caso que el device no puede
    // resolver solo.
    expect(v.canAcquire).toBe(false)
  })

  // ── "Libre pero no la tengo" (2026-09-01) ──────────────────────────────────
  // Estado NUEVO, y solo existe porque el servidor dejó de tomar la caja sola.
  // Antes un 409 de `claim.php` únicamente podía significar "la tiene otro"
  // —si estaba libre, el endpoint se la quedaba— así que `denied` alcanzaba
  // para todo. Ahora el latido pregunta con `acquire: false` y estos tres
  // motivos llegan al grant: la caja está disponible y el remedio es que el
  // cajero la tome, no que busque un admin.

  it.each(["released", "revoked", "never_held"] as const)(
    "denyReason %s ⇒ caja LIBRE: no emite, pero se puede tomar",
    (denyReason) => {
      const v = evaluateGrant(grant({ status: "denied", denyReason }), REG, NOW)
      expect(v.canIssue).toBe(false)
      expect(v.kind).toBe("free")
      expect(v.canAcquire).toBe(true)
    },
  )

  it("un denied SIN reason legible no emite, pero deja pedir la caja", () => {
    // Backend viejo, o un 409 sin `details` parseable. Cada pregunta cae para
    // su lado seguro por separado: no emitir nunca se relaja; pedir la caja es
    // inofensivo porque el claim devuelve el estado real.
    const v = evaluateGrant(grant({ status: "denied", denyReason: null }), REG, NOW)
    expect(v.canIssue).toBe(false)
    expect(v.kind).toBe("free")
    expect(v.canAcquire).toBe(true)
  })

  it("un denied con holder pero sin reason sigue siendo 'la tiene otro'", () => {
    // El tenedor es información dura: si vino un `holderDeviceId`, la caja está
    // ocupada aunque el `reason` no se haya podido leer. No ofrecerla.
    const v = evaluateGrant(
      grant({ status: "denied", denyReason: null, holderDeviceId: "dev-9" }),
      REG,
      NOW,
    )
    expect(v.kind).toBe("denied")
    expect(v.canAcquire).toBe(false)
  })

  it("teniendo la caja no se ofrece tomarla — no hay nada que tomar", () => {
    expect(evaluateGrant(grant(), REG, NOW).canAcquire).toBe(false)
  })

  it("sin grant se puede pedir la caja: puede estar libre y este device no lo sabe", () => {
    expect(evaluateGrant(null, REG, NOW).canAcquire).toBe(true)
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
    // El device se movió de caja: pedir la NUEVA es exactamente el remedio.
    expect(v.canAcquire).toBe(true)
  })

  it("vencido: no emite, pero reconfirmar/tomar sigue siendo el remedio", () => {
    // La tenencia server-side no vence sola, así que lo más probable es que la
    // caja siga siendo de este device y solo falte volver a confirmarla.
    const expired = grant({
      confirmedAt: new Date(NOW - TENANCY_TTL_MS - 60_000).toISOString(),
    })
    expect(evaluateGrant(expired, REG, NOW).canAcquire).toBe(true)
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
