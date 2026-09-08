/**
 * Establecimientos fiscales del alta — derivación y armado de filas.
 *
 * Vive fuera del componente porque es LÓGICA, no pintura: de qué cajas sale la
 * lista de establecimientos a declarar es una regla del dominio fiscal
 * (context/29 §1) y se prueba sin montar la pantalla.
 */

import type { EInvoiceEstablishment } from "@/lib/types/einvoice"

/** Fila vacía de establecimiento — el código lo pone quien la crea. */
export function emptyEstablishment(codigo: string): EInvoiceEstablishment {
  return {
    codigo,
    direccion: "",
    numeroCasa: "",
    departamento: "",
    departamentoDescripcion: "",
    distrito: "",
    distritoDescripcion: "",
    ciudad: "",
    ciudadDescripcion: "",
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
    return guardado ? { ...emptyEstablishment(codigo), ...guardado, codigo } : emptyEstablishment(codigo)
  })
}
