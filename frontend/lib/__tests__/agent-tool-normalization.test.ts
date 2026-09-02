import { afterEach, describe, expect, it, vi } from "vitest"

import { buildReadTools } from "@/lib/agent/read-tools"
import { MAX_ROWS, normalizeToolResult, withMeta } from "@/lib/agent/normalize-tool-result"
import { COUNTRY_LOCALE, UNKNOWN_CURRENCY_SIGN } from "@/lib/tenant-locale"

/**
 * GUARD de la normalización de las respuestas del catálogo de lectura.
 *
 * El defecto que fija este archivo es de PRODUCCIÓN, no hipotético: con el
 * passthrough crudo, un modelo leyó `transactionComplete: 0` + `transactionType:
 * 3` y escribió que eso «SUGIERE documentos emitidos a crédito o pendientes de
 * cierre». Era un dato exacto y lo presentó como especulación. Los payloads de
 * abajo son los que devuelve la API de verdad —salieron de una sesión real vía
 * MCP—, no ejemplos inventados: si alguien afloja la normalización, estos casos
 * vuelven a fallar con el shape que el modelo realmente ve.
 */

/** Fila real de `get_top_products` (`/v1/reports/products?view=general`). */
const TOP_PRODUCT_ROW = {
  smonth: null,
  usold: 2,
  total: 240000,
  tax: 0,
  cogs: 0,
  comission: 0,
  utility: 240000,
  deleted: false,
  taxName: "10",
  itemType: "product",
  id: "019fae5e-1111-4444-8888-aaaaaaaaaaaa",
}

/** Fila real de `get_sales_summary` (`/v1/reports/summary_year`). */
const SALES_SUMMARY_ROW = {
  month: 8,
  usold: 11,
  count: 2,
  salesTotal: 1230000,
  expensesTotal: 1672494,
  returnsTotal: 0,
  nonAddingTotal: 0,
  customers: 0,
}

/** Fila real de `get_outlets` (`/v1/outlets`). */
const OUTLET_ROW = { status: 1, ecom: false, outletDate: "2026-08-28 11:19:13.098481-03" }

/**
 * Moneda de fixture. `XTS` es el código que ISO 4217 reserva para pruebas: el
 * normalizador es agnóstico de la moneda, así que usar una real solo serviría
 * para meter un literal de país en los tests — que es justo lo que prohíbe
 * `lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts`.
 */
const TEST_CURRENCY = "XTS"

/** Normaliza y devuelve el sobre ya armado con una moneda dada. */
function run(payload: unknown, currency: string | null = TEST_CURRENCY) {
  const result = normalizeToolResult(payload)
  return { result, out: withMeta(result, currency) as Record<string, unknown> }
}

describe("enums traducidos a lenguaje humano", () => {
  it("transactionType sale con la etiqueta del dominio, no con el entero", () => {
    const { out } = run([{ transactionId: "t1", transactionType: 3, total: 100 }])
    expect((out.data as Record<string, unknown>[])[0].transactionType).toBe("Crédito")
  })

  it("un transactionType que el espejo TS no conoce viaja CRUDO", () => {
    // Inventar una etiqueta para un tipo desconocido sería exactamente el
    // error que este archivo existe para evitar. El número es la verdad.
    const { out } = run([{ transactionId: "t1", transactionType: 99, total: 1 }])
    expect((out.data as Record<string, unknown>[])[0].transactionType).toBe(99)
  })

  it("transactionComplete deja de ser 0/1 y pasa a un booleano que se explica", () => {
    const { result, out } = run([
      { transactionId: "t1", transactionType: 3, transactionComplete: 0, total: 500000 },
    ])
    const row = (out.data as Record<string, unknown>[])[0]
    expect(row.transactionComplete).toBeUndefined()
    expect(row.settled).toBe(false)
    // Y el sobre dice qué significa: es el dato exacto que el modelo trató
    // como especulación.
    expect(result.notes.join(" ")).toContain("saldado")
  })

  it("acepta el booleano en las tres formas en que lo serializa el backend", () => {
    for (const raw of [0, "0", false, "f"]) {
      const { out } = run([{ transactionId: "t", transactionComplete: raw, total: 1 }])
      expect((out.data as Record<string, unknown>[])[0].settled, `crudo: ${String(raw)}`).toBe(false)
    }
    for (const raw of [1, "1", true, "t"]) {
      const { out } = run([{ transactionId: "t", transactionComplete: raw, total: 1 }])
      expect((out.data as Record<string, unknown>[])[0].settled, `crudo: ${String(raw)}`).toBe(true)
    }
  })

  it("status numérico 1/0 se vuelve `active`, y el de texto no se toca", () => {
    const { out } = run([OUTLET_ROW], null)
    const row = (out as unknown as Record<string, unknown>[])[0]
    expect(row.active).toBe(true)
    expect(row.status).toBeUndefined()

    // Los estados de cheque ya son texto y se explican solos.
    const { out: checks } = run([{ direction: "issued", status: "bounced" }], null)
    expect((checks as unknown as Record<string, unknown>[])[0].status).toBe("bounced")
  })

  it("itemType 'product' NO se traduce como 'producto': el backend guarda ahí los servicios", () => {
    // `ItemKind::MAP` mete `servicio` y `servicio_sesiones` en itemType
    // 'product'. Decir "producto" haría que el modelo llame producto a un
    // servicio, con total seguridad.
    const { out } = run([{ ...TOP_PRODUCT_ROW }])
    expect((out.data as Record<string, unknown>[])[0].itemType).toBe("producto o servicio")
  })

  it("transactionStatus queda CRUDO — el comentario del schema está desactualizado", () => {
    const { out } = run([{ transactionId: "t", transactionStatus: 4, total: 1 }])
    expect((out.data as Record<string, unknown>[])[0].transactionStatus).toBe(4)
  })
})

