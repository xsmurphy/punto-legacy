import { z } from "zod"

import { chartSpecSchema } from "@/lib/agent/chart-spec"
import { normalizeToolResult, withMeta } from "@/lib/agent/normalize-tool-result"
import { UNKNOWN_CURRENCY_SIGN, resolveCurrencyLabel } from "@/lib/tenant-locale"

/**
 * Catálogo de tools de LECTURA de Punto — agnóstico del transporte.
 *
 * Estas 20 definiciones vivían inline en `app/api/agent/chat/route.ts`,
 * envueltas en el helper `tool()` del AI SDK. Salieron de ahí porque van a
 * tener DOS consumidores (`context/58` D11):
 *
 *   catálogo (este archivo) ─┬─→ agente de Punto  (route.ts, AI SDK)
 *                            └─→ MCP server       (clientes externos)
 *
 * Lo que NO debe duplicarse son las DEFINICIONES: qué tools existen, qué
 * aceptan, cómo se describen y contra qué endpoint corren. Si el MCP naciera
 * con su propia lista, en seis meses `get_sales_summary` significaría dos
 * cosas distintas y el número del panel no coincidiría con el que el modelo
 * del cliente le muestra al dueño.
 *
 * NO SE IMPORTA `tool()` DEL AI SDK ACÁ — ese helper es el TRANSPORTE, no la
 * definición: acoplarlo obligaría al MCP server a depender del SDK de chat
 * para leer un catálogo. `defineTool` (abajo) hace lo único que hacía falta de
 * él —atar el tipo de `execute` al de `inputSchema`— y es identidad en runtime,
 * igual que `tool()`, así que el resultado se le pasa al AI SDK tal cual.
 *
 * SOLO LECTURA, a propósito. Las mutaciones viven aparte (`makeActionTools`,
 * confirm-tool.ts) con un flujo de dos pasos —`register_action` devuelve un
 * `confirmToken`, `execute_action` lo consume— que existe para que un humano
 * confirme en la UI antes de escribir. Un cliente MCP no tiene esa UI, así que
 * ese mecanismo no se puede exponer tal cual: es la razón TÉCNICA detrás de D5
 * de `context/58` (read-only en la primera versión), no una restricción
 * arbitraria.
 *
 * ── Las respuestas se NORMALIZAN antes de salir ─────────────────────────────
 * Hasta 2026-08-31 las 19 tools de fetch terminaban en `return json?.data ??
 * json`: passthrough crudo de un endpoint hecho para el panel, que ya conoce el
 * vocabulario interno. El modelo del otro lado no lo conoce y lo pagaba
 * adivinando (ver el caso de producción citado en `normalize-tool-result.ts`).
 * Ahora todas pasan por el helper `read` de abajo, que traduce, poda y declara
 * la moneda en un solo lugar.
 */

/** Lo que cada tool necesita para hablar con la API. Lo arma el consumidor. */
export interface ToolContext {
  /** Base de la API compartida (`API_URL`), sin barra final. */
  apiUrl: string
  /**
   * Headers para los fetches de DATOS del negocio. Llevan la credencial y,
   * cuando el consumidor tiene un view-scope elegido, el `X-Outlet-Id` — así
   * las lecturas salen de la MISMA sucursal que el resto de la superficie y no
   * la del token. Los arma el consumidor porque cada uno resuelve su credencial
   * distinto: el agente reenvía el Bearer del panel, el MCP usará la API key
   * del tenant.
   */
  dataHeaders: Record<string, string>
  /**
   * Credencial cruda (`"Bearer …"`), SIN el view-scope.
   *
   * No es un duplicado de `dataHeaders`: hay lecturas que son TENANT-LEVEL y
   * no deben scopearse por sucursal — `get_settings` trae la config del
   * negocio (nombre, moneda, impuestos), que es una sola para toda la empresa.
   * Mandarle `X-Outlet-Id` no la cambiaría, pero afirmaría un scope que ese
   * dato no tiene. La distinción ya existía en `route.ts` como comentario;
   * acá queda en el tipo, que es donde el próximo consumidor la va a ver.
   */
  authHeader: string
}

/**
 * Identidad tipada: ata `execute` al `inputSchema` de la MISMA definición.
 *
 * Es lo único que `tool()` del AI SDK aportaba acá. Sin un helper genérico, un
 * objeto literal deja el parámetro de `execute` implícitamente `any` — TS no
 * correlaciona dos propiedades hermanas por su cuenta.
 */
