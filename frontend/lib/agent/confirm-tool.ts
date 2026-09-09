import { tool } from "ai"
import { z } from "zod"

import { SIFEN_TAX_REGIMES, SIFEN_TAXPAYER_TYPES } from "@/lib/einvoice/tax-regimes"

import { postConfirm, postExecute, WRITE_ACTIONS } from "./confirm-api"

/**
 * Los catálogos cerrados del alta, escritos para el modelo.
 *
 * `regimeId` y `taxpayerType` viajan como NÚMEROS, y hasta el 2026-09-08 sus
 * descripciones no decían cuáles: el modelo podía leer "Régimen Contable" en
 * la constancia del comercio y no tenía forma de saber que eso es el 8. La
 * lista sale de `lib/einvoice/tax-regimes.ts`, la misma que llena los selects
 * del formulario — enumerarla a mano acá sería la tercera copia.
 */
const catalogo = (items: readonly { code: number; label: string }[]): string =>
  items.map((i) => `${i.code}=${i.label}`).join(", ")

/**
 * Tools de acciones mutantes del agente (crear/editar contacto, ítem, usuario,
 * taxonomías, e importación tabular).
 *
 * DISEÑO (2026-06-30): son DOS tools con campos REQUERIDOS, no una sola tool con
 * todo opcional. Motivo: con una tool de campos all-optional, DeepSeek (y otros
 * via OpenRouter) satisface el schema emitiendo `{}` vacío → nunca manda action/
 * payload → el agente muestra el resumen pero "no inserta" (bug reportado). Un
 * schema con campos requeridos obliga al modelo a poblarlos (verificado: una tool
 * con `name`/`price` requeridos se llama correctamente).
 *
 * DISEÑO (2026-07-02): register_action recibe SIEMPRE un array `actions` (mínimo
 * 1), nunca una acción suelta. Motivo: pedir "crear Sprite, Coca Zero y Coca
 * Cola" generaba 3 llamadas a register_action → 3 confirmaciones separadas. Con
 * el array, el modelo agrupa todo el lote en una sola llamada → un solo
 * confirmToken → una sola confirmación → execute_action ejecuta el lote entero.
 *
 *   register_action(actions[], summary) → devuelve confirmToken (NO ejecuta)
 *   execute_action(confirmToken)        → ejecuta TODAS las acciones del lote
 *
 * CONFIGURACIÓN DE LA CUENTA (2026-09-01, context/66 F1): el agente además
 * crea y edita sucursales, crea cajas y le cambia el rol a un usuario
 * existente — D1 del owner, "ventas no las hace el bot, el resto sí". Las
 * cuatro acciones son PANEL-ONLY: el backend las rechaza bajo realm `pos-app`
 * (AgentActor), porque configurar el comercio no es tarea de cajero. Los campos
 * obligatorios de cada una los valida `/v1/ai/confirm` ANTES de emitir el
 * confirmToken, así el modelo recibe el "te falta el timbrado" a tiempo para
 * repreguntarlo en vez de mostrar un resumen que iba a fallar.
 *
 * FACTURACIÓN ELECTRÓNICA (2026-09-07, M7 de `context/58` + `context/66 §FE`):
 * `set_fiscal_data` y `provision_einvoice` completan el otro tramo del
 * onboarding — el que hoy frena a un comercio nuevo antes de poder vender. Las
 * dos son PANEL-ONLY por el mismo motivo que las cuatro de arriba.
 *
 * Dos reglas que el catálogo hace cumplir por forma, no por prompt:
 *
 *   - La RAZÓN SOCIAL no existe como campo del payload. Sale del padrón, y el
 *     servidor la vuelve a consultar al ejecutar en vez de confiar en la que el
 *     modelo mostró minutos antes. El fallback "razón social = nombre de la
 *     empresa" fue un bug fiscal eliminado en tres lugares el 2026-09-06 (SIFEN
 *     valida la razón social contra el padrón del RUC): darle un campo al
 *     modelo sería el cuarto.
 *   - Los CÓDIGOS GEOGRÁFICOS del domicilio fiscal no se le piden al usuario y
 *     tampoco los escribe el modelo: los resuelve `resolve_geo_codes` a partir
 *     del NOMBRE de la ciudad, contra el catálogo de la autoridad tributaria.
 *     Hasta el 2026-09-08 la descripción decía lo contrario ("pedíselos al
 *     usuario") porque no había de dónde sacarlos, y era exactamente lo que
 *     frenaba el alta: nadie sabe de memoria que CAPITAL es el 1. Lo que sigue
 *     prohibido es INVENTARLOS o deducirlos del nombre — y por eso la tool
 *     devuelve TODAS las homónimas en vez de elegir una.
 *   - DOS DEFAULTS, y son deliberados (owner, 2026-09-08): Asunción cuando la
 *     dirección no nombra ciudad, y Régimen Contable. El 90% de las altas son
 *     esas dos cosas, y preguntarlas convierte un alta de dos mensajes en un
 *     interrogatorio. El bot los ANUNCIA en el resumen en vez de preguntarlos:
 *     el que emite desde Encarnación o está en Maquila lo sabe y corrige. La
 *     misma preselección vive en el formulario (EMPTY_FORM,
 *     DEFAULT_ESTABLISHMENT_GEO) — las dos superficies dicen lo mismo.
 *   - Los SECRETOS (certificado .p12, su contraseña, el CSC) tampoco tienen
 *     campo, y `/v1/ai/confirm` rechaza el payload que los traiga. El agente
 *     corre sobre un proveedor externo: un secreto fiscal que entra al contexto
 *     del modelo ya se filtró, y redactarlo después no lo devuelve.
 *
 * `update_outlet` es PARCIAL por diseño: manda SOLO los campos que cambian y el
 * backend deja el resto de la sucursal como estaba. La regla no es cosmética —
 * `itemsTaxIncluded` (IVA incluido vs. añadido) es un campo de la sucursal, y
 * un rename que lo pisara le cambiaría la fiscalidad al comercio.
 *
 * COMPARTIDAS CON LA CAJA (2026-08-31): el asistente del POS usa ESTAS MISMAS
 * dos tools, no una copia. Lo único que cambia entre superficies son los
 * headers: el panel manda su Bearer y nada más, y la caja manda además el
 * `X-Operator-Token` que prueba QUIÉN está operando — sin él el backend
 * rechaza la escritura (ver `extraHeaders` en `makeActionTools`). Duplicar
 * estas definiciones habría duplicado también los schemas y las descripciones,
 * que son la parte que hay que ajustar contra un modelo real.
 */

