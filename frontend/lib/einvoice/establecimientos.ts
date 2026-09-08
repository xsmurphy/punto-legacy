/**
 * Establecimientos fiscales del alta — derivación y armado de filas.
 *
 * Vive fuera del componente porque es LÓGICA, no pintura: de qué cajas sale la
 * lista de establecimientos a declarar es una regla del dominio fiscal
 * (context/29 §1) y se prueba sin montar la pantalla.
 */

import type { EInvoiceEstablishment } from "@/lib/types/einvoice"

/**
 * Domicilio preseleccionado de un establecimiento nuevo: Asunción.
 *
 * NO es una asunción sobre dónde está el tenant — este formulario solo existe
 * para el alta ante SIFEN, que es paraguaya por definición, y el catálogo del
 * que salen estos códigos es el de la autoridad tributaria de Paraguay. Es un
 * DEFAULT de la cascada, siempre editable: el que emite desde Encarnación
 * cambia el departamento y los tres campos se limpian solos.
 *
 * Los códigos y las descripciones son los del catálogo (`sifen-geo.json`) y se
 * copian EXACTOS: `ASUNCION (DISTRITO)` es el nombre que la SET le da al
 * distrito y a la ciudad, no una errata. Una descripción que no coincida con
 * su código declara un domicilio contradictorio.
 *
 * Decisión del owner (2026-09-08): la enorme mayoría de los comercios que se
 * dan de alta emiten desde Asunción, y hacer que cada uno recorra tres selects
 * para llegar al mismo lugar es fricción en el paso donde el alta ya se
 * frenaba.
 */
export const DEFAULT_ESTABLISHMENT_GEO = {
  departamento: 1,
  departamentoDescripcion: "CAPITAL",
  distrito: 1,
  distritoDescripcion: "ASUNCION (DISTRITO)",
  ciudad: 1,
  ciudadDescripcion: "ASUNCION (DISTRITO)",
} as const

/** Fila vacía de establecimiento — el código lo pone quien la crea. */
export function emptyEstablishment(codigo: string): EInvoiceEstablishment {
  return {
    codigo,
    direccion: "",
    numeroCasa: "",
    ...DEFAULT_ESTABLISHMENT_GEO,
    telefono: "",
    email: "",
    denominacion: "",
  }
}

/**
 * Qué establecimientos declara el comercio: el `EEE` del punto de expedición
 * (`EEE-PPP`) de cada caja ACTIVA con timbrado.
 *
 * No se le pregunta al comercio cuántos locales tiene: ya lo dijo al cargar
 * los timbrados, y preguntarlo de nuevo abriría la puerta a que declare un
 * establecimiento que ninguna caja usa (o al revés, que falte el de la caja
 * que sí factura — que es el que hace fallar el alta). Es la misma fuente que
 * lee el backend (`registerStamps()`), no una copia.
 */
export function establishmentCodesFromRegisters(
  registers: { status: boolean; fiscal: { invoicePrefix: string } }[] | undefined,
): string[] {
  const codes = new Set<string>()
  for (const r of registers ?? []) {
    if (!r.status) continue
    const match = /^(\d{3})-(\d{3})$/.exec(r.fiscal.invoicePrefix.trim())
    if (match) codes.add(match[1])
  }
  return [...codes].sort()
}

/**
 * Filas del formulario para los códigos detectados, hidratadas con lo que el
 * espejo `fiscal` haya guardado de un alta anterior (patrón `initial`).
 *
 * `previous` son las filas que el usuario está tipeando ahora: cuando los
 * timbrados terminan de cargar y aparece un código nuevo, lo tipeado no se
 * pierde.
 */
export function establishmentsForCodes(
  codes: string[],
  saved: EInvoiceEstablishment[] | undefined,
  previous: EInvoiceEstablishment[],
): EInvoiceEstablishment[] {
  return codes.map((codigo) => {
    const enCurso = previous.find((e) => e.codigo === codigo)
    if (enCurso) return enCurso
    const guardado = (saved ?? []).find((e) => String(e?.codigo ?? "") === codigo)
    if (!guardado) return emptyEstablishment(codigo)
    // Los vacíos del alta guardada NO pisan el default: un establecimiento
    // que quedó sin domicilio geográfico (el caso de un alta a medias) tiene
    // que mostrar la preselección, no tres selects en blanco. Lo que el
    // comercio SÍ declaró manda siempre.
    const declarado = Object.fromEntries(
      Object.entries(guardado).filter(([, v]) => v !== "" && v !== null && v !== undefined),
    )
    return { ...emptyEstablishment(codigo), ...declarado, codigo }
  })
}
