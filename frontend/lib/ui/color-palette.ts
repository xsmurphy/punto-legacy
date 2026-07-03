/**
 * Paleta de colores unificada del panel.
 *
 * Fuente única de los swatches usados en Hotkeys, Usuarios (avatar), Impresoras
 * y Medios de pago. Convención: se persiste el `key` del color (ej. "amber"),
 * NO el hex — así el hex vive en un solo lugar y se puede reestilizar sin migrar
 * datos.
 *
 * `resolveColorBg` cubre además los datos legacy que guardaban el hex directo
 * (usuarios/impresoras viejos): si el valor empieza con "#" se devuelve tal cual
 * (passthrough), si no se busca como key en la paleta. NO se migran datos: el
 * resolver los cubre en lectura.
 */

export const PALETTE_COLORS: { key: string; bg: string }[] = [
  { key: "amber", bg: "#f59e0b" },
  { key: "slate", bg: "#64748b" },
  { key: "sky", bg: "#38bdf8" },
  { key: "rose", bg: "#f43f5e" },
  { key: "emerald", bg: "#10b981" },
  { key: "violet", bg: "#8b5cf6" },
]

/**
 * Resuelve un valor de color (key nuevo o hex legacy) a su hex renderizable.
 * - "#rrggbb" (legacy printers/users) → se devuelve tal cual.
 * - "amber"/"slate"/… (convención nueva) → hex de la paleta.
 * - vacío / desconocido → null (sin color).
 */
export function resolveColorBg(value: string | null | undefined): string | null {
  if (!value) return null
  if (value.startsWith("#")) return value
  return PALETTE_COLORS.find((c) => c.key === value)?.bg ?? null
}
