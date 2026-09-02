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
 *  - Sin credencial el handshake y el listado SÍ responden (un 401 dispara el
 *    flujo OAuth del cliente, que este server no habla — la UI de Connectors
 *    moría en dynamic client registration sin llegar a mandar nada), pero
 *    ejecutar una tool devuelve error de tool pidiendo la key. Los datos
 *    siguen detrás del gate real: la API valida la key en cada llamada.
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
      // El origen de los iconos se deriva de acá — sin Host no hay qué anunciar.
      host: "app.example.test",
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

  it("initialize responde 200 SIN credencial — un 401 dispararía OAuth en el cliente", async () => {
    const { POST } = await import("../../app/api/mcp/route")
    const res = await POST(rpc(INITIALIZE))
    expect(res.status).toBe(200)
    const body = await readRpc(res)
    expect((body.result as Record<string, unknown>)?.serverInfo).toMatchObject({ name: "punto" })
  })

  it("ejecutar una tool sin credencial devuelve error de tool pidiendo la key", async () => {
    const { POST } = await import("../../app/api/mcp/route")
    await POST(rpc(INITIALIZE))
    const res = await POST(
      rpc({
        jsonrpc: "2.0",
        id: 3,
        method: "tools/call",
        params: { name: "get_sales_summary", arguments: { year: 2026 } },
      }),
    )
    expect(res.status).toBe(200)
    const body = await readRpc(res)
    const result = body.result as { isError?: boolean; content?: { text?: string }[] } | undefined
    expect(result?.isError).toBe(true)
    expect(result?.content?.[0]?.text).toContain("API key")
    // Y no tocó la API: sin key no sale ningún fetch.
    expect(posFetchMock).not.toHaveBeenCalled()
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

  it("el origen de los iconos sale del REQUEST, no de un dominio hardcodeado", async () => {
    // Antes caía a un literal `https://app.punto.la`, y `APP_URL` no existe en el
    // env del Front: producción funcionaba por casualidad, y un contenedor de dev
    // habría anunciado los iconos de PRODUCCIÓN. Derivarlo del request es correcto
    // por definición — el cliente nos alcanzó en ese host.
    const { POST } = await import("../../app/api/mcp/route")
    const req = new Request("https://otro-host.test/api/mcp", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json, text/event-stream",
        "x-forwarded-host": "panel.cliente.test",
        "x-forwarded-proto": "https",
        ...AUTH,
      },
      body: JSON.stringify(INITIALIZE),
    })
    const body = await readRpc(await POST(req))
    const info = (body.result as { serverInfo?: Record<string, unknown> })?.serverInfo ?? {}
    expect(info.websiteUrl).toBe("https://panel.cliente.test")
    for (const i of (info.icons ?? []) as { src: string }[]) {
      expect(i.src.startsWith("https://panel.cliente.test/"), `src ajeno: ${i.src}`).toBe(true)
    }
  })

  it("el handshake lleva la identidad con la que el cliente dibuja el conector", async () => {
    // El cliente NO cae al favicon del dominio: si el handshake no manda
    // `title`/`icons`, el conector aparece sin marca. Y los `src` tienen que ser
    // absolutos — el cliente los busca desde su propio proceso, no desde el
    // navegador del usuario, así que una ruta relativa no resuelve.
    const { POST } = await import("../../app/api/mcp/route")
    const res = await POST(rpc(INITIALIZE, AUTH))
    const body = await readRpc(res)
    const info = (body.result as { serverInfo?: Record<string, unknown> })?.serverInfo ?? {}

    expect(info.title, "sin title el conector muestra el name crudo").toBeTruthy()
    expect(info.websiteUrl).toMatch(/^https?:\/\//)

    const icons = (info.icons ?? []) as { src: string; theme?: string }[]
    expect(icons.length, "sin icons el conector queda sin logo").toBeGreaterThan(0)
    for (const i of icons) expect(i.src, `icon relativo: ${i.src}`).toMatch(/^https?:\/\//)
    // Claro y oscuro: un logo pensado para un tema se ve mal en el otro.
    expect(icons.map((i) => i.theme).sort()).toEqual(["dark", "light"])
  })

  it("lista las tools de lectura y NO render_chart", async () => {
    const { POST } = await import("../../app/api/mcp/route")
    await POST(rpc(INITIALIZE, AUTH))

    const res = await POST(rpc({ jsonrpc: "2.0", id: 2, method: "tools/list", params: {} }, AUTH))
    const body = await readRpc(res)
    const result = body.result as
      | {
          tools?: {
            name: string
            description?: string
            inputSchema?: { properties?: Record<string, { description?: string }> }
          }[]
        }
      | undefined
    const tools = result?.tools ?? []

    expect(tools.length, `no listó tools: ${JSON.stringify(body)}`).toBeGreaterThan(10)
    const names = tools.map((t) => t.name)
    expect(names).toContain("get_sales_summary")
    expect(names).not.toContain("render_chart")
    // Las descripciones son la UX del producto: el modelo del cliente no tiene
    // otra cosa para elegir la herramienta correcta.
    for (const t of tools) expect(t.description, `${t.name} sin descripción`).toBeTruthy()

    // El MCP no declara parámetros: los HEREDA del catálogo compartido
    // (`buildReadOnlyFetchTools`). Este caso es el que prueba que esa herencia
    // funciona de verdad — la franja horaria (F3 de `context/67`) se agregó solo
    // en `read-tools.ts` y tiene que aparecer acá sin tocar este archivo. Si
    // alguna vez el transporte dejara de derivar el JSON Schema del zod, esto se
    // cae antes de que un cliente externo descubra que le falta un parámetro.
    const tx = tools.find((t) => t.name === "get_transactions")
    const props = tx?.inputSchema?.properties ?? {}
    expect(Object.keys(props)).toEqual(expect.arrayContaining(["hourFrom", "hourTo", "from", "to"]))
    // Y con su descripción, que es lo único que el modelo del cliente lee para
    // saber que la franja se repite en cada día del rango.
    expect(props.hourFrom?.description).toMatch(/franja horaria/i)
  })
})