// payload con campos EXPLÍCITOS (no z.record/additionalProperties, que los
// modelos no logran poblar). El modelo llena solo los que aplican al `action`.
const payloadSchema = z.object({
  name: z.string().optional().describe("Nombre (contacto, ítem, categoría, marca, etiqueta, usuario, sucursal, caja). En update_outlet es el nombre NUEVO de la sucursal — la sucursal a modificar se indica con outletName o id"),
  type: z.number().int().optional().describe("contacto: 1=cliente, 2=proveedor"),
  phone: z.string().optional(),
  email: z.string().optional().describe("Email del contacto o de la sucursal. En provision_einvoice es el email de FACTURACIÓN del comercio, al que llegan las notificaciones del emisor. Buscalo en este orden y usá el primero que aparezca, anunciando cuál elegiste: el de la constancia de RUC, y si no, el del comercio que devuelve get_settings. Solo si no hay ninguno preguntáselo — es una casilla que alguien tiene que leer, así que no la inventes"),
  note: z.string().optional(),
  // Dirección default del contacto (create_contact / update_contact). El
  // backend la crea junto con el contacto — no es un paso aparte. `lat`/`lng`
  // van como NÚMEROS: la columna es DECIMAL y el propio panel los tipa
  // `number` (contact-detail-view.tsx), así que un string acá desalinearía al
  // agente del resto del sistema.
  address: z.string().optional().describe("create_contact, update_contact y update_outlet: calle y número de la dirección (ej. 'Av. España 1234')"),
  city: z.string().optional().describe("create_contact y update_contact: ciudad de la dirección"),
  location: z.string().optional().describe("create_contact y update_contact: barrio o zona de la dirección"),
  lat: z.number().optional().describe("create_contact y update_contact: latitud decimal de la dirección (ej. -25.2867). Solo si la sabés con certeza — NUNCA inventes ni estimes coordenadas. Va SIEMPRE junto con lng: una sola de las dos se rechaza"),
  lng: z.number().optional().describe("create_contact y update_contact: longitud decimal de la dirección (ej. -57.3333). Va SIEMPRE junto con lat"),
  // Documento tributario y personal del contacto. Las descripciones NO nombran
  // un país: el mismo campo es RUC en Paraguay, CUIT en Argentina y RUT en
  // Chile, y el sistema entero deriva la etiqueta del país del comercio
  // (CountryDefaults). Poner "RUC" a secas acá le enseñaría al modelo a
  // hablarle de RUC a un comercio argentino.
  tin: z.string().optional().describe("create_contact y update_contact: identificador TRIBUTARIO del contacto — el que lleva la factura (RUC en Paraguay, CUIT en Argentina, RUT en Chile, RFC en México). Cargalo tal como lo dictó el usuario, sin reformatear ni completar dígitos verificadores. Es lo que hace falta para poder facturarle a ese cliente"),
  ci: z.string().optional().describe("create_contact y update_contact: documento PERSONAL del contacto (cédula en Paraguay, DNI en Argentina, CPF en Brasil). Va acá y NO en tin, que es el tributario. No puede repetirse entre contactos del mismo tipo: si ya lo tiene otro, el sistema te dice con cuál choca"),
  id: z.string().optional().describe("id del registro a actualizar (update_*), o del usuario al que se le cambia el rol (assign_role). En update_outlet es opcional: alcanza con outletName, y el id solo hace falta para desempatar dos sucursales del mismo nombre"),
  kind: z.string().optional().describe("create_item: 'producto'|'servicio'. tabular_import: 'items'|'contacts'"),
  price: z.number().optional().describe("create_item: precio de venta"),
  cost: z.number().optional().describe("create_item: costo"),
  sku: z.string().optional(),
  categoryName: z.string().optional(),
  brandName: z.string().optional(),
  taxName: z.string().optional().describe("create_item: impuesto del artículo, como lo nombra el comercio o por su tasa (ej. 'IVA 10%', '10', 'Exenta'). Si el usuario no lo dice, dejalo vacío: se aplica el primer impuesto del comercio y el resultado te devuelve cuál fue, para que se lo confirmes. Preguntalo cuando el usuario mencione que el producto lleva otra tasa o que está exento"),
  outletNames: z.array(z.string()).optional().describe("create_item: nombres de las sucursales donde va a existir el artículo. Si lo omitís, el artículo queda en UNA sola sucursal (la que el sistema elige por defecto), así que en un comercio con varias sucursales preguntá dónde va antes de crearlo — o mandá todas si el usuario dice que se vende en todas"),
  newPrice: z.number().optional().describe("update_item_price: nuevo precio"),
  roleName: z.string().optional().describe("create_user y assign_role: nombre del rol tal como existe en el comercio (ej. 'Cajero', 'Encargado'). No admin. Si no sabés qué roles hay, mirá los usuarios existentes antes de proponer la acción"),
  lockPass: z.string().optional().describe("create_user: PIN de 4 dígitos con el que la persona se identifica y desbloquea la CAJA. NUNCA lo inventes, lo generes ni lo sugieras vos: lo elige quien lo va a usar, así que pedíselo al usuario ('¿qué PIN de 4 dígitos le ponemos?'). No puede repetirse con el de otro empleado del comercio. Si el usuario no quiere darlo, creá igual al usuario: va a poder entrar al panel con su contraseña, pero NO va a poder operar la caja hasta que le carguen un PIN"),
  outletId: z.string().optional().describe("create_register: id de la sucursal donde va la caja"),
  outletName: z.string().optional().describe("create_register: nombre de la sucursal donde va la caja, si no tenés el id (ej. 'Central'). update_outlet: nombre ACTUAL de la sucursal que se va a modificar (el nombre nuevo va en name). Si el comercio tiene dos sucursales llamadas igual el sistema te lo dice con sus ids: preguntale al usuario cuál es y repetí la acción con id"),
  timbrado: z.string().optional().describe("create_register: número de timbrado que la SET le autorizó a la caja, solo dígitos. OBLIGATORIO para crear una caja — si el usuario no lo dio, pedíselo antes de registrar la acción"),
  expeditionPoint: z.string().optional().describe("create_register: establecimiento y punto de expedición de la caja, formato EEE-PPP (ej. 001-001). OBLIGATORIO. Dos cajas NO pueden tener el mismo punto de expedición con el mismo timbrado — si el usuario abre varias cajas, pedile uno distinto para cada una"),
  lastIssuedInvoiceNumber: z.number().optional().describe("set_register_numbering: la ÚLTIMA factura que la caja emitió con su talonario actual — el número impreso en el último comprobante que dio el comercio, sin los ceros de adelante. NUNCA lo estimes, lo deduzcas ni lo redondees: preguntáselo al usuario y usá exactamente lo que te diga. Si el talonario es nuevo y todavía no emitió ninguna, va 0 y la caja arranca en la 1. El sistema nunca baja una numeración: si el número que das es menor que lo ya emitido, la caja arranca igual en el primer número libre y te lo informa"),
  initialInvoiceNumber: z.string().optional().describe("create_register: número desde el que esta caja empieza a facturar. Sale del TIMBRADO que autorizó la SET, no lo elegís vos: si el timbrado habilita el rango 2336-5000, acá va 2336. Mandalo TAL COMO viene, con los ceros de adelante si los tiene ('00002336'), porque esos ceros son los dígitos que se imprimen en la factura. Si el usuario no lo menciona, dejalo vacío: la caja arranca en 1, que es lo habitual. Preguntalo si dice que el talonario continúa una numeración anterior"),
  lastInvoiceNumber: z.string().optional().describe("create_register: última factura del rango autorizado por el timbrado (ej. 5000). También sale del timbrado. Sirve para que la caja deje de emitir al agotarse el talonario en lugar de facturar fuera de rango. Opcional: vacío significa sin tope declarado"),
  // ── Facturación electrónica (M7 de context/58, context/66 §FE) ──────────
  // `ruc` es el del PROPIO comercio y no el de un contacto — ese va en `tin`.
  // La razón social NO tiene campo acá a propósito: la trae el padrón, y darle
  // uno al modelo es reabrir el bug fiscal que se cerró el 2026-09-06.
  ruc: z.string().optional().describe("set_fiscal_data: identificador tributario del PROPIO comercio (el que va a emitir las facturas), tal como figura en su constancia. Consultalo antes con lookup_taxpayer y mostrale al usuario la razón social que devuelve el padrón para que la confirme: esa razón social NO se manda en el payload, la vuelve a traer el servidor del padrón al ejecutar. NUNCA la tipees vos ni uses el nombre comercial del negocio"),
  taxpayerType: z.number().int().optional().describe(
    "provision_einvoice: tipo de contribuyente. Valores: " + catalogo(SIFEN_TAXPAYER_TYPES) + ". " +
    "No lo preguntes: lo dice el propio RUC. En Paraguay los RUC que empiezan con 80 son de personas JURÍDICAS (empresas); el resto se emiten sobre la cédula de una persona FÍSICA. Contrastalo con la razón social que devolvió lookup_taxpayer —un nombre y apellido es física, una S.A./S.R.L./asociación es jurídica— y si las dos señales coinciden usalo directo. Decí cuál estás declarando en el resumen. Solo preguntá si se contradicen"
  ),
  actividades: z
    .array(z.object({ codigo: z.number().int(), nombre: z.string() }))
    .optional()
    .describe("provision_einvoice: actividades económicas de la constancia de RUC, con su código y su descripción. La PRIMERA es la principal — el orden ES el dato. Si el comercio te mandó la constancia, LEELAS DE AHÍ y copialas tal cual (código y descripción exactos, respetando el orden en que figuran); no se las pidas tipeadas si ya las tenés delante. Si no tenés la constancia, pedísela o pedile que te las dicte. Lo que NUNCA se hace es inventarlas ni deducirlas del rubro del negocio"),
  regimeId: z.number().int().optional().describe(
    "provision_einvoice: régimen tributario del comercio. Valores: " + catalogo(SIFEN_TAX_REGIMES) + ". " +
    "Por defecto es 8 (Régimen Contable): es el régimen general y el de la enorme mayoría de los comercios. Los otros siete son casos especiales —turismo, maquila, importador, exportador, pequeño o mediano productor, Ley 60/90— que el que los tiene sabe que los tiene. " +
    "Usá 8 sin preguntar, salvo que la constancia de RUC o el usuario indiquen otro; si la constancia menciona uno de esos casos especiales, usá ese. " +
    "Decí en una línea con qué régimen estás dando de alta, para que el usuario pueda corregirte si no es el suyo — pero no frenes el alta esperando que te lo confirme"
  ),
  establecimientos: z
    .array(
      z.object({
        codigo: z.string(),
        direccion: z.string(),
        numeroCasa: z.string().optional(),
        departamento: z.number().int(),
        departamentoDescripcion: z.string(),
        distrito: z.number().int(),
        distritoDescripcion: z.string(),
        ciudad: z.number().int(),
        ciudadDescripcion: z.string(),
        telefono: z.string().optional(),
        email: z.string().optional(),
        denominacion: z.string().optional(),
      }),
    )
    .optional()
    .describe(
      "provision_einvoice: domicilio fiscal de cada local desde el que emite, uno por cada establecimiento. " +
      "Qué códigos declarar te lo dice get_einvoice_setup en `establishmentCodes` (salen del punto de expedición de las cajas: si la caja tiene 001-001, el código es '001') — no se los preguntes al usuario. " +
      "La DIRECCIÓN sale de la constancia de RUC si el comercio te la mandó; si no, pedísela. " +
      "Los códigos de departamento, distrito y ciudad son NÚMEROS del catálogo de la autoridad tributaria y los resolvés con resolve_geo_codes a partir del NOMBRE de la ciudad: nunca se los pidas al usuario, no los sabe de memoria. "
      + "Si la dirección NO nombra ninguna ciudad, asumí Asunción (departamento 1 CAPITAL, distrito 1 y ciudad 1, ambos 'ASUNCION (DISTRITO)') sin preguntar: es de donde emite la enorme mayoría de los comercios. Decilo en una línea al mostrar el resumen, así el que emite desde otra ciudad te corrige. " +
      "Las descripciones (departamentoDescripcion, distritoDescripcion, ciudadDescripcion) van EXACTAMENTE como las devuelve esa tool, no como las escribió el usuario. " +
      "Si resolve_geo_codes devuelve varias candidatas —hay ciudades con el mismo nombre en departamentos distintos— mostrale la lista con el departamento de cada una y preguntale cuál es la suya; si no devuelve ninguna, pedile el nombre como figura en su constancia. " +
      "telefono, email y denominacion del establecimiento son OPCIONALES para el alta: no se los pidas al usuario. Mandá los que ya conozcas por get_outlets y dejá vacíos los demás. numeroCasa también es opcional — si la dirección no tiene altura, omitilo y el backend declara la convención de SIFEN para 'sin número'. " +
      "Lo único que sigue prohibido es INVENTAR un código o deducirlo vos del nombre: un domicilio fiscal mal declarado es un dato falso ante la autoridad tributaria."
    ),
  infoAdicional: z.string().optional().describe("provision_einvoice: información adicional que el comercio quiere que salga en sus documentos. Opcional"),
  sessionId: z.string().optional().describe("tabular_import: id de sesión del adjunto"),
  mode: z.string().optional().describe("tabular_import: 'insert'|'update'"),
  mapping: z.record(z.string(), z.string()).nullish().describe("tabular_import: mapeo campo→columna, o null para auto"),
})

