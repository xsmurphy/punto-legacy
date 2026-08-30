import { describe, expect, it } from "vitest"

import { buildReadTools, buildReadOnlyFetchTools, PRESENTATION_TOOLS } from "@/lib/agent/read-tools"

/**
 * GUARD del catálogo compartido de tools de lectura.
 *
 * Estas definiciones tienen DOS consumidores (`context/58` D11): el agente de
 * Punto (`app/api/agent/chat/route.ts`) y el MCP server que va a servir a
 * clientes externos. El riesgo que este test cubre no es que el código
 * explote —TypeScript ya lo agarra— sino que el catálogo se degrade en
 * silencio de formas que solo se notan cuando un modelo elige mal:
 *
 *  - Una tool sin descripción, o con una descripción de tres palabras. El
 *    modelo del cliente no tiene la UI de Punto: lo ÚNICO que lee para decidir
 *    es el nombre y la descripción, así que ahí está la UX del producto
 *    (`context/58` §Arquitectura).
 *  - Una tool de ESCRITURA colada en un catálogo declarado read-only. Las
 *    mutaciones viven aparte (`makeActionTools`) porque necesitan la
 *    confirmación humana que un cliente MCP no tiene (D5).
 *  - `render_chart` filtrándose a un consumidor sin UI de chat: no hace fetch,
 *    la pinta el front, y en un cliente MCP no puede hacer nada útil.
 */

const ctx = {
  apiUrl: "https://api.example.test",
  dataHeaders: { Authorization: "Bearer x", "X-Outlet-Id": "outlet-1" },
  authHeader: "Bearer x",
}

describe("catálogo de tools de lectura", () => {
  const tools = buildReadTools(ctx)

  it("expone las 20 tools, todas con nombre de lectura o presentación", () => {
    const names = Object.keys(tools)
    expect(names).toHaveLength(20)
    // Si alguna vez entra una `create_*`/`update_*`/`delete_*` acá, es que se
    // movió una mutación al catálogo read-only. Ver D5.
    const mutantes = names.filter((n) => /^(create|update|delete|set|post|import)_/.test(n))
    expect(mutantes).toEqual([])
  })

  it("toda tool tiene una descripción que sirve para elegirla", () => {
    for (const [name, def] of Object.entries(tools)) {
      expect(def.description, `${name} sin descripción`).toBeTruthy()
      // Umbral deliberadamente bajo: no juzga estilo, caza el placeholder o
      // la descripción vacía. Pulir la redacción para que un modelo AJENO
      // elija bien es la fase M2 de `context/58`, y no se automatiza con un
      // largo mínimo. (`get_outlets` mide 29 y está bien: la tool es trivial.)
      expect(def.description.length, `${name}: descripción demasiado corta`).toBeGreaterThan(20)
      expect(def.inputSchema, `${name} sin inputSchema`).toBeTruthy()
      expect(typeof def.execute, `${name} sin execute`).toBe("function")
    }
  })

  it("el set sin presentación excluye render_chart y nada más", () => {
    const fetchOnly = Object.keys(buildReadOnlyFetchTools(ctx))
    expect(fetchOnly).toHaveLength(Object.keys(tools).length - PRESENTATION_TOOLS.length)
    for (const p of PRESENTATION_TOOLS) expect(fetchOnly).not.toContain(p)
  })

  it("ninguna tool sale a un host que no sea el apiUrl del contexto", async () => {
    // El catálogo no puede tener endpoints hardcodeados: el MCP server va a
    // pasar otro `apiUrl` y las lecturas tienen que seguirlo.
    const urls: string[] = []
    const orig = globalThis.fetch
    globalThis.fetch = (async (u: string | URL) => {
      urls.push(String(u))
      return new Response(JSON.stringify({ data: null }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    }) as typeof fetch

    try {
      for (const [name, def] of Object.entries(buildReadOnlyFetchTools(ctx))) {
        // Entrada mínima: no todas las tools llegan a hacer fetch con ella
        // (algunas validan y cortan antes), y no es lo que este caso mide. Lo
        // que se verifica es que NINGUNA salga a un host que no sea el del
        // contexto — un endpoint hardcodeado rompería al MCP server, que va a
        // pasar otro `apiUrl`.
        await (def.execute as (i: unknown) => Promise<unknown>)({ year: 2026 }).catch(() => {})
        for (const u of urls) expect(u.startsWith(ctx.apiUrl), `${name} salió a ${u}`).toBe(true)
        urls.length = 0
      }
    } finally {
      globalThis.fetch = orig
    }
  })
})
