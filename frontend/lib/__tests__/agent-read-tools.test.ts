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

  it("expone las 21 tools, todas con nombre de lectura o presentación", () => {
    const names = Object.keys(tools)
    // 20 al extraer el catálogo + `get_sales_kpis` (2026-08-31), que expone el
    // widget donde el backend calcula el ticket promedio.
    expect(names).toHaveLength(21)
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

  /**
   * `compareWith` DUPLICA los fetch. Que no dispare la segunda lectura por su
   * cuenta no es una optimización: sin este guard, un default o un descuido en
   * una tool le cobraría dos llamadas de red a todas las preguntas que no
   * necesitan comparar, y no habría forma de notarlo desde afuera —la respuesta
   * se vería igual, solo más lenta y más cara.
   */
  /**
   * Corre una tool contra un `fetch` de mentira y devuelve las lecturas de
   * DATOS que hizo.
   *
   * `/v1/settings` se filtra: es el resolver de la moneda, que se dispara
   * solo cuando el payload trae montos y ya está memoizado por instancia del
   * catálogo. Lo que se mide con esto es cuántas veces se leyó el REPORTE, y
   * con qué query.
   */
  async function urlsFor(tool: string, input: unknown): Promise<string[]> {
    const urls: string[] = []
    const orig = globalThis.fetch
    globalThis.fetch = (async (u: string | URL) => {
      urls.push(String(u))
      return new Response(JSON.stringify({ data: { rows: [{ total: 100 }] } }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    }) as typeof fetch
    try {
      const def = buildReadTools(ctx)[tool as keyof ReturnType<typeof buildReadTools>]
      await (def.execute as (i: unknown) => Promise<unknown>)(input)
    } finally {
      globalThis.fetch = orig
    }
    return urls.filter((u) => !u.includes("/v1/settings"))
  }

  /** Lo que devuelve una tool, y cuántas veces salió a la red para devolverlo. */
  async function resultOf(
    tool: string,
    input: unknown,
  ): Promise<{ out: { error?: string }; fetches: number }> {
    const orig = globalThis.fetch
    let fetches = 0
    globalThis.fetch = (async () => {
      fetches += 1
      return new Response(JSON.stringify({ data: { rows: [] } }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      })
    }) as typeof fetch
    try {
      const def = buildReadTools(ctx)[tool as keyof ReturnType<typeof buildReadTools>]
      const out = (await (def.execute as (i: unknown) => Promise<unknown>)(input)) as {
        error?: string
      }
      return { out, fetches }
    } finally {
      globalThis.fetch = orig
    }
  }

  const rango = { from: "2026-08-01", to: "2026-08-31" }

  describe("compareWith no cuesta nada hasta que lo piden", () => {

    it("sin compareWith hace UNA sola lectura", async () => {
      const urls = await urlsFor("get_transactions", rango)
      expect(urls).toHaveLength(1)
      expect(urls[0]).toContain("from=2026-08-01")
    })

    it("con compareWith hace dos, y la segunda es el período comparado", async () => {
      const urls = await urlsFor("get_transactions", { ...rango, compareWith: "previous_year" })
      expect(urls).toHaveLength(2)
      expect(urls[1]).toContain("from=2025-08-01")
      expect(urls[1]).toContain("to=2025-08-31")
    })

    it("sin rango explícito NO gasta la segunda lectura y lo declara", async () => {
      // Los endpoints default'ean el rango del lado del servidor, así que sin
      // from/to no sabemos qué ventana comparar. Se corta ANTES del fetch.
      const urls = await urlsFor("get_transactions", { compareWith: "previous_period" })
      expect(urls).toHaveLength(1)
    })

    it("un reporte que ignora las fechas se niega a comparar, sin fetch de más", async () => {
      const urls = await urlsFor("get_report", {
        report: "stock",
        ...rango,
        compareWith: "previous_period",
      })
      expect(urls).toHaveLength(1)
    })
  })

  /**
   * La FRANJA HORARIA (F3 de `context/67`).
   *
   * Es la dimensión que `from`/`to` no puede expresar —la misma franja repetida
   * en cada día del rango, no un intervalo continuo— y el modo de falla que
   * este bloque cubre no es que explote: es que devuelva un número plausible y
   * falso. Tres formas de producirlo, una por caso:
   *
   *  - la franja que no llega al baseline de `compareWith` (la mañana de
   *    septiembre comparada contra agosto entero),
   *  - la franja mandada a un reporte que no la mira y la ignora con 200,
   *  - la franja sin rango, que el backend rechaza con 422 (y que además le
   *    tira abajo el plan a la query: 3,7 → 109 ms, ver `context/67`).
   */
  describe("la franja horaria viaja entera o no viaja", () => {
    const manana = { hourFrom: "07:00", hourTo: "11:59" }

    it("viaja en la query de la lectura", async () => {
      const urls = await urlsFor("get_transactions", { ...rango, ...manana })
      expect(urls).toHaveLength(1)
      expect(urls[0]).toContain("hourFrom=07%3A00")
      expect(urls[0]).toContain("hourTo=11%3A59")
    })

    it("con compareWith va en LAS DOS rutas, no solo en la del período pedido", async () => {
      const urls = await urlsFor("get_transactions", {
        ...rango,
        ...manana,
        compareWith: "previous_year",
      })
      expect(urls).toHaveLength(2)
      // El baseline es el mismo rango del año anterior CON la misma franja: sin
      // esto el delta compararía una mañana contra un mes completo.
      expect(urls[1]).toContain("from=2025-08-01")
      expect(urls[1]).toContain("hourFrom=07%3A00")
      expect(urls[1]).toContain("hourTo=11%3A59")
    })

    it("get_top_products también la manda", async () => {
      const urls = await urlsFor("get_top_products", { ...rango, ...manana })
      expect(urls[0]).toContain("hourFrom=07%3A00")
    })

    it("get_report la manda en los reportes cableados, y en el baseline", async () => {
      const urls = await urlsFor("get_report", {
        report: "ventas_resumen",
        ...rango,
        ...manana,
        compareWith: "previous_period",
      })
      expect(urls).toHaveLength(2)
      for (const u of urls) expect(u).toContain("hourFrom=07%3A00")
    })

    it("sin rango explícito se rechaza ANTES del fetch, con el motivo", async () => {
      const { out, fetches } = await resultOf("get_transactions", manana)
      expect(fetches).toBe(0)
      expect(out.error).toMatch(/from y to/i)
    })

    it("un reporte que no filtra por hora se rechaza ANTES del fetch", async () => {
      // `marcas` sale de `rollup_*_day`, grano DÍA: la hora de cada venta ya no
      // existe ahí. El backend lo ignoraría y devolvería 200 con el día entero.
      const { out, fetches } = await resultOf("get_report", {
        report: "marcas",
        ...rango,
        ...manana,
      })
      expect(fetches).toBe(0)
      expect(out.error).toMatch(/no filtra por franja horaria/i)
      // Y le dice cuáles sí, que es lo que le permite reformular.
      expect(out.error).toContain("transacciones")
    })

    it("una hora mal formada no llega al backend", async () => {
      const { out, fetches } = await resultOf("get_transactions", { ...rango, hourFrom: "25:00" })
      expect(fetches).toBe(0)
      expect(out.error).toMatch(/HH:MM/)
    })

    it("la franja que cruza medianoche es válida y viaja tal cual", async () => {
      // 20:00 a 04:00 es la noche de un bar, no un error: el predicado lo
      // invierte el backend (`Date::hourRange`).
      const urls = await urlsFor("get_transactions", {
        ...rango,
        hourFrom: "20:00",
        hourTo: "04:00",
      })
      expect(urls).toHaveLength(1)
      expect(urls[0]).toContain("hourFrom=20%3A00")
      expect(urls[0]).toContain("hourTo=04%3A00")
    })

    it("sin franja la ruta es exactamente la de antes de esta feature", async () => {
      // El costo de la F3 para el 99% de las consultas tiene que ser CERO — ni
      // un parámetro vacío de más en la query.
      const [tx] = await urlsFor("get_transactions", rango)
      expect(tx).toBe(
        `${ctx.apiUrl}/v1/reports/transactions?limit=50&from=2026-08-01&to=2026-08-31`,
      )
      const [prod] = await urlsFor("get_top_products", rango)
      expect(prod).toBe(
        `${ctx.apiUrl}/v1/reports/products?view=general&from=2026-08-01&to=2026-08-31`,
      )
      const [rep] = await urlsFor("get_report", { report: "ordenes", ...rango })
      expect(rep).toBe(`${ctx.apiUrl}/v1/reports/orders?from=2026-08-01&to=2026-08-31`)
    })
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

/**
 * El 403 de permisos, traducido.
 *
 * Desde el 2026-09-02 los veinte endpoints de `/v1/reports/*` miden la lectura
 * contra el permiso de quien pregunta, así que un 403 dejó de ser una rareza:
 * es la respuesta NORMAL cuando un cajero le pide el balance al asistente. Lo
 * que el catálogo le devuelva al modelo en ese caso decide qué escucha el
 * usuario, y un `Error 403` pelado tiene dos finales malos — el modelo lo
 * parafrasea como una falla del sistema, o lo lee como transitorio y reintenta
 * la misma consulta para cobrar otro 403.
 */
describe("un 403 de permisos llega al modelo como una restricción, no como una falla", () => {
  function conRespuesta(status: number, body: unknown) {
    const orig = globalThis.fetch
    globalThis.fetch = (async () =>
      new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
      })) as typeof fetch
    return () => {
      globalThis.fetch = orig
    }
  }

  async function correr(status: number, body: unknown) {
    const restaurar = conRespuesta(status, body)
    try {
      const tools = buildReadOnlyFetchTools(ctx)
      return (await (tools.get_stock.execute as (i: unknown) => Promise<unknown>)({})) as {
        error?: string
      }
    } finally {
      restaurar()
    }
  }

  const envelope403 = {
    ok: false,
    error: { message: "No tenés permiso para esta acción (requiere: inventory.item.view)", code: 403 },
  }

  it("usa el mensaje del backend, que nombra la clave que falta", async () => {
    const out = await correr(403, envelope403)
    expect(out.error).toContain("inventory.item.view")
    expect(out.error).toContain("No tenés permiso")
  })

  it("le dice al modelo que no reintente", async () => {
    const out = await correr(403, envelope403)
    expect(out.error).toMatch(/no reintentes/i)
    // Y que es de permisos, no una caída — es la distinción que evita el
    // "hubo un problema al obtener el reporte".
    expect(out.error).toMatch(/permisos/i)
  })

  it("un 403 sin cuerpo JSON no se queda sin explicación", async () => {
    const restaurar = (() => {
      const orig = globalThis.fetch
      globalThis.fetch = (async () => new Response("<html>403</html>", { status: 403 })) as typeof fetch
      return () => {
        globalThis.fetch = orig
      }
    })()
    try {
      const tools = buildReadOnlyFetchTools(ctx)
      const out = (await (tools.get_stock.execute as (i: unknown) => Promise<unknown>)({})) as {
        error?: string
      }
      expect(out.error).toMatch(/permiso/i)
      expect(out.error).toMatch(/no reintentes/i)
    } finally {
      restaurar()
    }
  })

  it("el resto de los errores sigue como estaba", async () => {
    const out = await correr(500, { ok: false, error: { message: "boom", code: 500 } })
    expect(out.error).toBe("Error 500")
  })
})
