/**
 * Resuelve el alias `@/` de tsconfig cuando Node corre los archivos de
 * contenido directamente (exportador de Markdown). Next lo resuelve solo;
 * Node no.
 */
import { pathToFileURL } from "node:url"
import { join } from "node:path"

const RAIZ = join(import.meta.dirname, "..")

export async function resolve(specifier, context, next) {
  if (specifier.startsWith("@/")) {
    const destino = join(RAIZ, specifier.slice(2))
    // Los imports del proyecto son sin extensión; acá siempre son .ts
    const conExtension = destino.endsWith(".ts") ? destino : `${destino}.ts`
    return next(pathToFileURL(conExtension).href, context)
  }
  return next(specifier, context)
}
