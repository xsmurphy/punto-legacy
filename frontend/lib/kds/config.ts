/**
 * Config local del dispositivo KDS — persistida en localStorage, NO en BD
 * (cada pantalla física puede querer su propio nombre, densidad y estaciones;
 * es config del navegador del dispositivo, no del tenant).
 *
 * Rediseño 2026-07-27 — flujo horizontal de comandas:
 *   - `columnMode` DESAPARECE. Ya no hay columnas por estado: las comandas van
 *     una al lado de la otra ordenadas por tiempo y el estado es color, no
 *     posición.
 *   - `density` se reemplaza por `cardsPerScreen`, que es la misma decisión
 *     expresada en la unidad que le importa al cocinero ("cuántas comandas veo
 *     de una").
 *   - Nuevos: `name` (va en la barra inferior), `sortOrder` y `soundOnNew`.
 *
 * Los dispositivos ya instalados tienen la config vieja guardada, así que
 * `loadKdsConfig` migra en lectura (ver abajo) — nunca se asume que el JSON
 * de localStorage tiene la forma actual.
 */

export type KdsSortOrder = "oldest" | "newest"

export const KDS_CARDS_PER_SCREEN = [4, 6, 8, 12] as const
export type KdsCardsPerScreen = (typeof KDS_CARDS_PER_SCREEN)[number]

/** Oscuro / claro / por horario — ver `resolveKdsMode`. */
export type KdsTheme = "dark" | "light" | "auto"

export const KDS_ROTATE_SECONDS = [8, 12, 20, 30] as const
export type KdsRotateSeconds = (typeof KDS_ROTATE_SECONDS)[number]

/**
 * Hora (local del dispositivo) en la que el modo automático pasa a claro y a
 * oscuro. Se resuelve por HORARIO y no por `prefers-color-scheme`: una TV o
 * tablet colgada en una cocina reporta casi siempre "light" — es el default del
 * SO y nadie entra a configurarlo en un equipo montado en la pared —, así que
 * esa señal no sigue lo único que nos importa, que es la luz real del local.
 * La hora sí: al mediodía entra sol por los ventanales y de noche la cocina
 * está iluminada a media luz.
 */
const AUTO_LIGHT_FROM_HOUR = 7
const AUTO_DARK_FROM_HOUR = 19

/** Modo efectivo de la pantalla. `auto` se resuelve contra la hora dada. */
export function resolveKdsMode(theme: KdsTheme, now: Date = new Date()): "dark" | "light" {
  if (theme !== "auto") return theme
  const hour = now.getHours()
  return hour >= AUTO_LIGHT_FROM_HOUR && hour < AUTO_DARK_FROM_HOUR ? "light" : "dark"
}

export interface KdsConfig {
  /** Nombre de esta pantalla ("Parrilla", "Barra"). Vacío = se usa el nombre de la sucursal. */
  name: string
  /** Cuántas comandas entran de una. Define el ancho de cada tarjeta y el tamaño de página. */
  cardsPerScreen: KdsCardsPerScreen
  /** "oldest" = las más antiguas a la izquierda (default de cocina). */
  sortOrder: KdsSortOrder
  /** stationIds visibles — [] = todas las estaciones (comodín, mismo criterio que order_station.categoryids). */
  stationIds: string[]
  warnMin: number
  lateMin: number
  /** Aviso sonoro al entrar una comanda. Requiere desbloqueo por gesto — ver lib/kds/sound.ts. */
  soundOnNew: boolean
  /** Tono de la pantalla. Se elige por dispositivo: la luz de cada cocina es distinta. */
  theme: KdsTheme
  /**
   * Rotación automática de páginas. APAGADA por default: una pantalla que
   * cambia sola sin que nadie la toque desconcierta y le pelea a quien está
   * operando. Queda disponible para quien la quiera en una pantalla realmente
   * desatendida.
   *
   * El riesgo opuesto —que las comandas de la página 2 queden escondidas en
   * silencio— NO se resuelve moviendo la pantalla sola, sino avisando: con más
   * de una página la barra inferior muestra un contador explícito de lo que no
   * entra. La regla es "nunca esconder en silencio, nunca moverse solo sin que
   * lo pidan".
   */
  autoRotate: boolean
  rotateSeconds: KdsRotateSeconds
}

