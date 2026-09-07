import { z } from "zod"

import { buildReadTools, defineTool, type ToolContext } from "@/lib/agent/read-tools"
import { resolveTaxIdLabel } from "@/lib/tenant-locale"

/**
 * `get_einvoice_setup` — en qué punto está la facturación electrónica del
 * comercio y qué falta para que pueda emitir.
 *
 * M7 de `context/58` + `context/66 §FE`. El bot conduce la configuración de FE
 * (que es lo que hoy frena a un comercio nuevo antes de poder facturar), y no
 * puede conducir lo que no sabe leer: sin esto registraría `set_fiscal_data` a
 * un comercio que ya tiene el RUC cargado, o `provision_einvoice` antes de que
 * exista una caja con timbrado, que es el error que el propio servicio rechaza.
 *
 * ── DERIVADO, nunca persistido ──────────────────────────────────────────────
 * Mismo criterio que `get_setup_status` (F4 de `context/66`) y por el mismo
 * motivo: un progreso guardado se desincroniza del estado real la primera vez
 * que alguien carga el certificado desde el panel. Todo sale de leer el estado
 * en el momento de preguntar, con los endpoints que ya existen.
 *
 * ── Los secretos son BOOLEANOS y nada más ───────────────────────────────────
 * De acá salen "certificado cargado: sí/no" y "CSC cargado: sí/no", nunca su
 * contenido — ni el endpoint los devuelve (`getAccount` expone solo el estado
 * de la custodia) ni esta tool los pediría. Es la misma regla que hace que
 * `set_fiscal_data`/`provision_einvoice` no tengan campo para ellos: el agente
 * corre sobre un proveedor externo y un secreto fiscal que entra al contexto
 * del modelo ya se filtró.
 *
 * ── Cero país hardcodeado ───────────────────────────────────────────────────
 * El identificador fiscal se llama distinto en cada mercado y la etiqueta sale
 * de `resolveTaxIdLabel`, la única fuente de esa dimensión en el proyecto. El
 * número de autorización para facturar no tiene catálogo por país, así que se
 * nombra de forma neutra ("autorización para facturar"), igual que en
 * `setup-status.ts`.
 */

export type EinvoiceStepState = "listo" | "falta" | "no se pudo leer"

export interface EinvoiceStep {
  /** Id estable para que el modelo pueda nombrar un paso sin ambigüedad. */
  id: string
  title: string
  state: EinvoiceStepState
  /** Una frase: qué está resuelto o qué falta exactamente. */
  detail: string
  /** Los datos concretos que hay que pedirle al usuario, si los hay. */
  missing?: string[]
  /** Qué acción del agente lo resuelve. Vacío = el agente NO puede hacerlo. */
  agentActions: string[]
  /** Dónde se hace a mano, para lo que el agente no puede hacer. */
  where: string
}

export interface EinvoiceSetup {
  allDone: boolean
  done: number
  pending: number
  unreadable: number
  /** Id del primer paso pendiente, en orden de dependencia. */
  nextStep: string | null
  steps: EinvoiceStep[]
}

type Row = Record<string, unknown>

function isRecord(v: unknown): v is Row {
  return typeof v === "object" && v !== null && !Array.isArray(v)
}

/** Una lectura que falló trae `{ error }` — nunca se la interpreta como dato. */
function failed(payload: unknown): boolean {
  return isRecord(payload) && typeof payload.error === "string"
}

/**
 * El cuerpo de una lectura normalizada. `normalizeToolResult` envuelve el
 * payload en `{ value, ... }` cuando le agrega metadatos, así que hay que
 * mirar las dos formas — es lo mismo que hace `setup-status.ts`.
 */
function unwrap(payload: unknown): unknown {
  if (isRecord(payload) && "value" in payload) return payload.value
  return payload
}

function str(row: Row, key: string): string {
  const v = row[key]
  return typeof v === "string" ? v.trim() : ""
}

/**
 * ¿La fila está activa? Mismo criterio (y misma tolerancia de shape) que
 * `setup-status.ts`: el backend manda `status` como 0/1 en unas tablas y como
 * booleano en otras, y la ausencia del campo significa activa.
 */
function isActive(row: Row): boolean {
  if (typeof row.active === "boolean") return row.active
  if (typeof row.status === "boolean") return row.status
  if (typeof row.status === "number") return row.status === 1
  return true
}

