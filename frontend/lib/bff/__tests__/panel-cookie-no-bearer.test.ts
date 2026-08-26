import { readFileSync, readdirSync } from "node:fs"
import path from "node:path"
import { describe, expect, it } from "vitest"

/**
 * Guard estructural: ninguna route del PANEL re-acuña una cookie `_jwt*` como
 * `Authorization: Bearer`. Debe reenviar el header `cookie` CRUDO.
 *
 * ── El incidente que cierra (2026-08-26) ─────────────────────────────────────
 * `app/api/dashboard/income-chart/route.ts` extraía `_jwt_panel` POR NOMBRE
 * (`req.cookies.get("_jwt_panel")`) y la reenviaba como `Bearer`. Con dos
 * cookies homónimas en el browser (una `domain=.punto.la` del emisor PHP, otra
 * host-only del BFF de impersonación), `req.cookies.get()` de Next devuelve la
 * PRIMERA y PHP parsea la ÚLTIMA — y mandar el valor como Bearer le da
 * PRECEDENCIA en `authResolve()`. Resultado: el resto del dashboard resolvía un
 * tenant y este widget OTRO. El owner vio, en el panel de una empresa, las
 * ventas de otra. Fix: reenviar la cookie cruda, como el catch-all `/api/v1`,
 * `agent/chat`, `ocr-invoice` y `geo` — así todas las rutas del panel resuelven
 * por el MISMO `authResolve` y no pueden divergir por más emisores que existan.
 *
 * ── Por qué un guard y no solo el diff ───────────────────────────────────────
 * Es la MISMA clase de bug que el token-only del POS (ver
 * `pos-token-only.test.ts`): una credencial ambiental que viaja por un camino
 * que le da precedencia equivocada. La regla del panel es la simétrica: la
 * cookie viaja como cookie, nunca re-empaquetada como Bearer. Una route nueva
 * que lo reintroduzca no se ve en review sin recordar este historial.
 *
 * Alcance: TODO `app/api/**` MENOS `/api/pos/**` (device Bearer legítimo, su
 * propio guard) y `/api/v1/**` (catch-all multi-credencial, forwardCookie).
 */

// lib/bff/__tests__ → raíz de `frontend/`
const FRONTEND_ROOT = path.resolve(import.meta.dirname, "..", "..", "..")
const API_DIR = path.join(FRONTEND_ROOT, "app", "api")
const INCOME_CHART = path.join(API_DIR, "dashboard", "income-chart", "route.ts")

function listRoutes(dir: string): string[] {
  const out: string[] = []
  for (const dirent of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, dirent.name)
    if (dirent.isDirectory()) out.push(...listRoutes(full))
    else if (dirent.name === "route.ts") out.push(full)
  }
  return out
}

/** Comentarios fuera: este guard razona sobre CÓDIGO. Los docblocks citan a
 *  propósito `_jwt_panel` y `Bearer` para explicar el anti-patrón. */
function stripComments(src: string): string {
  return src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "")
}

function isExempt(file: string): boolean {
  const rel = path.relative(API_DIR, file).split(path.sep)
  return rel[0] === "pos" || rel[0] === "v1"
}

describe("las rutas del panel reenvían la cookie cruda, nunca la re-acuñan como Bearer", () => {
  const panelRoutes = listRoutes(API_DIR).filter((f) => !isExempt(f))

  it("encuentra rutas del panel (el guard no puede quedar vacío)", () => {
    expect(panelRoutes.length).toBeGreaterThan(0)
  })

  it("ninguna route del panel lee una cookie `_jwt*` por nombre Y la manda como Bearer", () => {
    const offenders = panelRoutes.filter((f) => {
      const code = stripComments(readFileSync(f, "utf8"))
      const readsNamedJwtCookie = /cookies\s*\.\s*get\s*\(\s*["'`]_jwt/.test(code)
      const buildsBearer = /Bearer\s*[$`]/.test(code) // `Bearer ${...}` / plantilla
      return readsNamedJwtCookie && buildsBearer
    })
    expect(offenders.map((f) => path.relative(FRONTEND_ROOT, f))).toEqual([])
  })

  describe("income-chart (el widget del incidente)", () => {
    const code = stripComments(readFileSync(INCOME_CHART, "utf8"))

    it("NO extrae `_jwt_panel` por nombre", () => {
      expect(code).not.toMatch(/cookies\s*\.\s*get\s*\(\s*["'`]_jwt_panel/)
    })

    it("NO construye un `Authorization: Bearer` propio", () => {
      expect(code).not.toMatch(/Bearer\s*[$`]/)
      expect(code).not.toMatch(/Authorization/)
    })

    it("reenvía el header `cookie` CRUDO al backend", () => {
      expect(code).toMatch(/req\s*\.\s*headers\s*\.\s*get\s*\(\s*["'`]cookie["'`]\s*\)/)
      // Y lo adjunta en la request al upstream (`headers: { cookie, ... }`).
      expect(code).toMatch(/headers\s*:\s*\{[^}]*\bcookie\b/s)
    })
  })
})
