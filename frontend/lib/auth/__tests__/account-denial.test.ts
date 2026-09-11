import { describe, expect, it } from "vitest"

import { ApiError } from "@/lib/api-client"
import {
  ACCOUNT_DENIAL_REASONS,
  readAccountDenialFromEnvelope,
  readAccountDenialReason,
  type AccountDenialReason,
} from "@/lib/auth/account-denial"

/**
 * Guard del branch que decide si el panel se reemplaza por la pantalla de
 * estado de cuenta.
 *
 * Lo que este test protege es la LÍNEA que separa dos 403 que se parecen:
 *
 *   - el del EMBUDO de auth (cuenta bloqueada/suspendida/inactiva), que afecta
 *     a todas las pantallas y merece cartel a pantalla completa;
 *   - el de PERMISOS de un endpoint, donde el resto del panel funciona y el
 *     cartel sería una mentira.
 *
 * El único discriminador válido es `error.details.reason`. Si alguien lo
 * reemplaza por un heurístico sobre el texto del mensaje, o si el backend
 * agrega un motivo nuevo sin que el panel lo contemple, acá se ve.
 */

function envelope(reason: string | null) {
  return {
    ok: false,
    error: {
      message: "Cuenta bloqueada por falta de pago",
      ...(reason ? { details: { reason } } : {}),
    },
  }
}

describe("readAccountDenialReason", () => {
  it("reconoce los tres motivos del resolver del backend", () => {
    for (const reason of ACCOUNT_DENIAL_REASONS) {
      const err = new ApiError(403, envelope(reason), "Cuenta bloqueada")
      expect(readAccountDenialReason(err)).toBe(reason)
    }
  })

  it("acepta el envelope pelado además del ApiError", () => {
    expect(
      readAccountDenialReason({ status: 403, ...envelope("account_suspended") }),
    ).toBe("account_suspended")
  })

  it("NO intercepta el 403 de permisos (sin reason en el sobre)", () => {
    const err = new ApiError(403, envelope(null), "No tenés permiso")
    expect(readAccountDenialReason(err)).toBeNull()
  })

  it("NO intercepta un reason desconocido", () => {
    // `outlet_out_of_scope` viaja por el MISMO campo y lo maneja el api-client
    // reintentando sin el header. No puede caer acá.
    const err = new ApiError(403, envelope("outlet_out_of_scope"), "Sucursal fuera de alcance")
    expect(readAccountDenialReason(err)).toBeNull()
    expect(readAccountDenialReason(new ApiError(403, envelope("company_unknown"), "x"))).toBeNull()
  })

  it("exige 403 — el mismo reason con otro status no cuenta", () => {
    expect(readAccountDenialReason(new ApiError(401, envelope("account_blocked"), "x"))).toBeNull()
    expect(readAccountDenialReason(new ApiError(500, envelope("account_blocked"), "x"))).toBeNull()
  })

  it("tolera basura sin romper", () => {
    for (const value of [null, undefined, "", 0, "account_blocked", new Error("boom"), {}]) {
      expect(readAccountDenialReason(value)).toBeNull()
    }
    expect(readAccountDenialReason(new ApiError(403, null, "sin cuerpo"))).toBeNull()
    expect(readAccountDenialReason(new ApiError(403, "texto plano", "no json"))).toBeNull()
  })
})

describe("readAccountDenialFromEnvelope (punto del transporte)", () => {
  it("lee el motivo del sobre ya parseado", () => {
    const reason: AccountDenialReason = "account_inactive"
    expect(readAccountDenialFromEnvelope(403, envelope(reason))).toBe(reason)
  })

  it("ignora todo lo que no sea un 403 con motivo conocido", () => {
    expect(readAccountDenialFromEnvelope(403, envelope(null))).toBeNull()
    expect(readAccountDenialFromEnvelope(200, envelope("account_blocked"))).toBeNull()
    expect(readAccountDenialFromEnvelope(403, null)).toBeNull()
  })
})
