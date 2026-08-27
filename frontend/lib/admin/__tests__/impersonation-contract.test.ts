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
 *   2. `app/api/admin/[...path]/route.ts` (BFF) intercepta esa respuesta y la
 *      reenvía al cliente como `{ok, redirectUrl, token, expiresIn}`.
 *   3. La ficha del tenant guarda el token con `setPanelToken()` y abre
 *      `redirectUrl` en una pestaña nueva.
 *
 * El 2026-08-25 se "alineó" el paso 1 con el paso 3 —que efectivamente no
 * encajan entre sí— sin ver el paso 2, que era el que los unía. Resultado: 502
 * "Backend no devolvió token de impersonación". Nada en el repo lo verificaba,
 * porque el contrato cruza dos lenguajes y ningún test miraba los dos lados.
 *
 * ── Cambio de modelo (context/54 F1/F2b, 2026-08-26) ────────────────────────
 * Antes el BFF convertía el token en una cookie `_jwt_panel` HttpOnly. Ahora el
 * panel es Bearer, así que el token viaja al cliente y se guarda en el storage
 * del panel. Eso además elimina de raíz el leak cross-tenant del 2026-08-26: la
 * impersonación acuñaba una SEGUNDA `_jwt_panel` con scope distinto al del
 * emisor PHP, y cada consumidor resolvía una sesión diferente. Con una sola
 * clave de storage no pueden coexistir dos credenciales de panel.
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

  it("el BFF reenvía el token al cliente junto con redirectUrl", () => {
    // Las tres puntas tienen que nombrar lo mismo: BFF lo emite, el hook lo
    // tipa, la página lo consume.
    expect(BFF).toMatch(/NextResponse\.json\(\{\s*ok:\s*true,\s*redirectUrl:\s*"\/",\s*token,\s*expiresIn\s*\}\)/)
    expect(HOOK).toMatch(/apiAdmin\.post<\{[^}]*token:\s*string/)
    expect(PAGE).toMatch(/res\?\.redirectUrl/)
    expect(PAGE).toMatch(/res\.token/)
  })

  it("el BFF NO acuña ninguna cookie de sesión de panel", () => {
    // El panel es Bearer: una cookie de sesión emitida acá sería una segunda
    // credencial conviviendo con el token del storage — exactamente la forma
    // del leak cross-tenant de 2026-08-26, que nació de dos `_jwt_panel` con
    // scopes distintos. Lo único permitido es el BORRADO de la variante legacy.
    expect(BFF).not.toMatch(/set-cookie",\s*`_jwt_panel=\$\{token\}/)
    expect(BFF).not.toMatch(/cookies\.set\(\s*"_jwt_panel"/)
    expect(BFF).toMatch(/"set-cookie", "_jwt_panel=; Path=\/; Max-Age=0"/)
    // El realm admin no puede quedar tocado por impersonar: la sesión del
    // operador de /admin sigue siendo la suya.
    expect(BFF).not.toMatch(/cookies\(\)\.delete\("_jwt_admin"\)/)
  })

  it("el cliente guarda el token en el storage del PANEL, no en otro lado", () => {
    // `setPanelToken` es la única puerta de escritura de esa credencial
    // (lib/auth/panel-token.ts). Escribirla a mano en localStorage o pasarla por
    // la URL la dejaría fuera de ese control — y por la URL además quedaría en
    // el historial y en los logs del server.
    expect(PAGE).toMatch(/setPanelToken\(res\.token\)/)
    // Acotado a la vecindad del token: esta página usa `localStorage` para otra
    // cosa (confirmación de borrado), y prohibirlo entero daría un falso rojo.
    expect(PAGE).not.toMatch(/localStorage[\s\S]{0,60}token/)
    expect(PAGE).not.toMatch(/redirectUrl[\s\S]{0,40}\?token=/)
  })

  it("la marca _imp_panel acompaña a la sesión y el panel la consume", () => {
    // Marca legible por JS, cosmética: el guard del panel la lee para mostrar
    // "Salir de impersonación" y la borra al salir. No es autoridad — forjarla
    // apenas muestra un botón cuyo click hace logout. Si una punta se renombra
    // sin la otra, el botón desaparece en silencio.
    expect(BFF).toMatch(/headers\.append\(\s*"set-cookie",\s*`_imp_panel=1/)
    const GUARD = read(path.join(FRONTEND, "components/layout/panel-auth-guard.tsx"))
    expect(GUARD).toMatch(/_imp_panel=1/)
    expect(GUARD).toMatch(/_imp_panel=; path=\/; max-age=0/)
    expect(GUARD).toMatch(/window\.location\.href = "\/admin"/)
  })

  it("salir de impersonación borra el token del panel", () => {
    // Sin esto, cerrar la impersonación dejaba la credencial del tenant viva en
    // el browser del admin: el botón devuelve a /admin pero la sesión sigue
    // usable desde cualquier pestaña del panel.
    const GUARD = read(path.join(FRONTEND, "components/layout/panel-auth-guard.tsx"))
    expect(GUARD).toMatch(/clearPanelToken\(\)/)
  })
})
