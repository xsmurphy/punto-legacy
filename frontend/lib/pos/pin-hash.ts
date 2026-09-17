/**
 * El hash con el que el POS valida un PIN SIN RED.
 *
 * SHA-256 hex, sin sal. Es el mismo esquema que `contact.pinhash` (lock screen)
 * y que `employee.markpinhash` (quiosco de marcación), y la falta de sal no es
 * un descuido: los dos hashes bajan en el bootstrap para que el dispositivo
 * pueda comparar sin conexión, y una sal por persona tendría que bajar con
 * ellos — o sea, el mismo secreto con un paso más.
 *
 * Lo que el hash compra es que el PIN no viaje ni se guarde en claro. Lo que NO
 * compra es resistencia a fuerza bruta: son 4 dígitos, 10.000 combinaciones.
 * Por eso ninguna de las dos superficies lo trata como una credencial fuerte —
 * el lock screen identifica al operador y revalida server-side cuando hay red,
 * y la marcación guarda una FOTO, que es su evidencia real.
 *
 * Vive acá y no en cada pantalla porque estaba duplicado: el lock screen tenía
 * su propia copia del snippet de Web Crypto, y el quiosco iba a ser la segunda.
 * Dos copias de un cálculo criptográfico son dos lugares donde el día que haya
 * que cambiarlo (sal, algoritmo, iteraciones) alguien se olvida de uno.
 */

/**
 * SHA-256 de `value`, en hexadecimal minúscula.
 *
 * `crypto.subtle` existe en todo browser que corra el POS (requiere contexto
 * seguro: https o localhost, que es donde vive la PWA). No hay fallback a
 * propósito: un fallback sería una implementación distinta produciendo hashes
 * distintos, y el primer síntoma sería "el PIN correcto no matchea".
 */
export async function sha256Hex(value: string): Promise<string> {
  const bytes = new TextEncoder().encode(value)
  const digest = await crypto.subtle.digest("SHA-256", bytes)
  return Array.from(new Uint8Array(digest))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("")
}
