/**
 * Entrega un `Blob` al usuario como archivo descargado.
 *
 * Vivía privado dentro de `components/data-table/data-table.tsx` (export a
 * XLSX). Se extrajo cuando apareció el segundo consumidor —el KuDE en PDF
 * desde el POS— porque el mecanismo es el mismo y copiarlo habría dejado dos
 * versiones del mismo `createObjectURL` para divergir.
 *
 * Por qué un `<a download>` sintético y no `window.open(url)`: el objeto URL es
 * efímero y el popup de una pestaña nueva disparado desde un `await` lo bloquea
 * el browser en móvil, que es donde corre el POS. El ancla no es un popup.
 *
 * `revokeObjectURL()` inmediato: el click ya inició la descarga, y no revocar
 * deja el blob retenido en memoria hasta que se descarga la pestaña — en una
 * caja que corre días sin recargar, eso se acumula.
 */
export function triggerDownload(blob: Blob, fileName: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement("a")
  a.href = url
  a.download = fileName
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
