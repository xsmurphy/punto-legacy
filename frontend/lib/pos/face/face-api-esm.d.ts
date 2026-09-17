/**
 * Tipos del bundle ESM de `@vladmandic/face-api` (RRHH F2).
 *
 * ── Por qué se importa el archivo del `dist` y no el paquete ───────────────
 *
 * El `main` del paquete apunta al build de Node, que hace
 * `require("@tensorflow/tfjs-node")` — un binario nativo que no está instalado
 * ni tiene por qué estarlo: acá el modelo corre en el NAVEGADOR. Importar
 * `"@vladmandic/face-api"` a secas hace que el build del servidor resuelva ese
 * camino y se caiga con un módulo que falta, aunque el código nunca corra ahí.
 *
 * El build ESM (`dist/face-api.esm.js`) es autocontenido —trae TensorFlow.js
 * adentro y no importa NADA— así que apuntarle directo evita el problema de
 * raíz, sin tocar la configuración de webpack ni marcar paquetes externos.
 *
 * Lo único que se pierde apuntando a un `.js` del `dist` son los tipos, y eso es
 * lo que esta declaración devuelve: reexporta los del propio paquete, que sí
 * están publicados. Así el motor queda tipado de verdad en vez de `any`.
 */
declare module "@vladmandic/face-api/dist/face-api.esm.js" {
  export * from "@vladmandic/face-api"
}