export function defineTool<S extends z.ZodType>(def: {
  description: string
  inputSchema: S
  execute: (input: z.infer<S>) => Promise<unknown>
}) {
  return def
}

/**
 * `render_chart` no hace fetch: es de PRESENTACIÓN y la pinta la UI del chat.
 * Un consumidor sin esa UI (el MCP) arma su set con `buildReadOnlyFetchTools`
 * en vez de mantener una segunda lista que se desincronice.
 */
export const PRESENTATION_TOOLS: readonly string[] = ["render_chart"]

export function buildReadTools({ apiUrl, dataHeaders, authHeader }: ToolContext) {
  /**
   * Moneda del tenant, resuelta UNA sola vez por instancia del catálogo (o sea,
   * por request en los dos consumidores) y solo si alguna lectura devuelve
   * montos.
   *
   * Las dos mitades de eso importan:
   *
   *  - LAZY, porque la mayoría de las llamadas no la necesita. `get_categories`
   *    o `get_users` no traen un solo monto: resolver la moneda al CONSTRUIR el
   *    catálogo le cobraría un fetch de settings a todas las lecturas para que
   *    lo aproveche una minoría. La promesa nace recién cuando el normalizador
   *    avisa que encontró campos monetarios.
   *  - MEMOIZADA, porque una conversación encadena varias tools y la moneda del
   *    negocio no cambia entre ellas. Se guarda la PROMESA y no el valor, así
   *    dos tools que corren en paralelo comparten el mismo fetch en vez de
   *    disparar dos.
   *
   * La etiqueta sale de `resolveCurrencyLabel` (`lib/tenant-locale.ts`), que ya
   * es la única fuente de esta dimensión en el proyecto: contempla que el
   * backend mande string VACÍO —`settingCurrency` es texto libre y no tiene
   * default, por decisión explícita en `SettingsService::withDefault`— y cae al
   * país del tenant antes de rendirse. Nunca inventa "Gs": eso es exactamente
   * lo que la regla de no-hardcodear-Paraguay prohíbe.
   *
   * `¤` (moneda no especificada) se traduce a `null`: para un humano ese glifo
   * significa "falta configurar esto", pero un modelo lo leería como una
   * etiqueta de moneda y escribiría "¤ 1.230.000". `null` deja que `withMeta`
   * lo diga con palabras.
   */
  let currencyPromise: Promise<string | null> | null = null
  function tenantCurrency(): Promise<string | null> {
    currencyPromise ??= (async () => {
      try {
        const res = await fetch(`${apiUrl}/v1/settings`, { headers: { Authorization: authHeader } })
        if (!res.ok) return null
        const json = (await res.json()) as { data?: unknown }
        const s = ((json?.data ?? json) ?? {}) as Record<string, unknown>
        const label = resolveCurrencyLabel({
          currency: typeof s.currency === "string" ? s.currency : null,
          country: typeof s.country === "string" ? s.country : null,
        })
        return label === UNKNOWN_CURRENCY_SIGN ? null : label
      } catch {
        // Fail-open: sin moneda las lecturas siguen sirviendo, y `withMeta`
        // aclara que los montos van sin unidad. Cortar la lectura entera porque
        // no se pudo leer una etiqueta sería peor que devolver el número.
        return null
      }
    })()
    return currencyPromise
  }

  /**
   * Fetch + desenvoltura + normalización, en un solo lugar.
   *
   * Las 19 tools repetían el mismo bloque (`try` / `!res.ok` / `json?.data ??
   * json` / `catch`). Además de ser cuatro líneas por tool, esa repetición es
   * la razón por la que el passthrough crudo se volvió el contrato de facto:
   * no había ningún punto por donde pasaran todas las respuestas. Ahora lo hay,
   * y la normalización entra una vez.
   */
  async function read(
    path: string,
    opts: {
      /** Para las lecturas TENANT-LEVEL, que no llevan view-scope. */
      headers?: Record<string, string>
      /** Recorte previo a la normalización (filtrar, cortar a `limit`). */
      transform?: (payload: unknown) => unknown
      /** Mensaje de error propio, cuando la tool tiene uno más específico. */
      errorLabel?: (status: number) => string
    } = {},
  ): Promise<unknown> {
    try {
      const res = await fetch(`${apiUrl}${path}`, { headers: opts.headers ?? dataHeaders })
      if (!res.ok) {
        return { error: opts.errorLabel ? opts.errorLabel(res.status) : `Error ${res.status}` }
      }
      const json = (await res.json()) as { data?: unknown }
      const payload = json?.data ?? json
      const normalized = normalizeToolResult(opts.transform ? opts.transform(payload) : payload)
      // El fetch de la moneda se dispara SOLO si el payload traía montos: es la
      // laziness de `tenantCurrency` vista desde acá.
      const currency = normalized.moneyFields.length > 0 ? await tenantCurrency() : null
      return withMeta(normalized, currency)
    } catch (err) {
      return { error: String(err) }
    }
  }

  /** Filas de un reporte, vengan como array pelado o dentro de `rows`. */
  function rowsOf(payload: unknown): unknown[] {
    if (Array.isArray(payload)) return payload
    const rows = (payload as { rows?: unknown })?.rows
    return Array.isArray(rows) ? rows : []
  }

  return {
  get_sales_summary: defineTool({
    description:
      "Resumen anual por mes: ventas, compras a proveedores, devoluciones y clientes nuevos. Usar cuando el usuario pregunte por ventas, ingresos, egresos o resultados de un año. Ojo: lo que devuelve como egreso son COMPRAS, no los gastos del módulo Finanzas.",
    inputSchema: z.object({
      year: z.number().int().describe("Año a consultar, ej. 2025"),
    }),
    execute: async ({ year }) =>
      read(`/v1/reports/summary_year?y=${year}`, {
        errorLabel: (s) => `No se pudo obtener el reporte (${s})`,
      }),
  }),

  get_contacts: defineTool({
    description: "Lista contactos (clientes o proveedores) del negocio. Útil para buscar un cliente o proveedor por nombre.",
    inputSchema: z.object({
      type: z.enum(["1", "2"]).describe("1 = clientes, 2 = proveedores"),
      q: z.string().optional().describe("Búsqueda por nombre"),
      limit: z.number().int().optional().default(20),
    }),
    execute: async ({ type, q, limit }) => {
      const params = new URLSearchParams({ type, limit: String(limit ?? 20) })
      if (q) params.set("q", q)
      return read(`/v1/contacts?${params}`)
    },
  }),

  get_items: defineTool({
    description: "Lista ítems (productos o servicios) del catálogo. Útil para buscar precios, costos o existencia de un producto.",
    inputSchema: z.object({
      q: z.string().optional().describe("Búsqueda por nombre o SKU"),
      limit: z.number().int().optional().default(20),
    }),
    execute: async ({ q, limit }) => {
      const params = new URLSearchParams({ limit: String(limit ?? 20) })
      if (q) params.set("q", q)
      return read(`/v1/items?${params}`)
    },
  }),

  get_stock: defineTool({
    description:
      "Stock actual de un artículo por sucursal. Buscá por nombre o SKU.",
    inputSchema: z.object({
      itemQuery: z.string().min(1).describe("Nombre o SKU del ítem a buscar"),
    }),
    execute: async ({ itemQuery }) =>
      // El endpoint /v1/reports/stock no filtra server-side todavía.
      // Filtramos ANTES de normalizar para no mandarle al LLM el listado
      // entero (puede ser miles de filas → muchos tokens). El filtro es
      // case-insensitive sobre name y sku.
      read(`/v1/reports/stock`, {
        transform: (payload) => {
          const q = itemQuery.toLowerCase()
          const filtered = rowsOf(payload).filter((r) => {
            const o = r as { name?: string; sku?: string; itemName?: string; itemSKU?: string }
            return (
              (o.name ?? o.itemName ?? "").toLowerCase().includes(q) ||
              (o.sku ?? o.itemSKU ?? "").toLowerCase().includes(q)
            )
          })
          return { matches: filtered.slice(0, 20), totalMatches: filtered.length }
        },
      }),
  }),

  get_categories: defineTool({
    description: "Lista categorías de productos del negocio.",
    inputSchema: z.object({}),
    execute: async () => read(`/v1/categories`),
  }),

  get_brands: defineTool({
    description: "Lista marcas de productos del negocio.",
    inputSchema: z.object({}),
    execute: async () => read(`/v1/brands`),
  }),

  get_tags: defineTool({
    description: "Lista etiquetas de productos del negocio.",
    inputSchema: z.object({}),
    execute: async () => read(`/v1/tags`),
  }),

  get_users: defineTool({
    description: "Lista usuarios/empleados del equipo del negocio.",
    inputSchema: z.object({
      q: z.string().optional().describe("Búsqueda por nombre"),
    }),
    execute: async ({ q }) => {
      const params = new URLSearchParams()
      if (q) params.set("q", q)
      return read(`/v1/users?${params}`)
    },
  }),

  get_drawers: defineTool({
    description: "Lista cajas/turnos del período. Útil para consultar cierres de caja.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
    }),
    execute: async ({ from, to }) => {
      const params = new URLSearchParams()
      if (from) params.set("from", from)
      if (to) params.set("to", to)
      return read(`/v1/reports/drawers?${params}`)
    },
  }),

  get_transactions: defineTool({
    description: "Lista ventas/transacciones del período. Útil para consultar movimientos.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      limit: z.number().int().optional().default(50),
    }),
    execute: async ({ from, to, limit }) => {
      const params = new URLSearchParams({ limit: String(limit ?? 50) })
      if (from) params.set("from", from)
      if (to) params.set("to", to)
      return read(`/v1/reports/transactions?${params}`)
    },
  }),

  get_top_products: defineTool({
    description: "Lista los productos más vendidos del período. Útil para responder 'top N productos vendidos'.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      limit: z.number().int().optional().default(10),
    }),
    execute: async ({ from, to, limit }) => {
      const params = new URLSearchParams({ view: "general" })
      if (from) params.set("from", from)
      if (to) params.set("to", to)
      return read(`/v1/reports/products?${params}`, {
        transform: (payload) => rowsOf(payload).slice(0, limit ?? 10),
      })
    },
  }),

  get_outlets: defineTool({
    description: "Lista sucursales del negocio.",
    inputSchema: z.object({}),
    execute: async () => read(`/v1/outlets`),
  }),

  get_settings: defineTool({
    description: "Configuración general del negocio (nombre, moneda, impuestos, etc.).",
    inputSchema: z.object({}),
    // Tenant-level: sin `X-Outlet-Id`. Ver el comentario de `authHeader`.
    execute: async () => read(`/v1/settings`, { headers: { Authorization: authHeader } }),
  }),

  get_report: defineTool({
    description:
      "Devuelve CUALQUIER reporte del negocio por su nombre. Usalo para consultas de reportes " +
      "(ventas, clientes, medios de pago, flujo de caja, compras y gastos, inventario, etc.). " +
      "Pasá el rango de fechas cuando aplique (from/to en YYYY-MM-DD).",
    inputSchema: z.object({
      report: z
        .enum([
          "ventas_resumen",
          "transacciones",
          "productos",
          "clientes",
          "categorias",
          "marcas",
          "medios_de_pago",
          "ordenes",
          "cuentas_por_cobrar",
          "cuentas_por_pagar",
          "flujo_de_caja",
          "compras_y_gastos",
          "movimientos_de_caja",
          "control_de_cajas",
          "pagos_epos",
          "inventario",
          "stock",
          "produccion",
          "calificacion_clientes",
          "staff_usuarios",
        ])
        .describe("Nombre del reporte a consultar"),
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
    }),
    execute: async ({ report, from, to }) => {
      const routes: Record<string, string> = {
        ventas_resumen: "/v1/reports/summary",
        transacciones: "/v1/reports/transactions",
        productos: "/v1/reports/products",
        clientes: "/v1/reports/customers",
        categorias: "/v1/reports/categories",
        marcas: "/v1/reports/brands",
        medios_de_pago: "/v1/reports/payment-methods",
        ordenes: "/v1/reports/orders",
        cuentas_por_cobrar: "/v1/reports/open-invoices",
        cuentas_por_pagar: "/v1/reports/open-invoices?state=outcome",
        flujo_de_caja: "/v1/reports/cashflow",
        compras_y_gastos: "/v1/reports/purchases",
        movimientos_de_caja: "/v1/reports/expenses",
        control_de_cajas: "/v1/reports/drawers",
        pagos_epos: "/v1/reports/vpayments",
        inventario: "/v1/reports/inventory",
        stock: "/v1/reports/stock",
        produccion: "/v1/reports/production",
        calificacion_clientes: "/v1/reports/satisfaction",
        staff_usuarios: "/v1/reports/users",
      }
      const base = routes[report]
      if (!base) return { error: `Reporte desconocido: ${report}` }
      const qs = new URLSearchParams()
      if (from) qs.set("from", from)
      if (to) qs.set("to", to)
      const sep = base.includes("?") ? "&" : "?"
      const q = qs.toString()
      // Ejecutor GENÉRICO: 20 reportes, cada uno con su propia forma de salida.
      // La normalización que recibe es la del diccionario por NOMBRE DE CAMPO,
      // que es justamente la que funciona sin conocer la forma: los campos que
      // comparte con el resto (`total`, `tax`, `transactionType`, `cogs`) salen
      // traducidos, y los propios de un reporte que nadie relevó viajan crudos.
      // Ver el informe: los shapes de estos 20 endpoints NO están relevados uno
      // por uno y hacerlo es un trabajo aparte.
      return read(`${base}${q ? sep + q : ""}`)
    },
  }),

  get_finance_accounts: defineTool({
    description:
      "Cuentas de Finanzas con su SALDO actual (Efectivo, bancos, billeteras). Usar para 'cuánto tengo en el banco', 'saldo de caja', etc.",
    inputSchema: z.object({}),
    execute: async () => read(`/v1/finance/accounts`),
  }),

  get_finance_summary: defineTool({
    description:
      "Resumen financiero: total en cuentas, ingresos y egresos del período. Usar para '¿cómo va mi caja?', '¿cuánto ingresó/gasté?'.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
    }),
    execute: async ({ from, to }) => {
      const qs = new URLSearchParams()
      if (from) qs.set("from", from)
      if (to) qs.set("to", to)
      const q = qs.toString()
      return read(`/v1/finance/summary${q ? "?" + q : ""}`)
    },
  }),

  get_finance_movements: defineTool({
    description:
      "Movimientos de Finanzas (entradas y salidas de dinero) del período, con cuenta y categoría. Filtros opcionales.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      kind: z.enum(["income", "expense"]).optional().describe("income = entradas, expense = salidas"),
    }),
    execute: async ({ from, to, kind }) => {
      const qs = new URLSearchParams({ limit: "100" })
      if (from) qs.set("from", from)
      if (to) qs.set("to", to)
      if (kind) qs.set("kind", kind)
      return read(`/v1/finance/movements?${qs}`)
    },
  }),

  get_customer_evolution: defineTool({
    description:
      "Serie mensual de clientes NUEVOS del negocio. Usar para graficar o describir la evolución de clientes a lo largo del tiempo ('evolución de clientes', 'crecimiento de clientes', 'clientes nuevos por mes'). Devuelve filas {bucket: 'YYYY-MM', new: N} — agregá vos si necesitás otro granularidad, esto ya viene por mes.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
    }),
    execute: async ({ from, to }) => {
      const qs = new URLSearchParams({ widget: "customersSeries" })
      if (from) qs.set("from", from)
      if (to) qs.set("to", to)
      return read(`/v1/reports/dashboard?${qs}`)
    },
  }),

  get_finance_checks: defineTool({
    description:
      "Cheques (emitidos y recibidos) con su estado. Usar para 'cheques pendientes', 'cheques por cobrar', etc.",
    inputSchema: z.object({
      direction: z.enum(["issued", "received"]).optional().describe("issued = emitidos, received = recibidos"),
      status: z
        .enum(["pending", "deposited", "cleared", "bounced", "cancelled"])
        .optional()
        .describe("Estado del cheque"),
    }),
    execute: async ({ direction, status }) => {
      const qs = new URLSearchParams()
      if (direction) qs.set("direction", direction)
      if (status) qs.set("status", status)
      const q = qs.toString()
      return read(`/v1/finance/checks${q ? "?" + q : ""}`)
    },
  }),

  render_chart: defineTool({
    description:
      "Renderiza un gráfico en el chat (line, bar, area o donut) a partir de datos que YA obtuviste con otras tools en ESTA conversación. Es una tool de PRESENTACIÓN — no hace fetch, la UI muestra exactamente la spec que le pases. Usala para evoluciones, comparaciones o distribuciones, y SIEMPRE que el usuario pida un 'gráfico' o 'dashboard'. Agregá los datos por mes/semana ANTES de llamarla (máx 60 filas, máx 4 series) — nunca mandes filas crudas. Podés llamarla varias veces seguidas para armar un mini-dashboard.",
    inputSchema: chartSpecSchema,
    execute: async (spec) => {
      return { ok: true, ...spec }
    },
  }),
  }
}

/** Solo las que hacen fetch — sin las de presentación. Para consumidores sin UI de chat. */
export function buildReadOnlyFetchTools(ctx: ToolContext) {
  return Object.fromEntries(
    Object.entries(buildReadTools(ctx)).filter(([name]) => !PRESENTATION_TOOLS.includes(name)),
  )
}
