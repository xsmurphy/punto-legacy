import { describe, expect, it } from "vitest"

import { buildEinvoiceSetupTool, deriveEinvoiceSetup, type EinvoiceStep } from "@/lib/agent/einvoice-setup"
import { buildReadOnlyFetchTools } from "@/lib/agent/read-tools"

/**
 * `get_einvoice_setup` (M7 de `context/58`, `context/66 §FE`) DERIVA el estado
 * de la facturación electrónica de tres lecturas. Lo que hay que fijar no es
 * que el código corra, sino que la derivación diga la verdad en los estados de
 * en medio — que son los que un comercio real atraviesa y los que no se pueden
 * reproducir a mano en producción.
 *
 * Los dos casos que este test protege de verdad:
 *
 *  - RUC cargado y razón social VACÍA. Es el estado que hasta el 2026-09-06 se
 *    tapaba con el nombre comercial del negocio, y es un bug fiscal: el
 *    organismo valida la razón social contra el padrón del identificador
 *    tributario. Acá tiene que salir "falta", nunca "listo".
 *  - Caja activa SIN autorización para facturar. Existe, opera, y aun así el
 *    alta del emisor va a fallar. Un chequeo que solo contara cajas la daría
 *    por resuelta.
 *
 * El país NUNCA es Paraguay en estos fixtures: la regla del proyecto es que
 * nada asuma un mercado, y un fixture paraguayo por default deja pasar
 * justamente el bug que la regla prohíbe.
 */

const ctx = {
  apiUrl: "https://api.example.test",
  dataHeaders: { Authorization: "Bearer x", "X-Outlet-Id": "outlet-1" },
  authHeader: "Bearer x",
}

function byId(steps: EinvoiceStep[], id: string): EinvoiceStep {
  const step = steps.find((s) => s.id === id)
  if (!step) throw new Error(`paso inexistente: ${id}`)
  return step
}

/**
 * Los fixtures se tipan FLOJO a propósito, igual que la derivación: son
 * payloads de red, y un tipo estrecho inferido del literal haría que cada
 * variante del caso base (agregarle el RUC, vaciarle el bloque fiscal a una
 * caja) no compile — obligando a escribir los fixtures completos de nuevo en
 * vez de decir en una línea qué cambia respecto del caso base.
 */
type Sources = {
  settings: Record<string, unknown>
  registers: Record<string, unknown>
  account: Record<string, unknown>
}

/** Cuenta recién creada: nada de facturación electrónica configurado. */
function emptySources(): Sources {
  return {
    // La normalización poda los campos vacíos, así que un negocio sin datos
    // fiscales llega con las claves AUSENTES, no con strings vacíos.
    settings: { name: "Almacén Rivadavia", country: "AR", currency: "$" },
    registers: { registers: [] },
    account: { configured: false, provisioned: false, status: "unconfigured" },
  }
}

/** Todo configurado, en un mercado no paraguayo. */
function completeSources(): Sources {
  return {
    settings: {
      name: "Almacén Rivadavia",
      billingName: "RIVADAVIA S.R.L.",
      ruc: "30-71234567-9",
      country: "AR",
    },
    registers: {
      registers: [
        { id: "r1", name: "Caja 1", status: 1, fiscal: { invoiceAuth: "16778831", invoicePrefix: "001-001" } },
      ],
    },
    account: {
      configured: true,
      provisioned: true,
      status: "ok",
      certUploaded: true,
      cscStored: true,
      lastError: null,
    },
  }
}

