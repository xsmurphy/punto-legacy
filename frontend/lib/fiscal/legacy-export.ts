/**
 * Escritor de los dos archivos fiscales PY (RG90 y Libro Ventas) en el FORMATO
 * DEL LEGACY — decisión del owner, 2026-09-11.
 *
 * Los contadores ya presentan estos archivos tal como los emitía el panel
 * ENCOM, así que el formato de salida es ese. Y el del legacy no es un XLSX:
 * `generateXLSfromArray()` armaba un TSV (celdas separadas por TAB, filas por
 * CRLF) y lo mandaba con `Content-Type: application/vnd.ms-excel` y extensión
 * `.xls`. Excel lo abre igual, y es el archivo con el que trabajan.
 *
 * Por eso estos dos exports NO pasan por `exportRowsToXlsx()` —el export
 * genérico de `<DataTable>`, que produce un XLSX real con exceljs— y tienen su
 * propio escritor acá. No es duplicación: son dos formatos distintos con
 * destinatarios distintos. El resto de los listados sigue exportando XLSX.
 *
 * ── Por qué el formateo de montos vive en el FRONT ──
 * El backend manda los montos como NÚMEROS (`FiscalService`), que es lo
 * correcto: son el dato. Cómo se escriben —con separador de miles, con cuántos
 * decimales— depende de la config del tenant (`thousand` / `decimal` del
 * bootstrap), que es la misma dimensión que ya resuelve `formatAmount()` para
 * toda la UI. Duplicar esa resolución en PHP habría dado dos formateadores del
 * mismo número para divergir.
 */

import { triggerDownload } from "@/lib/download-blob"
import { formatAmount } from "@/lib/format-money"
import type { TenantLocaleConfig } from "@/lib/tenant-locale"

export type FiscalDataset = "rg90" | "libro-ventas"

export type FiscalRow = Record<string, string | number>

/**
 * Columnas de MONTO de cada dataset, por su encabezado exacto.
 *
 * No alcanza con mirar `typeof value === "number"`: los códigos de la SET
 * (tipo de identificación del comprador, tipo de comprobante, condición de
 * venta) también viajan como números y NO llevan separador de miles — un
 * "1.090" en la columna de tipo de comprobante invalida la fila.
 *
 * Estos strings son el contrato con `api/lib/Reports/FiscalService.php`: si
 * allá cambia un encabezado, tiene que cambiar acá. El layout de los dos
 * archivos es fijo por definición (Marangatu para RG90, la planilla del
 * contador para Libro Ventas), así que el contrato es estable a propósito.
 */
const MONEY_COLUMNS: Record<FiscalDataset, ReadonlySet<string>> = {
  rg90: new Set([
    "MONTO GRAVADO AL 10%",
    "MONTO GRAVADO AL 5%",
    "MONTO NO GRAVADO O EXENTO",
    "MONTO TOTAL DEL COMPROBANTE",
  ]),
  "libro-ventas": new Set([
    "GRAV. 10%",
    "IVA 10%",
    "GRAV. 5%",
    "IVA 5%",
    "EXENTA",
    "TOTAL",
    "10%",
    "5%",
    "EXENTO",
  ]),
}

/**
 * Cómo se escribe un monto en cada archivo, calcado del legacy:
 *
 *  - RG90 → `formatCurrentNumber()`: TEXTO con el separador de miles del
 *    tenant y sus decimales.
 *  - Libro Ventas → `round()`: entero pelado, sin separadores.
 *
 * La única desviación: el legacy dejaba la columna TOTAL del Libro Ventas SIN
 * redondear (todas sus vecinas pasaban por `round()`, esa no), así que una
 * venta con decimales metía un `12345.6` en medio de una columna de enteros.
 * Acá se redondea igual — copiar el formato no incluye copiar el descuido.
 */
function formatMoneyCell(
  value: number,
  dataset: FiscalDataset,
  locale: TenantLocaleConfig | null,
): string {
  return dataset === "rg90" ? formatAmount(value, locale) : String(Math.round(value))
}

/**
 * Una celda no puede contener el separador de columnas ni el de filas: un
 * nombre de cliente con un TAB adentro correría todas las columnas siguientes
 * de esa fila. El legacy hacía `implode("\t", $row)` sin sanear y comía ese
 * riesgo; acá los blancos se colapsan a un espacio.
 */
function sanitizeCell(text: string): string {
  return text.replace(/[\t\r\n]+/g, " ")
}

/** Contenido del archivo: encabezados + filas, TAB entre celdas y CRLF al final de cada fila. */
export function buildFiscalTsv(
  rows: FiscalRow[],
  dataset: FiscalDataset,
  locale: TenantLocaleConfig | null,
): string {
  if (rows.length === 0) return ""

  // El ORDEN de las columnas es el que manda el backend (el orden de las
  // claves del objeto, que es el orden del layout). Acá no se reordena ni se
  // renombra nada: el layout es responsabilidad del service que lo arma.
  const headers = Object.keys(rows[0])
  const money = MONEY_COLUMNS[dataset]

  const lines = [headers.map(sanitizeCell).join("\t")]

  for (const row of rows) {
    const cells = headers.map((header) => {
      const value = row[header]
      if (value === null || value === undefined) return ""
      if (typeof value === "number" && money.has(header)) {
        return formatMoneyCell(value, dataset, locale)
      }
      return sanitizeCell(String(value))
    })
    lines.push(cells.join("\t"))
  }

  return lines.map((line) => `${line}\r\n`).join("")
}

/**
 * Nombre del archivo, como el legacy: `RG90-dd-mm-YYYY.xls` /
 * `VENTAS-dd-mm-YYYY.xls`.
 *
 * La fecha es la de HOY —la de emisión del archivo—, NO la del rango del
 * reporte. Es lo que el contador espera ver y con lo que ya tiene sus
 * carpetas armadas.
 */
export function fiscalFileName(dataset: FiscalDataset, today: Date = new Date()): string {
  const dd = String(today.getDate()).padStart(2, "0")
  const mm = String(today.getMonth() + 1).padStart(2, "0")
  const yyyy = today.getFullYear()
  const label = dataset === "rg90" ? "RG90" : "VENTAS"

  return `${label}-${dd}-${mm}-${yyyy}.xls`
}

/** Arma el archivo y lo entrega al usuario. */
export function downloadFiscalLegacyFile(
  rows: FiscalRow[],
  dataset: FiscalDataset,
  locale: TenantLocaleConfig | null,
): void {
  const tsv = buildFiscalTsv(rows, dataset, locale)
  // El mismo Content-Type que mandaba el legacy: es lo que hace que el doble
  // clic abra Excel en vez del editor de texto.
  const blob = new Blob([tsv], { type: "application/vnd.ms-excel;charset=utf-8" })
  triggerDownload(blob, fiscalFileName(dataset))
}
