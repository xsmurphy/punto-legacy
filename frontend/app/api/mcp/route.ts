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
 * el realm `api` es read-only en `apiAuthTenant()`.
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

/**
 * Sin credencial NO se responde 401: el 401 es lo que dispara OAuth en el
 * cliente MCP. La UI de Connectors de Claude Desktop, al recibirlo, intenta
 * dynamic client registration contra endpoints que este server no tiene
 * (verificado contra prod: `WWW-Authenticate` ausente, los `/.well-known/*` y
 * `/register` dan 404) y muere con "Couldn't register with Punto's sign-in
 * service" ANTES de mandar una sola request al route. Los "Additional request
 * headers" del conector viajan en TODAS las requests, así que la key por
 * `x-api-key` llega igual — pero solo si el handshake no aborta primero.
 *
 * Por eso `initialize` y `tools/list` responden sin key y la exigencia vive en
 * la ejecución de cada tool. No baja la seguridad: la validez de la key nunca
 * se resolvió acá sino en la API en cada llamada (`authResolve`), y el listado
 * ya salía con cualquier key inventada — el catálogo de tools es público de
 * hecho, los DATOS siguen detrás del gate real del backend.
 */
const MISSING_KEY_MESSAGE =
  "Falta la API key de Punto. Mandala en el header `x-api-key` (o `Authorization: Bearer <key>`). " +
  "La generás en Ajustes → Keys de integración."

/**
 * Origen público de ESTE deploy, para armar URLs absolutas.
 *
 * `APP_URL` gana si está definida (config deliberada), pero NO se depende de
 * ella: hoy no existe en el env del Front. El fallback lee los headers que pone
 * el proxy —`x-forwarded-*`, que es lo que llega detrás de Coolify/Traefik— y
 * recién después el host directo del request.
 *
 * Sin literal de dominio: si nada resuelve, se devuelve '' y el caller OMITE los
 * iconos en vez de anunciar URLs de otro entorno. Un conector sin logo es un
 * detalle; uno que apunta al dominio equivocado es una mentira.
 */
function resolveOrigin(req: Request): string {
  const env = (process.env.APP_URL ?? "").trim().replace(/\/+$/, "")
  if (env !== "") return env

  const host = req.headers.get("x-forwarded-host") ?? req.headers.get("host") ?? ""
  if (host === "") return ""
  const proto = req.headers.get("x-forwarded-proto") ?? "https"
  return `${proto}://${host}`.replace(/\/+$/, "")
}