/** Filas de un listado que puede venir pelado o bajo una clave. */
function rowsFrom(payload: unknown, key: string): Row[] | null {
  const body = unwrap(payload)
  if (Array.isArray(body)) return body.filter(isRecord)
  if (isRecord(body)) {
    const inner = body[key]
    if (Array.isArray(inner)) return inner.filter(isRecord)
  }
  return null
}

function unreadable(
  id: string,
  title: string,
  queDato: string,
  agentActions: string[],
  where: string,
): EinvoiceStep {
  return {
    id,
    title,
    state: "no se pudo leer",
    detail: `No se pudo leer ${queDato}, así que este punto no se puede afirmar ni descartar.`,
    agentActions,
    where,
  }
}

// ── Los pasos ────────────────────────────────────────────────────────────────

/**
 * 1 — Identidad fiscal: identificador tributario Y razón social.
 *
 * Los dos juntos, no uno u otro: el emisor se registra con la razón social del
 * padrón y con el RUC vacío no hay a quién consultarle. Y la razón social vacía
 * es el estado que hasta el 2026-09-06 se tapaba con el nombre comercial del
 * negocio — de ahí que acá se declare FALTA en vez de darse por resuelta.
 */
function checkFiscalIdentity(settings: unknown): EinvoiceStep {
  const id = "datos_fiscales"
  const title = "Datos fiscales del comercio"
  const where = "Ajustes → Facturación electrónica"

  if (failed(settings)) {
    return unreadable(id, title, "la configuración del negocio", ["set_fiscal_data"], where)
  }
  const body = unwrap(settings)
  if (!isRecord(body)) {
    return unreadable(id, title, "la configuración del negocio", ["set_fiscal_data"], where)
  }

  // Etiqueta del documento fiscal: ajuste del tenant → país del tenant →
  // genérico. Los dos escalones se pasan juntos, igual que en `setup-status.ts`
  // — un comercio argentino tiene que leer "CUIT", no el nombre de otro
  // mercado, y con solo el país se perdería el override que ya configuró.
  const etiqueta = resolveTaxIdLabel({ tinName: str(body, "tin"), country: str(body, "country") })

  const ruc = str(body, "ruc")
  const billingName = str(body, "billingName")
  const faltan: string[] = []
  if (ruc === "") faltan.push(`${etiqueta} del comercio`)
  // La razón social no se le pide al usuario: sale del padrón a partir del
  // identificador tributario. Por eso NO entra en `missing`, que es la lista de
  // lo que el bot tiene que preguntar.
  if (faltan.length > 0 || billingName === "") {
    return {
      id,
      title,
      state: "falta",
      detail:
        ruc === ""
          ? `Falta el ${etiqueta} del comercio: sin él no se puede consultar el padrón ni registrar al emisor.`
          : `Está cargado el ${etiqueta} (${ruc}) pero falta la razón social. Volvé a cargarlo: la razón social la trae el padrón.`,
      missing: faltan.length > 0 ? faltan : [`${etiqueta} del comercio (para volver a consultar el padrón)`],
      agentActions: ["lookup_taxpayer", "set_fiscal_data"],
      where,
    }
  }

  return {
    id,
    title,
    state: "listo",
    detail: `${etiqueta} ${ruc} a nombre de "${billingName}".`,
    agentActions: [],
    where,
  }
}

/**
 * 2 — Al menos una caja con autorización para facturar.
 *
 * Va ANTES del alta del emisor y no es un orden cosmético: el provisioning lee
 * los timbrados de las CAJAS (cada caja es un punto de expedición) y falla si
 * ninguna activa lo tiene completo. Que el bot lo sepa antes es la diferencia
 * entre pedir el dato y chocar contra un error.
 */
