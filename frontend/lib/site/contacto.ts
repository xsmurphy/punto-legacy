/**
 * Datos de contacto del sitio. Salen del mercado activo (`markets.ts`) —
 * este módulo solo arma los links derivados.
 */

import { getMarket } from "@/lib/site/markets"

export const CONTACTO = getMarket().contacto

export const WHATSAPP_URL = `https://wa.me/${CONTACTO.whatsappNumero}?text=${encodeURIComponent(
  "Hola, quiero saber si Punto le sirve a mi negocio."
)}`

/**
 * Contacto con SOPORTE desde dentro del producto (no desde el sitio): el
 * usuario ya es cliente, así que el mensaje no es el del formulario de ventas.
 *
 * Sale del MISMO número del mercado activo — el panel no hardcodea un teléfono
 * propio ni inventa un canal nuevo. Si algún día soporte tiene su propia línea,
 * se agrega a `markets.ts` y este link la toma solo.
 */
export const SOPORTE_WHATSAPP_URL = `https://wa.me/${CONTACTO.whatsappNumero}?text=${encodeURIComponent(
  "Hola, necesito ayuda con el estado de mi cuenta en Punto."
)}`

/** Teléfono de contacto tal como se muestra (formato nacional del mercado). */
export const SOPORTE_TELEFONO = CONTACTO.telefono

/** Embed de Google Maps centrado en la oficina (sin API key). */
export const MAPS_EMBED_URL = `https://www.google.com/maps?q=${CONTACTO.coords.lat},${CONTACTO.coords.lng}&z=18&hl=es&output=embed`

/** Link para abrir la ubicación en Google Maps. */
export const MAPS_URL = `https://www.google.com/maps/search/?api=1&query=${CONTACTO.coords.lat},${CONTACTO.coords.lng}`

/** Link al perfil de Instagram. */
export const INSTAGRAM_URL = `https://instagram.com/${CONTACTO.instagram}`
