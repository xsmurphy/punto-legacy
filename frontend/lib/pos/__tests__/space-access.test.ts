/**
 * El espejo de front del guard de exclusividad de mesa (`space-access.ts`).
 *
 * Lo que se ancla acá NO es la autorización —esa la hace
 * `api/lib/Spaces/SpaceOwnershipGuard.php` y no se puede evadir desde el
 * browser— sino la FIDELIDAD del espejo: que el front no ofrezca una acción que
 * el backend va a rechazar, ni apague una que aceptaría. Un espejo que se
 * desincroniza no rompe la seguridad, rompe la confianza del cajero en la caja.
 *
 * El orden de las salidas es la parte frágil, y el caso que más duele es el
 * dueño SIN token: es intuitivo resolverlo a favor ("es su propia mesa") y el
 * backend igual lo rechaza, porque la identidad que compara sale de la
 * afirmación firmada, no del match local del PIN. Ese test está para que
 * reordenar la función falle acá y no en la caja de un comercio.
 */

import { describe, expect, it } from "vitest"

import {
  SPACE_OVERRIDE_PERMISSION,
  evaluateSpaceAccess,
  type SpaceAccessInput,
} from "@/lib/pos/space-access"

const ANA = { id: "u-ana", name: "Ana" }

/** Caso base: Ana identificada, con token, sin permisos extra, mesa de Ana. */
function input(overrides: Partial<SpaceAccessInput> = {}): SpaceAccessInput {
  return {
    session: { waiterId: ANA.id },
    activeUser: ANA,
    operatorToken: "tok-1",
    permissions: [],
    waiterName: "Ana",
    ...overrides,
  }
}

describe("evaluateSpaceAccess", () => {
  it("sin sesión no aplica la exclusividad: el espacio libre lo opera cualquiera", () => {
    const r = evaluateSpaceAccess(input({ session: null, activeUser: null, operatorToken: null }))
    expect(r).toEqual({ allowed: true, reason: null })
  })

  it("mesa sin mozo asignado: permitido incluso sin token ni operador identificado", () => {
    const r = evaluateSpaceAccess(
      input({ session: { waiterId: null }, activeUser: null, operatorToken: null }),
    )
    expect(r.allowed).toBe(true)
  })

  it("un `waiterId` de puro espacio en blanco cuenta como mesa sin mozo", () => {
    // El guard PHP compara contra `''` tras `trim()`; si el espejo tomara el
    // string crudo como "asignada", apagaría acciones que el backend acepta.
    const r = evaluateSpaceAccess(input({ session: { waiterId: "   " }, activeUser: null }))
    expect(r.allowed).toBe(true)
  })

  it("sin operador identificado, la mesa asignada se bloquea (fail-closed)", () => {
    const r = evaluateSpaceAccess(input({ activeUser: null }))
    expect(r.allowed).toBe(false)
    expect(r.reason).toContain("PIN")
  })

  it("el DUEÑO sin token queda bloqueado: la identidad la prueba el token, no el PIN local", () => {
    // Regresión 2026-08-25. Un `/api/pos/unlock` caído con el device online
    // dejaba al mozo con sus cinco acciones habilitadas sobre su propia mesa y
    // un 403 esperándolo en cada una. Si alguien mueve el chequeo del dueño
    // arriba del token "porque obviamente puede", este test lo frena.
    const r = evaluateSpaceAccess(input({ operatorToken: null }))
    expect(r.allowed).toBe(false)
    expect(r.reason).toContain("identidad verificada")
  })

  it("el dueño con token pasa", () => {
    expect(evaluateSpaceAccess(input()).allowed).toBe(true)
  })

  it("mesa ajena con override Y token: pasa, es la válvula del encargado", () => {
    const r = evaluateSpaceAccess(
      input({
        session: { waiterId: "u-bruno" },
        waiterName: "Bruno",
        permissions: [SPACE_OVERRIDE_PERMISSION],
      }),
    )
    expect(r.allowed).toBe(true)
  })

  it("mesa ajena con override pero SIN token: bloqueado — el permiso no vale sin identidad probada", () => {
    const r = evaluateSpaceAccess(
      input({
        session: { waiterId: "u-bruno" },
        waiterName: "Bruno",
        permissions: [SPACE_OVERRIDE_PERMISSION],
        operatorToken: null,
      }),
    )
    expect(r.allowed).toBe(false)
  })

  it("mesa ajena sin override: bloqueado, y el motivo nombra al mozo que la atiende", () => {
    const r = evaluateSpaceAccess(
      input({ session: { waiterId: "u-bruno" }, waiterName: "Bruno" }),
    )
    expect(r.allowed).toBe(false)
    expect(r.reason).toContain("Bruno")
  })

  it("mesa ajena de un mozo que no está en el roster: bloquea igual, con nombre genérico", () => {
    // El roster del bootstrap puede no tener al mozo (alta reciente, otra
    // sucursal). No saber el nombre nunca puede resolverse a favor.
    const r = evaluateSpaceAccess(input({ session: { waiterId: "u-x" }, waiterName: null }))
    expect(r.allowed).toBe(false)
    expect(r.reason).toContain("otro mozo")
  })
})