function checkRegisterStamps(registers: unknown): EinvoiceStep {
  const id = "caja_con_timbrado"
  const title = "Caja habilitada para facturar"
  const where = "Sucursales → Cajas"

  if (failed(registers)) {
    return unreadable(id, title, "las cajas del comercio", ["create_register"], where)
  }
  const rows = rowsFrom(registers, "registers")
  if (rows === null) {
    return unreadable(id, title, "las cajas del comercio", ["create_register"], where)
  }

  // Solo las ACTIVAS cuentan: el provisioning lee los timbrados de las cajas
  // activas, así que una caja dada de baja con timbrado cargado no habilita
  // nada y decir lo contrario mandaría al bot a intentar un alta que falla.
  const activas = rows.filter(isActive)

  // El timbrado y el punto de expedición viven en el bloque fiscal de la caja,
  // y el par tiene que estar COMPLETO: una caja con timbrado y sin punto de
  // expedición no puede emitir (la unicidad fiscal es del par, `context/29`).
  const habilitadas = activas.filter((r) => {
    const fiscal = isRecord(r.fiscal) ? r.fiscal : {}
    return str(fiscal, "invoiceAuth") !== "" && str(fiscal, "invoicePrefix") !== ""
  })

  if (habilitadas.length === 0) {
    return {
      id,
      title,
      state: "falta",
      detail:
        activas.length === 0
          ? "El comercio no tiene ninguna caja activa."
          : "Ninguna caja activa tiene cargada su autorización para facturar y su punto de expedición.",
      missing: [
        "número de autorización para facturar (timbrado) de cada caja",
        "punto de expedición de cada caja (formato EEE-PPP)",
      ],
      agentActions: ["create_register"],
      where,
    }
  }

  return {
    id,
    title,
    state: "listo",
    detail: `${habilitadas.length} de ${activas.length} caja(s) activa(s) con autorización para facturar cargada.`,
    agentActions: [],
    where,
  }
}

/**
 * 3 — El emisor existe del lado del proveedor.
 *
 * `provisioned` es lo que dice si el alta llegó a destino; `status` y
 * `lastError` son lo que dice si además quedó operativa. Se reportan los dos
 * porque un alta que se creó pero no verificó no es lo mismo que una que nunca
 * se intentó, y la acción que resuelve cada caso es distinta.
 */
function checkProvisioning(account: unknown): EinvoiceStep {
  const id = "emisor"
  const title = "Alta del emisor electrónico"
  const where = "Ajustes → Facturación electrónica"

  if (failed(account) || !isRecord(unwrap(account))) {
    return unreadable(id, title, "el estado de la cuenta de facturación", ["provision_einvoice"], where)
  }
  const body = unwrap(account) as Row

  if (body.provisioned !== true) {
    return {
      id,
      title,
      state: "falta",
      detail:
        body.configured === true
          ? "El alta del emisor quedó a medias: se puede retomar con los mismos datos."
          : "El comercio todavía no está dado de alta como emisor electrónico.",
      missing: [
        "email de facturación del comercio",
        "actividades económicas de la constancia (código y descripción, la principal primera)",
      ],
      agentActions: ["provision_einvoice"],
      where,
    }
  }

  const status = str(body, "status")
  const lastError = str(body, "lastError")
  if (status !== "ok" && status !== "connected" && lastError !== "") {
    return {
      id,
      title,
      state: "falta",
      detail: `El emisor está creado pero la verificación no pasó: ${lastError}`,
      agentActions: ["provision_einvoice"],
      where,
    }
  }

  return {
    id,
    title,
    state: "listo",
    detail: `Emisor dado de alta${status !== "" ? ` (estado: ${status})` : ""}.`,
    agentActions: [],
    where,
  }
}

/**
 * 4 — Certificado de firma y CSC.
 *
 * El ÚNICO paso con `agentActions` vacío a propósito: son secretos fiscales y
 * no son acciones del agente en ningún transporte. El campo `where` es acá la
 * respuesta completa, no un complemento.
 */
function checkSecrets(account: unknown): EinvoiceStep {
  const id = "certificado_y_csc"
  const title = "Certificado de firma y código de seguridad (CSC)"
  const where = "Ajustes → Facturación electrónica (se cargan ahí, nunca por el chat)"

  if (failed(account) || !isRecord(unwrap(account))) {
    return unreadable(id, title, "el estado de la cuenta de facturación", [], where)
  }
  const body = unwrap(account) as Row

  // `certUploaded` = lo tiene el PROVEEDOR (es lo que habilita firmar).
  // `certStored` = además quedó en custodia cifrada en Punto, que es lo que
  // permite reconfigurar sin volver a pedírselo al comercio. Son cosas
  // distintas y para "¿puede emitir?" manda la primera.
  const certOk = body.certUploaded === true || body.certStored === true
  const cscOk = body.cscStored === true

  if (certOk && cscOk) {
    return {
      id,
      title,
      state: "listo",
      detail: "Certificado de firma y CSC cargados.",
      agentActions: [],
      where,
    }
  }

  const faltan: string[] = []
  if (!certOk) faltan.push("certificado de firma (.p12) y su contraseña")
  if (!cscOk) faltan.push("código de seguridad del contribuyente (CSC): identificador y clave")

  return {
    id,
    title,
    state: "falta",
    detail:
      `Falta cargar: ${faltan.join(" y ")}. ` +
      "Son secretos fiscales: NO se los pidas por el chat ni los recibas como archivo adjunto — " +
      "el comercio los carga él mismo en Ajustes → Facturación electrónica, donde viajan directo al servidor. " +
      "Los consigue en el portal de la autoridad tributaria.",
    // Sin `missing`: no son datos que el bot tenga que pedir.
    agentActions: [],
    where,
  }
}

