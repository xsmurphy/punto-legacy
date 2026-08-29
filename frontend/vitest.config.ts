import { defineConfig } from "vitest/config"
import path from "node:path"

/**
 * Config mínima de Vitest.
 *
 * El frontend no tiene (todavía) una suite general — esto entró con el
 * arranque offline del POS, que es lógica pura sobre IndexedDB y por lo tanto
 * verificable sin browser ni servidor. `include` sigue acotado a propósito:
 * esto no declara una convención de testing para todo el repo, cubre los
 * caminos que no se pueden revisar leyendo.
 *
 * `hooks/` se sumó el 2026-08-29 con el guard del envelope del detalle de
 * transacción: los FETCHERS de un hook son lógica pura sobre la respuesta HTTP
 * (mockeando `posFetch` alcanza — no hace falta browser ni `renderHook`), y su
 * bug era justamente del tipo que no se ve leyendo, porque castear el envelope
 * a payload compila y devuelve un objeto truthy. Esto NO habilita testear
 * hooks con estado de React, que necesitarían jsdom + testing-library.
 */
export default defineConfig({
  resolve: {
    alias: { "@": path.resolve(import.meta.dirname, ".") },
  },
  test: {
    environment: "node",
    include: ["lib/**/__tests__/**/*.test.ts", "hooks/**/__tests__/**/*.test.ts"],
  },
})
