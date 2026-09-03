import { z } from "zod"

import { chartSpecSchema } from "@/lib/agent/chart-spec"
import {
  normalizeToolResult,
  withMeta,
  type NormalizedResult,
} from "@/lib/agent/normalize-tool-result"
import {
  BASELINE_FETCH_FAILED,
  NEEDS_EXPLICIT_RANGE,
  TRUNCATED_SAMPLE,
  buildComparison,
  comparisonRange,
  comparisonUnavailable,
  type CompareWith,
  type ComparisonMode,
  type DateRange,
} from "@/lib/agent/period-comparison"
import { UNKNOWN_CURRENCY_SIGN, resolveCurrencyLabel } from "@/lib/tenant-locale"

/**
 * Catálogo de tools de LECTURA de Punto — agnóstico del transporte.
 *
 * Estas definiciones vivían inline en `app/api/agent/chat/route.ts` (eran 20 al
 * momento de la extracción),
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
 *
 * ── Y COMPARAN, cuando se lo piden ──────────────────────────────────────────
 * Hasta 2026-08-31 ninguna tool devolvía una comparativa, y sin "contra qué" el
 * modelo solo podía describir el período pedido: «vendiste 47.500.000 en
 * agosto» no es análisis, es el dato que el dueño ya tenía. El parámetro
 * `compareWith` (y `compareYear` en `get_sales_summary`) dispara una segunda
 * lectura y devuelve un bloque `comparison` con los dos períodos nombrados por
 * sus fechas y el delta ya calculado. La aritmética vive en
 * `period-comparison.ts`; acá solo se decide qué tool la ofrece y contra qué
 * ruta corre la segunda lectura.
 *
 * Es OPCIONAL y sin default a propósito: duplica los fetch, así que una tool que
 * comparara siempre le cobraría el doble a las preguntas que no lo necesitan.
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

// ── Catálogo de reportes de `get_report` ─────────────────────────────────────

export interface ReportRoute {
  /** Ruta bajo la API, con su query fija si la tiene. */
  path: string
  /**
   * Si el endpoint filtra por `from`/`to`. Los tres que NO lo hacen son FOTOS
   * del estado actual (stock, cuentas por cobrar y por pagar): mandarles un
   * rango no cambia lo que devuelven, así que tampoco tiene sentido compararlos
   * entre períodos — un delta sobre un dato que ignora las fechas daría siempre
   * cero y el modelo lo leería como "no cambió nada".
   */
  ranged: boolean
  /**
   * Si el endpoint acepta la FRANJA HORARIA (`hourFrom`/`hourTo`, F1 de
   * `context/67`). Es un subconjunto ESTRICTO de `ranged`, no un sinónimo: son
   * los cinco que la F1 cableó, y el resto la ignora o la rechaza.
   *
   * Esta bandera existe para que el catálogo no mande el parámetro a un
   * endpoint que no lo mira. Un filtro ignorado en silencio es el peor
   * resultado posible acá: el modelo pidió "de 7 a 12", recibe 200 con los
   * totales del día ENTERO, y se los atribuye a la franja. Un reporte plausible
   * y falso — el mismo modo de falla por el que la F1 dejó afuera `drawers`.
   */
  hourly?: boolean
  /**
   * Si el reporte NO filtra por sucursal y por lo tanto rechaza `outletId`.
   *
   * Va en NEGATIVO —una lista de excepciones— y no como un `outletScoped: true`
   * en las otras diecinueve: el default correcto es que un reporte del negocio
   * se lea por sucursal, así que escribir la bandera positiva veinte veces hace
   * que el reporte veintiuno nazca sin ella y quede rechazando un filtro que sí
   * aplica. Acá el que se olvide de tocar esta tabla hereda el comportamiento
   * bueno.
   *
   * Mismo motivo que `hourly`: sin esto, `outletId` sobre un reporte que lo
   * ignora devuelve 200 con los datos de TODAS las sucursales y el modelo se los
   * atribuye a la que pidió.
   */
  tenantWide?: boolean
}

/**
 * Los 20 reportes que expone `get_report`, con su endpoint real.
 *
 * ── Esta tabla es la ÚNICA fuente: el enum del schema sale de sus claves ────
 * Antes vivía dentro de `execute` y el enum de zod era una segunda lista escrita
 * a mano. Dos listas que tienen que coincidir siempre terminan coincidiendo
 * menos, y acá el costo lo pagaba el modelo: podía elegir un id del enum que la
 * tabla no tuviera y recibir "Reporte desconocido".
 *
 * ── Tres rutas estaban ROTAS y devolvían 404 en producción ──────────────────
 * El router (`api/router.php:32-40`) mapea `/v1/<path>` a un archivo del árbol
 * sin rewrites ni alias: si el `.php` no existe, es 404 y no hay red de
 * contención. Estas tres apuntaban a archivos inexistentes (verificado contra
 * prod vía MCP, `{"error":"Error 404"}`):
 *
 *   ventas_resumen      → `/v1/reports/summary`       (no existe; es `sales?dataset=summary`)
 *   cuentas_por_cobrar  → `/v1/reports/open-invoices` (no existe; el archivo va con guión BAJO)
 *   cuentas_por_pagar   → idem, con `state=outcome`
 *
 * `ventas_resumen` era el peor de los tres: es el reporte que el modelo elige
 * para casi cualquier pregunta sobre ventas, y recibía un error donde esperaba
 * el resumen. `agent-report-routes.test.ts` recorre esta tabla contra el
 * filesystem para que el cuarto no entre en silencio.
 */
