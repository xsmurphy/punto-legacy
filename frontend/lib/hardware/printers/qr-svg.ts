import { renderToStaticMarkup } from "react-dom/server"
import { createElement } from "react"
import { QRCodeSVG } from "qrcode.react"

/**
 * QR como STRING de SVG, para los renderers que arman HTML por concatenación
 * (`html-renderer.ts`) en vez de montar React.
 *
 * ── Por qué esta vía y no otra librería ─────────────────────────────────
 *
 * `qrcode.react` ya es la única fuente de QR del proyecto: la pantalla de
 * pareo del POS y `<PspQrDialog>` (cobro con QR de una pasarela) pintan con
 * ella. Sumar un segundo generador para la impresión significaría que el QR
 * que el cajero ve en pantalla y el que sale impreso los produce código
 * distinto — y un QR mal generado en un comprobante fiscal no se detecta hasta
 * que alguien lo escanea. `renderToStaticMarkup` es lo que traduce ese
 * componente al string que el renderer de hoja necesita; `react-dom` ya es
 * dependencia, así que no entra nada nuevo al bundle.
 *
 * ── Por qué SVG y no canvas ─────────────────────────────────────────────
 *
 * El destino es una impresora de HOJA: el SVG escala a la resolución real del
 * driver, un canvas se rasteriza al tamaño CSS y sale borroneado justo en los
 * módulos chicos, que es lo que rompe la lectura. Además `QRCodeCanvas`
 * necesita un DOM montado y un frame para dibujarse; el SVG se serializa
 * sincrónico, que es lo que exige `renderTemplateToHtml` (es una función SYNC
 * — ver el docblock de `print-in-browser.ts` sobre por qué la impresión no
 * espera a nada).
 *
 * ── Nivel de corrección de errores ──────────────────────────────────────
 *
 * 'M' (~15%), el mismo que usa el camino ESC/POS (`render-template.ts`). En
 * papel el QR se arruga, se mancha y se dobla: con 'L' (7%) un pliegue lo
 * vuelve ilegible, y 'Q'/'H' agrandan la matriz más de lo que entra en el
 * bloque que el operador dibujó. El KuDE de referencia usa una matriz de esta
 * densidad.
 */
export function qrSvgMarkup(value: string, opts: { sizePx?: number } = {}): string {
  const size = opts.sizePx && opts.sizePx > 0 ? opts.sizePx : 128

  return renderToStaticMarkup(
    createElement(QRCodeSVG, {
      value,
      size,
      level: "M",
      // Sin `marginSize` el QR queda pegado al contenido vecino y muchos
      // lectores fallan: el "quiet zone" es parte de la especificación, no un
      // margen estético. 4 módulos es el mínimo que pide la norma.
      marginSize: 4,
      // Negro sobre blanco explícito: el bloque puede caer sobre una plantilla
      // con fondo, y un QR que hereda color no escanea.
      bgColor: "#ffffff",
      fgColor: "#000000",
    }),
  )
}
