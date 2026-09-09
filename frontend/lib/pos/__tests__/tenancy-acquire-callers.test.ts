/**
 * Guard de código fuente: QUIÉN puede tomar una caja.
 *
 * El veto del administrador (owner, 2026-09-09,
 * `RegisterLeaseService::isAdminRevoked()`) descansa sobre una propiedad que no
 * vive en ningún tipo: que `acquire: 'operator'` lo mande UN SOLO call-site, el
 * botón "Tomar caja" que toca una persona. El servidor no puede distinguir una
 * intención autodeclarada de otra — si mañana un camino automático copia ese
 * valor porque "así anda", el veto se evapora en silencio y nadie se entera
 * hasta que un admin libere una caja en producción y la tablet se la vuelva a
 * llevar. Que es, literalmente, el incidente que este cambio arregló.
 *
 * Por eso el guard es sobre el TEXTO del repo y no sobre el comportamiento: lo
 * que hay que impedir es que aparezca un segundo emisor, y eso se ve leyendo,
 * no ejecutando.
 */

import { readFileSync, readdirSync, statSync } from "node:fs"
import path from "node:path"
import { describe, expect, it } from "vitest"

/** Raíz de `frontend/` — este archivo vive en `frontend/lib/pos/__tests__`. */
const FRONTEND_ROOT = path.resolve(import.meta.dirname, "..", "..", "..")

/** Subárboles donde puede vivir un call-site del POS. */
const SCANNED = ["app", "components", "hooks", "lib"]

const SKIP_DIRS = new Set(["node_modules", ".next", "__tests__"])

/**
 * Saca comentarios antes de buscar. Es imprescindible: los docblocks de
 * `register-tenancy.ts` y `use-offline-sync.ts` CITAN los valores de `acquire`
 * para explicar el incidente, y sin esto el guard leería esas explicaciones
 * como call-sites. Crudo a propósito — acá solo se busca un patrón, no se
 * parsea TypeScript.
 */
function stripComments(src: string): string {
  return src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "")
}

function* walk(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    if (SKIP_DIRS.has(entry)) continue
    const full = path.join(dir, entry)
    if (statSync(full).isDirectory()) {
      yield* walk(full)
    } else if (/\.tsx?$/.test(entry)) {
      yield full
    }
  }
}

/** Archivos (relativos a `frontend/`) que mencionan `acquire: "operator"`. */
function operatorCallSites(): string[] {
  const found: string[] = []
  for (const root of SCANNED) {
    for (const file of walk(path.join(FRONTEND_ROOT, root))) {
      const src = stripComments(readFileSync(file, "utf8"))
      // Solo el USO como valor de `acquire`, no la definición del tipo ni los
      // comentarios que lo nombran.
      if (/acquire:\s*["']operator["']/.test(src)) {
        found.push(path.relative(FRONTEND_ROOT, file))
      }
    }
  }
  return found.sort()
}

describe("quién puede TOMAR una caja", () => {
  it("solo el botón del cajero manda acquire: 'operator'", () => {
    expect(operatorCallSites()).toEqual(["components/register/pay-dialog.tsx"])
  })

  it("ningún call-site manda acquire: true (adquisición automática)", () => {
    // `true` es la adquisición AUTOMÁTICA: el servidor la acepta por
    // compatibilidad con bundles viejos, pero este bundle no debe producirla —
    // era `ensureTenancy()` y fue exactamente el camino del incidente.
    const offenders: string[] = []
    for (const root of SCANNED) {
      for (const file of walk(path.join(FRONTEND_ROOT, root))) {
        if (/acquire:\s*true/.test(stripComments(readFileSync(file, "utf8")))) {
          offenders.push(path.relative(FRONTEND_ROOT, file))
        }
      }
    }
    expect(offenders).toEqual([])
  })
})