export const REPORT_ROUTES = {
  // `sales.php` despacha por `dataset`; sin él ya default'ea a summary, pero se
  // manda explícito para que la ruta diga qué pide.
  ventas_resumen: { path: "/v1/reports/sales?dataset=summary", ranged: true, hourly: true },
  transacciones: { path: "/v1/reports/transactions", ranged: true, hourly: true },
  productos: { path: "/v1/reports/products", ranged: true, hourly: true },
  clientes: { path: "/v1/reports/customers", ranged: true },
  categorias: { path: "/v1/reports/categories", ranged: true },
  marcas: { path: "/v1/reports/brands", ranged: true },
  medios_de_pago: { path: "/v1/reports/payment-methods", ranged: true },
  ordenes: { path: "/v1/reports/orders", ranged: true, hourly: true },
  cuentas_por_cobrar: { path: "/v1/reports/open_invoices", ranged: false },
  cuentas_por_pagar: { path: "/v1/reports/open_invoices?state=outcome", ranged: false },
  flujo_de_caja: { path: "/v1/reports/cashflow", ranged: true },
  compras_y_gastos: { path: "/v1/reports/purchases", ranged: true },
  movimientos_de_caja: { path: "/v1/reports/expenses", ranged: true, hourly: true },
  control_de_cajas: { path: "/v1/reports/drawers", ranged: true },
  // `pagos_epos` (`/v1/reports/vpayments`) NO se expone: el módulo ePOS está
  // muerto y su pantalla ya salió del panel. Un reporte muerto en el catálogo
  // no es neutro — el modelo lo lee como una opción válida y lo elige, para
  // recibir un error o una lista vacía que después interpreta como "no hubo
  // pagos". El endpoint sigue existiendo para el panel; lo que se saca es la
  // oferta al agente.
  inventario: { path: "/v1/reports/inventory", ranged: true },
  stock: { path: "/v1/reports/stock", ranged: false },
  produccion: { path: "/v1/reports/production", ranged: true },
  calificacion_clientes: { path: "/v1/reports/satisfaction", ranged: true },
  // El único que NO filtra por sucursal: `api/v1/reports/users.php` no tiene
  // una sola referencia a outlet (ni `Roc::build` ni `OUTLET_ID`). Es una
  // planilla de desempeño del EQUIPO del tenant.
  staff_usuarios: { path: "/v1/reports/users", ranged: true, tenantWide: true },
} as const satisfies Record<string, ReportRoute>

export type ReportId = keyof typeof REPORT_ROUTES

/** El enum del schema NO se escribe a mano: sale de la tabla de arriba. */
const REPORT_IDS = Object.keys(REPORT_ROUTES) as [ReportId, ...ReportId[]]

// ── `compareWith`, el parámetro compartido ───────────────────────────────────

/**
 * Un solo fragmento de schema para todas las tools que comparan.
 *
 * La descripción es la UI de esta feature: el modelo decide si pedir la
 * comparación —y cuál de las dos— leyendo esto y nada más. Por eso dice qué
 * ventana es cada modo CON UN EJEMPLO (los nombres solos se confunden: para
 * "agosto contra agosto del año pasado" la respuesta es `previous_year`, y un
 * modelo que lea `previous_period` como "el mismo período de antes" elige mal),
 * y por eso dice que cuesta una segunda lectura.
 */
const compareWithSchema = z
  .enum(["previous_period", "previous_year"])
  .optional()
  .describe(
    "Opcional. Compara el período pedido contra otro y agrega un bloque `comparison` con el cambio absoluto y el porcentual YA calculados — no hace falta que restes nada. " +
      "previous_period = el mismo rango corrido hacia atrás su propia duración (1 al 31 de agosto se compara contra el 1 al 31 de julio). " +
      "previous_year = el MISMO rango calendario del año anterior (agosto 2026 contra agosto 2025); es el que corresponde cuando preguntan 'contra el año pasado'. " +
      "Requiere from y to explícitos. Cuesta una segunda lectura: pedilo cuando la pregunta sea si subió o bajó, no por costumbre.",
  )

// ── `hourFrom`/`hourTo`, la franja horaria ───────────────────────────────────

/**
 * La franja horaria (F3 de `context/67`) es la dimensión que `from`/`to` no
 * puede expresar, y es probablemente el mejor consumidor de esta feature: deja
 * preguntar "¿cómo me fue en el turno mañana este mes?" en lenguaje natural.
 *
 * Lo que el modelo TIENE que entender leyendo estas descripciones —porque es el
 * error natural— es que NO es lo mismo que poner horas en el rango. Un rango es
 * un intervalo CONTINUO: `from=2026-09-01 07:00` / `to=2026-09-30 11:59` incluye
 * las 19 horas de cada noche del medio. La franja se repite en CADA día del
 * rango. Por eso las dos descripciones dicen el ejemplo y no solo la definición.
 */

/** El mismo regex que `Date::HOUR_BOUND_RE` (`api/lib/App/Helpers/Date.php`). */
const HOUR_BOUND_RE = /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/

