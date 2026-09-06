import { z } from "zod"

import { buildReadTools, defineTool, type ToolContext } from "@/lib/agent/read-tools"
import { resolveTaxIdLabel } from "@/lib/tenant-locale"

/**
 * `get_setup_status` — qué le falta configurar a la cuenta para poder operar.
 *
 * F4 de `context/66-onboarding-conducido-por-el-agente.md`. El objetivo de
 * negocio es el onboarding: la configuración inicial es donde se pierden los
 * clientes, y el bot no puede conducirla si no sabe en qué punto está el
 * comercio.
 *
 * ── DERIVADO, nunca persistido ──────────────────────────────────────────────
 * Decisión cerrada del plan (§Arquitecturas rechazadas): no hay tabla de
 * progreso. Un checklist guardado se desincroniza del estado real la primera
 * vez que alguien crea una caja desde el panel mientras el bot cree que falta
 * — y el que se equivoca en ese caso es el bot, que va a ofrecer crear algo que
 * ya existe. Todo lo de acá sale de leer el estado en el momento de preguntar.
 *
 * ── Se apoya en el catálogo compartido, no lo duplica ───────────────────────
 * Cuatro de las seis lecturas son las MISMAS tools que usa el agente para
 * responder cualquier otra cosa (`buildReadTools`). Reimplementar los fetches
 * acá habría creado una segunda definición de "qué es una sucursal" que se
 * desincroniza igual que el checklist persistido.
 *
 * Las otras dos van directo al endpoint porque el catálogo NO las expone:
 *
 *   - CAJAS: `get_drawers` suena a esto y no lo es — devuelve TURNOS de caja
 *     (aperturas y cierres de un período), no las cajas configuradas del
 *     comercio. El dato que hace falta acá es el timbrado de cada caja, que
 *     vive en `/v1/register?resource=listAll` (el mismo endpoint que usa el
 *     panel en `useRegistersAdmin`).
 *   - IMPUESTOS: no hay tool de lectura de `/v1/taxes`.
 *
 * Sus respuestas NO viajan al modelo: de las dos salen booleanos y conteos, así
 * que no pasan por el normalizador — que existe para traducirle el vocabulario
 * interno a un modelo que las va a leer.
 *
 * ── El checklist NO afirma lo que no pudo leer ──────────────────────────────
 * Cada chequeo puede terminar en `"no se pudo leer"`. Decirle a un comercio que
 * le falta una sucursal porque el endpoint devolvió 500 es peor que no
 * responder: lo manda a crear una segunda sucursal encima de la que ya tiene.
 *
 * ── Cero país hardcodeado ───────────────────────────────────────────────────
 * El identificador fiscal se llama distinto en cada mercado (RUC, CUIT, RFC,
 * CNPJ…) y la etiqueta sale de `resolveTaxIdLabel`, que ya es la única fuente
 * de esa dimensión en el proyecto: ajuste del tenant → país del tenant →
 * genérico. El número de autorización para facturar tiene el mismo problema y
 * NO tiene catálogo por país, así que se nombra de forma neutra ("autorización
 * para facturar", "punto de expedición") en vez de inventarle a un comercio de
 * otro mercado un término que no usa.
 */

// ── Forma del resultado ──────────────────────────────────────────────────────

/**
 * Estado de un chequeo, en palabras.
 *
 * Nada de `done: 1` ni de enums numéricos: esto lo lee un modelo que después se
 * lo explica al dueño del comercio, y el vocabulario interno es exactamente lo
 * que el normalizador de `normalize-tool-result.ts` existe para eliminar.
 */
export type CheckState = "listo" | "falta" | "no se pudo leer"

export interface SetupCheck {
  /** Id estable para que el modelo pueda referirse a un ítem sin ambigüedad. */
  id: string
  /** Título corto, para mostrarle al usuario tal cual. */
  title: string
  state: CheckState
  /** Una frase: qué está resuelto o qué falta exactamente. */
  detail: string
  /**
   * Los datos concretos que faltan, cuando el chequeo puede desglosarlos. Es
   * lo que convierte el checklist en una pregunta ("dame el nombre de los
   * usuarios y el número de autorización de las cajas") en vez de un reporte.
   */
  missing?: string[]
  /**
   * Qué acción del agente lo resuelve. Vacío significa que el agente NO puede
   * hacerlo — no que el sistema no lo soporte: `where` dice dónde se hace.
   */
  agentActions: string[]
  /** Dónde se configura a mano, para lo que el agente no puede hacer. */
  where: string
}

