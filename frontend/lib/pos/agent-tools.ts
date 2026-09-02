/**
 * El set de tools del asistente de la CAJA: el recorte de LECTURA (context/59
 * D3) más las dos tools de ESCRITURA, que son las mismas del panel.
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
 *   get_transactions "¿cuánto se vendió hoy?", "¿esa venta se cobró?" — sujeta
 *                    al permiso del operador (ver `POS_TOOL_PERMISSION`).
 *
 * Las seis son preguntas que se responden DE PIE, frente a alguien, en
 * segundos. Ese es el criterio de admisión: si la pregunta se hace sentado,
 * es del panel.
 *
 * Estar en la lista no alcanza: lo que el asistente puede LEER está sujeto a
 * los permisos de la persona que pregunta, igual que lo que puede escribir. El
 * corte —cuáles exigen permiso y cuáles no— está en `POS_TOOL_PERMISSION`.
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
 * ─────────────────────────────────────────────────────────────────────────
 * LAS DOS TOOLS DE ESCRITURA — por qué ahora sí, y cuál es el límite
 *
 * Hasta el 2026-08-31 acá no había ninguna: el asistente de la caja era de solo
 * lectura (D2 de context/59). El owner reabrió esa decisión con un caso
 * concreto —"si necesita modificar algo de un producto no tiene que entrar al
 * panel, solo se lo pide al bot"— y la respuesta correcta no era relajar el
 * alcance sino atarlo a la persona.
 *
 * `register_action` / `execute_action` salen de `lib/agent/confirm-tool.ts`, el
 * MISMO módulo que usa el panel. No hay una versión POS de estas tools, y no
 * debe haberla: los schemas y las descripciones son lo caro de mantener, y el
 * alcance de escritura del agente es UNO solo (contactos, ítems básicos,
 * taxonomías; nada de ventas, caja, sucursales ni permisos).
 *
 * Lo que las acota en la caja son dos cosas, las dos del backend:
 *
 *   1. LA PERSONA. Bajo realm `pos-app` el Bearer identifica una tablet, no a
 *      alguien: no expira y lo comparten todos los turnos. Por eso estas tools
 *      solo se arman si hay `operatorToken` —la `OperatorAssertion` firmada que
 *      emite el unlock por PIN— y `api/lib/Ai/AgentActor.php` evalúa CADA acción
 *      contra el rol de esa persona. Sin operador: 403, sin excepción. El cajero
 *      que no puede editar precios en el panel tampoco puede pedírselo al bot.
 *   2. EL confirmToken. `register_action` no escribe: registra el lote y
 *      devuelve un token de 5 minutos que queda a nombre de quien lo pidió.
 *      Recién `execute_action`, tras la confirmación explícita, ejecuta — y solo
 *      si lo consume la MISMA persona que lo registró (una tablet la desbloquean
 *      tres personas por turno).
 *
 * `create_user` queda afuera desde la caja, bloqueada por realm en el backend:
 * dar de alta un empleado es la puerta a más accesos y se hace en el panel.
 */

import type { ToolSet } from "ai"
import { buildReadOnlyFetchTools, type ToolContext } from "@/lib/agent/read-tools"
import { makeActionTools } from "@/lib/agent/confirm-tool"

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
 *   2. ~~Su GET no chequea permisos~~ — RESUELTO el 2026-09-02: el GET exige
 *      `reports.drawers.view` por `OperatorContext::requirePermission()`, igual
 *      que la rama POST. Queda como referencia de por qué esta tool estuvo
 *      apagada, no como pendiente.
 *
 * Con el punto 2 cerrado, lo que sigue faltando es lo que NO se tocó: el punto
 * 1 (el reporte no scopea por caja) y, antes que eso, el REALM —
 * `drawers.php` acepta `['panel', 'api']` y nada más, así que hoy una request
 * `pos-app` ni siquiera llega al gate: rebota en el embudo con 401. Abrirlo al
 * POS es una decisión aparte, no un efecto secundario de haber puesto el gate.
 *
 * Quien la agregue: primero abrir el realm y resolver el scope por caja,
 * después esta línea.
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