describe("colisiones de nombre entre reportes", () => {
  it("`cogs` es costo unitario promedio en stock y costo de lo vendido en productos", () => {
    const { out: stock } = run([{ itemId: "i1", name: "Coca", onHand: 12, cogs: 8000 }])
    const stockRow = (stock.data as Record<string, unknown>[])[0]
    expect(stockRow.averageUnitCost).toBe(8000)
    expect(stockRow.costOfGoodsSold).toBeUndefined()

    const { out: products } = run([{ id: "i1", usold: 3, total: 30000, cogs: 12000 }])
    const productRow = (products.data as Record<string, unknown>[])[0]
    expect(productRow.costOfGoodsSold).toBe(12000)
    expect(productRow.averageUnitCost).toBeUndefined()
  })

  it("`count` son ventas en el resumen y unidades en un depósito", () => {
    const { out: summary } = run([SALES_SUMMARY_ROW])
    expect((summary.data as Record<string, unknown>[])[0].salesCount).toBe(2)

    // La fila de depósito trae `min`, cuya regla aporta una nota, así que esta
    // respuesta SÍ lleva sobre: el mínimo es del ítem y no del depósito, y eso
    // no cabe en un nombre de campo.
    const { result, out: depot } = run(
      [{ locationId: "d1", locationName: "Depósito", min: 5, count: 40 }],
      null,
    )
    const depotRow = (depot.data as Record<string, unknown>[])[0]
    expect(depotRow.onHand).toBe(40)
    expect(depotRow.salesCount).toBeUndefined()
    expect(depotRow.itemMinStock).toBe(5)
    expect(result.notes.join(" ")).toContain("no existe un mínimo por depósito")
  })

  it("`type` es tipo de transacción en cobros y rol del contacto en el listado", () => {
    const { out: cobro } = run([{ transactionId: "t1", type: 5, total: 100 }])
    expect((cobro.data as Record<string, unknown>[])[0].type).toBe("Recibo")

    const { out: contacto } = run([{ id: "c1", UID: "c1", name: "Ana", type: 2 }], null)
    expect((contacto as unknown as Record<string, unknown>[])[0].type).toBe("proveedor")
  })

  it("`type` sin marca de fila reconocible viaja crudo", () => {
    const { out } = run([{ foo: "bar", type: 2 }], null)
    expect((out as unknown as Record<string, unknown>[])[0].type).toBe(2)
  })

  it("`month` numérico se renombra, pero el flag booleano del reporte no", () => {
    const { out } = run({ month: true, rows: [SALES_SUMMARY_ROW] })
    expect((out.data as Record<string, unknown>).month).toBe(true)
    const row = ((out.data as Record<string, unknown>).rows as Record<string, unknown>[])[0]
    expect(row.monthNumber).toBe(8)
    expect(row.month).toBeUndefined()
  })
})