export interface SetupStatus {
  /** Todos los chequeos en `"listo"`. */
  allDone: boolean
  done: number
  pending: number
  /** Chequeos cuya fuente no se pudo leer: ni resueltos ni pendientes. */
  unreadable: number
  /** Id del primer chequeo pendiente, en orden de dependencia. */
  nextStep: string | null
  checks: SetupCheck[]
}

// ── Lectura de los payloads ──────────────────────────────────────────────────

/**
 * Las respuestas que alimentan la derivación.
 *
 * `unknown` a propósito: son payloads de red y cualquier tipado más fuerte acá
 * sería una promesa que el runtime no sostiene. La derivación es total —
 * cualquier forma inesperada cae en `"no se pudo leer"`.
 */
export interface SetupSources {
  /** `get_settings` (normalizada). */
  settings: unknown
  /** `get_outlets` (normalizada). */
  outlets: unknown
  /** `get_users` (normalizada). */
  users: unknown
  /** `get_items` (normalizada). */
  items: unknown
  /** `/v1/register?resource=listAll`, cruda. */
  registers: unknown
  /** `/v1/taxes`, cruda. */
  taxes: unknown
}

type Row = Record<string, unknown>

function isRecord(v: unknown): v is Row {
  return typeof v === "object" && v !== null && !Array.isArray(v)
}

/**
 * Una lectura fallida. El contrato de error del catálogo es `{ error }`, y
 * `undefined`/`null` cubren el caso de que el fetch ni siquiera haya salido.
 */
function failed(payload: unknown): boolean {
  return payload === null || payload === undefined || (isRecord(payload) && "error" in payload)
}

/**
 * Saca el payload real del sobre `{ meta, data }` que arma `withMeta`.
 *
 * El sobre aparece SOLO cuando hay algo que declarar (moneda, recorte, notas),
 * así que la misma tool devuelve a veces el valor pelado y a veces envuelto.
 * Consumirlo sin desenvolver funcionaría hasta el día que un campo de monto
 * entre en la respuesta.
 */
function unwrap(payload: unknown): unknown {
  if (isRecord(payload) && "data" in payload && "meta" in payload) return payload.data
  return payload
}

/** Filas de una lista, venga como array pelado o bajo su clave de sobre. */
function rowsFrom(payload: unknown, key: string): Row[] | null {
  const value = unwrap(payload)
  if (Array.isArray(value)) return value.filter(isRecord)
  if (isRecord(value)) {
    const list = value[key]
    if (Array.isArray(list)) return list.filter(isRecord)
  }
  return null
}

/**
 * Si una fila está activa.
 *
 * El diccionario de `tool-field-rules.ts` renombra `status: 0|1` a un booleano
 * `active`, así que las filas normalizadas traen `active` y las crudas (cajas)
 * traen `status`. Se aceptan las dos formas en vez de asumir una: son la misma
 * dimensión y cuál llega depende de por dónde entró el dato.
 *
 * Sin ninguna de las dos, la fila cuenta como activa: el estado es una
 * excepción (dar de baja), y tratar su ausencia como "inactiva" escondería
 * sucursales o cajas que el comercio sí tiene.
 */
function isActive(row: Row): boolean {
  if (typeof row.active === "boolean") return row.active
  if (typeof row.status === "boolean") return row.status
  if (typeof row.status === "number") return row.status === 1
  return true
}

function text(value: unknown): string {
  return typeof value === "string" ? value.trim() : ""
}

// ── Los chequeos ─────────────────────────────────────────────────────────────

/** Chequeo que no se pudo evaluar porque su fuente falló. */
function unreadable(
  id: string,
  title: string,
  what: string,
  agentActions: string[],
  where: string,
): SetupCheck {
  return {
    id,
    title,
    state: "no se pudo leer",
    detail: `No se pudo leer ${what}, así que no se sabe si está configurado.`,
    agentActions,
    where,
  }
}

