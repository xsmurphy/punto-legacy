import { readFileSync, readdirSync } from "node:fs"
import path from "node:path"
import { describe, expect, it } from "vitest"

/**
 * Guard estructural: **cada realm tiene SU token, y nadie lee cookies de auth.**
 *
 * ── El invariante (context/54, decisión del owner 2026-08-26) ────────────────
 * El panel autentica con el Bearer de `lib/auth/panel-token.ts`; el device (POS,
 * KDS, display…) con el de `lib/auth/device-token.ts`. Cada cliente HTTP lee SU
 * clave de storage y la adjunta a propósito. Nadie manda cookies.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Panel y `/pos` se usan en el MISMO navegador. Mientras el panel autenticaba
 * por cookie, el browser adjuntaba esa credencial SOLO en toda request
 * same-origin, así que el server recibía dos credenciales sin que nadie las
 * pidiera y tenía que adivinar cuál usar: cuatro incidentes de sesión cruzada en
 * dos meses (2026-07-19, 08-24, 08-25, 08-26), con deslogueos del POS en la caja
 * y un leak cross-tenant en el dashboard.
 *
 * Con los dos realms en Bearer esa clase de bug deja de ser expresable... pero
 * solo mientras las dos credenciales no se mezclen EN EL CÓDIGO. Ese es el
 * riesgo residual que este guard cubre: ya no puede pasar por accidente
 * ambiental, así que la única forma de volver a cruzarlas es que alguien escriba
 * un import equivocado. Acá se ve.
 */

// lib/auth/__tests__ → raíz de `frontend/`
const FRONTEND_ROOT = path.resolve(import.meta.dirname, "..", "..", "..")
const API_DIR = path.join(FRONTEND_ROOT, "app", "api")

const PANEL_CLIENT = path.join(FRONTEND_ROOT, "lib", "api-client.ts")
const POS_CLIENT = path.join(FRONTEND_ROOT, "lib", "api", "pos-fetch.ts")
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

/** Comentarios fuera: este guard razona sobre CÓDIGO, y los docblocks citan a
 *  propósito `cookie`, `_jwt_panel` y `Bearer` para explicar el anti-patrón. */
function stripComments(src: string): string {
  return src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "")
}

