import { describe, expect, it } from "vitest"

import { normalizeToolResult } from "@/lib/agent/normalize-tool-result"

/**
 * Ninguna credencial del equipo llega al modelo.
 *
 * `/v1/users` le manda al panel el PIN de caja en claro (`lockPass`) para
 * prellenar el form de equipo, y el agente corre con esa misma credencial:
 * sin la poda, cada `get_users` reenviaba el PIN de todo el personal a un
 * modelo externo. `pinhash` es SHA-256 sin sal de 4 dígitos — 10.000
 * combinaciones, reversible en el acto — así que un hash filtrado ES el PIN.
 *
 * El test cubre las tres claves aunque el endpoint ya no proyecte los hashes:
 * la regla del normalizador es la segunda capa, y tiene que frenar sola si la
 * primera se cae.
 */
describe("poda de credenciales del equipo", () => {
  const usersRow = {
    id: "u-1",
    name: "Ana Cajera",
    lockPass: "4321",
    lockpasshash: "ab12cd34",
    pinhash: "e3b0c44298fc1c149afbf4c8996fb924",
    status: 1,
  }

  it("get_users no deja pasar el PIN ni sus hashes", () => {
    const out = JSON.stringify(normalizeToolResult([usersRow]).value)
    expect(out).not.toContain("4321")
    expect(out).not.toContain("ab12cd34")
    expect(out).not.toContain("e3b0c44298fc1c149afbf4c8996fb924")
    expect(out).not.toMatch(/lockPass|lockpasshash|pinhash/)
    // Y lo que sí sirve para responder sigue ahí.
    expect(out).toContain("Ana Cajera")
  })

  it("la poda es por clave, a cualquier profundidad", () => {
    // `meta` NO se usa como clave del fixture a propósito: es el sobre que el
    // normalizador reserva para sí (ver RESERVED_KEYS) y no lo recorre.
    const nested = { users: [usersRow], team: { owner: { pinhash: "zz" } } }
    const out = JSON.stringify(normalizeToolResult(nested).value)
    expect(out).not.toMatch(/pinhash|lockPass/)
  })
})