describe("poda", () => {
  it("la fila real de get_top_products pierde el ruido y conserva lo accionable", () => {
    const { out } = run([TOP_PRODUCT_ROW])
    const row = (out.data as Record<string, unknown>[])[0]

    // Ruido que se va.
    expect(row.smonth).toBeUndefined() // null
    expect(row.cogs).toBeUndefined() // 0 = sin costeo cargado
    expect(row.costOfGoodsSold).toBeUndefined()
    expect(row.comission).toBeUndefined() // 0
    expect(row.salesCommission).toBeUndefined()
    expect(row.deleted).toBeUndefined() // false

    // Lo que sirve, traducido.
    expect(row.unitsSold).toBe(2)
    expect(row.total).toBe(240000)
    expect(row.tax).toBe(0) // 0 es un DATO en un impuesto, no ausencia
    expect(row.taxName).toBe("10")
    expect(row.id).toBe(TOP_PRODUCT_ROW.id)
  })

  it("sin costo cargado el margen NO viaja, y el sobre explica la ausencia", () => {
    // El crudo traía `utility: 240000` con `cogs: 0`: leído tal cual, un margen
    // del 100% que no existe.
    const { result, out } = run([TOP_PRODUCT_ROW])
    const row = (out.data as Record<string, unknown>[])[0]
    expect(row.utility).toBeUndefined()
    expect(row.grossProfit).toBeUndefined()
    expect(result.notes.join(" ")).toContain("sin costo")
  })

  it("con costo cargado el margen sí viaja, con la advertencia del IVA", () => {
    const { result, out } = run([{ id: "i1", usold: 1, total: 100, cogs: 60, utility: 40 }])
    const row = (out.data as Record<string, unknown>[])[0]
    expect(row.grossProfit).toBe(40)
    expect(result.notes.join(" ")).toContain("IVA")
  })

  it("los IDs que el agente necesita para ESCRIBIR se conservan", () => {
    // `update_contact` y `update_item_price` reciben el `id` del registro
    // (`lib/agent/confirm-tool.ts`), y el modelo lo saca de estas mismas
    // lecturas. Podarlos dejaría al agente del panel sin forma de editar nada.
    const { out: item } = run([{ id: "item-1", name: "Coca", price: 5000 }])
    expect((item.data as Record<string, unknown>[])[0].id).toBe("item-1")

    const { out: contact } = run([{ id: "c-1", UID: "c-1", name: "Ana", type: 1 }], null)
    expect((contact as unknown as Record<string, unknown>[])[0].id).toBe("c-1")
  })

  it("poda el id que la fila ya resolvió a nombre, y el UID duplicado de id", () => {
    const { out } = run([
      {
        transactionId: "t1",
        total: 1000,
        outletId: "o1",
        outletName: "Centro",
        userId: "u1",
        userName: "Ana",
        customerId: "c1",
        customerName: "Cliente",
        registerId: "r1",
        registerName: "Caja 1",
        companyId: "comp-1",
      },
    ])
    const row = (out.data as Record<string, unknown>[])[0]
    for (const gone of ["outletId", "userId", "customerId", "registerId", "companyId"]) {
      expect(row[gone], `${gone} debería estar podado`).toBeUndefined()
    }
    expect(row.outletName).toBe("Centro")
    expect(row.transactionId).toBe("t1")

    const { out: contact } = run([{ id: "c1", UID: "c1", name: "Ana" }], null)
    expect((contact as unknown as Record<string, unknown>[])[0].UID).toBeUndefined()
  })

  it("conserva el id cuando la fila NO trae el nombre correspondiente", () => {
    const { out } = run([{ transactionId: "t1", outletId: "o1", total: 1 }])
    expect((out.data as Record<string, unknown>[])[0].outletId).toBe("o1")
  })

  it("`total` y `netTotal` duplicados: viaja uno solo; si difieren, los dos", () => {
    const { out: igual } = run([{ transactionId: "t", total: 900, netTotal: 900 }])
    expect((igual.data as Record<string, unknown>[])[0].netTotal).toBeUndefined()

    const { out: distinto } = run([{ transactionId: "t", total: 900, netTotal: 800 }])
    expect((distinto.data as Record<string, unknown>[])[0].netTotal).toBe(800)
  })

  it("0 y false NO son ausencia: los decide el diccionario, no una poda ciega", () => {
    const { out } = run([SALES_SUMMARY_ROW])
    const row = (out.data as Record<string, unknown>[])[0]
    expect(row.returnsTotal).toBe(0)
    expect(row.nonRevenueSalesTotal).toBe(0)
    expect(row.newCustomers).toBe(0)
  })
})

