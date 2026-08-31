/**
 * Datos de contacto del sitio. Salen del mercado activo (`markets.ts`) —
 * este módulo solo arma los links derivados.
 */

import { getMarket } from "@/lib/site/markets"

export const CONTACTO = getMarket().contacto

export const WHATSAPP_URL = `https://wa.me/${CONTACTO.whatsappNumero}?text=${encodeURIComponent(
  "Hola, quiero saber si Punto le sirve a mi negocio."
)}`

/** Embed de Google Maps centrado en la oficina (sin API key). */
export const MAPS_EMBED_URL = `https://www.google.com/maps?q=${CONTACTO.coords.lat},${CONTACTO.coords.lng}&z=18&hl=es&output=embed`

/** Link para abrir la ubicación en Google Maps. */
export const MAPS_URL = `https://www.google.com/maps/search/?api=1&query=${CONTACTO.coords.lat},${CONTACTO.coords.lng}`

/** Link al perfil de Instagram. */
export const INSTAGRAM_URL = `https://instagram.com/${CONTACTO.instagram}`
