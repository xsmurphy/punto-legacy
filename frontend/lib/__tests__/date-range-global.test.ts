import fs from "node:fs"
import path from "node:path"

import { describe, expect, it } from "vitest"

/**
 * El rango de fecha del panel es GLOBAL: se elige una vez y vale para reportes,
 * dashboards y listados, sobrevive al reload y a la navegación. La única fuente
 * de verdad es `hooks/use-date-range.ts`.
 *
 * El bug que motiva esto: el hook ya existía —y su docblock ya prometía
 * exactamente eso— pero siete pantallas guardaban el rango en un
 * `React.useState(defaultDateRange)` local. Ese estado muere en cada montaje,
 * así que elegir un rango en Ventas, entrar a Órdenes y volver lo borraba. El
 * usuario tenía que re-seleccionarlo en cada pantalla, todo el tiempo.
 *
 * Que el hook exista no alcanzó: un consumidor nuevo se escribe copiando al
 * consumidor de al lado, y si el de al lado estaba mal, el bug se reproduce.
 * Por eso el guard es ESTRUCTURAL y no de comportamiento: el defecto no está en
 * lo que el hook devuelve —eso funciona—, está en QUIÉN lo llama. Ningún test
 * de render de una pantalla puede detectar que OTRA pantalla lo esquivó.
 *
 * Regla: todo archivo que renderice `<DateRangePicker>` usa `useDateRange`,
 * salvo los de ALLOWLIST, que llevan el motivo escrito.
 */

const ROOT = path.join(__dirname, "..", "..")
const SCAN_DIRS = ["app", "components", "lib", "hooks"]

/**
 * Excepciones deliberadas. Cada una es una decisión, no una deuda: si algún día
 * cambia, se borra la línea y el archivo pasa a migrar. Agregar una entrada acá
 * sin motivo real es esconder el bug, no resolverlo.
 */
const ALLOWLIST: Record<string, string> = {
  "app/(panel)/finanzas/prevision/page.tsx":
    "Previsión mira para ADELANTE (hoy → hoy+30: vencimientos futuros de " +
    "cheques, cuotas y facturas). El rango global es retrospectivo, así que " +
    "compartirlo mostraría un listado de vencimientos futuros vacío. Tiene su " +
    "propio `defaultForecastRange()`, declarado en el archivo.",

  "components/domain/contacts/contact-schedule-compact.tsx":
    "Va EMBEBIDA en la ficha de un contacto, que no es un reporte ni un " +
    "dashboard: es el drill-down de una entidad. Con el rango global, abrir un " +
    "cliente después de haber acotado un reporte a dos semanas mostraría su " +
    "agenda vacía, y eso se lee como 'este cliente no tiene turnos', no como " +
    "'hay un filtro puesto'. Arranca siempre en el default, que es predecible.",

  "components/domain/contacts/contact-orders-compact.tsx":
    "Mismo caso que `contact-schedule-compact`: pestaña de la ficha del " +
    "contacto, drill-down de una entidad y no un reporte. Un rango heredado de " +
    "otra pantalla haría ver a un cliente activo como un cliente sin órdenes.",
}

const PICKER_MODULE = "@/components/date-range-picker"
const HOOK_MODULE = "@/hooks/use-date-range"

/**
 * `useState(defaultDateRange)` y también `useState(() => defaultDateRange())`.
 * La forma lazy no es un caso exótico: es el idiom MÁS correcto de los dos
 * (no llama a la función en cada render), así que es el más probable en el
 * archivo de alguien que reintroduzca el bug de buena fe. Un guard que solo
 * mira la forma directa deja pasar justo la versión bien escrita del error.
 */
const ESTADO_LOCAL = /useState[^(]*\(\s*(\(\s*\)\s*=>\s*)?defaultDateRange/

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    if (entry.name === "node_modules" || entry.name.startsWith(".")) continue
    const full = path.join(dir, entry.name)
    if (entry.isDirectory()) walk(full, out)
    else if (/\.tsx?$/.test(entry.name)) out.push(full)
  }
  return out
}

/**
 * Solo cuentan los IMPORTS, no cualquier mención del nombre. `reports/balance`
 * nombra `DateRangePicker` en un comentario para explicar por qué NO lo lleva
 * (es una foto a hoy, no un período): un match por texto suelto lo marcaría
 * como infractor y el guard mentiría desde el primer día.
 */