describe("moneda", () => {
  it("declara la moneda una sola vez en meta, no pegada a cada valor", () => {
    const { out } = run([SALES_SUMMARY_ROW], TEST_CURRENCY)
    const meta = out.meta as Record<string, unknown>
    expect(meta.currency).toBe(TEST_CURRENCY)
    expect(meta.amountFields).toContain("salesTotalBeforeDiscount")
    // Los montos siguen siendo NÚMEROS: un "Gs 1.230.000" no se puede sumar.
    expect((out.data as Record<string, unknown>[])[0].salesTotalBeforeDiscount).toBe(1230000)
  })

  it("sin moneda resoluble lo dice con palabras, y NUNCA inventa una", () => {
    const { out } = run([SALES_SUMMARY_ROW], null)
    const meta = out.meta as Record<string, unknown>
    expect(meta.currency).toBeNull()
    expect(String(meta.currencyNote)).toContain("sin unidad")
    // Ni siquiera el `¤` de "moneda no especificada": para un humano ese glifo
    // dice "falta configurar esto", pero un modelo lo leería como etiqueta y
    // escribiría "¤ 1.230.000".
    expect(JSON.stringify(out)).not.toContain(UNKNOWN_CURRENCY_SIGN)
    // Que tampoco aparezca una moneda inventada NO se chequea acá con literales:
    // lo cubre `lib/tenant-locale/__tests__/no-hardcoded-paraguay.test.ts`, que
    // escanea todo `lib/` y es el guard canónico del proyecto.
  })

  it("una respuesta sin montos no lleva sobre: no hay nada que declarar", () => {
    const { result, out } = run([{ id: "b1", name: "Coca Cola" }])
    expect(result.moneyFields).toEqual([])
    expect(Array.isArray(out)).toBe(true)
  })
})

describe("tope de tamaño de respuesta", () => {
  it("recorta a MAX_ROWS y AVISA que el total es parcial", () => {
    const rows = Array.from({ length: 500 }, (_, i) => ({ transactionId: `t${i}`, total: 10 }))
    const { out } = run(rows)
    expect((out.data as unknown[]).length).toBe(MAX_ROWS)
    expect(out.meta).toMatchObject({ truncated: { returned: MAX_ROWS, total: 500 } })
    expect(String((out.meta as Record<string, unknown>).truncatedNote)).toContain("parcial")
  })

  it("recorta también cuando las filas vienen dentro de `rows`", () => {
    const rows = Array.from({ length: 300 }, (_, i) => ({ id: `i${i}` }))
    const { out } = run({ needsOutlet: false, rows })
    expect(((out.data as Record<string, unknown>).rows as unknown[]).length).toBe(MAX_ROWS)
  })

  it("no toca las listas anidadas cortas, que son parte del dato", () => {
    const { out } = run([
      { itemId: "i1", onHand: 10, depots: [{ locationId: "d1", locationName: "A", count: 10 }] },
    ], null)
    expect(((out as unknown as Record<string, unknown>[])[0].depots as unknown[]).length).toBe(1)
  })
})

describe("errores", () => {
  it("un error de tool pasa intacto y sin sobre", () => {
    const { out } = run({ error: "Error 403" })
    expect(out).toEqual({ error: "Error 403" })
  })
})

// ── Integración con el catálogo ──────────────────────────────────────────────