/**
 * 1 — Datos del negocio: los que salen impresos en cada comprobante.
 *
 * Es el primero de la cadena porque no depende de nada y porque sin razón
 * social ni identificador fiscal, todo lo que se emita después sale mal.
 *
 * El agente NO tiene acción para esto: la configuración de la empresa no está
 * en el allowlist de `/v1/ai/confirm`. Se declara dónde se hace, que es
 * distinto de que el sistema no lo soporte.
 */
function checkCompanyProfile(settings: unknown): SetupCheck {
  const id = "company_profile"
  const title = "Datos del negocio"
  const where = "Configuración → Empresa (/settings?section=empresa)"

  if (failed(settings)) {
    return unreadable(id, title, "la configuración del negocio", [], where)
  }
  const s = unwrap(settings)
  if (!isRecord(s)) {
    return unreadable(id, title, "la configuración del negocio", [], where)
  }

  // La etiqueta del documento fiscal sale del tenant, nunca de un default: un
  // comercio argentino tiene que leer "CUIT", no el nombre paraguayo.
  const taxIdLabel = resolveTaxIdLabel({ tinName: text(s.tin), country: text(s.country) })

  const missing: string[] = []
  if (text(s.name) === "") missing.push("nombre del negocio")
  if (text(s.country) === "") missing.push("país")
  // `billingName` es la razón social FISCAL y no tiene fallback: el nombre
  // comercial ("Balloon Party") y la razón social ("BALLOON PARTY S.A.") son
  // datos distintos, y la SET valida la segunda contra el padrón del RUC.
  //
  // Hasta 2026-09-06 esto exigía que faltaran las DOS para reclamar, con un
  // comentario que declaraba correcto el fallback — así que el asistente no
  // reclamaba nunca una razón social ausente y el emisor terminaba
  // registrándose con el nombre de fantasía. El mismo fallback estaba en
  // `EInvoiceProvisioningService::companyFiscal()` y en `CompanyFiscalSummary`
  // (components/settings/einvoice-manager.tsx); se sacó en los tres.
  if (text(s.billingName) === "") missing.push("razón social")
  if (text(s.ruc) === "") missing.push(taxIdLabel)

  if (missing.length === 0) {
    return {
      id,
      title,
      state: "listo",
      detail: `Razón social y ${taxIdLabel} cargados.`,
      agentActions: [],
      where,
    }
  }
  return {
    id,
    title,
    state: "falta",
    detail: `Faltan datos del negocio: ${missing.join(", ")}.`,
    missing,
    agentActions: [],
    where,
  }
}

/** 2 — Al menos una sucursal activa. Todo lo demás cuelga de acá. */
function checkOutlets(outlets: unknown): SetupCheck {
  const id = "outlets"
  const title = "Sucursales"
  const where = "Sucursales (/outlets)"
  const rows = failed(outlets) ? null : rowsFrom(outlets, "rows")

  if (rows === null) return unreadable(id, title, "las sucursales", ["create_outlet"], where)

  const active = rows.filter(isActive)
  if (active.length === 0) {
    return {
      id,
      title,
      state: "falta",
      detail: "El negocio no tiene ninguna sucursal activa.",
      missing: ["nombre de la sucursal"],
      agentActions: ["create_outlet"],
      where,
    }
  }
  return {
    id,
    title,
    state: "listo",
    detail: `${active.length} sucursal(es) activa(s).`,
    agentActions: [],
    where,
  }
}

/**
 * 3 — Al menos una caja habilitada para facturar.
 *
 * La caja ES el punto de expedición fiscal (`context/29`): sin su número de
 * autorización y su punto de expedición no emite un comprobante, así que una
 * caja creada y vacía no cuenta como resuelta. Es el hueco real del onboarding
 * — el alta de la cuenta ya crea una caja, pero sin ningún dato fiscal.
 */
