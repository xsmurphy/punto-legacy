import { z } from "zod"

import { chartSpecSchema } from "@/lib/agent/chart-spec"

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
  return {
  get_sales_summary: defineTool({
    description:
      "Resumen anual de ventas, gastos y devoluciones por mes. Usar cuando el usuario pregunte por ventas, ingresos, egresos o resultados de un año.",
    inputSchema: z.object({
      year: z.number().int().describe("Año a consultar, ej. 2025"),
    }),
    execute: async ({ year }) => {
      try {
        const res = await fetch(
          `${apiUrl}/v1/reports/summary_year?y=${year}`,
          { headers: dataHeaders }
        )
        if (!res.ok) {
          return { error: `No se pudo obtener el reporte (${res.status})` }
        }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_contacts: defineTool({
    description: "Lista contactos (clientes o proveedores) del negocio. Útil para buscar un cliente o proveedor por nombre.",
    inputSchema: z.object({
      type: z.enum(["1", "2"]).describe("1 = clientes, 2 = proveedores"),
      q: z.string().optional().describe("Búsqueda por nombre"),
      limit: z.number().int().optional().default(20),
    }),
    execute: async ({ type, q, limit }) => {
      try {
        const params = new URLSearchParams({ type, limit: String(limit ?? 20) })
        if (q) params.set("q", q)
        const res = await fetch(`${apiUrl}/v1/contacts?${params}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_items: defineTool({
    description: "Lista ítems (productos o servicios) del catálogo. Útil para buscar precios, costos o existencia de un producto.",
    inputSchema: z.object({
      q: z.string().optional().describe("Búsqueda por nombre o SKU"),
      limit: z.number().int().optional().default(20),
    }),
    execute: async ({ q, limit }) => {
      try {
        const params = new URLSearchParams({ limit: String(limit ?? 20) })
        if (q) params.set("q", q)
        const res = await fetch(`${apiUrl}/v1/items?${params}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_stock: defineTool({
    description:
      "Stock actual de un artículo por sucursal. Buscá por nombre o SKU.",
    inputSchema: z.object({
      itemQuery: z.string().min(1).describe("Nombre o SKU del ítem a buscar"),
    }),
    execute: async ({ itemQuery }) => {
      try {
        // El endpoint /v1/reports/stock no filtra server-side todavía.
        // Filtramos en este handler ANTES de devolver al LLM para no
        // mandarle el listado entero (puede ser miles de filas → muchos
        // tokens). El filtro es case-insensitive sobre name y sku.
        const res = await fetch(`${apiUrl}/v1/reports/stock`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        const raw = (json?.data ?? json) as unknown
        const rows = Array.isArray(raw)
          ? raw
          : Array.isArray((raw as { rows?: unknown[] })?.rows)
            ? (raw as { rows: unknown[] }).rows
            : []
        const q = itemQuery.toLowerCase()
        const filtered = rows.filter((r) => {
          const o = r as { name?: string; sku?: string; itemName?: string; itemSKU?: string }
          return (
            (o.name ?? o.itemName ?? "").toLowerCase().includes(q) ||
            (o.sku ?? o.itemSKU ?? "").toLowerCase().includes(q)
          )
        })
        return { matches: filtered.slice(0, 20), totalMatches: filtered.length }
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_categories: defineTool({
    description: "Lista categorías de productos del negocio.",
    inputSchema: z.object({}),
    execute: async () => {
      try {
        const res = await fetch(`${apiUrl}/v1/categories`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_brands: defineTool({
    description: "Lista marcas de productos del negocio.",
    inputSchema: z.object({}),
    execute: async () => {
      try {
        const res = await fetch(`${apiUrl}/v1/brands`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_tags: defineTool({
    description: "Lista etiquetas de productos del negocio.",
    inputSchema: z.object({}),
    execute: async () => {
      try {
        const res = await fetch(`${apiUrl}/v1/tags`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_users: defineTool({
    description: "Lista usuarios/empleados del equipo del negocio.",
    inputSchema: z.object({
      q: z.string().optional().describe("Búsqueda por nombre"),
    }),
    execute: async ({ q }) => {
      try {
        const params = new URLSearchParams()
        if (q) params.set("q", q)
        const res = await fetch(`${apiUrl}/v1/users?${params}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_drawers: defineTool({
    description: "Lista cajas/turnos del período. Útil para consultar cierres de caja.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
    }),
    execute: async ({ from, to }) => {
      try {
        const params = new URLSearchParams()
        if (from) params.set("from", from)
        if (to) params.set("to", to)
        const res = await fetch(`${apiUrl}/v1/reports/drawers?${params}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
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
      try {
        const params = new URLSearchParams({ limit: String(limit ?? 50) })
        if (from) params.set("from", from)
        if (to) params.set("to", to)
        const res = await fetch(`${apiUrl}/v1/reports/transactions?${params}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
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
      try {
        const params = new URLSearchParams({ view: "general" })
        if (from) params.set("from", from)
        if (to) params.set("to", to)
        const res = await fetch(`${apiUrl}/v1/reports/products?${params}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        const raw = (json?.data ?? json) as unknown
        const rows = Array.isArray(raw)
          ? raw
          : Array.isArray((raw as { rows?: unknown[] })?.rows)
            ? (raw as { rows: unknown[] }).rows
            : []
        return rows.slice(0, limit ?? 10)
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_outlets: defineTool({
    description: "Lista sucursales del negocio.",
    inputSchema: z.object({}),
    execute: async () => {
      try {
        const res = await fetch(`${apiUrl}/v1/outlets`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_settings: defineTool({
    description: "Configuración general del negocio (nombre, moneda, impuestos, etc.).",
    inputSchema: z.object({}),
    execute: async () => {
      try {
        const res = await fetch(`${apiUrl}/v1/settings`, { headers: { Authorization: authHeader } })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
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
      try {
        const qs = new URLSearchParams()
        if (from) qs.set("from", from)
        if (to) qs.set("to", to)
        const sep = base.includes("?") ? "&" : "?"
        const q = qs.toString()
        const res = await fetch(`${apiUrl}${base}${q ? sep + q : ""}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_finance_accounts: defineTool({
    description:
      "Cuentas de Finanzas con su SALDO actual (Efectivo, bancos, billeteras). Usar para 'cuánto tengo en el banco', 'saldo de caja', etc.",
    inputSchema: z.object({}),
    execute: async () => {
      try {
        const res = await fetch(`${apiUrl}/v1/finance/accounts`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
    },
  }),

  get_finance_summary: defineTool({
    description:
      "Resumen financiero: total en cuentas, ingresos y egresos del período. Usar para '¿cómo va mi caja?', '¿cuánto ingresó/gasté?'.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
    }),
    execute: async ({ from, to }) => {
      try {
        const qs = new URLSearchParams()
        if (from) qs.set("from", from)
        if (to) qs.set("to", to)
        const q = qs.toString()
        const res = await fetch(`${apiUrl}/v1/finance/summary${q ? "?" + q : ""}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
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
      try {
        const qs = new URLSearchParams({ limit: "100" })
        if (from) qs.set("from", from)
        if (to) qs.set("to", to)
        if (kind) qs.set("kind", kind)
        const res = await fetch(`${apiUrl}/v1/finance/movements?${qs}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
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
      try {
        const qs = new URLSearchParams({ widget: "customersSeries" })
        if (from) qs.set("from", from)
        if (to) qs.set("to", to)
        const res = await fetch(`${apiUrl}/v1/reports/dashboard?${qs}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: { rows?: unknown[] } }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
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
      try {
        const qs = new URLSearchParams()
        if (direction) qs.set("direction", direction)
        if (status) qs.set("status", status)
        const q = qs.toString()
        const res = await fetch(`${apiUrl}/v1/finance/checks${q ? "?" + q : ""}`, { headers: dataHeaders })
        if (!res.ok) return { error: `Error ${res.status}` }
        const json = (await res.json()) as { data?: unknown }
        return json?.data ?? json
      } catch (err) {
        return { error: String(err) }
      }
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
