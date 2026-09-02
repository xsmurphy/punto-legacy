import { describe, expect, it } from "vitest"

import { buildReadOnlyFetchTools } from "@/lib/agent/read-tools"
import {
  buildSetupStatusTool,
  deriveSetupStatus,
  type SetupCheck,
  type SetupSources,
} from "@/lib/agent/setup-status"

/**
 * El checklist de onboarding (`context/66` F4) se DERIVA de lecturas, así que
 * lo que hay que fijar no es que el código corra sino que la derivación diga la
 * verdad sobre estados que no se pueden reproducir a mano en producción: una
 * cuenta recién creada, una configurada del todo, y —sobre todo— las de en
 * medio, que son en las que un checklist mal derivado hace daño.
 *
 * El caso que este test protege de verdad es la caja SIN autorización para
 * facturar: existe, está activa, y aun así el comercio no puede emitir. Un
 * chequeo que solo contara cajas la daría por resuelta y el onboarding
 * terminaría con un negocio que no factura.
 *
 * El país NUNCA es Paraguay en estos fixtures salvo cuando el caso es
 * justamente ese: la regla del proyecto es que nada asuma un mercado, y un
 * fixture paraguayo por default deja pasar exactamente el bug que la regla
 * prohíbe.
 */

const ctx = {
  apiUrl: "https://api.example.test",
  dataHeaders: { Authorization: "Bearer x", "X-Outlet-Id": "outlet-1" },
  authHeader: "Bearer x",
}

/** Todo vacío: una cuenta que se acaba de crear y nadie tocó. */
function emptySources(): SetupSources {
  return {
    // La normalización poda los campos vacíos, así que un negocio sin datos
    // llega con las claves AUSENTES y no con strings vacíos.
    settings: { currency: "$", country: "AR" },
    outlets: { rows: [] },
    users: { users: [] },
    items: { items: [] },
    registers: { registers: [] },
    taxes: { taxes: [] },
  }
}

/** Una cuenta configurada de punta a punta, en un mercado no paraguayo. */
function completeSources(): SetupSources {
  return {
    settings: {
      name: "Almacén Rivadavia",
      billingName: "Rivadavia SRL",
      ruc: "30-71234567-9",
      country: "AR",
      currency: "$",
    },
    outlets: { rows: [{ id: "o1", name: "Casa Central", active: true }] },
    users: {
      users: [
        { id: "u1", name: "Ana", active: true, pinhash: "abc123" },
        { id: "u2", name: "Beto", active: true },
      ],
    },
    items: { items: [{ id: "i1", name: "Café" }] },
    registers: {
      registers: [
        {
          id: "r1",
          name: "Caja 1",
          outletName: "Casa Central",
          status: true,
          fiscal: { invoiceAuth: "12345678", invoicePrefix: "001-001" },
        },
      ],
    },
    taxes: { taxes: [{ id: "t1", name: "IVA 21%" }] },
  }
}

function byId(checks: SetupCheck[], id: string): SetupCheck {
  const found = checks.find((c) => c.id === id)
  if (!found) throw new Error(`falta el chequeo ${id}`)
  return found
}

describe("checklist de configuración — cuenta vacía", () => {
  const status = deriveSetupStatus(emptySources())

  it("marca todo como pendiente y arranca por los datos del negocio", () => {
    expect(status.allDone).toBe(false)
    expect(status.done).toBe(0)
    expect(status.pending).toBe(status.checks.length)
    expect(status.unreadable).toBe(0)
    // El orden es de DEPENDENCIA: sin datos del negocio no sirve crear cajas.
    expect(status.nextStep).toBe("company_profile")
  })

  it("nombra el identificador fiscal según el país del tenant, no según Paraguay", () => {
    const company = byId(status.checks, "company_profile")
    // País AR → CUIT. Si esto dijera "RUC", sería la asunción que
    // `lib/tenant-locale` existe para eliminar.
    expect(company.missing).toContain("CUIT")
    expect(company.detail).not.toContain("RUC")
  })

  it("pide los datos concretos que hacen falta para cada acción", () => {
    // Es lo que convierte el checklist en onboarding: el bot lee esto y sabe
    // qué preguntar antes de registrar la acción.
    expect(byId(status.checks, "outlets").agentActions).toEqual(["create_outlet"])
    expect(byId(status.checks, "registers").agentActions).toEqual(["create_register"])
    expect(byId(status.checks, "team").agentActions).toEqual(["create_user"])
    expect(byId(status.checks, "catalog").agentActions).toEqual([
      "create_item",
      "tabular_import",
    ])
    for (const id of ["outlets", "registers", "team", "catalog"]) {
      expect(byId(status.checks, id).missing?.length ?? 0).toBeGreaterThan(0)
    }
  })

  it("no le atribuye al agente lo que el agente no puede hacer", () => {
    // Configurar la empresa e impuestos no está en el allowlist de
    // `/v1/ai/confirm`: se declara dónde se hace, no que no se pueda.
    const company = byId(status.checks, "company_profile")
    expect(company.agentActions).toEqual([])
    expect(company.where).not.toBe("")
    const taxes = byId(status.checks, "taxes")
    expect(taxes.agentActions).toEqual([])
    expect(taxes.where).not.toBe("")
  })
})

