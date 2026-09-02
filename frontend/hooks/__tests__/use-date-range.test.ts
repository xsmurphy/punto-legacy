import { beforeEach, describe, expect, it, vi } from "vitest"

/**
 * Contrato de PERSISTENCIA del rango global: lo que se escribe se relee igual,
 * en el próximo montaje y en la próxima pestaña.
 *
 * Se testea la capa pura (`setDateRange` / `readDateRange` / `clearDateRange`),
 * no `useDateRange()`: la suite corre en `environment: "node"` y montar el hook
 * pediría jsdom + testing-library. La parte que el bug rompía es justamente
 * ésta —de dónde sale el rango en el primer render—, y el hook no hace más que
 * llamarla en un effect. Que CADA pantalla llame al hook lo cubre el guard
 * estructural de `lib/__tests__/date-range-global.test.ts`.
 */

// `use-date-range` mira `typeof window` en cada llamada, así que alcanza con
// tener el stub puesto antes de invocarla. `defaultDateRange` se mockea para no
// arrastrar el árbol de componentes del picker a un test de serialización.
vi.mock("@/components/date-range-picker", () => ({
  defaultDateRange: () => ({
    from: new Date(2026, 0, 1),
    to: new Date(2026, 0, 8),
  }),
}))

const store = new Map<string, string>()
vi.stubGlobal("window", {
  localStorage: {
    getItem: (k: string) => store.get(k) ?? null,
    setItem: (k: string, v: string) => void store.set(k, v),
    removeItem: (k: string) => void store.delete(k),
  },
})

const { DATE_RANGE_KEY, clearDateRange, readDateRange, setDateRange } = await import(
  "@/hooks/use-date-range"
)

describe("persistencia del rango de fecha global", () => {
  beforeEach(() => store.clear())

  it("sin nada guardado devuelve el default", () => {
    const r = readDateRange()
    expect(r.from.getFullYear()).toBe(2026)
    expect(r.to.getDate()).toBe(8)
  })

  it("un rango elegido se relee igual — este es el reload y el cambio de pantalla", () => {
    setDateRange({ from: new Date(2026, 4, 3), to: new Date(2026, 4, 20) })

    const r = readDateRange()
    expect(r.from.getFullYear()).toBe(2026)
    expect(r.from.getMonth()).toBe(4)
    expect(r.from.getDate()).toBe(3)
    expect(r.to.getDate()).toBe(20)
  })

  it("se guarda la fecha sin hora ni zona — el picker trabaja a grano de día", () => {
    // Serializar el `Date` completo (o un ISO en UTC) corría el día para
    // tenants al oeste de Greenwich: se elegía el 3 y volvía el 2.
    setDateRange({ from: new Date(2026, 4, 3, 23, 30), to: new Date(2026, 4, 20, 1, 15) })
    expect(JSON.parse(store.get(DATE_RANGE_KEY)!)).toEqual({ from: "2026-05-03", to: "2026-05-20" })
  })

  it("una fecha local tardía no se corre de día al releerse", () => {
    setDateRange({ from: new Date(2026, 4, 3, 23, 59), to: new Date(2026, 4, 20, 23, 59) })
    expect(readDateRange().from.getDate()).toBe(3)
  })

  it("limpiar vuelve al default", () => {
    setDateRange({ from: new Date(2026, 4, 3), to: new Date(2026, 4, 20) })
    clearDateRange()
    expect(store.has(DATE_RANGE_KEY)).toBe(false)
    expect(readDateRange().to.getDate()).toBe(8)
  })

  it("el panel y la caja no comparten rango", () => {
    // Mismo origin, mismo localStorage: sin claves separadas, el rango de 90
    // días que el dueño dejó en un reporte le aparecería al cajero buscando la
    // venta que acaba de emitir.
    setDateRange({ from: new Date(2026, 0, 1), to: new Date(2026, 2, 31) }, "panel")
    setDateRange({ from: new Date(2026, 4, 10), to: new Date(2026, 4, 10) }, "pos")

    expect(readDateRange("panel").to.getMonth()).toBe(2)
    expect(readDateRange("pos").to.getMonth()).toBe(4)

    // Y limpiar uno no toca al otro.
    clearDateRange("pos")
    expect(readDateRange("panel").to.getMonth()).toBe(2)
    expect(readDateRange("pos").to.getDate()).toBe(8) // default mockeado
  })

  it("un valor corrupto no rompe la pantalla: cae al default", () => {
    store.set(DATE_RANGE_KEY, "{ esto no es json")
    expect(readDateRange().to.getDate()).toBe(8)

    store.set(DATE_RANGE_KEY, JSON.stringify({ from: "ayer", to: "hoy" }))
    expect(readDateRange().to.getDate()).toBe(8)
  })
})