export const DEFAULT_KDS_CONFIG: KdsConfig = {
  name: "",
  cardsPerScreen: 6,
  sortOrder: "oldest",
  stationIds: [],
  warnMin: 10,
  lateMin: 20,
  soundOnNew: false,
  theme: "dark",
  autoRotate: false,
  rotateSeconds: 12,
}

const KEY = "punto.kds.config"

/** Forma vieja (pre flujo horizontal) que todavía vive en los dispositivos. */
interface LegacyKdsConfig {
  columnMode?: unknown
  density?: unknown
}

function coerceCardsPerScreen(value: unknown, legacy: LegacyKdsConfig): KdsCardsPerScreen {
  if (typeof value === "number" && (KDS_CARDS_PER_SCREEN as readonly number[]).includes(value)) {
    return value as KdsCardsPerScreen
  }
  // Migración de `density`: preserva la intención del que ya lo había ajustado
  // ("compacta = quiero ver más de una vez") en vez de resetearlo al default.
  if (legacy.density === "compact") return 8
  return DEFAULT_KDS_CONFIG.cardsPerScreen
}

function coerceRotateSeconds(value: unknown): KdsRotateSeconds {
  if (typeof value === "number" && (KDS_ROTATE_SECONDS as readonly number[]).includes(value)) {
    return value as KdsRotateSeconds
  }
  return DEFAULT_KDS_CONFIG.rotateSeconds
}

function coercePositiveInt(value: unknown, fallback: number): number {
  return typeof value === "number" && Number.isFinite(value) && value > 0 ? Math.floor(value) : fallback
}

/**
 * Lee y NORMALIZA la config. Construye el objeto campo por campo en vez de
 * hacer `{ ...DEFAULT, ...parsed }`: así una config vieja no arrastra claves
 * muertas (`columnMode`, `density`) ni valores fuera de rango que romperían el
 * layout (ej. `cardsPerScreen: 18` heredado del KDS legacy → grilla ilegible).
 */
export function loadKdsConfig(): KdsConfig {
  if (typeof window === "undefined") return DEFAULT_KDS_CONFIG
  try {
    const raw = window.localStorage.getItem(KEY)
    if (!raw) return DEFAULT_KDS_CONFIG
    const parsed = JSON.parse(raw) as Partial<KdsConfig> & LegacyKdsConfig
    if (!parsed || typeof parsed !== "object") return DEFAULT_KDS_CONFIG

    const warnMin = coercePositiveInt(parsed.warnMin, DEFAULT_KDS_CONFIG.warnMin)
    const lateMin = coercePositiveInt(parsed.lateMin, DEFAULT_KDS_CONFIG.lateMin)

    return {
      name: typeof parsed.name === "string" ? parsed.name.slice(0, 40) : DEFAULT_KDS_CONFIG.name,
      cardsPerScreen: coerceCardsPerScreen(parsed.cardsPerScreen, parsed),
      sortOrder: parsed.sortOrder === "newest" ? "newest" : "oldest",
      stationIds: Array.isArray(parsed.stationIds)
        ? parsed.stationIds.filter((v): v is string => typeof v === "string")
        : [],
      warnMin,
      // `late` nunca puede quedar antes que `warn` — un umbral invertido dejaría
      // la tarjeta en rojo permanente y el canal de demora sin señal útil.
      lateMin: Math.max(lateMin, warnMin),
      soundOnNew: parsed.soundOnNew === true,
      theme: parsed.theme === "light" || parsed.theme === "auto" ? parsed.theme : "dark",
      // Solo encendida si el dispositivo la pidió explícitamente.
      autoRotate: parsed.autoRotate === true,
      rotateSeconds: coerceRotateSeconds(parsed.rotateSeconds),
    }
  } catch {
    return DEFAULT_KDS_CONFIG
  }
}

export function saveKdsConfig(config: KdsConfig): void {
  if (typeof window === "undefined") return
  try {
    window.localStorage.setItem(KEY, JSON.stringify(config))
  } catch {
    /* storage lleno / modo privado — la pantalla sigue con la config en memoria */
  }
}
