import { defineConfig } from "vitest/config"
import path from "node:path"

/**
 * Config mínima de Vitest.
 *
 * El frontend no tiene (todavía) una suite general — esto entró con el
 * arranque offline del POS, que es lógica pura sobre IndexedDB y por lo tanto
 * verificable sin browser ni servidor. `include` está acotado a propósito:
 * esto no declara una convención de testing para todo el repo, cubre el
 * camino que no se puede revisar leyendo.
 */
export default defineConfig({
  resolve: {
    alias: { "@": path.resolve(import.meta.dirname, ".") },
  },
  test: {
    environment: "node",
    include: ["lib/**/__tests__/**/*.test.ts"],
  },
})