// Una acción individual del lote — schema PLANO: `action` + los campos de
// payload al MISMO nivel (sin objeto `payload` anidado). Motivo (2026-07-07):
// el nesting `{action, payload:{...}}` dentro de `actions[]` hacía que el modelo
// (vía OpenRouter) emitiera mal el primer tool-call → el AI SDK lo rechazaba por
// validación → el modelo narraba "problema técnico, ajuste en el formato" + `{}`
// y recién el 2do intento validaba. Aplanando, el primer intento valida.
// El wire format al backend (`/v1/ai/confirm`) sigue siendo {action, payload}:
// se re-anida en el `execute` de register_action (ver abajo).
const actionItemSchema = payloadSchema.extend({
  action: z.string().describe(
    // La lista sale de `WRITE_ACTIONS` (confirm-api.ts) para no tener una copia
    // más: la comparten estas tools y las del server MCP.
    WRITE_ACTIONS.join(" | ") + ". " +
    "update_outlet modifica una sucursal EXISTENTE (nombre, dirección, teléfono, email, descripción): mandá SOLO los campos que cambian — los que omitas quedan como están, y los que mandes vacíos se ignoran. " +
    "set_fiscal_data carga la identidad fiscal del comercio (mandá SOLO ruc: la razón social la trae el padrón). " +
    "provision_einvoice da de alta al comercio como emisor electrónico (email + actividades + tipo de contribuyente + régimen + establecimientos): antes tiene que estar cargado el RUC y tiene que haber al menos una caja con timbrado, y el certificado y el CSC se cargan aparte en Configuración → Facturación electrónica. " +
    "set_register_numbering le dice a una caja desde qué número sigue facturando, y SOLO se usa cuando get_einvoice_setup lo pide: el alta del emisor ya deja resuelto todo lo que se puede deducir, así que si no aparece en el paso 'numeracion' no hay nada que cargar"
  ),
})