describe("deriveEinvoiceSetup", () => {
  it("una cuenta nueva tiene los cuatro pasos pendientes y arranca por los datos fiscales", () => {
    const setup = deriveEinvoiceSetup(emptySources())
    expect(setup.allDone).toBe(false)
    expect(setup.pending).toBe(4)
    expect(setup.unreadable).toBe(0)
    expect(setup.nextStep).toBe("datos_fiscales")
  })

  it("una cuenta configurada del todo no reporta nada pendiente", () => {
    const setup = deriveEinvoiceSetup(completeSources())
    expect(setup.allDone).toBe(true)
    expect(setup.nextStep).toBeNull()
  })

  it("nombra el identificador tributario como su mercado, no como el nuestro", () => {
    const setup = deriveEinvoiceSetup(emptySources())
    const paso = byId(setup.steps, "datos_fiscales")
    // Tenant argentino: le pide CUIT. Sale de `resolveTaxIdLabel`, no de un
    // literal — si alguien hardcodeara un nombre de otro mercado, esto rompe.
    expect(paso.missing?.join(" ")).toMatch(/CUIT/)
  })

  it("respeta la etiqueta que el comercio configuró a mano por encima de la de su país", () => {
    const sources = emptySources()
    sources.settings = { ...sources.settings, tin: "Tax ID" }
    expect(byId(deriveEinvoiceSetup(sources).steps, "datos_fiscales").missing?.join(" ")).toMatch(/Tax ID/)
  })

  it("con identificador tributario pero SIN razón social, los datos fiscales NO están listos", () => {
    // El bug fiscal de 2026-09-06: la razón social vacía se tapaba con el
    // nombre comercial. Acá tiene que quedar pendiente y resolverse volviendo
    // a consultar el padrón, nunca escribiéndola a mano.
    const sources = emptySources()
    sources.settings = { ...sources.settings, ruc: "30-71234567-9" }
    const paso = byId(deriveEinvoiceSetup(sources).steps, "datos_fiscales")
    expect(paso.state).toBe("falta")
    expect(paso.agentActions).toContain("lookup_taxpayer")
    expect(paso.detail).toMatch(/razón social/i)
  })

  it("la razón social nunca se le pide al usuario: no entra en lo que hay que preguntar", () => {
    const paso = byId(deriveEinvoiceSetup(emptySources()).steps, "datos_fiscales")
    expect(paso.missing?.join(" ")).not.toMatch(/razón social/i)
  })

  it("una caja activa sin autorización para facturar deja el paso pendiente", () => {
    const sources = completeSources()
    sources.registers = { registers: [{ id: "r1", name: "Caja 1", status: 1, fiscal: {} }] }
    const paso = byId(deriveEinvoiceSetup(sources).steps, "caja_con_timbrado")
    expect(paso.state).toBe("falta")
    expect(paso.agentActions).toContain("create_register")
  })

  it("una caja con timbrado pero SIN punto de expedición tampoco habilita", () => {
    // La unicidad fiscal es del PAR (context/29): media configuración no emite.
    const sources = completeSources()
    sources.registers = {
      registers: [{ id: "r1", name: "Caja 1", status: 1, fiscal: { invoiceAuth: "16778831" } }],
    }
    expect(byId(deriveEinvoiceSetup(sources).steps, "caja_con_timbrado").state).toBe("falta")
  })

  it("una caja DADA DE BAJA con timbrado no cuenta como habilitada", () => {
    const sources = completeSources()
    sources.registers = {
      registers: [
        { id: "r1", name: "Caja vieja", status: 0, fiscal: { invoiceAuth: "16778831", invoicePrefix: "001-001" } },
      ],
    }
    expect(byId(deriveEinvoiceSetup(sources).steps, "caja_con_timbrado").state).toBe("falta")
  })

  it("un alta a medias se distingue de una que nunca se intentó", () => {
    const sources = completeSources()
    sources.account = { ...sources.account, provisioned: false }
    const paso = byId(deriveEinvoiceSetup(sources).steps, "emisor")
    expect(paso.state).toBe("falta")
    expect(paso.detail).toMatch(/a medias/i)
    expect(paso.agentActions).toContain("provision_einvoice")
  })

  it("un emisor creado cuya verificación falló reporta el motivo real", () => {
    const sources = completeSources()
    sources.account = { ...sources.account, status: "error", lastError: "Error de Certificado o CSC" }
    const paso = byId(deriveEinvoiceSetup(sources).steps, "emisor")
    expect(paso.state).toBe("falta")
    expect(paso.detail).toContain("Error de Certificado o CSC")
  })

  it("los secretos no son acciones del agente y le dice al modelo que no los pida", () => {
    const sources = completeSources()
    sources.account = { ...sources.account, certUploaded: false, cscStored: false }
    const paso = byId(deriveEinvoiceSetup(sources).steps, "certificado_y_csc")
    expect(paso.state).toBe("falta")
    // Vacío SIEMPRE: no hay acción del catálogo que cargue un secreto fiscal,
    // ni la va a haber. Si algún día esto trae una acción, es que alguien metió
    // el certificado o el CSC en el contexto del modelo.
    expect(paso.agentActions).toEqual([])
    // Tampoco son datos que el bot tenga que pedir por chat.
    expect(paso.missing).toBeUndefined()
    expect(paso.detail).toMatch(/NO se los pidas por el chat/)
  })

  it("una lectura que falló no se reporta como pendiente", () => {
    // Decirle a un comercio que le falta cargar el emisor porque el endpoint
    // devolvió 500 lo manda a dar de alta uno que ya existe.
    const sources = completeSources()
    sources.account = { error: "Error 500" }
    const setup = deriveEinvoiceSetup(sources)
    expect(byId(setup.steps, "emisor").state).toBe("no se pudo leer")
    expect(byId(setup.steps, "certificado_y_csc").state).toBe("no se pudo leer")
    expect(setup.unreadable).toBe(2)
    expect(setup.allDone).toBe(false)
  })
})

