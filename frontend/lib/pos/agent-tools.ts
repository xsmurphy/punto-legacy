/**
 * Recorte del catálogo de tools para el asistente de la CAJA (context/59 D3).
 *
 * `lib/agent/read-tools.ts` es el catálogo COMPARTIDO con el MCP y NO se
 * modifica ni se duplica: dos listas escritas por separado se desincronizan en
 * semanas, y las descripciones —que son la UX real del agente— son la parte
 * cara de mantener (context/58 §Arquitectura, context/59 §"Arquitecturas
 * RECHAZADAS" #4). El recorte vive acá, del lado del POS, como una lista de
 * ids que filtra lo que devuelve `buildReadOnlyFetchTools()`.
 *
 * El filtro se aplica en el SERVER (`app/api/pos/agent/chat/route.ts`). Un
 * recorte que viviera solo en el cliente no sería un recorte: el modelo corre
 * server-side y las tools se las da el BFF.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ ESTÁ CADA UNA
 *
 *   get_items        "¿a cuánto está esto?", "¿qué presentaciones hay?".
 *   get_stock        "¿nos queda?", "¿hay en la otra sucursal?".
 *   get_contacts     "¿cuánto debe este cliente?", "¿qué datos tiene?".
 *   get_categories   ubicar un ítem cuando no se sabe el nombre exacto.
 *   get_brands       ídem.
 *   get_transactions "¿cuánto se vendió hoy?", "¿esa venta se cobró?".
 *
 * Las seis son preguntas que se responden DE PIE, frente a alguien, en
 * segundos. Ese es el criterio de admisión: si la pregunta se hace sentado,
 * es del panel.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * POR QUÉ NO ESTÁ EL RESTO — y `get_report` es LA exclusión que importa
 *
 * `get_report` NO es una tool más: es una META-TOOL que despacha a ~20
 * endpoints de reportes por un mapa de nombres (`read-tools.ts`, definición de
 * `get_report`). Incluirla en esta lista abriría el catálogo ENTERO con una
 * sola entrada —`flujo_de_caja`, `cuentas_por_cobrar`, `cuentas_por_pagar`,
 * `compras_y_gastos`, `staff_usuarios`, `inventario`— y volvería decorativo
 * todo lo demás que hay acá. Es la razón por la que este archivo es una
 * allowlist explícita y no una denylist: una denylist que se olvide de
 * `get_report` no recorta nada.
 *
 * El resto queda afuera por alcance, no por accidente:
 *
 *   get_finance_accounts / get_finance_summary / get_finance_movements /
 *   get_finance_checks   tesorería del comercio; nada de eso se contesta
 *                        frente a un cliente. Además hoy esos endpoints son
 *                        `['panel','mcp']` — no darles `pos-app` es el
 *                        límite duro debajo de esta lista.
 *   get_sales_summary    histórico anual: pregunta de escritorio.
 *   get_customer_evolution  analítica de cartera.
 *   get_top_products     reporte de gestión. Es el primer candidato si el
 *                        owner quiere ampliar (no expone plata y "¿qué se
 *                        vende más?" sí es pregunta de mostrador); afuera por
 *                        defecto porque el criterio es abrir de a poco.
 *   get_users            roster de empleados.
 *   get_outlets          estructura del comercio; `get_stock` ya cubre el
 *                        caso legítimo ("¿hay en la otra sucursal?").
 *   get_tags             sin caso de uso en la caja.
 *   get_settings         la caja ya tiene moneda, decimales, país y nombre
 *                        del comercio en su propia config (`useCatalogStore`
 *                        → `PosConfig`), que viaja en el body del request.
 *                        Abrir superficie para un dato que ya tenemos, no.
 *   render_chart         ya la excluye `buildReadOnlyFetchTools` (es de
 *                        PRESENTACIÓN). En una tablet de caja un gráfico
 *                        tampoco es la respuesta.
 *
 * Y NINGUNA tool de escritura: ni `register_action` ni `execute_action`. El
 * asistente de la caja es SOLO LECTURA. La caja ya tiene sus escrituras, con
 * su permiso, su confirmación y su comportamiento offline; el asistente no es
 * una segunda puerta a ellas (context/59 D2).
 */

import { buildReadOnlyFetchTools, type ToolContext } from "@/lib/agent/read-tools"

/**
 * Ids habilitados en la caja. Verificados uno por uno contra las claves reales
 * de `buildReadTools()` en `lib/agent/read-tools.ts`.
 *
 * `get_drawers` NO ENTRA en esta tanda, a propósito, aunque "¿cómo viene la
 * caja?" sea una pregunta legítima de mostrador. Dos motivos, los dos del lado
 * del backend (context/59 §riesgo, D9):
 *
 *   1. `/v1/reports/drawers` NO scopea por caja — `Roc::build()` filtra por
 *      companyId + outletId y nada más, así que devuelve los arqueos de TODA
 *      la sucursal: todas las cajas, todos los cajeros, últimos 7 días.
 *   2. Su GET no chequea permisos: el `hasPermission('reports.drawers.view')`
 *      está dentro de la rama POST. Y el Bearer del device no expira ni
 *      identifica a una persona, así que habilitarla dejaría a cualquiera que
 *      tenga la tablet leyendo arqueos, haya o no alguien desbloqueado.
 *
 * Quien la agregue: primero el gate de operador en el GET de `drawers.php`
 * (`OperatorAssertion` + `reports.drawers.view` sobre el rol de la persona que
 * tipeó el PIN), después esta línea.
 */
export const POS_TOOL_IDS = [
  "get_items",
  "get_stock",
  "get_contacts",
  "get_categories",
  "get_brands",
  "get_transactions",
  // "get_drawers",  ← ver el docblock de arriba: falta el gate de operador en
  //                   el GET de api/v1/reports/drawers.php (D9 de context/59).
] as const

export type PosToolId = (typeof POS_TOOL_IDS)[number]

type ReadOnlyToolSet = ReturnType<typeof buildReadOnlyFetchTools>

/**
 * Arma el set de tools del asistente de la caja: el catálogo compartido,
 * filtrado a `POS_TOOL_IDS`.
 *
 * Si un id de la lista no existe en el catálogo (alguien lo renombró del otro
 * lado), se saltea con un log en vez de romper el chat: perder una tool
 * degrada la respuesta, tirar el endpoint deja la caja sin asistente. El log
 * es lo que hace que se note.
 */
export function buildPosAgentTools(ctx: ToolContext): ReadOnlyToolSet {
  const catalog = buildReadOnlyFetchTools(ctx)
  const picked: ReadOnlyToolSet = {}

  for (const id of POS_TOOL_IDS) {
    const definition = catalog[id]
    if (!definition) {
      console.error(
        `[pos-agent] la tool "${id}" no existe en el catálogo compartido ` +
          `(lib/agent/read-tools.ts) — se omite. Revisar POS_TOOL_IDS.`,
      )
      continue
    }
    picked[id] = definition
  }

  return picked
}
