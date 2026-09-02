import { describe, expect, it } from "vitest"

import {
  buildPosAgentTools,
  POS_TOOL_IDS,
  POS_TOOL_PERMISSION,
} from "@/lib/pos/agent-tools"

/**
 * El recorte del asistente de la caja tiene DOS ejes, y este test cuida el
 * segundo: no solo qué tools existen (la allowlist `POS_TOOL_IDS`), sino cuáles
 * se le ofrecen al modelo SEGÚN los permisos de la persona que preguntó.
 *
 * Es la mitad CLIENTE del gate — la que evita que el asistente le prometa a un
 * cajero un dato que el backend le va a negar con 403
 * (`OperatorContext::requirePermission()` en el GET de
 * `api/v1/reports/transactions.php`). Que sea solo la mitad no la hace
 * decorativa: sin ella el modelo llama la tool, cobra el 403 y contesta "no
 * pude obtener el dato" a una pregunta que en realidad no tenía que ofrecer.
 */

const ctx = {
  apiUrl: "https://api.example.test",
  dataHeaders: { Authorization: "Bearer device" },
  authHeader: "Bearer device",
}

describe("tools del asistente de la caja según permisos del operador", () => {
  it("sin el permiso de ventas no se ofrece get_transactions", () => {
    const names = Object.keys(buildPosAgentTools(ctx, "operator-token", ["pos.ai.use"]))
    expect(names).not.toContain("get_transactions")
    // Y el resto del mostrador sigue entero: el corte es por tool, no un
    // apagón del asistente.
    expect(names).toEqual(expect.arrayContaining(["get_items", "get_stock", "get_contacts"]))
  })

  it("con reports.sales.view sí se ofrece", () => {
    const names = Object.keys(
      buildPosAgentTools(ctx, "operator-token", ["pos.ai.use", "reports.sales.view"]),
    )
    expect(names).toContain("get_transactions")
  })

  it("sin operador identificado no hay escrituras ni lecturas con permiso", () => {
    // Caja bloqueada (o desbloqueo offline): no hay a quién medirle nada.
    const names = Object.keys(buildPosAgentTools(ctx, "", []))
    expect(names).not.toContain("get_transactions")
    expect(names).not.toContain("register_action")
    expect(names).not.toContain("execute_action")
  })

  it("las tools de mostrador NO exigen permiso extra — es el catálogo que la caja ya muestra", () => {
    const names = Object.keys(buildPosAgentTools(ctx, "", []))
    for (const id of POS_TOOL_IDS) {
      if (POS_TOOL_PERMISSION[id]) continue
      expect(names, `${id} debería estar disponible sin permiso extra`).toContain(id)
    }
  })

  it("toda tool con permiso declarado está en la allowlist", () => {
    for (const id of Object.keys(POS_TOOL_PERMISSION)) {
      expect(POS_TOOL_IDS as readonly string[]).toContain(id)
    }
  })
})