const hourFromSchema = z
  .string()
  .optional()
  .describe(
    "Opcional. Hora de INICIO de la franja horaria, formato HH:MM en 24 horas (ej. 07:00). " +
      "Filtra la misma franja de CADA día del rango: hourFrom=07:00 y hourTo=11:59 con from=2026-09-01 y to=2026-09-30 devuelve solo lo ocurrido de 07:00 a 11:59 de cada uno de esos 30 días. " +
      "NO es lo mismo que poner la hora en from/to: eso sería un intervalo continuo e incluiría las noches del medio. " +
      "Requiere from y to explícitos. Si la dejás sin mandar, la franja arranca a las 00:00.",
  )

const hourToSchema = z
  .string()
  .optional()
  .describe(
    "Opcional. Hora de FIN de la franja horaria, formato HH:MM en 24 horas (ej. 11:59), inclusive. " +
      "Puede ser MENOR que hourFrom: 20:00 a 04:00 es una franja válida y significa la noche que cruza la medianoche (un bar), no un error. " +
      "Requiere from y to explícitos. Si la dejás sin mandar, la franja llega hasta el final del día.",
  )

// ── `outletId`, la sucursal de la consulta ───────────────────────────────────

/**
 * Elegir UNA sucursal dentro de las que el usuario alcanza.
 *
 * El default —no mandarlo— es el CONSOLIDADO de las sucursales asignadas al
 * usuario dueño de la credencial, resuelto en el backend
 * (`api/bootstrap.php`, realm `api`) contra `contact_outlet`. La credencial no
 * pinta una sucursal: el alcance se define en el usuario.
 *
 * Va SOLO en las tools cuyo endpoint realmente filtra por sucursal. Ofrecerlo en
 * las demás sería el mismo modo de falla que documenta `ReportRoute.hourly`: el
 * modelo pide "la sucursal Centro", recibe 200 con el total del tenant y se lo
 * atribuye a Centro. Un dato plausible y falso es peor que no ofrecer el filtro.
 *
 * Una sucursal fuera del alcance del usuario devuelve 403, NUNCA una lista
 * vacía — un vacío se leería como "no hubo ventas ahí", que es una mentira con
 * forma de dato.
 */
const outletIdSchema = z
  .string()
  .optional()
  .describe(
    "Opcional. UUID de UNA sucursal, de las que devuelve get_outlets. " +
      "Si no lo mandás, el resultado es el CONSOLIDADO de todas las sucursales que este usuario tiene asignadas — que es lo que se quiere casi siempre. " +
      "Mandalo solo cuando la pregunta sea explícitamente sobre una sucursal en particular. " +
      "Una sucursal que el usuario no tenga asignada devuelve error 403, no un resultado vacío.",
  )

/** La sucursal sobre una ruta ya armada. Sin sucursal no la toca. */
function withOutlet(path: string, outletId?: string): string {
  if (!outletId) return path
  return `${path}${path.includes("?") ? "&" : "?"}outletId=${encodeURIComponent(outletId)}`
}

interface HourBand {
  hourFrom?: string
  hourTo?: string
}

/**
 * Todo lo que puede impedir que la franja viaje — resuelto ANTES del fetch.
 *
 * Devuelve el texto que va a leer el modelo, o `null` si la franja es
 * aplicable. Es una función y no un `.refine()` del schema a propósito: un
 * refine fallado no llega al modelo como un dato de la conversación, sale por
 * el canal de error del transporte (en el agente lo agarra el `onError` de
 * `toUIMessageStreamResponse` y el usuario ve "error al conectar con el
 * asistente"). Devolver `{ error }` desde `execute` es el mismo patrón que ya
 * usa `get_report` con un reporte desconocido, y es el único que deja al modelo
 * repreguntar en vez de cortar el turno.
 *
 * El caso que importa es el segundo: sin `from`/`to` los endpoints default'ean
 * el rango del lado del servidor (los últimos 7 días), así que una franja sin
 * rango explícito devolvería la mañana de una ventana que nadie eligió. Y del
 * lado del backend está MEDIDO que además rompe el plan de la query — sin rango,
 * el predicado de franja tira abajo la salida temprana del `LIMIT` (3,7 → 109 ms
 * sobre 400k filas, tabla en `context/67`), que es la razón por la que los
 * endpoints rechazan esa combinación con 422 en vez de servirla lenta.
 */
function hourBandProblem(band: HourBand & { from?: string; to?: string }): string | null {
  const { hourFrom, hourTo, from, to } = band
  if (!hourFrom && !hourTo) return null

  for (const [name, value] of [
    ["hourFrom", hourFrom],
    ["hourTo", hourTo],
  ] as const) {
    if (value && !HOUR_BOUND_RE.test(value)) {
      return `${name}="${value}" no es una hora válida. Se espera HH:MM en 24 horas, entre 00:00 y 23:59 (por ejemplo 07:00 o 20:30).`
    }
  }

  if (!from || !to) {
    return (
      "La franja horaria (hourFrom/hourTo) solo se puede pedir junto a un rango de fechas explícito: " +
      "mandá también from y to en YYYY-MM-DD. Sin rango el reporte usaría una ventana por default y la franja se aplicaría sobre días que el usuario no eligió. " +
      "Preguntale qué período quiere y volvé a llamar con from, to y la franja."
    )
  }

  return null
}