async function handle(req: Request): Promise<Response> {
  const authHeader = resolveBearer(req)

  const apiUrl = process.env.API_URL ?? ""
  if (apiUrl === "") {
    console.error("[mcp] API_URL no configurada")
    return Response.json(
      { jsonrpc: "2.0", error: { code: -32603, message: "Servidor mal configurado" }, id: null },
      { status: 500 },
    )
  }

  // La identidad del server viaja en el HANDSHAKE, no se deduce del dominio: el
  // cliente dibuja la tarjeta del conector con lo que le mandemos acá. Sin
  // `icons`, Claude no cae al favicon del sitio — muestra el conector sin
  // marca. (Dato de la sesión de Fish, que se topó con lo mismo.)
  //
  // Dos variantes por tema: el panel del cliente puede estar en claro u oscuro y
  // un logo pensado para uno se ve mal en el otro.
  //
  // URLs ABSOLUTAS: el cliente las busca desde su proceso, no desde el navegador
  // del usuario, así que una ruta relativa no resuelve.
  //
  // El host se DERIVA DE ESTA REQUEST, no de un literal. La versión anterior
  // caía a `"https://app.punto.la"` hardcodeado y — verificado contra Coolify —
  // `APP_URL` NO existe en el env del Front, así que producción estaba
  // funcionando por casualidad y no por configuración. Además de violar la
  // regla 3 del proyecto, hacía que un contenedor de dev anunciara los iconos y
  // el sitio de PRODUCCIÓN.
  //
  // Derivarlo del request es correcto por definición: el cliente nos alcanzó en
  // ese host, así que los assets servidos desde ahí le van a resolver. Un `Host`
  // mentiroso solo se perjudica a sí mismo (recibe URLs de íconos que no puede
  // cargar); no hay forma de que afecte a otro.
  const appUrl = resolveOrigin(req)
  const server = new McpServer({
    name: "punto",
    version: "1.0.0",
    title: "Punto",
    description: "Los datos de tu comercio: ventas, stock, clientes, caja y finanzas.",
    // Sin origen resuelto no se anuncian: mejor un conector sin logo que uno
    // que apunta al dominio de otro entorno.
    ...(appUrl !== ""
      ? {
          websiteUrl: appUrl,
          icons: [
            { src: `${appUrl}/logos/icon_bg_light.png`, mimeType: "image/png", theme: "light" as const },
            { src: `${appUrl}/logos/icon_bg_dark.png`, mimeType: "image/png", theme: "dark" as const },
          ],
        }
      : {}),
  })

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

  // Prefijo `punto_` — el namespace es responsabilidad del SERVER, no del
  // cliente (misma convención que el MCP de Fish, que expone `fish_*`).
  //
  // Va acá y NO en el catálogo (`lib/agent/read-tools.ts`): ahí los nombres
  // pelados son correctos porque el agente del panel y el de la caja corren
  // DENTRO de Punto, donde no hay con quién chocar. El choque aparece recién
  // en el transporte, cuando las tools se mezclan con las de otro producto:
  // un `get_contacts` pelado es ambiguo para un cliente que ya tiene
  // contactos propios, y el modelo termina cruzando las dos fuentes.
  //
  // OJO al cambiarlo: los clientes MCP cachean el catálogo, así que un
  // conector ya configurado sigue pidiendo los nombres viejos hasta que se
  // reconecta. Renombrar acá obliga a reconectar TODOS los conectores.
  for (const [name, def] of Object.entries(tools)) {
    server.registerTool(
      `punto_${name}`,
      { description: def.description, inputSchema: def.inputSchema },
      async (args: unknown) => {
        // La exigencia de credencial vive acá y no en el embudo (ver arriba):
        // como error DE TOOL y no de protocolo, el modelo del cliente lo lee y
        // le explica al usuario qué configurar, en vez de un fallo opaco.
        if (authHeader === "") {
          return { content: [{ type: "text" as const, text: MISSING_KEY_MESSAGE }], isError: true }
        }
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

/**
 * GET y DELETE se rechazan con 405 INMEDIATO, sin pasar por el transporte.
 *
 * GET es el stream SSE del protocolo y DELETE el cierre de sesión: los dos
 * presuponen una sesión, y en stateless no hay ninguna. Delegarlos al
 * transporte —que era la versión anterior de este archivo— hace que el GET abra
 * un stream que nunca emite ni cierra: la request queda COLGADA hasta el
 * timeout del cliente.
 *
 * Y colgar es peor que rechazar. Claude Desktop sondea con GET al agregar el
 * conector, se queda esperando, y reporta "Couldn't connect to the server" —
 * aunque el POST, que es por donde pasa todo el protocolo, funcione
 * perfectamente. El síntoma manda a revisar la URL y el server, que están bien.
 *
 * 405 con `Allow: POST` le dice al cliente exactamente qué hacer, en
 * milisegundos.
 */
function methodNotAllowed(): Response {
  return Response.json(
    {
      jsonrpc: "2.0",
      error: {
        code: -32000,
        message: "Este server MCP es stateless: usá POST. GET (stream SSE) y DELETE (cierre de sesión) no aplican.",
      },
      id: null,
    },
    { status: 405, headers: { Allow: "POST" } },
  )
}

export const GET = methodNotAllowed
export const DELETE = methodNotAllowed
