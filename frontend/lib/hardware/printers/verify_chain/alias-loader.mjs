// alias-loader.mjs — resuelve el alias "@/*" → "frontend/*" (mismo mapeo que
// tsconfig.json `paths`) para poder correr los módulos TS reales de
// `lib/hardware/printers/` con el runtime nativo de TS de Node (sin
// bundler, sin agregar dependencias — ni tsx ni ts-node). Sin esto,
// `import "@/lib/catalog/store"` no resuelve fuera de Next.js.
//
// Se registra con `node --import ./register-loader.mjs run.mts` (ver run.sh).
// Solo intercepta specifiers que empiezan con "@/" — todo lo demás (paquetes
// de node_modules, rutas relativas) sigue el resolver default de Node.

import { existsSync } from "node:fs"
import { fileURLToPath, pathToFileURL } from "node:url"
import path from "node:path"

const FRONTEND_ROOT = path.resolve(fileURLToPath(import.meta.url), "../../../../../")

export async function resolve(specifier, context, nextResolve) {
  if (specifier.startsWith("@/")) {
    let target = path.join(FRONTEND_ROOT, specifier.slice(2))
    // Node ESM no resuelve extensiones automáticamente — Next.js/tsconfig sí.
    // "@/lib/x" en este código siempre apunta a "lib/x.ts" (nunca .tsx acá).
    if (!path.extname(target) && existsSync(`${target}.ts`)) {
      target = `${target}.ts`
    }
    return nextResolve(pathToFileURL(target).href, context)
  }
  return nextResolve(specifier, context)
}