describe("resolución de moneda desde el catálogo", () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  /** Mock de fetch que registra las URLs y responde por ruta. */
  function stubFetch(byPath: Record<string, unknown>) {
    const calls: string[] = []
    vi.stubGlobal(
      "fetch",
      vi.fn(async (url: string | URL) => {
        const u = String(url)
        calls.push(u)
        const key = Object.keys(byPath).find((k) => u.includes(k))
        return new Response(JSON.stringify({ data: key ? byPath[key] : null }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        })
      }),
    )
    return calls
  }

  const ctx = {
    apiUrl: "https://api.example.test",
    dataHeaders: { Authorization: "Bearer x", "X-Outlet-Id": "outlet-1" },
    authHeader: "Bearer x",
  }

  it("NO pide settings cuando la lectura no trae montos", async () => {
    const calls = stubFetch({ "/v1/brands": [{ id: "b1", name: "Coca" }] })
    const tools = buildReadTools(ctx)
    await tools.get_brands.execute({})
    expect(calls.some((u) => u.includes("/v1/settings"))).toBe(false)
  })

  it("pide settings UNA sola vez aunque varias lecturas traigan montos", async () => {
    const calls = stubFetch({
      "/v1/settings": { currency: TEST_CURRENCY, country: "" },
      "/v1/reports/summary_year": [SALES_SUMMARY_ROW],
    })
    const tools = buildReadTools(ctx)
    const a = (await tools.get_sales_summary.execute({ year: 2026 })) as Record<string, unknown>
    const b = (await tools.get_sales_summary.execute({ year: 2025 })) as Record<string, unknown>

    expect(calls.filter((u) => u.includes("/v1/settings")).length).toBe(1)
    expect((a.meta as Record<string, unknown>).currency).toBe(TEST_CURRENCY)
    expect((b.meta as Record<string, unknown>).currency).toBe(TEST_CURRENCY)
  })

  it("con `currency` vacío cae al país del tenant en vez de quedar sin etiqueta", async () => {
    // El backend manda string VACÍO cuando el comercio no configuró moneda
    // (`settingCurrency` es texto libre sin default), así que `?? "Gs"` no
    // alcanzaba — y "Gs" además sería inventar Paraguay.
    stubFetch({
      "/v1/settings": { currency: "", country: "BR" },
      "/v1/reports/summary_year": [SALES_SUMMARY_ROW],
    })
    const tools = buildReadTools(ctx)
    const out = (await tools.get_sales_summary.execute({ year: 2026 })) as Record<string, unknown>
    // La etiqueta se compara contra la tabla de países, no contra un símbolo
    // escrito acá: si mañana cambia el default de BR, este test sigue diciendo
    // la verdad en vez de fijar una copia.
    expect((out.meta as Record<string, unknown>).currency).toBe(COUNTRY_LOCALE.BR.currency)
  })

  it("sin moneda NI país, la respuesta no afirma ninguna moneda", async () => {
    stubFetch({
      "/v1/settings": { currency: "", country: "" },
      "/v1/reports/summary_year": [SALES_SUMMARY_ROW],
    })
    const tools = buildReadTools(ctx)
    const out = (await tools.get_sales_summary.execute({ year: 2026 })) as Record<string, unknown>
    expect((out.meta as Record<string, unknown>).currency).toBeNull()
  })

  it("si settings falla, la lectura igual responde con los montos", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async (url: string | URL) => {
        if (String(url).includes("/v1/settings")) return new Response("nope", { status: 500 })
        return new Response(JSON.stringify({ data: [SALES_SUMMARY_ROW] }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        })
      }),
    )
    const tools = buildReadTools(ctx)
    const out = (await tools.get_sales_summary.execute({ year: 2026 })) as Record<string, unknown>
    expect((out.meta as Record<string, unknown>).currency).toBeNull()
    expect((out.data as Record<string, unknown>[])[0].salesTotalBeforeDiscount).toBe(1230000)
  })

  it("un 403 del endpoint devuelve el error y no dispara el fetch de moneda", async () => {
    const calls: string[] = []
    vi.stubGlobal(
      "fetch",
      vi.fn(async (url: string | URL) => {
        calls.push(String(url))
        return new Response("denied", { status: 403 })
      }),
    )
    const tools = buildReadTools(ctx)
    // `limit` es obligatorio en el schema de la tool: el `{}` pelado tipaba mal
    // y rompía `tsc` (y con él el build del Front). El caso que se prueba es el
    // 403, así que el valor no importa mientras sea válido.
    const out = (await tools.get_transactions.execute({ limit: 1 })) as Record<
      string,
      unknown
    >
    // El texto ya no dice "403": desde el 2026-09-02 el catálogo traduce ese
    // status a la restricción de permisos que es, porque el número pelado hacía
    // que el modelo lo contara como una caída del sistema o lo reintentara. Lo
    // que este caso cuida sigue siendo lo de la línea de abajo — que una lectura
    // fallida NO gaste una segunda llamada resolviendo la moneda del tenant.
    expect(String(out.error)).toMatch(/permiso/i)
    expect(calls.some((u) => u.includes("/v1/settings"))).toBe(false)
  })
})