/** La franja como sufijo de query. Vacío cuando no la pidieron. */
function hoursSuffix(band: HourBand): string {
  let qs = ""
  if (band.hourFrom) qs += `&hourFrom=${encodeURIComponent(band.hourFrom)}`
  if (band.hourTo) qs += `&hourTo=${encodeURIComponent(band.hourTo)}`
  return qs
}

/** La franja sobre unos `URLSearchParams` ya armados. Sin franja no los toca. */
function setHours(params: URLSearchParams, band: HourBand): URLSearchParams {
  if (band.hourFrom) params.set("hourFrom", band.hourFrom)
  if (band.hourTo) params.set("hourTo", band.hourTo)
  return params
}

/** Un año como rango, para que `comparison` diga fechas y no solo un número. */
function yearRange(year: number): DateRange {
  return { from: `${year}-01-01`, to: `${year}-12-31` }
}

interface ComparePlanOk {
  ok: true
  mode: ComparisonMode
  current: DateRange
  baseline: DateRange
  /** Ruta de la segunda lectura, ya con el rango de comparación adentro. */
  path: string
  /** De dónde salen las métricas dentro del payload normalizado. */
  metricsFrom?: (value: unknown) => unknown
}

type ComparePlan = ComparePlanOk | { ok: false; block: unknown }

/**
 * Traduce `compareWith` + el rango pedido en la segunda lectura a disparar.
 *
 * Cuando no se puede comparar devuelve un plan `ok: false` con el bloque que va
 * a viajar en la respuesta — NO `undefined`. La diferencia importa: el modelo
 * pidió una comparación, y si el bloque simplemente no apareciera, respondería
 * igual inventándose el "contra qué". Un `comparison.unavailable` con el motivo
 * lo obliga a decir que no lo tiene.
 *
 * Exige `from` y `to` explícitos porque los endpoints default'ean el rango del
 * lado del servidor (los últimos 7 días, el mes en curso, según cuál): sin las
 * dos fechas no sabemos qué ventana devolvió la primera lectura, y comparar
 * contra una ventana adivinada es peor que no comparar.
 */