/**
 * Tools cuya LECTURA está sujeta a un permiso de la persona que pregunta.
 *
 * ── El corte, y por qué no es parejo ──────────────────────────────────────
 *
 * `get_transactions` devuelve las VENTAS de la sucursal —montos, clientes,
 * medios de pago, de todas las cajas y todos los turnos—. Es exactamente lo que
 * el panel muestra detrás de `reports.sales.view`, y un cajero que no puede
 * abrir ese reporte tampoco puede obtenerlo preguntándole al asistente: sería
 * la misma pantalla con otra puerta.
 *
 * Las otras cinco (`get_items`, `get_stock`, `get_contacts`, `get_categories`,
 * `get_brands`) NO llevan permiso extra, y no por comodidad: son lo que la
 * pantalla de venta ya tiene abierto delante de quien opera la caja. El buscador
 * de artículos muestra precio y stock, el selector de cliente muestra su saldo,
 * y categorías y marcas son la taxonomía con la que se navega ese mismo
 * catálogo. Exigir operador ahí no protegería nada —el dato está a un toque de
 * distancia en la misma pantalla— y rompería la consulta de mostrador, que es
 * la razón de ser del asistente. El gate que sí las cubre es el del device
 * (`items.php` pide `inventory.item.view`, `contacts.php` la familia
 * `contacts.customer.*`), que es el piso correcto para una terminal.
 *
 * OJO al agregar una clave acá: tiene que estar TAMBIÉN en la allowlist de
 * `api/v1/unlock-pin.php` (la que decide qué permisos de panel bajan a la
 * caja). Si no baja, el POS nunca la ve, la tool queda apagada para todos —
 * incluido el dueño— y el síntoma es "el asistente dejó de contestar eso" sin
 * ningún error. Las dos listas se tocan juntas.
 *
 * El mapa es la mitad CLIENTE del gate: evita que el modelo prometa un dato que
 * va a terminar en 403. La que manda es la del backend
 * (`OperatorContext::requirePermission()` en el GET de
 * `api/v1/reports/transactions.php`), y sigue valiendo aunque alguien llame al
 * BFF a mano con la lista de permisos inflada.
 */
export const POS_TOOL_PERMISSION: Partial<Record<PosToolId, string>> = {
  get_transactions: "reports.sales.view",
}

type ReadOnlyToolSet = ReturnType<typeof buildReadOnlyFetchTools>

/**
 * Arma el set de tools del asistente de la caja: el catálogo compartido
 * filtrado a `POS_TOOL_IDS`, más las dos de escritura si hay un operador
 * identificado.
 *
 * Si un id de la lista no existe en el catálogo (alguien lo renombró del otro
 * lado), se saltea con un log en vez de romper el chat: perder una tool
 * degrada la respuesta, tirar el endpoint deja la caja sin asistente. El log
 * es lo que hace que se note.
 *
 * @param operatorToken afirmación firmada del operador (`X-Operator-Token`).
 *   Vacía o ausente = nadie probó su PIN en esta caja → NO se arman las tools de
 *   escritura. Es la mitad cliente del fail-closed: la que manda es la del
 *   backend, pero ofrecerle al modelo una capacidad que va a terminar en 403 es
 *   pedirle que le prometa al cajero algo que no va a pasar.
 * @param operatorPermissions permisos del operador tal como los devolvió el
 *   unlock por PIN. Recortan las LECTURAS sujetas a permiso (`POS_TOOL_PERMISSION`)
 *   por el mismo motivo y con la misma fuerza que el parámetro de arriba: es una
 *   señal de UX, no el gate. Inflarla no habilita nada — el 403 lo pone el
 *   backend, que mide contra el rol real de esa persona.
 */
export function buildPosAgentTools(
  ctx: ToolContext,
  operatorToken = "",
  operatorPermissions: readonly string[] = [],
): ToolSet {
  const catalog = buildReadOnlyFetchTools(ctx)
  const picked: ReadOnlyToolSet = {}

  for (const id of POS_TOOL_IDS) {
    const required = POS_TOOL_PERMISSION[id]
    // Sin el permiso, la tool ni se ofrece: el modelo no la llama, no cobra el
    // 403 y no le anuncia al cajero un dato que no va a poder darle.
    if (required && !operatorPermissions.includes(required)) continue

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

  if (operatorToken === "") {
    return picked
  }

  return {
    ...picked,
    ...makeActionTools(ctx.authHeader, ctx.apiUrl, { "X-Operator-Token": operatorToken }),
  }
}
