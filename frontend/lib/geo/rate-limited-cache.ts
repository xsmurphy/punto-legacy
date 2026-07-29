/**
 * Caché TTL + espaciado mínimo entre llamadas salientes, en memoria del
 * proceso Node del BFF. Photon es un servicio comunitario gratuito (sin API
 * key, sin cuota contratada) — no hay que martillearlo. Esto es
 * intencionalmente simple:
 *
 *   - Caché por clave (query normalizada o coordenada redondeada), TTL fijo.
 *   - Un "mutex" de espaciado: como mucho una llamada saliente a Photon cada
 *     `MIN_SPACING_MS`, en cola (no se descartan requests, se demoran).
 *
 * Esto es best-effort y por-instancia: si el BFF corre en varias instancias
 * (horizontal scaling), cada una tiene su propio caché y su propio contador
 * de espaciado — no hay coordinación entre procesos. Si eso se vuelve un
 * problema real (Photon empieza a tirar 429), la solución correcta es mover
 * el caché/rate-limit a Redis (ya hay uno en la infra, ver
 * context/06-infraestructura.md) — no agregar más lógica acá.
 */

interface CacheEntry<T> {
  value: T
  expiresAt: number
}

const DEFAULT_TTL_MS = 5 * 60 * 1000
const MIN_SPACING_MS = 150

class TtlCache<T> {
  private store = new Map<string, CacheEntry<T>>()

  get(key: string): T | undefined {
    const entry = this.store.get(key)
    if (!entry) return undefined
    if (Date.now() > entry.expiresAt) {
      this.store.delete(key)
      return undefined
    }
    return entry.value
  }

  set(key: string, value: T, ttlMs = DEFAULT_TTL_MS): void {
    // Poda oportunista para no crecer sin límite en un proceso long-lived.
    if (this.store.size > 500) {
      const now = Date.now()
      for (const [k, v] of this.store) {
        if (v.expiresAt < now) this.store.delete(k)
      }
    }
    this.store.set(key, { value, expiresAt: Date.now() + ttlMs })
  }
}

// Instancias module-level: sobreviven entre requests dentro del mismo
// proceso Node (Next corre en modo server persistente, no edge-per-request).
export const geoAutocompleteCache = new TtlCache<unknown>()
export const geoReverseCache = new TtlCache<unknown>()

// El mapeo link-corto → link-largo de Google NUNCA cambia (es un alias fijo
// generado una vez), así que este cache usa un TTL mucho más largo que los
// de arriba — no tiene sentido re-resolver el mismo short link a cada rato.
export const SHORT_LINK_TTL_MS = 30 * 24 * 60 * 60 * 1000 // 30 días
export const shortLinkCache = new TtlCache<string>()

let lastCallAt = 0
let queue: Promise<void> = Promise.resolve()

/**
 * Encola `fn` detrás de cualquier llamada anterior, espaciando como mínimo
 * `MIN_SPACING_MS` entre el fin de una y el inicio de la siguiente. No es un
 * rate limiter estricto (no rechaza), es un throttle cooperativo: bajo carga
 * normal del BFF (typeahead de un form a la vez) alcanza para no bombardear
 * a Photon.
 */
export function throttledCall<T>(fn: () => Promise<T>): Promise<T> {
  const run = queue.then(async () => {
    const wait = lastCallAt + MIN_SPACING_MS - Date.now()
    if (wait > 0) await new Promise((r) => setTimeout(r, wait))
    lastCallAt = Date.now()
  })
  queue = run.catch(() => {})
  return run.then(fn)
}

/** Redondea una coordenada a ~11m de precisión — suficiente para cachear
 *  reverse geocoding sin perder utilidad práctica. */
export function roundCoord(n: number): number {
  return Math.round(n * 10000) / 10000
}

/** Normaliza una query de autocomplete para usar como clave de caché. */
export function normalizeQueryKey(q: string, country: string): string {
  return `${country.toLowerCase()}::${q.trim().toLowerCase().replace(/\s+/g, " ")}`
}
