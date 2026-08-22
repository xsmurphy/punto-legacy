/** Datos de contacto de Punto, únicos para todo el sitio. */

export const CONTACTO = {
  /** Formato internacional para mostrar. */
  telefono: "+595 981 078798",
  /** Sin "+" ni espacios, como lo pide wa.me. */
  whatsappNumero: "595981078798",
  direccion:
    "Av. Aviadores del Chaco — Edif. The Top, piso 15, of. 1502B — Asunción, Paraguay",
  coords: { lat: -25.2853893, lng: -57.5696954 },
} as const

export const WHATSAPP_URL = `https://wa.me/${CONTACTO.whatsappNumero}?text=${encodeURIComponent(
  "Hola, quiero saber si Punto le sirve a mi negocio.",
)}`

/** Embed de Google Maps centrado en la oficina (sin API key). */
export const MAPS_EMBED_URL = `https://www.google.com/maps?q=${CONTACTO.coords.lat},${CONTACTO.coords.lng}&z=18&hl=es&output=embed`

/** Link para abrir la ubicación en Google Maps. */
export const MAPS_URL = `https://www.google.com/maps/search/?api=1&query=${CONTACTO.coords.lat},${CONTACTO.coords.lng}`