/**
 * Deriva el estado completo. PURA: no toca la red, así que se prueba con
 * respuestas de ejemplo sin mockear nada.
 *
 * El orden es el de DEPENDENCIA: sin identidad fiscal no hay padrón que
 * consultar, sin caja con timbrado el alta del emisor falla, y el certificado
 * se aplica sobre un emisor que ya existe.
 */
export function deriveEinvoiceSetup(sources: {
  settings: unknown
  registers: unknown
  account: unknown
}): EinvoiceSetup {
  const steps: EinvoiceStep[] = [
    checkFiscalIdentity(sources.settings),
    checkRegisterStamps(sources.registers),
    checkProvisioning(sources.account),
    checkSecrets(sources.account),
  ]

  const done = steps.filter((s) => s.state === "listo").length
  const pending = steps.filter((s) => s.state === "falta").length

  return {
    allDone: done === steps.length,
    done,
    pending,
    unreadable: steps.filter((s) => s.state === "no se pudo leer").length,
    nextStep: steps.find((s) => s.state === "falta")?.id ?? null,
    steps,
  }
}

// ── La tool ──────────────────────────────────────────────────────────────────

/**
 * Lectura cruda de un endpoint que el catálogo compartido no expone.
 *
 * Tenant-level: credencial pelada, sin `X-Outlet-Id`. La cuenta de facturación
 * es una sola para toda la empresa y las cajas se listan enteras — scopearlas
 * por la sucursal elegida afirmaría un recorte que esos datos no tienen.
 */
async function fetchRaw(apiUrl: string, authHeader: string, path: string): Promise<unknown> {
  try {
    const res = await fetch(`${apiUrl}${path}`, { headers: { Authorization: authHeader } })
    if (!res.ok) return { error: `Error ${res.status}` }
    const json = (await res.json()) as { data?: unknown }
    return json?.data ?? json
  } catch (err) {
    return { error: String(err) }
  }
}

/**
 * Construye la tool.
 *
 * Se registra en el route del panel Y en el del MCP, a diferencia de
 * `get_setup_status`. No es una inconsistencia: aquel es el checklist general
 * del onboarding, y este es la lectura que hace falta para el caso de uso que
 * M7 vino a habilitar —configurar la facturación electrónica POR MCP—, donde
 * `punto_register_actions` ya puede escribir `set_fiscal_data` y
 * `provision_einvoice`. Dejar la escritura sin su lectura obligaría al modelo
 * del cliente a proponer pasos a ciegas, que es exactamente lo que el embudo
 * de confirmación existe para evitar.
 */
export function buildEinvoiceSetupTool(ctx: ToolContext) {
  const read = buildReadTools(ctx)

  return {
    get_einvoice_setup: defineTool({
      description:
        "Estado de la facturación electrónica del comercio: qué está configurado y qué falta para que pueda emitir. " +
        "Usala cuando el usuario pida configurar la facturación electrónica, pregunte si ya puede facturar, o antes de registrar set_fiscal_data o provision_einvoice — así no proponés cargar algo que ya está. " +
        "Devuelve los pasos en orden de dependencia (datos fiscales, caja con autorización para facturar, alta del emisor, certificado y CSC) y, en cada uno que falta, qué datos pedirle al usuario y qué acción lo resuelve. " +
        "Del certificado y del CSC devuelve solo si están cargados o no: son secretos y no se piden por el chat. " +
        "El estado se calcula en el momento, así que refleja también lo que se haya configurado desde el panel.",
      inputSchema: z.object({}),
      execute: async () => {
        // En paralelo: tres lecturas independientes, encadenarlas triplicaría
        // la espera de una sola pregunta.
        const [settings, registers, account] = await Promise.all([
          read.get_settings.execute({}),
          fetchRaw(ctx.apiUrl, ctx.authHeader, "/v1/register?resource=listAll"),
          fetchRaw(ctx.apiUrl, ctx.authHeader, "/v1/einvoice?resource=account"),
        ])

        return deriveEinvoiceSetup({ settings, registers, account })
      },
    }),
  }
}