// Los fetch viven en `confirm-api.ts`: desde M6 el mismo embudo lo llaman estas
// tools y las del server MCP, y lo único que cambia entre superficies es el
// mensaje de guía para el modelo. Ver el docblock de ese archivo.
async function registerConfirmation(
  authHeader: string,
  apiUrl: string,
  extraHeaders: Record<string, string>,
  actions: Array<{ action: string; payload: unknown }>,
  summary: string,
) {
  console.error("[agent] register_action input", JSON.stringify({ actions, summary }))
  const res = await postConfirm(apiUrl, authHeader, extraHeaders, actions, summary)
  if (!res.ok) return { error: res.error }
  return {
    confirmToken: res.data.confirmToken,
    summary: res.data.summary,
    count: res.data.count,
    pendingConfirmation: true,
    message: "Acción(es) pendiente(s) de confirmación del usuario. La UI ya muestra el resumen — NO lo repitas en texto. Esperá su aprobación explícita antes de llamar execute_action.",
  }
}

async function executeConfirmation(
  authHeader: string,
  apiUrl: string,
  extraHeaders: Record<string, string>,
  confirmToken: string,
) {
  console.error("[agent] execute_action confirmToken", JSON.stringify(confirmToken))
  const res = await postExecute(apiUrl, authHeader, extraHeaders, confirmToken)
  if (!res.ok) return { error: res.error }
  return res.data ?? { ok: true }
}

