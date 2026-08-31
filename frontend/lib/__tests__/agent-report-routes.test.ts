import { describe, expect, it } from "vitest"
import fs from "node:fs"
import path from "node:path"

import { REPORT_ROUTES } from "@/lib/agent/read-tools"

/**
 * Paridad de `get_report` con el árbol real de endpoints.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Tres de las veinte rutas apuntaban a archivos que no existen y devolvían 404
 * en producción (verificado por MCP contra prod, 2026-08-31):
 *
 *   ventas_resumen      → `/v1/reports/summary`        — el archivo es `sales.php`
 *   cuentas_por_cobrar  → `/v1/reports/open-invoices`  — el archivo va con guión BAJO
 *   cuentas_por_pagar   → idem
 *
 * Ninguno de los tres fallaba de forma visible: el router
 * (`api/router.php:32-40`) mapea `/v1/<path>` a un archivo del árbol sin
 * rewrites ni alias, así que un nombre equivocado es un 404 que llega al modelo
 * como un `{error}` cualquiera. `ventas_resumen` es el reporte que el modelo
 * elige para casi cualquier pregunta sobre ventas, y recibía ese error donde
 * esperaba el resumen.
 *
 * Este test lee el FILESYSTEM, igual que `sale-type.test.ts` lee el enum PHP: es
 * lo único que hace que renombrar o mover un endpoint del lado de la API rompa
 * acá en vez de degradar el agente en silencio. Una lista de nombres escrita a
 * mano sería una cuarta copia del mismo dato.
 */

const API_V1 = path.resolve(import.meta.dirname, "../../../api/v1")

/** `/v1/reports/sales?dataset=summary` → `<repo>/api/v1/reports/sales.php`. */
function endpointFile(route: string): string {
  const withoutQuery = route.split("?")[0]
  const relative = withoutQuery.replace(/^\/v1\//, "")
  return path.join(API_V1, `${relative}.php`)
}

describe("get_report: cada reporte apunta a un endpoint que existe", () => {
  it("encuentra el árbol de endpoints de la API", () => {
    expect(
      fs.existsSync(API_V1),
      `No se encontró ${API_V1}. Es la fuente de verdad de este test: si la API se ` +
        "movió, hay que actualizar la ruta, no borrar el test.",
    ).toBe(true)
  })

  it.each(Object.entries(REPORT_ROUTES))("%s resuelve a un archivo real", (id, route) => {
    const file = endpointFile(route.path)
    expect(
      fs.existsSync(file),
      `El reporte "${id}" apunta a "${route.path}", que resuelve a ${file} y ese archivo ` +
        "no existe. El router no tiene rewrites: la llamada devuelve 404 y el modelo recibe " +
        "un error donde espera el reporte.",
    ).toBe(true)
  })

  it("toda ruta es del árbol /v1 y sin barra final", () => {
    for (const [id, route] of Object.entries(REPORT_ROUTES)) {
      expect(route.path.startsWith("/v1/"), `${id}: la ruta no arranca en /v1/`).toBe(true)
      expect(route.path.endsWith("/"), `${id}: la ruta termina en barra`).toBe(false)
    }
  })

  /**
   * `ranged` decide si la tool ofrece `compareWith`, así que una marca
   * equivocada no rompe nada visible: simplemente compara —o se niega a
   * comparar— cuando no corresponde. Marcar un reporte que ignora las fechas
   * como comparable es el caso peligroso, porque el "período anterior"
   * devolvería el mismo dato y el delta daría cero: el modelo lo leería como
   * "no cambió nada" en vez de "esto no se mide por período".
   *
   * Se verifica contra el PHP: un endpoint que filtra por período lee `from`.
   */
  it.each(Object.entries(REPORT_ROUTES))("%s declara `ranged` según lo que lee el PHP", (id, route) => {
    const source = fs.readFileSync(endpointFile(route.path), "utf8")
    const readsFrom = /['"]from['"]/.test(source)
    expect(
      readsFrom,
      `El reporte "${id}" está declarado ranged: ${route.ranged}, pero su endpoint ` +
        `${route.path} ${readsFrom ? "SÍ" : "NO"} lee el parámetro 'from'.`,
    ).toBe(route.ranged)
  })
})