describe("separación de credenciales por realm", () => {
  it("el cliente del PANEL no toca el token del device", () => {
    const code = stripComments(readFileSync(PANEL_CLIENT, "utf8"))
    expect(code).not.toMatch(/device-token/)
    expect(code).not.toMatch(/getDeviceToken/)
    // Y sí usa el suyo.
    expect(code).toMatch(/getPanelToken/)
  })

  it("el cliente del DEVICE no toca el token del panel", () => {
    const code = stripComments(readFileSync(POS_CLIENT, "utf8"))
    expect(code).not.toMatch(/panel-token/)
    expect(code).not.toMatch(/getPanelToken/)
    expect(code).toMatch(/getDeviceToken/)
  })

  it("el cliente del panel es token-only (no manda cookies)", () => {
    const code = stripComments(readFileSync(PANEL_CLIENT, "utf8"))
    // `credentials: "omit"` no es cosmético: el BFF es same-origin y el default
    // de fetch mandaría las cookies igual.
    expect(code).toMatch(/credentials:\s*["']omit["']/)
    expect(code).not.toMatch(/credentials:\s*["']include["']/)
  })

  it("no existe un helper genérico de token (un `getToken()` sin realm es el bug)", () => {
    const authDir = path.join(FRONTEND_ROOT, "lib", "auth")
    const offenders: string[] = []
    for (const dirent of readdirSync(authDir, { withFileTypes: true })) {
      if (!dirent.isFile() || !dirent.name.endsWith(".ts")) continue
      const code = stripComments(readFileSync(path.join(authDir, dirent.name), "utf8"))
      if (/export\s+(?:async\s+)?function\s+getToken\b/.test(code)) offenders.push(dirent.name)
    }
    expect(offenders).toEqual([])
  })
})

describe("el BFF no usa cookies de auth", () => {
  const routes = listRoutes(API_DIR)

  it("encuentra rutas (el guard no puede quedar vacío)", () => {
    expect(routes.length).toBeGreaterThan(0)
  })

  it("ninguna ruta del PANEL reenvía la cookie upstream", () => {
    // Misma excepción que abajo: `app/api/admin/**` reenvía `_jwt_admin` a su
    // propio backend. Realm aparte, migración aparte (context/54 §3, F4).
    const offenders = routes.filter((f) => {
      const rel = path.relative(API_DIR, f).split(path.sep)
      if (rel[0] === "admin") return false
      const code = stripComments(readFileSync(f, "utf8"))
      return (
        /forwardCookie\s*:\s*true/.test(code) ||
        /headers\s*\.\s*(?:set|append)\s*\(\s*["'`]cookie["'`]/i.test(code) ||
        /headers\s*:\s*\{[^}]*\bcookie\b\s*[,}]/.test(code)
      )
    })
    expect(offenders.map((f) => path.relative(FRONTEND_ROOT, f))).toEqual([])
  })

  it("ninguna ruta del PANEL trata una cookie `_jwt*` como credencial", () => {
    // Excepción deliberada: `app/api/admin/**` lee `_jwt_admin`. El realm admin
    // sigue en cookie — es otra superficie (no convive con el POS en el browser
    // del cajero, que es lo que causaba los incidentes) y su migración se decide
    // aparte (context/54 §3, F4). Cuando se migre, sale esta excepción.
    //
    // `_imp_panel` (marca de impersonación, cosmética) no cuenta: no es auth.
    const offenders = routes.filter((f) => {
      const rel = path.relative(API_DIR, f).split(path.sep)
      if (rel[0] === "admin") return false
      const code = stripComments(readFileSync(f, "utf8"))
      return /cookies\s*\.\s*get\s*\(\s*["'`]_jwt/.test(code)
    })
    expect(offenders.map((f) => path.relative(FRONTEND_ROOT, f))).toEqual([])
  })

  describe("income-chart (el widget del leak cross-tenant de 2026-08-26)", () => {
    const code = stripComments(readFileSync(INCOME_CHART, "utf8"))

    it("reenvía el `Authorization` entrante, no una credencial que arma él", () => {
      expect(code).toMatch(/req\s*\.\s*headers\s*\.\s*get\s*\(\s*["'`]authorization["'`]\s*\)/i)
      expect(code).toMatch(/Authorization:\s*auth\b/)
    })

    it("no lee ninguna cookie", () => {
      expect(code).not.toMatch(/cookie/i)
    })
  })
})

describe("todo emisor de sesión de panel entrega el token al cliente", () => {
  // Cada endpoint que emite una sesión de panel (`PanelAuth::issuePanelSession`)
  // produce una credencial NUEVA. Con cookie, el browser la reemplazaba sola;
  // con Bearer el cliente tiene que adoptarla explícitamente, o sigue mandando
  // la anterior. Cuando eso pasa el síntoma es mudo y confuso: el flujo dice que
  // funcionó y el backend sigue resolviendo la sesión vieja (ej. cambiar de
  // sucursal y ver los datos de la anterior).
  //
  // Los cuatro emisores vivos, verificados 2026-08-26. Si aparece un quinto,
  // este guard no lo conoce — pero el que lo agregue va a encontrar acá el
  // contrato que tiene que cumplir.
  const emitters: Array<[string, string]> = [
    ["login", path.join(FRONTEND_ROOT, "app", "(auth)", "login", "page.tsx")],
    ["signup", path.join(FRONTEND_ROOT, "app", "(auth)", "signup", "page.tsx")],
    ["cambio de sucursal", path.join(FRONTEND_ROOT, "hooks", "use-bootstrap.ts")],
    [
      "impersonación",
      path.join(FRONTEND_ROOT, "app", "(admin)", "admin", "companies", "[id]", "page.tsx"),
    ],
  ]

  it.each(emitters)("%s guarda el token con setPanelToken", (_label, file) => {
    const code = stripComments(readFileSync(file, "utf8"))
    expect(code).toMatch(/setPanelToken\(/)
  })

  it("el logout del panel borra el token", () => {
    const guard = stripComments(
      readFileSync(path.join(FRONTEND_ROOT, "components", "layout", "panel-auth-guard.tsx"), "utf8"),
    )
    expect(guard).toMatch(/clearPanelToken\(\)/)
  })
})

describe("el panel no usa `fetch` crudo contra su propio BFF", () => {
  // Un `fetch("/api/...")` directo desde el panel se salta el api-client, que es
  // el ÚNICO lugar que adjunta la credencial (Bearer) y el view-scope. Mientras
  // el panel usó cookie el agujero era invisible: el browser la mandaba sola y
  // el fetch crudo "funcionaba". Al migrar a Bearer, el chart de ingresos quedó
  // devolviendo `BFF 401` en producción mientras el resto del dashboard cargaba
  // normal — un solo widget roto, difícil de atribuir.
  //
  // Excepciones legítimas (no son credencial de panel):
  //   - `/api/pos/*`      → los llama `pos-fetch.ts` con el Bearer del device.
  //   - `/api/admin/*`    → realm admin, cookie `_jwt_admin` (F4 pendiente).
  //   - `/api/geo/reverse`, `/api/geo/resolve-short-link` → sin auth de tenant.
  //   - `/api/v1/einvoice-public` → endpoint público, por token en la URL.
  const ALLOWED = [
    /\/api\/pos\//,
    /\/api\/admin\//,
    /\/api\/geo\/reverse/,
    /\/api\/geo\/resolve-short-link/,
    /\/api\/v1\/einvoice-public/,
  ]

  function walk(dir: string): string[] {
    const out: string[] = []
    for (const dirent of readdirSync(dir, { withFileTypes: true })) {
      if (dirent.name === "node_modules" || dirent.name === "__tests__") continue
      const full = path.join(dir, dirent.name)
      if (dirent.isDirectory()) out.push(...walk(full))
      else if (/\.tsx?$/.test(dirent.name)) out.push(full)
    }
    return out
  }

  it("ningún call-site del panel llama fetch() a /api/ sin pasar por el api-client", () => {
    const roots = ["app", "components", "hooks", "lib"].map((d) => path.join(FRONTEND_ROOT, d))
    const offenders: string[] = []
    for (const root of roots) {
      for (const file of walk(root)) {
        // Los route handlers del BFF corren en el server: su `fetch` va al
        // backend PHP, no al propio BFF.
        if (file.includes(path.join("app", "api"))) continue
        const code = stripComments(readFileSync(file, "utf8"))
        const matches = code.match(/fetch\(\s*[`"']\/api\/[^`"']*/g) ?? []
        for (const m of matches) {
          if (ALLOWED.some((re) => re.test(m))) continue
          offenders.push(`${path.relative(FRONTEND_ROOT, file)} → ${m.slice(0, 60)}`)
        }
      }
    }
    expect(offenders).toEqual([])
  })
})

describe("nada del panel espera que la cookie viaje sola", () => {
  // `credentials: "include"` es la huella de "esto se autenticaba solo". Es un
  // marcador MÁS fuerte que buscar `fetch("/api/…")`: atrapa también la
  // descarga que arma la URL con un helper, o cualquier variante que un grep de
  // paths no ve. Fue el caso que se escapó en la primera pasada — la plantilla
  // CSV de importación de artículos, que quedó devolviendo 401 en producción.
  //
  // Excepción: `/admin` sigue autenticando por cookie `_jwt_admin` (realm
  // aparte, F4 pendiente). Cuando migre, sale de acá.
  const ADMIN_FILES = [
    path.join("lib", "api-admin.ts"),
    path.join("hooks", "use-admin.ts"),
  ]

  function walk(dir: string): string[] {
    const out: string[] = []
    for (const dirent of readdirSync(dir, { withFileTypes: true })) {
      if (dirent.name === "node_modules" || dirent.name === "__tests__") continue
      const full = path.join(dir, dirent.name)
      if (dirent.isDirectory()) out.push(...walk(full))
      else if (/\.tsx?$/.test(dirent.name)) out.push(full)
    }
    return out
  }

  it("ningún archivo del panel usa `credentials: \"include\"`", () => {
    const roots = ["app", "components", "hooks", "lib"].map((d) => path.join(FRONTEND_ROOT, d))
    const offenders: string[] = []
    for (const root of roots) {
      for (const file of walk(root)) {
        const rel = path.relative(FRONTEND_ROOT, file)
        if (ADMIN_FILES.includes(rel)) continue
        const code = stripComments(readFileSync(file, "utf8"))
        if (/credentials:\s*["']include["']/.test(code)) offenders.push(rel)
      }
    }
    expect(offenders).toEqual([])
  })
})
