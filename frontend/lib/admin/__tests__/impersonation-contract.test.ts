import { describe, expect, it } from "vitest"
import { readFileSync } from "node:fs"
import path from "node:path"

/**
 * Contrato de "Entrar como empresa" (impersonación desde /admin).
 *
 * El flujo tiene TRES piezas y cada una habla con la siguiente en un formato
 * distinto. Ninguna de las tres es obvia leyendo las otras dos:
 *
 *   1. `api/v1/admin/companies.php` (?action=enter) responde `{token, expiresIn}`.
 *      El token va en el body a propósito: no lo consume el navegador.
 *   2. `app/api/admin/[...path]/route.ts` (BFF) intercepta esa respuesta, guarda
 *      el token como cookie `_jwt_panel` HttpOnly con las banderas del front, y
 *      responde otra cosa: `{ok, redirectUrl}`.
 *   3. La ficha del tenant abre `redirectUrl` en una pestaña nueva.
 *
 * El 2026-08-25 se "alineó" el paso 1 con el paso 3 —que efectivamente no
 * encajan entre sí— sin ver el paso 2, que era el que los unía. Resultado: el
 * BFF dejó de encontrar el token y devolvió 502 "Backend no devolvió token de
 * impersonación". Nada en el repo lo verificaba, porque el contrato cruza dos
 * lenguajes y ningún test miraba los dos lados a la vez.
 *
 * Estos chequeos son de código, no de runtime: garantizan que las tres piezas
 * sigan nombrando lo mismo. Si el flujo se rediseña, se cambian los tres juntos
 * y este archivo con ellos — que es exactamente el punto.
 */

const FRONTEND = path.resolve(import.meta.dirname, "../../..")
const REPO = path.resolve(FRONTEND, "..")

function read(abs: string): string {
  return readFileSync(abs, "utf8")
}

const PHP_ENDPOINT = read(path.join(REPO, "api/v1/admin/companies.php"))
const BFF = read(path.join(FRONTEND, "app/api/admin/[...path]/route.ts"))
const HOOK = read(path.join(FRONTEND, "hooks/use-admin.ts"))
const PAGE = read(path.join(FRONTEND, "app/(admin)/admin/companies/[id]/page.tsx"))

describe("contrato de impersonación /admin", () => {
  it("el endpoint PHP devuelve el token en el body", () => {
    // Sin esto el BFF corta con 502 y el botón no abre nada.
    expect(PHP_ENDPOINT).toMatch(/apiOk\(\[\s*'token'\s*=>\s*\$tokenData\['token'\]/)
  })

  it("el BFF lee exactamente esa clave", () => {
    expect(BFF).toMatch(/json\.data\?\.token/)
    expect(BFF).toMatch(/urlParams\.get\("action"\) === "enter"/)
  })

  it("el BFF —y sólo el BFF— convierte el token en la cookie del panel", () => {
    expect(BFF).toMatch(/res\.cookies\.set\(\s*"_jwt_panel"/)
    // El realm admin no puede quedar tocado por impersonar: la sesión del
    // operador de /admin sigue siendo la suya.
    expect(BFF).not.toMatch(/cookies\(\)\.delete\("_jwt_admin"\)/)
  })

  it("el BFF responde redirectUrl, que es lo que consume el front", () => {
    expect(BFF).toMatch(/NextResponse\.json\(\{\s*ok:\s*true,\s*redirectUrl:/)
    expect(HOOK).toMatch(/apiAdmin\.post<\{\s*redirectUrl:\s*string\s*\}>/)
    expect(PAGE).toMatch(/res\?\.redirectUrl/)
  })

  it("la marca _imp_panel acompaña a la cookie y el panel la consume", () => {
    // El BFF setea la marca legible por JS junto a `_jwt_panel`; el guard del
    // panel la lee para mostrar "Salir de impersonación" y la borra al salir.
    // Si una punta se renombra sin la otra, el botón desaparece en silencio.
    expect(BFF).toMatch(/res\.cookies\.set\(\s*"_imp_panel"/)
    const GUARD = read(path.join(FRONTEND, "components/layout/panel-auth-guard.tsx"))
    expect(GUARD).toMatch(/_imp_panel=1/)
    expect(GUARD).toMatch(/_imp_panel=; path=\/; max-age=0/)
    expect(GUARD).toMatch(/window\.location\.href = "\/admin"/)
  })

  it("el token nunca se guarda del lado del cliente", () => {
    // Si alguien lo pasa por localStorage o por la URL, deja de ser HttpOnly y
    // se vuelve una credencial de panel al alcance de cualquier script.
    expect(PAGE).not.toMatch(/localStorage[\s\S]{0,40}token/)
    expect(PAGE).not.toMatch(/redirectUrl[\s\S]{0,40}\?token=/)
  })
})
