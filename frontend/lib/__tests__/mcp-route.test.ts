import { describe, expect, it, vi, beforeEach, afterEach } from "vitest"

/**
 * Smoke del protocolo MCP contra el route real (`app/api/mcp/route.ts`).
 *
 * Existe por una limitación concreta: no hay forma de probar contra Claude
 * desde el entorno de desarrollo, así que "funciona" dependería de que alguien
 * lo conecte a mano. Esto ejercita el handshake y el listado con Requests
 * estándar —que es literalmente lo que el cliente manda— y cubre el modo de
 * falla más probable: que el server arranque pero hable mal el protocolo.
 *
 * También fija dos propiedades de seguridad del route:
 *  - Sin credencial no se atiende NADA, ni siquiera el listado de tools.
 *  - La key entra por `x-api-key` además de por `Authorization`: la UI de
 *    Connectors de Claude RESERVA `authorization` para su propio bearer de
 *    OAuth y solo deja elegir los alternativos, así que sin eso la única
 *    instalación posible sería editar el JSON de config a mano.
 *  - `render_chart` no se expone: es de presentación y un cliente MCP no tiene
 *    UI de chat donde pintarla.
 */

const posFetchMock = vi.fn()
vi.mock("@/lib/api/pos-fetch", () => ({ posFetch: (...a: unknown[]) => posFetchMock(...a) }))

const OLD_ENV = process.env.API_URL

beforeEach(() => {
  process.env.API_URL = "https://api.example.test"
})
afterEach(() => {
  process.env.API_URL = OLD_ENV
})

/** Un POST JSON-RPC como el que manda un cliente MCP. */
function rpc(body: unknown, headers: Record<string, string> = {}): Request {
  return new Request("https://app.example.test/api/mcp", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      // El transporte exige que el cliente acepte ambos tipos.
      Accept: "application/json, text/event-stream",
      ...headers,
    },
    body: JSON.stringify(body),
  })
}

const AUTH = { Authorization: "Bearer key-de-prueba" }

const INITIALIZE = {
  jsonrpc: "2.0",
  id: 1,
  method: "initialize",
  params: {
    protocolVersion: "2025-06-18",
    capabilities: {},
    clientInfo: { name: "test", version: "1.0.0" },
  },
}

/** El transporte puede responder JSON o SSE; se normaliza a objeto. */
async function readRpc(res: Response): Promise<Record<string, unknown>> {
  const text = await res.text()
  const ct = res.headers.get("content-type") ?? ""
  if (ct.includes("text/event-stream")) {
    const line = text.split("\n").find((l) => l.startsWith("data:"))
    return line ? JSON.parse(line.slice(5).trim()) : {}
  }
  return text ? JSON.parse(text) : {}
}

describe("route MCP", () => {
  it("GET responde 405 al instante, no cuelga", async () => {
    // Regresión concreta: delegar GET al transporte abría un stream SSE que
    // nunca emitía ni cerraba, así que la request quedaba colgada hasta el
    // timeout. Claude Desktop sondea con GET al agregar el conector y reportaba
    // "Couldn't connect to the server" aunque el POST funcionara — un síntoma
    // que manda a revisar la URL, que está bien.
    const { GET } = await import("../../app/api/mcp/route")
    const res = await Promise.race([
      Promise.resolve(GET()),
      new Promise<never>((_, rej) => setTimeout(() => rej(new Error("el GET colgó")), 2000)),
    ])
    expect(res.status).toBe(405)
    expect(res.headers.get("allow")).toBe("POST")
  })

  it("rechaza sin Authorization, antes de exponer nada", async () => {
    const { POST } = await import("../../app/api/mcp/route")
    const res = await POST(rpc(INITIALIZE))
    expect(res.status).toBe(401)
    const body = await readRpc(res)
    expect(JSON.stringify(body)).toContain("API key")
  })

  it("acepta la key por x-api-key, que es lo que ofrece la UI de Connectors", async () => {
    const { POST } = await import("../../app/api/mcp/route")
    const res = await POST(rpc(INITIALIZE, { "x-api-key": "key-pelada-sin-bearer" }))
    expect(res.status).toBe(200)
    const body = await readRpc(res)
    expect((body.result as Record<string, unknown>)?.serverInfo).toMatchObject({ name: "punto" })
  })

  it("completa el handshake de initialize", async () => {
    const { POST } = await import("../../app/api/mcp/route")
    const res = await POST(rpc(INITIALIZE, AUTH))
    expect(res.status).toBe(200)
    const body = await readRpc(res)
    const result = body.result as Record<string, unknown> | undefined
    expect(result, `respuesta inesperada: ${JSON.stringify(body)}`).toBeTruthy()
    expect(result).toHaveProperty("protocolVersion")
    expect(result?.serverInfo).toMatchObject({ name: "punto" })
  })

  it("lista las tools de lectura y NO render_chart", async () => {
    const { POST } = await import("../../app/api/mcp/route")
    await POST(rpc(INITIALIZE, AUTH))

    const res = await POST(rpc({ jsonrpc: "2.0", id: 2, method: "tools/list", params: {} }, AUTH))
    const body = await readRpc(res)
    const result = body.result as { tools?: { name: string; description?: string }[] } | undefined
    const tools = result?.tools ?? []

    expect(tools.length, `no listó tools: ${JSON.stringify(body)}`).toBeGreaterThan(10)
    const names = tools.map((t) => t.name)
    expect(names).toContain("get_sales_summary")
    expect(names).not.toContain("render_chart")
    // Las descripciones son la UX del producto: el modelo del cliente no tiene
    // otra cosa para elegir la herramienta correcta.
    for (const t of tools) expect(t.description, `${t.name} sin descripción`).toBeTruthy()
  })
})
