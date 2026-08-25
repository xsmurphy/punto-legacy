/**
 * Locale y moneda de los LIBROS DE PUNTO S.A. — el emisor, no el tenant.
 *
 * ## Qué es esto y por qué existe
 *
 * El realm `/admin` no es una pantalla de tenant: es la consola SaaS interna
 * de Punto S.A. (listado de comercios, MRR/ARR, planes, paquetes de créditos
 * IA, cobranzas). Punto S.A. es una empresa paraguaya y factura sus planes en
 * guaraníes, así que formatear esos números en `es-PY` / `PYG` es una
 * DECISIÓN DE NEGOCIO CORRECTA, no una asunción de país filtrada.
 *
 * El problema no era la decisión: era que estaba invisible. Había ~24
 * literales `"es-PY"` y `"PYG"` sueltos en `app/(admin)/**`, indistinguibles
 * de los bugs reales que el barrido de 2026-08-25 fue a cazar. Un `grep es-PY`
 * no podía separar «esto está bien» de «esto está mal». Este archivo convierte
 * esos literales dispersos en UNA decisión con nombre, documentada y
 * greppeable.
 *
 * ## PROHIBIDO usar esto en superficies de tenant
 *
 * Bajo NINGUNA circunstancia se importa desde `app/(panel)`, `app/(pos)`,
 * `app/(screen)`, ni desde ningún componente/helper que vea un tenant o el
 * cliente de un tenant (tickets, facturas, KuDE, portal, exports, prints).
 * Ahí la moneda / locale / zona horaria / país salen SIEMPRE de la config del
 * tenant vía `lib/tenant-locale.ts` — regla del owner (2026-08-25): el sistema
 * nunca asume Paraguay. Importar estas constantes en esas rutas reintroduce
 * exactamente el bug que `lib/tenant-locale.ts` fue creado para matar, y no
 * falla ruidosamente: lo descubre el cliente del comercio.
 *
 * Regla mental: si el número describe lo que Punto COBRA, es de acá. Si
 * describe lo que el comercio vende, cobra o imprime, es de `tenant-locale`.
 *
 * ## Excepción documentada dentro del propio `/admin`
 *
 * Las facturas que Punto le emite a un tenant (`AdminInvoice`) traen su
 * `currency` desde el backend (la tabla `billing` tiene
 * `currency VARCHAR(8) NOT NULL DEFAULT 'USD'`, o sea puede haber planes en
 * USD). Para esas filas se usa `formatPuntoSaasNumber` — que solo agrupa
 * miles/decimales — y se imprime el código de moneda que vino del dato.
 * NUNCA `formatPuntoSaasMoney`, porque etiquetaría como guaraníes un monto
 * que el backend dice que es otra cosa.
 */

/** Locale de los libros de Punto S.A. Ver el docblock: NO es el locale de ningún tenant. */
export const PUNTO_SAAS_LOCALE = "es-PY"

/** Moneda en la que Punto S.A. factura sus planes. NO es la moneda de ningún tenant. */
export const PUNTO_SAAS_CURRENCY = "PYG"

/**
 * Normaliza lo que llega de los hooks de `/admin`: mientras la query está en
 * vuelo el valor es `undefined`, y un agregado vacío puede volver `null`. Se
 * pinta 0 en vez de "NaN", igual que hace `lib/format` para el tenant.
 */
function safeNumber(n: number | null | undefined): number {
  return typeof n === "number" && isFinite(n) ? n : 0
}

/**
 * Agrupa un número con el formato de Punto S.A. (miles con `.`, decimales con `,`).
 *
 * Es el helper correcto para conteos (tenants, usuarios, créditos IA) y para
 * montos cuya moneda se imprime aparte porque viene del backend.
 */
export function formatPuntoSaasNumber(
  n: number | null | undefined,
  options?: Intl.NumberFormatOptions,
): string {
  return new Intl.NumberFormat(PUNTO_SAAS_LOCALE, options).format(safeNumber(n))
}

/**
 * Formatea un monto EN GUARANÍES — o sea, precios de la lista de Punto S.A.
 * (planes, paquetes de créditos). Sin decimales por default, que es como se
 * escribe el guaraní.
 *
 * Si la moneda del monto viene del backend, este NO es el helper: usá
 * `formatPuntoSaasNumber` y renderizá el código de moneda del dato.
 */
export function formatPuntoSaasMoney(
  n: number | null | undefined,
  options?: Intl.NumberFormatOptions,
): string {
  return new Intl.NumberFormat(PUNTO_SAAS_LOCALE, {
    style: "currency",
    currency: PUNTO_SAAS_CURRENCY,
    maximumFractionDigits: 0,
    ...options,
  }).format(safeNumber(n))
}

/** Fecha con el formato de Punto S.A. (dd/mm/aaaa por default). */
export function formatPuntoSaasDate(
  value: Date | string | number,
  options?: Intl.DateTimeFormatOptions,
): string {
  return new Date(value).toLocaleDateString(PUNTO_SAAS_LOCALE, options)
}

/** Fecha + hora con el formato de Punto S.A. */
export function formatPuntoSaasDateTime(
  value: Date | string | number,
  options?: Intl.DateTimeFormatOptions,
): string {
  return new Date(value).toLocaleString(PUNTO_SAAS_LOCALE, options)
}
