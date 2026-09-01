import fs from "node:fs"
import path from "node:path"

import { describe, expect, it } from "vitest"

/**
 * Toda query de Control de Caja lleva la CAJA en su clave de caché.
 *
 * El bug que motiva esto: `summary`, `hourly` y `blockers` usaban su clave
 * base (`["drawer","summary"]`) mientras `status` sí incluía el `registerId`.
 * El fetch siempre traía la caja correcta —el token la lleva—, pero las dos
 * cajas compartían entrada de caché, así que al cambiar de caja React Query
 * servía el arqueo de la anterior. Una caja recién creada mostraba las ventas
 * de otra, del mes pasado, con la cabecera diciendo el estado de la nueva.
 *
 * En una pantalla de arqueo eso no es un glitch visual: el cajero cuenta
 * billetes contra ese número.
 *
 * El test es ESTRUCTURAL —lee el archivo— y no de comportamiento, porque el
 * defecto vive en la declaración de la clave, no en lo que el hook devuelve.
 * Montar React Query para descubrir que dos hooks comparten caché costaría
 * mucho más y fallaría por razones más difusas.
 */

const HOOK_PATH = path.join(__dirname, "..", "..", "hooks", "use-drawer.ts")

describe("claves de caché de Control de Caja", () => {
  const source = fs.readFileSync(HOOK_PATH, "utf8")

  it("el archivo se puede leer (si se movió, este test hay que actualizarlo)", () => {
    expect(source.length).toBeGreaterThan(0)
    expect(source).toContain("DRAWER_KEYS")
  })

  it("ninguna query usa DRAWER_KEYS sin el registerId", () => {
    // `queryKey: DRAWER_KEYS.algo` a secas es el defecto. La forma correcta es
    // `queryKey: [...DRAWER_KEYS.algo, registerId]`.
    //
    // Se excluyen las invalidaciones: ahí la clave BASE es lo correcto, porque
    // matchea por prefijo y alcanza a todas las cajas del dispositivo.
    const sinScope = [...source.matchAll(/(\w+\(\{\s*)?queryKey:\s*DRAWER_KEYS\.(\w+)/g)]
      .filter((m) => m[1] === undefined || !m[1].startsWith("invalidateQueries"))
      .map((m) => m[2])
    expect(
      sinScope,
      `estas queries comparten caché entre cajas: ${sinScope.join(", ")}. ` +
        "Poné el registerId en la clave, como hace `status`.",
    ).toEqual([])
  })

  it("las cuatro queries del cajón llevan el registerId", () => {
    const conScope = [...source.matchAll(/queryKey:\s*\[\.\.\.DRAWER_KEYS\.(\w+),\s*registerId/g)].map(
      (m) => m[1],
    )
    for (const k of ["status", "summary", "hourly", "blockers"]) {
      expect(conScope, `\`${k}\` no lleva el registerId en su clave`).toContain(k)
    }
  })

  it("las invalidaciones siguen usando la clave BASE, que alcanza a todas las cajas", () => {
    // `invalidateQueries` matchea por prefijo: `["drawer","summary"]` alcanza
    // `["drawer","summary",<cualquier caja>]`. Invalidar con la clave completa
    // dejaría viva la caché de las demás cajas del dispositivo.
    expect(source).toContain("invalidateQueries({ queryKey: DRAWER_KEYS.summary })")
  })
})
