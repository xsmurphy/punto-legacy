import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js"
import { WebStandardStreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/webStandardStreamableHttp.js"

import { buildReadOnlyFetchTools } from "@/lib/agent/read-tools"

/**
 * Server MCP de Punto — M1 de `context/58`.
 *
 * Es un ROUTE, no un contenedor aparte. MCP sobre Streamable HTTP es JSON-RPC
 * por POST: un endpoint más. Un proceso propio solo haría falta con transporte
 * stdio (el cliente arranca un binario local) o si se quisiera escalar/aislar
 * esto del panel — ninguna de las dos aplica hoy, y evitarlo ahorra una app en
 * Coolify, sus env y su dominio.
 *
 * ── STATELESS, y no es una preferencia ──────────────────────────────────────
 * `sessionIdGenerator: undefined` desactiva el manejo de sesión del SDK. Es
 * obligatorio acá: un route handler de Next no garantiza el mismo proceso entre
 * requests, así que cualquier estado en memoria se perdería de forma
 * intermitente — el peor modo de fallar. Nuestras tools son lecturas sin
 * estado, así que no se pierde nada.
 *
 * ── El server se construye POR REQUEST, y eso es de seguridad ───────────────
 * Las tools se arman con el Bearer de ESTA request. Un `McpServer` module-level
 * y compartido quedaría con la credencial del primer tenant que lo tocó, y a
 * partir de ahí las lecturas de todos saldrían con esa key: un leak
 * cross-tenant silencioso, del que nadie se entera hasta que un comercio ve
 * datos de otro. Instanciar por request lo hace imposible por construcción, y
 * el costo es despreciable frente al fetch que viene después.
 *
 * ── Solo lectura ────────────────────────────────────────────────────────────
 * `buildReadOnlyFetchTools` excluye `render_chart` (es de presentación, la
 * pinta la UI del chat y acá no hay UI). Las mutaciones nunca estuvieron en el
 * catálogo: viven en `confirm-tool.ts` detrás de una confirmación humana que un
 * cliente MCP no tiene (D5). Y aunque alguien las expusiera, el backend corta:
 * el realm `mcp` es read-only en `apiAuthTenant()`.
 */

export const runtime = "nodejs"
export const dynamic = "force-dynamic"
export const maxDuration = 60

/**
 * Headers por los que puede llegar la key, en orden de preferencia.
 *
 * `authorization` es el natural y el que usa el puente `mcp-remote`. Pero la UI
 * de Connectors de Claude Desktop lo RESERVA para su propio bearer de OAuth: en
 * "Additional request headers" aparece deshabilitado, y los seleccionables son
 * los de abajo. Como nuestro server no habla OAuth, sin esta lista la única
 * instalación posible era editar `claude_desktop_config.json` a mano — que para
 * un comercio no es una opción.
 *
 * Son varios y no uno porque el menú lo dicta el cliente, no nosotros: el
 * usuario elige cualquiera de esa lista y todos tienen que funcionar, o el
 * fracaso depende de cuál haya tocado.
 */
const KEY_HEADERS = [
  "authorization",
  "x-api-key",
  "api-key",
  "apikey",
  "x-apikey",
  "x-api-token",
  "api-token",
  "x-auth-token",
  "x-access-token",
] as const

/**
 * Devuelve la credencial normalizada a `Bearer <key>`, venga como venga.
 *
 * Los headers alternativos llevan la key PELADA (nadie escribe "Bearer " en un
 * campo que se llama `x-api-key`), así que se le antepone el esquema — el
 * backend espera `Bearer` y no debería enterarse de por dónde entró.
 */
function resolveBearer(req: Request): string {
  for (const name of KEY_HEADERS) {
    const raw = (req.headers.get(name) ?? "").trim()
    if (raw === "") continue
    return /^bearer\s+/i.test(raw) ? raw : `Bearer ${raw}`
  }
  return ""
}

async function handle(req: Request): Promise<Response> {
  const authHeader = resolveBearer(req)
  if (authHeader === "") {
    // Rechazo temprano por ausencia de credencial. La VALIDEZ de la key no se
    // chequea acá: la resuelve la API en cada llamada (`authResolve`, realm
    // `mcp`). Verificarla en este punto costaría un roundtrip extra por
    // request para adelantar un error que igual va a llegar — y duplicaría en
    // el front una decisión que es del backend.
    return Response.json(
      {
        jsonrpc: "2.0",
        error: {
          code: -32001,
          message:
            "Falta la API key de Punto. Mandala en el header `x-api-key` (o `Authorization: Bearer <key>`). " +
            "La generás en Ajustes → Keys de integración.",
        },
        id: null,
      },
      { status: 401 },
    )
  }

  const apiUrl = process.env.API_URL ?? ""
  if (apiUrl === "") {
    console.error("[mcp] API_URL no configurada")
    return Response.json(
      { jsonrpc: "2.0", error: { code: -32603, message: "Servidor mal configurado" }, id: null },
      { status: 500 },
    )
  }

  const server = new McpServer({ name: "punto", version: "1.0.0" })

  // Mismas definiciones que consume el agente propio (`context/58` D11): el
  // catálogo es la fuente compartida, este archivo solo es otro transporte.
  const tools = buildReadOnlyFetchTools({
    apiUrl,
    // El view-scope NO viaja: un cliente MCP no tiene el selector de sucursal
    // del panel, así que las lecturas salen con la sucursal del usuario que
    // emitió la key. Es lo mismo que ve ese usuario cuando entra sin tocar el
    // selector.
    dataHeaders: { Authorization: authHeader },
    authHeader,
  })

  for (const [name, def] of Object.entries(tools)) {
    server.registerTool(
      name,
      { description: def.description, inputSchema: def.inputSchema },
      async (args: unknown) => {
        const result = await (def.execute as (i: unknown) => Promise<unknown>)(args)
        // El resultado va como texto JSON: es lo que el protocolo transporta, y
        // el modelo del cliente lo lee igual de bien que un objeto.
        return { content: [{ type: "text" as const, text: JSON.stringify(result) }] }
      },
    )
  }

  const transport = new WebStandardStreamableHTTPServerTransport({ sessionIdGenerator: undefined })
  await server.connect(transport)
  return transport.handleRequest(req)
}

export const POST = handle
// El transporte también atiende GET (stream SSE) y DELETE (cierre de sesión).
// En stateless ninguno hace falta, pero un cliente puede sondearlos: que los
// conteste el transporte es más honesto que un 405 nuestro.
export const GET = handle
export const DELETE = handle