function checkRegisters(registers: unknown): SetupCheck {
  const id = "registers"
  const title = "Caja habilitada para facturar"
  const where = "Sucursales → la sucursal → Cajas (/outlets)"
  const rows = failed(registers) ? null : rowsFrom(registers, "registers")

  if (rows === null) return unreadable(id, title, "las cajas", ["create_register"], where)

  const active = rows.filter(isActive)
  if (active.length === 0) {
    return {
      id,
      title,
      state: "falta",
      detail: "El negocio no tiene ninguna caja activa.",
      missing: [
        "sucursal donde va la caja",
        "nombre de la caja",
        "número de autorización para facturar",
        "punto de expedición",
      ],
      agentActions: ["create_register"],
      where,
    }
  }

  const ready = active.filter((r) => {
    const fiscal = isRecord(r.fiscal) ? r.fiscal : {}
    return text(fiscal.invoiceAuth) !== "" && text(fiscal.invoicePrefix) !== ""
  })
  if (ready.length === 0) {
    return {
      id,
      title,
      state: "falta",
      detail:
        `Hay ${active.length} caja(s) activa(s), pero ninguna tiene cargada la autorización ` +
        "para facturar con su punto de expedición, así que ninguna puede emitir.",
      missing: ["número de autorización para facturar", "punto de expedición"],
      agentActions: ["create_register"],
      where,
    }
  }
  return {
    id,
    title,
    state: "listo",
    detail: `${ready.length} de ${active.length} caja(s) activa(s) pueden emitir.`,
    agentActions: [],
    where,
  }
}

/**
 * 4 — Alguien que pueda operar la caja.
 *
 * El chequeo es "hay un usuario activo con PIN", no "hay alguien además del
 * dueño": la lista de equipo no marca quién es el dueño (el rol legacy '1'
 * convive con roles propios del comercio), así que decidir eso sería adivinar.
 * Lo que sí es verificable es lo que importa para abrir el mostrador — el PIN
 * de 4 dígitos es lo que desbloquea la caja.
 *
 * Del PIN se deriva un booleano y nada más: el hash NUNCA sale de acá. Son 4
 * dígitos (10.000 combinaciones) y mandarlo al modelo sería regalar la
 * identidad del operador en la caja.
 */
function checkTeam(users: unknown): SetupCheck {
  const id = "team"
  const title = "Equipo con PIN de caja"
  const where = "Equipo (/contacts?type=0)"
  const rows = failed(users) ? null : rowsFrom(users, "users")

  if (rows === null) return unreadable(id, title, "el equipo", ["create_user"], where)

  const active = rows.filter(isActive)
  const withPin = active.filter((u) => text(u.pinhash) !== "")

  if (withPin.length > 0) {
    return {
      id,
      title,
      state: "listo",
      detail: `${withPin.length} de ${active.length} persona(s) del equipo tienen PIN de caja.`,
      agentActions: [],
      where,
    }
  }
  if (active.length === 0) {
    return {
      id,
      title,
      state: "falta",
      detail: "No hay ninguna persona activa en el equipo.",
      missing: ["nombre de la persona", "rol", "PIN de 4 dígitos que elija esa persona"],
      agentActions: ["create_user"],
      where,
    }
  }
  return {
    id,
    title,
    state: "falta",
    detail:
      `Hay ${active.length} persona(s) en el equipo, pero ninguna tiene PIN de caja: ` +
      "nadie puede desbloquear el mostrador.",
    missing: ["PIN de 4 dígitos que elija cada persona"],
    agentActions: ["create_user"],
    where,
  }
}

/**
 * 5 — Impuestos del comercio.
 *
 * El alta de la cuenta siembra el impuesto del país elegido, así que este
 * chequeo normalmente sale resuelto; existe porque un comercio que los borró
 * no puede facturar y ese estado es invisible desde cualquier otra pantalla.
 * No hay acción del agente: crear impuestos no está en el allowlist.
 */
function checkTaxes(taxes: unknown): SetupCheck {
  const id = "taxes"
  const title = "Impuestos"
  const where = "Catálogo → Impuestos (/settings/catalog?tab=taxes)"
  const rows = failed(taxes) ? null : rowsFrom(taxes, "taxes")

  if (rows === null) return unreadable(id, title, "los impuestos", [], where)

  if (rows.length === 0) {
    return {
      id,
      title,
      state: "falta",
      detail: "El negocio no tiene ningún impuesto configurado.",
      missing: ["nombre y tasa del impuesto"],
      agentActions: [],
      where,
    }
  }
  return {
    id,
    title,
    state: "listo",
    detail: `${rows.length} impuesto(s) configurado(s).`,
    agentActions: [],
    where,
  }
}

