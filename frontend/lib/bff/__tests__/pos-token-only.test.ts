import { readFileSync, readdirSync } from "node:fs"
import path from "node:path"
import { describe, expect, it } from "vitest"

/**
 * Guard estructural: `/api/pos/*` es TOKEN-ONLY.
 *
 * La regla (invariante, ver `context/08-convenciones-criticas.md`): ninguna
 * puerta del POS acepta la cookie del operador ni la reenvía al backend. La
 * única credencial es el Bearer del device.
 *
 * Por qué existe este test y no alcanza con revisar el diff: la misma clase de
 * bug entró TRES veces en dos meses, siempre por un camino distinto, y las tres
 * veces se arregló en el call-site.
 *
 *   1. 2026-07-19 — Bearer automático en `api-client.ts`: el PANEL operaba con
 *      el outlet de la caja.
 *   2. 2026-08-24 — `/v1/users` con Bearer de device → 403 silencioso → lock
 *      screen sin PINs.
 *   3. 2026-08-25 — `/api/pos/bootstrap` sin Bearer resolvía como panel por la
 *      cookie y devolvía 200 SIN el roster; el cache envenenado dejó un iPhone
 *      recién pareado bloqueado.
 *
 * La causa común: el browser del operador lleva las DOS credenciales (cookie
 * `_jwt_panel` del panel + Bearer del device en localStorage), así que cualquier
 * puerta que acepte las dos puede resolver el realm equivocado. Una ruta POS
 * nueva que reenvíe la cookie reabre exactamente ese agujero, y en una review no
 * se ve: el reviewer tendría que acordarse de este historial.
 *
 * Las dos exigencias por archivo:
 *   (a) NO reenviar la cookie upstream — ni a mano (`headers.set("cookie", …)`)
 *       ni pidiéndole al proxy compartido que lo haga (`forwardCookie: true`).
 *   (b) EXIGIR Bearer antes de tocar el backend — vía `requireBearer: true` en
 *       cada `bffProxy()`, o con un check explícito del header en los handlers
 *       que no usan el proxy.
 *
 * La contraparte de este guard está en PHP
 * (`api/tests/pos_token_only_precedence_test.php`): verifica la precedencia de
 * `authResolve()`, que desde context/54 F2 queda como defensa en profundidad —
 * el catch-all `/api/v1/*` ya no reenvía cookies, así que el panel y el POS lo
 * usan cada uno con SU Bearer y no hay ambigüedad que resolver.
 */

// lib/bff/__tests__ → raíz de `frontend/`
const FRONTEND_ROOT = path.resolve(import.meta.dirname, "..", "..", "..")
const POS_API_DIR = path.join(FRONTEND_ROOT, "app", "api", "pos")

function listRoutes(dir: string): string[] {
  const out: string[] = []
  for (const dirent of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, dirent.name)
    if (dirent.isDirectory()) out.push(...listRoutes(full))
    else if (dirent.name === "route.ts") out.push(full)
  }
  return out
}

/**
 * Comentarios fuera: este guard razona sobre CÓDIGO. Los docblocks de estas
 * rutas explican justamente por qué no se reenvía la cookie, y sin quitarlos la
 * palabra "cookie" en la prosa daría un falso positivo en cada archivo bien
 * escrito — el test castigaría documentar la regla.
 */
function stripComments(src: string): string {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, "")
    // `//` en cualquier posición, no solo a principio de línea: un comentario
    // al final de una línea de código que mencione `forwardCookie: true` daría
    // falso rojo. (Aproximación: no intenta respetar `//` dentro de strings —
    // en estos handlers no aparece, y errar hacia "borro de más" solo puede
    // producir un falso ROJO visible, nunca un falso verde.)
    .replace(/\/\/.*$/gm, "")
}

const routeFiles = listRoutes(POS_API_DIR)

const API_DIR = path.join(FRONTEND_ROOT, "app", "api")

describe("/api/pos/* es token-only", () => {
  it("encuentra las rutas del POS (el guard no puede quedar vacío)", () => {
    // Sin esto, mover o renombrar el directorio dejaría 0 archivos que escanear
    // y la suite pasaría en verde sin verificar nada.
    expect(routeFiles.length).toBeGreaterThan(0)
  })

  it("NINGUNA ruta del BFF reenvía la cookie (`forwardCookie` no tiene opt-ins)", () => {
    // Antes el catch-all `/api/v1` era el único opt-in legítimo, porque la
    // credencial del PANEL era la cookie `_jwt_panel`. Desde context/54 F2 el
    // panel es Bearer, así que ya no queda ninguna puerta multi-credencial: cada
    // request llega con UNA credencial explícita. Un `forwardCookie: true` nuevo
    // reabriría el escenario de los cuatro incidentes de sesión cruzada.
    const optIns = listRoutes(API_DIR).filter((f) =>
      /forwardCookie\s*:\s*true/.test(stripComments(readFileSync(f, "utf8"))),
    )
    expect(optIns.map((f) => path.relative(FRONTEND_ROOT, f))).toEqual([])
  })

  describe.each(routeFiles.map((f) => [path.relative(FRONTEND_ROOT, f), f] as const))(
    "%s",
    (_rel, file) => {
      const code = stripComments(readFileSync(file, "utf8"))

      it("no reenvía el header cookie upstream", () => {
        // A mano: `headers.set("cookie", …)` / `headers.append('cookie', …)`.
        expect(code).not.toMatch(/headers\s*\.\s*(?:set|append)\s*\(\s*["'`]cookie["'`]/i)
        // Estilo object-literal: `headers: { cookie }` / `{ "Cookie": … }`. Es
        // una forma viva en este repo (app/api/agent/chat, app/api/ocr-invoice),
        // así que el guard tiene que verla o se lo esquiva sin querer.
        expect(code).not.toMatch(/["'`]?[Cc]ookie["'`]?\s*:/)
        // Vía el proxy compartido: `forwardCookie: true` es el opt-in que SOLO
        // le corresponde al catch-all del panel (`app/api/v1/[...path]`).
        expect(code).not.toMatch(/forwardCookie\s*:\s*true/)
      })

      it("exige Bearer antes de pegarle al backend", () => {
        // Cada llamada a bffProxy tiene que pedir el Bearer. Contamos llamadas
        // y opt-ins: si hay 3 `bffProxy(` y 2 `requireBearer: true`, alguien
        // agregó una rama sin el guard.
        const proxyCalls = code.match(/\bbffProxy\s*\(/g)?.length ?? 0
        const requireBearer = code.match(/requireBearer\s*:\s*true/g)?.length ?? 0
        if (proxyCalls > 0) {
          expect(requireBearer).toBe(proxyCalls)
        }
        // Y SIEMPRE, sin cortar acá: una ruta puede mezclar `bffProxy` con un
        // `fetch` artesanal en otra rama, y ésa también tiene que exigir Bearer.
        // Con un `return` temprano esa mitad no se auditaba nunca.
        if (proxyCalls === 0 || /\bfetch\s*\(/.test(code)) {
          expect(code).toMatch(/Bearer\\s\+\\S\+/)
          expect(code).toMatch(/status:\s*401/)
        }
      })

      it("no acepta la cookie del panel como credencial", () => {
        // El anti-patrón concreto de los tres incidentes: tratar la PRESENCIA
        // de `_jwt_panel` (o `_jwt`) como prueba de autenticación. `\b` cubre
        // tanto el parseo del header (`_jwt=`) como `req.cookies.get("_jwt")`.
        expect(code).not.toMatch(/_jwt_panel\b/)
        expect(code).not.toMatch(/_jwt\b/)
      })
    },
  )
})
