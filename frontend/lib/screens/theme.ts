/**
 * Tono claro/oscuro de las pantallas device-paired (KDS, despacho, estación de
 * impresión, pantalla de cliente). Persistido en localStorage POR MÓDULO —
 * no en una clave compartida con el panel/POS.
 *
 * Historia: `(screen)/layout.tsx` forzaba `forcedTheme="light"` en TODAS las
 * pantallas de este grupo de rutas porque compartían storage con el theme del
 * panel — al cambiar el tono en otra pestaña, la pantalla del cliente heredaba
 * `dark` sin que nadie lo pidiera ahí. El KDS ya había resuelto esto con su
 * propio storage scopeado (`punto.kds.config`, campo `theme`) + una clase
 * `.dark` aplicada a mano en su wrapper en vez de depender de next-themes. Acá
 * generalizamos ese mecanismo — storage con clave por módulo — para que
 * despacho, impresión y checkout puedan tener su propio selector sin volver a
 * exponerse al bug original.
 */

export type ScreenTheme = "dark" | "light" | "auto"

export type ScreenModule = "kds" | "display" | "print" | "checkout"

/**
 * Hora (local del dispositivo) en la que el modo automático pasa a claro y a
 * oscuro. Se resuelve por HORARIO y no por `prefers-color-scheme`: una TV o
 * tablet colgada en una cocina o mostrador reporta casi siempre "light" — es
 * el default del SO y nadie entra a configurarlo en un equipo montado en la
 * pared —, así que esa señal no sigue lo único que nos importa, que es la luz
 * real del local. La hora sí: al mediodía entra sol por los ventanales y de
 * noche el local está iluminado a media luz.
 */
const AUTO_LIGHT_FROM_HOUR = 7
const AUTO_DARK_FROM_HOUR = 19

/** Modo efectivo de la pantalla. `auto` se resuelve contra la hora dada. */
export function resolveScreenMode(theme: ScreenTheme, now: Date = new Date()): "dark" | "light" {
  if (theme !== "auto") return theme
  const hour = now.getHours()
  return hour >= AUTO_LIGHT_FROM_HOUR && hour < AUTO_DARK_FROM_HOUR ? "light" : "dark"
}

function storageKey(module: ScreenModule): string {
  return `punto.screen.theme.${module}`
}

/** Lee el tema guardado para este módulo. JSON inválido o ausente → default. */
export function loadScreenTheme(module: ScreenModule, defaultTheme: ScreenTheme): ScreenTheme {
  if (typeof window === "undefined") return defaultTheme
  try {
    const raw = window.localStorage.getItem(storageKey(module))
    if (!raw) return defaultTheme
    const parsed = JSON.parse(raw) as unknown
    if (parsed === "dark" || parsed === "light" || parsed === "auto") return parsed
    return defaultTheme
  } catch {
    return defaultTheme
  }
}

export function saveScreenTheme(module: ScreenModule, theme: ScreenTheme): void {
  if (typeof window === "undefined") return
  try {
    window.localStorage.setItem(storageKey(module), JSON.stringify(theme))
  } catch {
    /* storage lleno / modo privado — la pantalla sigue con el tema en memoria */
  }
}