function importsFrom(source: string, module: string): string | null {
  const re = new RegExp(
    `import\\s+([\\s\\S]*?)\\s+from\\s+["']${module.replace(/[/@]/g, "\\$&")}["']`,
    "g",
  )
  const clauses = [...source.matchAll(re)].map((m) => m[1])
  return clauses.length > 0 ? clauses.join(" ") : null
}

describe("el rango de fecha del panel es global", () => {
  const files = SCAN_DIRS.flatMap((d) => walk(path.join(ROOT, d)))
  const rel = (f: string) => path.relative(ROOT, f).split(path.sep).join("/")

  const pickerConsumers = files.filter((f) => {
    const clause = importsFrom(fs.readFileSync(f, "utf8"), PICKER_MODULE)
    return clause !== null && /\bDateRangePicker\b/.test(clause)
  })

  it("el escaneo encuentra archivos (si la ruta del picker cambió, este test hay que actualizarlo)", () => {
    expect(files.length).toBeGreaterThan(100)
    expect(pickerConsumers.length).toBeGreaterThan(10)
    // El propio `date-range-picker.tsx` DEFINE el componente en vez de
    // importarlo, así que queda fuera del escaneo por construccion — no
    // necesita excepción.
    expect(pickerConsumers.map(rel)).toContain("app/(panel)/reports/summary/page.tsx")
  })

  it("todo consumidor del selector usa el hook global", () => {
    const infractores = pickerConsumers
      .map(rel)
      .filter((f) => !(f in ALLOWLIST))
      .filter((f) => importsFrom(fs.readFileSync(path.join(ROOT, f), "utf8"), HOOK_MODULE) === null)

    expect(
      infractores,
      `estas pantallas renderizan <DateRangePicker> con estado local, así que su ` +
        `rango se borra al navegar o recargar: ${infractores.join(", ")}. ` +
        `Usá \`useDateRange()\` de ${HOOK_MODULE}, o —si el rango tiene que ser ` +
        `local de verdad— agregá el archivo a ALLOWLIST en este test CON EL MOTIVO.`,
    ).toEqual([])
  })

  it("ningún consumidor del selector se queda con `defaultDateRange` como estado local", () => {
    // El síntoma exacto del bug original. Un archivo puede importar el hook y
    // aun así tener un `useState(defaultDateRange)` colgado de una migración a
    // medias; el rango se pintaría del global pero un segundo estado lo pisaría.
    const conEstadoLocal = pickerConsumers
      .map(rel)
      .filter((f) => !(f in ALLOWLIST))
      .filter((f) => ESTADO_LOCAL.test(fs.readFileSync(path.join(ROOT, f), "utf8")))

    expect(
      conEstadoLocal,
      `estos archivos siguen guardando el rango en estado local: ${conEstadoLocal.join(", ")}`,
    ).toEqual([])
  })

  it("el detector de estado local reconoce las dos formas de escribirlo", () => {
    // Un guard cuyo regex no matchea el bug es peor que no tener guard: pasa en
    // verde y da confianza falsa. Se verifica acá contra las dos formas.
    expect(ESTADO_LOCAL.test("const [range, setRange] = React.useState(defaultDateRange)")).toBe(true)
    expect(
      ESTADO_LOCAL.test(
        "const [range, setRange] = React.useState<DateRangeValue>(defaultDateRange)",
      ),
    ).toBe(true)
    expect(
      ESTADO_LOCAL.test("const [range, setRange] = React.useState(() => defaultDateRange())"),
    ).toBe(true)
    expect(ESTADO_LOCAL.test("const { range, setRange } = useDateRange()")).toBe(false)
  })

  it("la allowlist no tiene entradas muertas ni sin motivo", () => {
    // Una entrada que ya no corresponde a un consumidor real es una excepción
    // que nadie va a volver a mirar y que taparía una regresión futura si el
    // archivo vuelve a usar el picker.
    const consumidores = new Set(pickerConsumers.map(rel))
    for (const [file, motivo] of Object.entries(ALLOWLIST)) {
      expect(consumidores, `\`${file}\` está en ALLOWLIST pero ya no usa el picker`).toContain(file)
      expect(motivo.length, `\`${file}\` está en ALLOWLIST sin un motivo escrito`).toBeGreaterThan(60)
    }
  })
})
