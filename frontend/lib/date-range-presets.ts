/**
 * Rango de fechas del panel: tipo, presets relativos y su forma persistida.
 *
 * Vive en `lib/` (y no dentro de `components/date-range-picker.tsx`) porque lo
 * consumen dos capas que no dibujan nada: el rango global (`hooks/use-date-range`)
 * y el estado persistido de los listados (`hooks/use-persisted-table-state`).
 *
 * ── Por qué un preset se guarda como PRESET ─────────────────────────────────
 *
 * "Hoy" no es un par de fechas: es una regla que se resuelve contra el reloj.
 * Si se persisten las fechas que dio la regla el día que se eligió, al día
 * siguiente "Hoy" muestra AYER y el listado parece roto (o peor, parece que no
 * hubo ventas). Por eso `DateRangeValue.preset` viaja junto a las fechas y la
 * serialización guarda solo el id; al leer se vuelve a resolver contra `now`.
 * Un rango elegido a mano en el calendario no tiene preset y se guarda con sus
 * fechas, que es lo que el usuario eligió.
 */

export type DateRangePresetId = "today" | "last7" | "last14" | "last30" | "last90" | "thisMonth"

export interface DateRangeValue {
  from: Date
  to: Date
  /**
   * Presente solo si el rango salió de un preset relativo. Cualquier código que
   * reconstruya el rango (`{ from, to }`) lo pierde a propósito: un rango
   * modificado ya no es "Hoy".
   */
  preset?: DateRangePresetId
}

export const DATE_RANGE_PRESETS: ReadonlyArray<{ id: DateRangePresetId; label: string }> = [
  { id: "today", label: "Hoy" },
  { id: "last7", label: "Últimos 7 días" },
  { id: "last14", label: "Últimos 14 días" },
  { id: "last30", label: "Últimos 30 días" },
  { id: "last90", label: "Últimos 90 días" },
  { id: "thisMonth", label: "Este mes" },
]

const DAYS_BY_PRESET: Partial<Record<DateRangePresetId, number>> = {
  last7: 7,
  last14: 14,
  last30: 30,
  last90: 90,
}

function isPresetId(v: unknown): v is DateRangePresetId {
  return typeof v === "string" && DATE_RANGE_PRESETS.some((p) => p.id === v)
}

/** Resuelve un preset contra el reloj. Misma aritmética que tenía el picker. */
export function resolveDateRangePreset(id: DateRangePresetId, now: Date = new Date()): DateRangeValue {
  if (id === "today") {
    return { from: new Date(now.getFullYear(), now.getMonth(), now.getDate()), to: now, preset: id }
  }
  if (id === "thisMonth") {
    return { from: new Date(now.getFullYear(), now.getMonth(), 1), to: now, preset: id }
  }
  const days = DAYS_BY_PRESET[id] ?? 7
  return { from: new Date(now.getTime() - days * 24 * 60 * 60 * 1000), to: now, preset: id }
}

/** Forma en storage: un preset, o un rango manual a granularidad de día. */
export type StoredDateRange = { preset: DateRangePresetId } | { from: string; to: string }

function pad(n: number): string {
  return String(n).padStart(2, "0")
}

export function dateToDayKey(d: Date): string {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export function dayKeyToDate(s: string): Date | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s)
  if (!m) return null
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  return Number.isNaN(d.getTime()) ? null : d
}

export function serializeDateRange(value: DateRangeValue): StoredDateRange {
  if (value.preset && isPresetId(value.preset)) return { preset: value.preset }
  return { from: dateToDayKey(value.from), to: dateToDayKey(value.to) }
}

/**
 * Inversa de `serializeDateRange`. Acepta también el formato viejo `{from,to}`
 * (lo único que se guardaba antes de los presets). Devuelve `null` ante
 * cualquier cosa ilegible: el caller cae a su default, nunca rompe.
 */
export function deserializeDateRange(raw: unknown, now: Date = new Date()): DateRangeValue | null {
  if (!raw || typeof raw !== "object") return null
  const obj = raw as Record<string, unknown>
  if (isPresetId(obj.preset)) return resolveDateRangePreset(obj.preset, now)
  const from = typeof obj.from === "string" ? dayKeyToDate(obj.from) : null
  const to = typeof obj.to === "string" ? dayKeyToDate(obj.to) : null
  if (!from || !to) return null
  return { from, to }
}