/**
 * @param extraHeaders headers adicionales para los DOS fetches. La caja manda
 *   acá su `X-Operator-Token`: es la prueba de identidad de la persona que
 *   tipeó el PIN, y sin ella `/v1/ai/confirm` y `/v1/ai/execute` responden 403
 *   bajo realm `pos-app` (el Bearer del device es del mueble, no de nadie). El
 *   panel no pasa nada: ahí la credencial YA es la persona. Nunca se manda una
 *   cookie por acá — la caja es token-only (context/08 §60).
 */
export function makeActionTools(
  authHeader: string,
  apiUrl: string,
  extraHeaders: Record<string, string> = {},
) {
  return {
    register_action: tool({
      description:
        "Registra un LOTE de una o más acciones mutantes (crear/editar contacto, ítem, usuario, categoría, marca, etiqueta; cambiarle el rol a un usuario; crear o editar una sucursal; crear una caja; cargar los datos fiscales del comercio o darlo de alta como emisor electrónico; o importación tabular) para que el usuario las confirme JUNTAS. NO las ejecuta: devuelve un confirmToken. Si el usuario pidió varios ítems (ej. 'creá Sprite, Coca Zero y Coca Cola'), agrupá TODAS las acciones en un solo llamado con actions=[...] — nunca llames register_action varias veces para un mismo pedido. La UI muestra el resumen como tarjeta — no lo repitas en texto. Recién cuando el usuario confirme, llamá execute_action con ese confirmToken.",
      inputSchema: z.object({
        actions: z.array(actionItemSchema).min(1).describe("Lote de acciones a confirmar juntas (mínimo 1)"),
        summary: z.string().describe("Resumen legible del LOTE completo para mostrar al usuario (ej. 'Crear 3 productos: Sprite, Coca Zero, Coca Cola')"),
      }),
      execute: async ({ actions, summary }) => {
        // Re-anidar plano → {action, payload} que espera el backend, intacto.
        const nested = actions.map(({ action, ...fields }) => ({ action, payload: fields }))
        return registerConfirmation(authHeader, apiUrl, extraHeaders, nested, summary)
      },
    }),

    execute_action: tool({
      description:
        "Ejecuta el LOTE de acciones YA confirmado por el usuario. Llamala SOLO después de que el usuario confirmó explícitamente, con el confirmToken que devolvió register_action.",
      inputSchema: z.object({
        confirmToken: z.string().describe("Token devuelto por register_action"),
      }),
      execute: async ({ confirmToken }) =>
        executeConfirmation(authHeader, apiUrl, extraHeaders, confirmToken),
    }),
  }
}
