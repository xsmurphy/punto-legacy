/**
 * Mercados del sitio de marketing.
 *
 * Hoy el sitio habla SOLO a Paraguay, pero todo lo que cambia de país
 * (precio del plan, moneda, cómo se llama el documento fiscal, el organismo,
 * los datos de contacto, qué rubros se ofrecen) vive acá y no hardcodeado en
 * las páginas. Sumar un país es agregar una entrada a `MARKETS` — no tocar
 * componentes.
 *
 * El mercado activo se elige con `NEXT_PUBLIC_SITE_MARKET` (default `PY`).
 * Cuando haya más de uno en vivo, el paso siguiente natural es resolverlo por
 * dominio o por segmento de ruta (`/ar/...`) en el middleware, que ya rutea
 * el sitio por host.
 *
 * Los textos de `rubros.ts` y `modulos.ts` pueden usar tokens que se
 * reemplazan al renderizar — ver `applyMarketTerms`:
 *   {docFiscal}   → "RUC" en PY, "CUIT" en AR, "NIT" en CO…
 *   {organismo}   → cómo se nombra al fisco en ese país
 *   {moneda}      → símbolo/prefijo de la moneda
 */

export type MarketCode = "PY"

export type Market = {
  code: MarketCode
  /** País, para copy y metadata. */
  pais: string
  /** Locale de OpenGraph y del `lang` si algún día difiere. */
  locale: string
  moneda: {
    /** Prefijo con el que se muestran los montos ("Gs.", "$"). */
    prefijo: string
    /** Código ISO, para datos estructurados y billing. */
    codigo: string
    /** Decimales que usa la moneda en precios de lista. */
    decimales: number
  }
  /** Cómo se llama en ese país lo que en PY es RUC, SET, etc. */
  terminos: {
    docFiscal: string
    organismo: string
  }
  plan: {
    /** Monto en la unidad de la moneda (sin separadores). */
    precio: number
    /** Sobre qué se cobra: "por mes, por sucursal". */
    periodo: string
    /** Badge sobre el precio; null lo oculta. */
    badge: string | null
    /** Créditos de IA incluidos por mes. */
    creditosIa: number
  }
  contacto: {
    telefono: string
    /** Sin "+" ni espacios, como lo pide wa.me. */
    whatsappNumero: string
    direccion: string
    coords: { lat: number; lng: number }
  }
  /**
   * Rubros que se ofrecen en este mercado (slugs de `rubros.ts`). `null`
   * muestra todos — útil mientras haya un solo país.
   */
  rubros: string[] | null
  /**
   * Cómo se traducen los montos de EJEMPLO (mockups, tickets de muestra)
   * a este mercado: se multiplican por `escala` y se redondean al múltiplo
   * `redondeo`. La historia del mockup es la misma; los números tienen que
   * sonar creíbles en la moneda local.
   */
  ejemplos: { escala: number; redondeo: number }
}

export const MARKETS: Record<MarketCode, Market> = {
  PY: {
    code: "PY",
    pais: "Paraguay",
    locale: "es_PY",
    moneda: { prefijo: "Gs.", codigo: "PYG", decimales: 0 },
    terminos: { docFiscal: "RUC", organismo: "la SET" },
    plan: {
      precio: 295000,
      periodo: "por mes, por sucursal",
      badge: "Precio promocional",
      creditosIa: 10000,
    },
    contacto: {
      telefono: "+595 981 078798",
      whatsappNumero: "595981078798",
      direccion:
        "Av. Aviadores del Chaco — Edif. The Top, piso 15, of. 1502B — Asunción, Paraguay",
      coords: { lat: -25.2853893, lng: -57.5696954 },
    },
    rubros: null,
    ejemplos: { escala: 1, redondeo: 1 },
  },
}

const ACTIVE = (process.env.NEXT_PUBLIC_SITE_MARKET ?? "PY") as MarketCode

/** Mercado activo del sitio. */
export function getMarket(): Market {
  return MARKETS[ACTIVE] ?? MARKETS.PY
}

/** Formatea un monto con la moneda del mercado (separador de miles local). */
export function marketMoney(
  amount: number,
  market: Market = getMarket()
): string {
  const n = new Intl.NumberFormat("es-PY", {
    minimumFractionDigits: market.moneda.decimales,
    maximumFractionDigits: market.moneda.decimales,
  }).format(amount)
  return `${market.moneda.prefijo} ${n}`
}

/** Microcopy del precio bajo los CTA — una sola frase para todo el sitio. */
export function planLine(market: Market = getMarket()): string {
  return `${marketMoney(market.plan.precio, market)} ${market.plan.periodo}.`
}

/** Convierte un monto de ejemplo a la escala del mercado. */
export function marketExampleMoney(
  amount: number,
  market: Market = getMarket()
): string {
  const escalado = amount * market.ejemplos.escala
  const paso = market.ejemplos.redondeo || 1
  const redondeado = Math.round(escalado / paso) * paso
  return marketMoney(redondeado, market)
}

/**
 * Reemplaza los tokens de mercado en un texto de contenido:
 *   {docFiscal} {organismo} {moneda} y {money:145000}
 * El token de dinero lleva el monto en la moneda base del contenido
 * (guaraníes) y sale ya escalado y formateado para el mercado activo.
 */
export function applyMarketTerms(
  text: string,
  market: Market = getMarket()
): string {
  return text
    .replaceAll("{docFiscal}", market.terminos.docFiscal)
    .replaceAll("{organismo}", market.terminos.organismo)
    .replaceAll("{moneda}", market.moneda.prefijo)
    .replace(/\{money:(-?\d+)\}/g, (_, n) =>
      marketExampleMoney(Number(n), market)
    )
}