describe("checklist de configuración — cuenta completa", () => {
  const status = deriveSetupStatus(completeSources())

  it("no deja nada pendiente", () => {
    expect(status.allDone).toBe(true)
    expect(status.pending).toBe(0)
    expect(status.unreadable).toBe(0)
    expect(status.nextStep).toBeNull()
    expect(status.checks.every((c) => c.state === "listo")).toBe(true)
  })

  it("nunca devuelve el hash del PIN, solo si alguien lo tiene", () => {
    // El PIN son 4 dígitos: su hash es reversible por fuerza bruta y no tiene
    // por qué viajarle al modelo.
    expect(JSON.stringify(status)).not.toContain("abc123")
    expect(byId(status.checks, "team").detail).toContain("1 de 2")
  })
})

describe("checklist de configuración — estados intermedios", () => {
  it("una caja activa SIN autorización para facturar no cuenta como resuelta", () => {
    const sources = completeSources()
    sources.registers = {
      registers: [
        {
          id: "r1",
          name: "Caja Principal",
          status: true,
          // Es exactamente la caja que crea el alta de la cuenta: existe, está
          // activa, y no puede emitir un comprobante.
          fiscal: { invoiceAuth: "", invoicePrefix: "" },
        },
      ],
    }
    const status = deriveSetupStatus(sources)
    const registers = byId(status.checks, "registers")

    expect(registers.state).toBe("falta")
    expect(registers.missing).toContain("número de autorización para facturar")
    expect(registers.missing).toContain("punto de expedición")
    expect(status.nextStep).toBe("registers")
    expect(status.allDone).toBe(false)
  })

  it("un equipo sin PIN no puede abrir la caja", () => {
    const sources = completeSources()
    sources.users = { users: [{ id: "u1", name: "Ana", active: true }] }
    const team = byId(deriveSetupStatus(sources).checks, "team")

    expect(team.state).toBe("falta")
    expect(team.detail).toContain("ninguna tiene PIN")
    expect(team.agentActions).toEqual(["create_user"])
  })

  it("una sucursal dada de baja no cuenta como sucursal activa", () => {
    const sources = completeSources()
    sources.outlets = { rows: [{ id: "o1", name: "Casa Central", active: false }] }
    expect(byId(deriveSetupStatus(sources).checks, "outlets").state).toBe("falta")
  })

  it("desenvuelve el sobre { meta, data } que agregan las tools con montos", () => {
    const sources = completeSources()
    sources.items = {
      meta: { currency: "$", amountFields: ["price"] },
      data: { items: [{ id: "i1", name: "Café", price: 4500 }] },
    }
    expect(byId(deriveSetupStatus(sources).checks, "catalog").state).toBe("listo")
  })

  it("un tenant paraguayo ve el nombre paraguayo del documento fiscal", () => {
    // El otro lado de la misma regla: derivar del país tiene que funcionar
    // TAMBIÉN para Paraguay — lo prohibido es asumirlo, no soportarlo.
    const sources = emptySources()
    sources.settings = { country: "PY" }
    const company = byId(deriveSetupStatus(sources).checks, "company_profile")
    expect(company.missing).toContain("RUC")
  })

  it("respeta la etiqueta que el tenant configuró por encima del país", () => {
    const sources = emptySources()
    sources.settings = { country: "BR", tin: "CNPJ/MF" }
    const company = byId(deriveSetupStatus(sources).checks, "company_profile")
    expect(company.missing).toContain("CNPJ/MF")
  })
})

describe("checklist de configuración — lecturas fallidas", () => {
  it("no reporta como faltante lo que no pudo leer", () => {
    const sources = completeSources()
    sources.outlets = { error: "Error 500" }
    sources.taxes = null
    const status = deriveSetupStatus(sources)

    expect(byId(status.checks, "outlets").state).toBe("no se pudo leer")
    expect(byId(status.checks, "taxes").state).toBe("no se pudo leer")
    expect(status.unreadable).toBe(2)
    expect(status.pending).toBe(0)
    // Tampoco los da por buenos: sin poder verificarlos, la cuenta no está lista.
    expect(status.allDone).toBe(false)
    expect(status.nextStep).toBeNull()
  })

  it("una forma inesperada cae en 'no se pudo leer', no en 'falta'", () => {
    const sources = completeSources()
    sources.registers = "vino un string"
    expect(byId(deriveSetupStatus(sources).checks, "registers").state).toBe("no se pudo leer")
  })
})

describe("get_setup_status — registro de la tool", () => {
  it("se construye con una descripción que le dice al modelo cuándo usarla", () => {
    const tools = buildSetupStatusTool(ctx)
    expect(Object.keys(tools)).toEqual(["get_setup_status"])
    expect(tools.get_setup_status.description.length).toBeGreaterThan(120)
  })

  it("NO viaja al MCP: es una tool de onboarding del panel", () => {
    // El MCP sirve el catálogo compartido a clientes externos. Si esta tool
    // apareciera ahí, sería porque alguien la movió a `read-tools.ts` — que es
    // justo lo que no queremos sin decidirlo (context/66 F4).
    expect(Object.keys(buildReadOnlyFetchTools(ctx))).not.toContain("get_setup_status")
  })
})