describe("deriveEinvoiceSetup — desde qué número emite cada caja", () => {
  const caja = (over: Record<string, unknown> = {}) => ({
    registerId: "reg-1",
    registerName: "Caja 1",
    outletName: "Central",
    invoiceAuth: "12345678",
    invoicePrefix: "001-001",
    current: 1,
    floor: 1,
    proposal: null,
    source: null,
    needsAnswer: false,
    detail: "",
    question: "",
    ...over,
  })

  it("no agrega el paso si no hay numeración que leer (emisor todavía inexistente)", () => {
    // Sin emisor no hay contra qué resolver el número. El checklist tiene que
    // quedar como estaba, no sumar un paso en rojo que nadie puede cerrar.
    const setup = deriveEinvoiceSetup(completeSources())
    expect(setup.steps.map((s) => s.id)).not.toContain("numeracion")
  })

  it("queda LISTO cuando toda caja tiene de dónde deducir su próximo número", () => {
    const sources = { ...completeSources(), numbering: { registers: [caja({ current: 616, source: "emitter", proposal: 616 })] } }
    const paso = byId(deriveEinvoiceSetup(sources).steps, "numeracion")
    expect(paso.state).toBe("listo")
    expect(paso.detail).toContain("616")
    // Nada que preguntar: el alta ya lo dejó aplicado.
    expect(paso.agentActions).toEqual([])
  })

  it("pide el último emitido —textual— cuando es una migración", () => {
    // El caso del owner: emisor nuevo cuyo contador arranca en 1 contra un
    // talonario de papel que venía en 614. Ese número no existe en ningún
    // sistema, así que la única salida correcta es preguntarlo.
    const pregunta = "¿Cuál fue la ÚLTIMA factura que emitiste con el talonario de la caja \"Caja 1\" (timbrado 12345678 · 001-001)?"
    const sources = {
      ...completeSources(),
      numbering: { registers: [caja({ needsAnswer: true, question: pregunta })] },
    }
    const paso = byId(deriveEinvoiceSetup(sources).steps, "numeracion")
    expect(paso.state).toBe("falta")
    expect(paso.agentActions).toEqual(["set_register_numbering"])
    // La pregunta viaja TAL CUAL: el bot la hace en la conversación en vez de
    // mandar al usuario a otra pantalla.
    expect(paso.missing).toEqual([pregunta])
    // Y el modelo tiene que saber que estimar acá no es una opción.
    expect(paso.detail).toMatch(/NUNCA lo estimes/)
  })

  it("una lectura que falló no se reporta como pendiente", () => {
    const sources = { ...completeSources(), numbering: { error: "Error 500" } }
    expect(byId(deriveEinvoiceSetup(sources).steps, "numeracion").state).toBe("no se pudo leer")
  })
})

describe("get_einvoice_setup — registro de la tool", () => {
  it("se construye con una descripción que le dice al modelo cuándo usarla", () => {
    const tools = buildEinvoiceSetupTool(ctx)
    expect(Object.keys(tools)).toEqual(["get_einvoice_setup"])
    expect(tools.get_einvoice_setup.description.length).toBeGreaterThan(120)
  })

  it("NO vive en el catálogo compartido: es una lectura de configuración", () => {
    // Se registra en los routes (panel y MCP), no en `read-tools.ts`. Si
    // apareciera acá sería porque alguien la movió, y el POS —que arma su set
    // filtrando ese mismo catálogo— podría terminar ofreciéndosela a un cajero.
    expect(Object.keys(buildReadOnlyFetchTools(ctx))).not.toContain("get_einvoice_setup")
  })
})