function planComparison(args: {
  compareWith: CompareWith | undefined
  from?: string
  to?: string
  pathFor: (range: DateRange) => string
  metricsFrom?: (value: unknown) => unknown
}): ComparePlan | undefined {
  const { compareWith, from, to } = args
  if (!compareWith) return undefined

  const unavailable = { ok: false as const, block: comparisonUnavailable(compareWith, NEEDS_EXPLICIT_RANGE) }
  if (!from || !to) return unavailable

  const current: DateRange = { from, to }
  const baseline = comparisonRange(current, compareWith)
  if (!baseline) return unavailable

  return {
    ok: true,
    mode: compareWith,
    current,
    baseline,
    path: args.pathFor(baseline),
    metricsFrom: args.metricsFrom,
  }
}

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
  interface ReadOptions {
    /** Para las lecturas TENANT-LEVEL, que no llevan view-scope. */
    headers?: Record<string, string>
    /** Recorte previo a la normalización (filtrar, cortar a `limit`). */
    transform?: (payload: unknown) => unknown
    /** Mensaje de error propio, cuando la tool tiene uno más específico. */
    errorLabel?: (status: number) => string
    /** Comparación contra otro período. Ver `planComparison`. */
    compare?: ComparePlan
  }

  /** Una lectura normalizada, o el objeto de error que devuelven las tools. */
  type ReadOutcome = { ok: true; result: NormalizedResult } | { ok: false; error: unknown }

  /**
   * El texto del fallo que ve el MODELO — y, a través suyo, el usuario.
   *
   * El 403 se trata aparte porque desde el 2026-09-02 dejó de ser un caso raro:
   * los veinte endpoints de `/v1/reports/*` gatean la lectura contra el permiso
   * de quien pregunta, así que un cajero que le pida el balance al asistente
   * recibe uno. Y un `Error 403` pelado es lo peor que le podés dar a un modelo
   * en esa situación: no dice qué pasó, así que lo parafrasea como una falla del
   * sistema ("no pude obtener el reporte", "hubo un problema") o lo lee como
   * transitorio y reintenta la misma consulta, que vuelve a dar 403.
   *
   * El backend ya manda la frase correcta —`No tenés permiso para esta acción
   * (requiere: reports.sales.view)`, ver `api/lib/response.php`—, así que acá se
   * la lee del envelope y se le agrega la instrucción de qué hacer con ella.
   * `errorLabel` NO gana sobre esta rama a propósito: es justamente el que
   * convierte un 403 en "No se pudo obtener el reporte (403)", que es la
   * confusión que esto viene a sacar.
   */
  async function describeFailure(res: Response, opts: ReadOptions): Promise<string> {
    if (res.status === 403) {
      let detalle = ""
      try {
        const body = (await res.json()) as { error?: { message?: unknown } }
        if (typeof body?.error?.message === "string") detalle = body.error.message.trim()
      } catch {
        // 403 sin cuerpo JSON (proxy, gateway). Queda el texto genérico.
      }
      return (
        (detalle !== "" ? detalle : "No tenés permiso para consultar esto.") +
        " Es una restricción de permisos de tu usuario, no una falla: decíselo al" +
        " usuario y NO reintentes la consulta."
      )
    }
    return opts.errorLabel ? opts.errorLabel(res.status) : `Error ${res.status}`
  }

  async function fetchAndNormalize(path: string, opts: ReadOptions): Promise<ReadOutcome> {
    try {
      const res = await fetch(`${apiUrl}${path}`, { headers: opts.headers ?? dataHeaders })
      if (!res.ok) {
        return { ok: false, error: { error: await describeFailure(res, opts) } }
      }
      const json = (await res.json()) as { data?: unknown }
      const payload = json?.data ?? json
      return { ok: true, result: normalizeToolResult(opts.transform ? opts.transform(payload) : payload) }
    } catch (err) {
      return { ok: false, error: { error: String(err) } }
    }
  }

  /**
   * La segunda lectura, la de la línea de base.
   *
   * Su payload NO se devuelve: de ahí solo salen las métricas agregadas. Es lo
   * que hace que comparar cueste una llamada de red pero casi ningún token —
   * duplicar las filas en la respuesta sería pagar dos veces por un dato que el
   * modelo igual tendría que sumar a mano.
   */
  async function resolveComparison(
    current: NormalizedResult,
    opts: ReadOptions,
    plan: ComparePlanOk,
  ): Promise<unknown> {
    const ranges = { current: plan.current, baseline: plan.baseline }
    const baseline = await fetchAndNormalize(plan.path, opts)
    if (!baseline.ok) return comparisonUnavailable(plan.mode, BASELINE_FETCH_FAILED, ranges)

    // Recortada cualquiera de las dos, las sumas son de una MUESTRA y el
    // porcentaje sería inventado. Ver `TRUNCATED_SAMPLE`.
    if (current.truncated || baseline.result.truncated) {
      return comparisonUnavailable(plan.mode, TRUNCATED_SAMPLE, ranges)
    }

    const pick = plan.metricsFrom ?? ((v: unknown) => v)
    return buildComparison({
      mode: plan.mode,
      current: plan.current,
      baseline: plan.baseline,
      currentPayload: pick(current.value),
      baselinePayload: pick(baseline.result.value),
    })
  }

  async function read(path: string, opts: ReadOptions = {}): Promise<unknown> {
    const current = await fetchAndNormalize(path, opts)
    if (!current.ok) return current.error

    // La segunda lectura sale SOLO cuando la pidieron. Sin `compareWith` este
    // bloque no existe y la tool cuesta exactamente lo que costaba antes.
    let comparison: unknown
    if (opts.compare) {
      comparison = opts.compare.ok
        ? await resolveComparison(current.result, opts, opts.compare)
        : opts.compare.block
    }

    // El fetch de la moneda se dispara SOLO si el payload traía montos: es la
    // laziness de `tenantCurrency` vista desde acá.
    const currency = current.result.moneyFields.length > 0 ? await tenantCurrency() : null
    return withMeta(current.result, currency, comparison)
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
      "Resumen anual por mes: ventas, compras a proveedores, devoluciones y clientes nuevos. Usar cuando el usuario pregunte por ventas, ingresos, egresos o resultados de un año. " +
      "Pasá compareYear para comparar contra otro año completo: devuelve el bloque `comparison` con el cambio absoluto y porcentual del año entero ya calculados. " +
      "Ojo: lo que devuelve como egreso son COMPRAS, no los gastos del módulo Finanzas.",
    inputSchema: z.object({
      year: z.number().int().describe("Año a consultar, ej. 2025"),
      // Un AÑO y no un `compareWith`: esta tool no toma rango, así que
      // "previous_period" no querría decir nada acá (el período anterior a un
      // año ES el año anterior). Y aceptar el año explícito habilita 2026 contra
      // 2023, que es una pregunta legítima y que un enum de dos valores no puede
      // expresar.
      compareYear: z
        .number()
        .int()
        .optional()
        .describe(
          "Opcional. Año contra el que comparar (normalmente year - 1). Agrega el bloque `comparison` con los totales anuales de los dos años, el cambio absoluto y el porcentual. Cuesta una segunda lectura.",
        ),
      outletId: outletIdSchema,
    }),
    execute: async ({ year, compareYear, outletId }) =>
      read(withOutlet(`/v1/reports/summary_year?y=${year}`, outletId), {
        errorLabel: (s) => `No se pudo obtener el reporte (${s})`,
        compare:
          compareYear !== undefined && compareYear !== year
            ? {
                ok: true,
                mode: "explicit_year",
                current: yearRange(year),
                baseline: yearRange(compareYear),
                // La sucursal viaja también en el baseline: sin esto se
                // compararía una sucursal contra el consolidado y el delta
                // saldría enorme por una razón que nadie vería.
                path: withOutlet(`/v1/reports/summary_year?y=${compareYear}`, outletId),
                // El payload es `{ year, years, months }`: los números que se
                // pueden sumar están en `months`, no en el objeto de arriba.
                metricsFrom: (value) => (value as { months?: unknown } | null)?.months,
              }
            : undefined,
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
      outletId: outletIdSchema,
    }),
    execute: async ({ itemQuery, outletId }) =>
      // El endpoint /v1/reports/stock no filtra POR ÍTEM server-side todavía.
      // Filtramos ANTES de normalizar para no mandarle al LLM el listado
      // entero (puede ser miles de filas → muchos tokens). El filtro es
      // case-insensitive sobre name y sku.
      //
      // Por SUCURSAL sí filtra, y de hecho EXIGE una: el reporte agrupa stock
      // por outlet, así que sin una sucursal única responde
      // `{needsOutlet:true}` con el motivo. Por eso `outletId` importa más acá
      // que en el resto — es la forma de contestar la pregunta, no un refinamiento.
      read(withOutlet(`/v1/reports/stock`, outletId), {
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
    description:
      "Lista cajas/turnos del período con sus montos de apertura y cierre. Útil para consultar cierres de caja y diferencias de arqueo. " +
      "Acepta compareWith para ver cómo se movió contra otro período.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      compareWith: compareWithSchema,
      outletId: outletIdSchema,
    }),
    execute: async ({ from, to, compareWith, outletId }) => {
      const pathFor = (r: DateRange) =>
        withOutlet(`/v1/reports/drawers?from=${r.from}&to=${r.to}`, outletId)
      const params = new URLSearchParams()
      if (from) params.set("from", from)
      if (to) params.set("to", to)
      if (outletId) params.set("outletId", outletId)
      return read(`/v1/reports/drawers?${params}`, {
        compare: planComparison({ compareWith, from, to, pathFor }),
      })
    },
  }),

  get_transactions: defineTool({
    description:
      "Lista ventas/transacciones del período. Útil para consultar movimientos uno por uno. " +
      "Acepta compareWith, pero solo puede comparar cuando el período entra entero en la respuesta: para el total de un mes movido usá get_sales_kpis, que trae los totales sin las filas. " +
      "Acepta franja horaria (hourFrom/hourTo) para quedarse con las ventas de una misma franja de cada día del rango.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      limit: z.number().int().optional().default(50),
      hourFrom: hourFromSchema,
      hourTo: hourToSchema,
      compareWith: compareWithSchema,
      outletId: outletIdSchema,
    }),
    execute: async ({ from, to, limit, hourFrom, hourTo, compareWith, outletId }) => {
      const problema = hourBandProblem({ hourFrom, hourTo, from, to })
      if (problema) return { error: problema }

      // La franja y la sucursal viajan en LAS DOS rutas. Comparar "de 7 a 12 del
      // 1 al 7 de septiembre" contra "todo agosto" daría un delta enorme y
      // falso: el baseline tiene que ser la MISMA franja de la MISMA sucursal
      // del período anterior.
      const pathFor = (r: DateRange) =>
        withOutlet(
          `/v1/reports/transactions?limit=${limit ?? 50}&from=${r.from}&to=${r.to}` +
            hoursSuffix({ hourFrom, hourTo }),
          outletId,
        )
      const params = new URLSearchParams({ limit: String(limit ?? 50) })
      if (from) params.set("from", from)
      if (to) params.set("to", to)
      setHours(params, { hourFrom, hourTo })
      if (outletId) params.set("outletId", outletId)
      return read(`/v1/reports/transactions?${params}`, {
        compare: planComparison({ compareWith, from, to, pathFor }),
      })
    },
  }),

  get_top_products: defineTool({
    description:
      "Productos más vendidos del período, con unidades, total facturado y costo. Útil para 'top N productos vendidos'. " +
      "Trae además `previousPeriodTotals` (los totales del período anterior) y `previousPeriodUnitsByItemId` (cuántas unidades vendió CADA ítem antes), así que la comparación producto por producto ya viene incluida y no hace falta una segunda consulta. " +
      "Acepta franja horaria (hourFrom/hourTo) para responder qué se vende en un turno: el período anterior que devuelve el backend sale con la MISMA franja.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      limit: z.number().int().optional().default(10),
      hourFrom: hourFromSchema,
      hourTo: hourToSchema,
      outletId: outletIdSchema,
    }),
    // Sin `compareWith`, y es una decisión, no un olvido. Esta tool devuelve un
    // TOP-N: sumar sus filas da el total de los N productos que pidieron, no el
    // del período. Un delta entre "los 10 primeros de agosto" y "todo julio"
    // sale plausible y es falso — exactamente lo que el motor de comparación
    // existe para no producir. Y no hace falta: el backend ya manda el período
    // anterior COMPLETO en `prev`, más el desglose por ítem en `prevByItem`, que
    // es un eje que ninguna comparación agregada puede dar. Para el año contra
    // año a nivel producto, dos llamadas con rangos explícitos son honestas
    // porque las dos devuelven la misma forma.
    execute: async ({ from, to, limit, hourFrom, hourTo, outletId }) => {
      const problema = hourBandProblem({ hourFrom, hourTo, from, to })
      if (problema) return { error: problema }

      const params = new URLSearchParams({ view: "general" })
      if (from) params.set("from", from)
      if (to) params.set("to", to)
      setHours(params, { hourFrom, hourTo })
      if (outletId) params.set("outletId", outletId)
      return read(`/v1/reports/products?${params}`, {
        // Antes acá había un `rowsOf(payload).slice(0, limit)` que devolvía las
        // filas PELADAS y tiraba el resto del objeto — incluidos `prev` y
        // `prevByItem`, que `ProductsService::general()` calcula sin que nadie
        // se lo pida (`api/lib/Reports/ProductsService.php:57-67`) cuando la
        // consulta no filtra por cliente, usuario ni ítem. O sea: la comparación
        // ya venía hecha y viajada, y se descartaba en la última línea.
        //
        // Ahora se recortan las filas y se conserva el sobre. `prev` y
        // `prevByItem` los renombra el diccionario.
        transform: (payload) => {
          const rows = rowsOf(payload).slice(0, limit ?? 10)
          if (Array.isArray(payload) || payload === null || typeof payload !== "object") {
            return rows
          }
          const envelope: Record<string, unknown> = { ...(payload as Record<string, unknown>), rows }

          // `prevByItem` viene con TODOS los ítems que vendieron algo el período
          // anterior — en un catálogo grande son miles de pares id→unidades, y
          // acá se muestran diez filas. Sin este recorte, el mapa del período
          // anterior pesaría más que la respuesta entera y el modelo tendría que
          // buscar diez ids adentro de mil. Se conservan solo los de las filas
          // visibles: para cada producto del top se puede decir cuánto vendía
          // antes, que es la pregunta.
          const prevByItem = envelope.prevByItem
          if (prevByItem && typeof prevByItem === "object" && !Array.isArray(prevByItem)) {
            const visible = new Set(rows.map((r) => (r as { id?: unknown })?.id))
            envelope.prevByItem = Object.fromEntries(
              Object.entries(prevByItem as Record<string, unknown>).filter(([id]) => visible.has(id)),
            )
          }
          return envelope
        },
      })
    },
  }),

  get_outlets: defineTool({
    // La descripción dice "las que este usuario puede consultar" y no "las del
    // negocio", y no es una sutileza de redacción: el backend ahora acota la
    // lista al alcance del usuario dueño de la credencial. Si acá siguiera
    // diciendo "del negocio", un modelo que reciba dos sucursales de un comercio
    // que tiene cuatro concluiría que el comercio tiene dos — y lo afirmaría.
    // Que el catálogo declare el recorte es lo que lo vuelve un límite conocido
    // en vez de un dato equivocado.
    description:
      "Lista las sucursales que ESTE usuario puede consultar — no necesariamente todas las del negocio: el acceso a cada sucursal lo define el usuario dueño de la credencial. " +
      "Usala antes de pedir un reporte de una sucursal puntual: de acá salen los outletId válidos. " +
      "Sin outletId, los reportes devuelven el consolidado de todas estas.",
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
      "Pasá el rango de fechas cuando aplique (from/to en YYYY-MM-DD). " +
      "Acepta compareWith en los reportes que filtran por fecha: agrega el bloque `comparison` contra el período anterior o contra el mismo período del año pasado. " +
      "Los reportes stock, cuentas_por_cobrar y cuentas_por_pagar son fotos del estado actual: ignoran el rango de fechas y no se pueden comparar entre períodos. " +
      "Acepta franja horaria (hourFrom/hourTo) SOLO en cinco reportes: ventas_resumen, transacciones, productos, ordenes y movimientos_de_caja. En el resto la franja no existe y la llamada se rechaza — no la mandes. " +
      "Acepta outletId en todos menos staff_usuarios, que es del equipo del negocio entero.",
    inputSchema: z.object({
      report: z.enum(REPORT_IDS).describe("Nombre del reporte a consultar"),
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      hourFrom: hourFromSchema,
      hourTo: hourToSchema,
      compareWith: compareWithSchema,
      outletId: outletIdSchema,
    }),
    execute: async ({ report, from, to, hourFrom, hourTo, compareWith, outletId }) => {
      const route: ReportRoute | undefined = REPORT_ROUTES[report]
      if (!route) return { error: `Reporte desconocido: ${report}` }

      // Mismo criterio que la franja: un reporte que no filtra por sucursal NO
      // recibe el parámetro en silencio. Devolvería 200 con los datos de todas
      // las sucursales y el modelo se los atribuiría a la que pidió.
      if (outletId && route.tenantWide) {
        return {
          error:
            `El reporte "${report}" no se filtra por sucursal: es del equipo del negocio completo. ` +
            `Volvé a consultarlo sin outletId.`,
        }
      }

      // Un reporte sin franja NO recibe el parámetro en silencio para que el
      // backend lo ignore: el modelo leería un 200 con el día entero como si
      // fuera la franja que pidió. Se corta acá, con el motivo y la alternativa,
      // que es lo único que le permite reformular la consulta.
      //
      // Va ANTES de validar el formato de la hora a propósito: si el reporte no
      // filtra por franja, decirle "25:00 no es una hora válida" lo mandaría a
      // corregir la hora para volver a chocar contra el mismo muro. El error
      // accionable es el reporte, no el formato.
      if ((hourFrom || hourTo) && !route.hourly) {
        return {
          error:
            `El reporte "${report}" no filtra por franja horaria: o es una foto del estado actual, o se sirve de un agregado por DÍA donde la hora de cada operación ya no existe. ` +
            `Los reportes que sí la aceptan son: ventas_resumen, transacciones, productos, ordenes y movimientos_de_caja. ` +
            `Volvé a consultar sin hourFrom/hourTo, o elegí uno de esos si la pregunta es sobre una franja del día.`,
        }
      }

      const problema = hourBandProblem({ hourFrom, hourTo, from, to })
      if (problema) return { error: problema }

      // La franja Y la sucursal entran por acá, así que viajan igual en la
      // lectura del período pedido y en la del baseline de `compareWith` — un
      // baseline sin franja compararía la mañana de septiembre contra el agosto
      // completo, y uno sin sucursal compararía una sucursal contra el
      // consolidado.
      const withRange = (base: string, f?: string, t?: string) => {
        const qs = new URLSearchParams()
        if (f) qs.set("from", f)
        if (t) qs.set("to", t)
        setHours(qs, { hourFrom, hourTo })
        if (outletId) qs.set("outletId", outletId)
        const q = qs.toString()
        return `${base}${q ? (base.includes("?") ? "&" : "?") + q : ""}`
      }

      // Un reporte que ignora las fechas no se compara: el "período anterior"
      // devolvería el MISMO dato de hoy y el delta daría cero, que el modelo
      // leería como "no cambió nada" en vez de "esto no se mide por período".
      const compare = route.ranged
        ? planComparison({
            compareWith,
            from,
            to,
            pathFor: (r) => withRange(route.path, r.from, r.to),
          })
        : compareWith
          ? {
              ok: false as const,
              block: comparisonUnavailable(
                compareWith,
                `El reporte "${report}" es una foto del estado actual y no filtra por fechas: no hay período anterior contra el cual compararlo.`,
              ),
            }
          : undefined

      // Ejecutor GENÉRICO: 20 reportes, cada uno con su propia forma de salida.
      // La normalización que recibe es la del diccionario por NOMBRE DE CAMPO,
      // que es justamente la que funciona sin conocer la forma: los campos que
      // comparte con el resto (`total`, `tax`, `transactionType`, `cogs`) salen
      // traducidos, y los propios de un reporte que nadie relevó viajan crudos.
      // Ver el informe: los shapes de estos 20 endpoints NO están relevados uno
      // por uno y hacerlo es un trabajo aparte.
      //
      // La comparación se apoya en esa misma generalidad: suma solo los campos
      // que el diccionario declaró aditivos (`ADDITIVE_FIELDS`), así un reporte
      // cuyas columnas nadie relevó no produce una comparación inventada — no
      // produce ninguna.
      return read(withRange(route.path, from, to), { compare })
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
      "Resumen financiero: total en cuentas, ingresos, egresos y flujo neto del período. Usar para '¿cómo va mi caja?', '¿cuánto ingresó/gasté?'. " +
      "Acepta compareWith para ver si el flujo del período mejoró o empeoró contra el período anterior o contra el mismo período del año pasado. " +
      "Ojo: el saldo de las cuentas es una foto de AHORA y no se mueve con el rango de fechas, así que la comparación cubre los flujos y no el saldo.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      compareWith: compareWithSchema,
    }),
    execute: async ({ from, to, compareWith }) => {
      const qs = new URLSearchParams()
      if (from) qs.set("from", from)
      if (to) qs.set("to", to)
      const q = qs.toString()
      return read(`/v1/finance/summary${q ? "?" + q : ""}`, {
        compare: planComparison({
          compareWith,
          from,
          to,
          pathFor: (r) => `/v1/finance/summary?from=${r.from}&to=${r.to}`,
        }),
      })
    },
  }),

  get_sales_kpis: defineTool({
    description:
      "Indicadores de venta del período en un solo bloque: total vendido neto, TICKET PROMEDIO por venta, cantidad de ventas, compras a proveedores, resultado neto y margen. " +
      "Es la tool para 'cuánto vendí', 'cuál es mi ticket promedio', 'cómo vengo este mes' y para cualquier comparación de ventas entre períodos: devuelve los totales del período completo, sin listar transacciones, " +
      "así que sirve igual en un comercio con diez ventas que en uno con diez mil. Acepta compareWith.",
    inputSchema: z.object({
      from: z.string().optional().describe("Fecha inicio YYYY-MM-DD"),
      to: z.string().optional().describe("Fecha fin YYYY-MM-DD"),
      compareWith: compareWithSchema,
      outletId: outletIdSchema,
    }),
    /**
     * Expone `dashboard?widget=incomeOutcomeStats`, que era el ÚNICO lugar del
     * backend donde se calcula el ticket promedio
     * (`api/lib/Reports/DashboardService.php:121`) y no lo alcanzaba ninguna
     * tool.
     *
     * Se expone el widget en vez de DERIVAR el promedio en el normalizador, y
     * el motivo está en el diccionario: `count` significa cosas distintas según
     * el reporte —unidades de un depósito en stock, cantidad de ventas en el
     * resumen anual— así que una división genérica `total / count` daría un
     * ticket promedio con pinta de correcto en reportes donde el denominador no
     * son ventas. Acá el denominador está confirmado
     * (`COUNT(transactionId)` de las ventas no anuladas de tipo 0, 3 y 6,
     * `DashboardService.php:107-113`) porque lo calcula el mismo SELECT.
     */
    execute: async ({ from, to, compareWith, outletId }) => {
      const pathFor = (r: DateRange) =>
        withOutlet(
          `/v1/reports/dashboard?widget=incomeOutcomeStats&from=${r.from}&to=${r.to}`,
          outletId,
        )
      const qs = new URLSearchParams({ widget: "incomeOutcomeStats" })
      if (from) qs.set("from", from)
      if (to) qs.set("to", to)
      if (outletId) qs.set("outletId", outletId)
      return read(`/v1/reports/dashboard?${qs}`, {
        compare: planComparison({ compareWith, from, to, pathFor }),
      })
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
      outletId: outletIdSchema,
    }),
    execute: async ({ from, to, outletId }) => {
      const qs = new URLSearchParams({ widget: "customersSeries" })
      if (from) qs.set("from", from)
      if (to) qs.set("to", to)
      if (outletId) qs.set("outletId", outletId)
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