/**
 * 6 — Catálogo con algo para vender.
 *
 * Sin conteo total a propósito: `/v1/items` devuelve como mucho `limit` filas y
 * no informa el total, así que un número acá sería el tamaño de la muestra
 * presentado como el del catálogo.
 */
function checkCatalog(items: unknown): SetupCheck {
  const id = "catalog"
  const title = "Catálogo"
  const where = "Catálogo (/items)"
  const rows = failed(items) ? null : rowsFrom(items, "items")

  if (rows === null) {
    return unreadable(id, title, "el catálogo", ["create_item", "tabular_import"], where)
  }
  if (rows.length === 0) {
    return {
      id,
      title,
      state: "falta",
      detail: "El catálogo está vacío: no hay nada para vender.",
      missing: ["nombre y precio de cada artículo (o una planilla con el catálogo)"],
      agentActions: ["create_item", "tabular_import"],
      where,
    }
  }
  return {
    id,
    title,
    state: "listo",
    detail: "El catálogo tiene artículos cargados.",
    agentActions: [],
    where,
  }
}

/**
 * Deriva el checklist completo. PURA: no toca la red, así que se testea con
 * respuestas de ejemplo sin mockear nada.
 *
 * El orden es el de DEPENDENCIA, no el de importancia: una caja necesita su
 * sucursal, y el equipo necesita una caja que desbloquear. `nextStep` es el
 * primer pendiente de esa cadena, que es por dónde tiene que empezar el bot.
 */
export function deriveSetupStatus(sources: SetupSources): SetupStatus {
  const checks: SetupCheck[] = [
    checkCompanyProfile(sources.settings),
    checkOutlets(sources.outlets),
    checkRegisters(sources.registers),
    checkTeam(sources.users),
    checkTaxes(sources.taxes),
    checkCatalog(sources.items),
  ]

  const done = checks.filter((c) => c.state === "listo").length
  const pending = checks.filter((c) => c.state === "falta").length
  const unreadableCount = checks.filter((c) => c.state === "no se pudo leer").length

  return {
    allDone: done === checks.length,
    done,
    pending,
    unreadable: unreadableCount,
    nextStep: checks.find((c) => c.state === "falta")?.id ?? null,
    checks,
  }
}

// ── La tool ──────────────────────────────────────────────────────────────────

/**
 * Lectura cruda de un endpoint que el catálogo no expone.
 *
 * Tenant-level: va con la credencial pelada y SIN `X-Outlet-Id`. Cajas e
 * impuestos son de toda la empresa, y scopearlos por la sucursal elegida en el
 * panel afirmaría un recorte que esos datos no tienen — es la misma distinción
 * que `get_settings` hace en el catálogo.
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
 * Construye la tool. Se registra SOLO en el route del panel: es una lectura de
 * onboarding —la hace el dueño configurando su cuenta— y no parte del catálogo
 * compartido que sirve el MCP a clientes externos.
 */
export function buildSetupStatusTool(ctx: ToolContext) {
  const read = buildReadTools(ctx)

  return {
    get_setup_status: defineTool({
      description:
        "Qué le falta configurar a la cuenta para poder operar. Usala cuando el usuario pida ayuda para configurar su negocio, diga que la cuenta es nueva, o pregunte qué le falta. " +
        "Devuelve un checklist en orden de dependencia (datos del negocio, sucursales, caja habilitada para facturar, equipo con PIN, impuestos, catálogo) y, en cada ítem que falta, qué datos hay que pedirle al usuario y qué acción lo resuelve. " +
        "El estado se calcula en el momento: no hay progreso guardado, así que refleja también lo que el usuario haya configurado desde el panel.",
      inputSchema: z.object({}),
      execute: async () => {
        // En paralelo: son seis lecturas independientes y encadenarlas
        // multiplicaría por seis la espera de una sola pregunta.
        const [settings, outlets, users, items, registers, taxes] = await Promise.all([
          read.get_settings.execute({}),
          read.get_outlets.execute({}),
          read.get_users.execute({}),
          read.get_items.execute({ limit: 1 }),
          fetchRaw(ctx.apiUrl, ctx.authHeader, "/v1/register?resource=listAll"),
          fetchRaw(ctx.apiUrl, ctx.authHeader, "/v1/taxes"),
        ])

        return deriveSetupStatus({ settings, outlets, users, items, registers, taxes })
      },
    }),
  }
}
