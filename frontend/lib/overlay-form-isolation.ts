import type { FormEvent, FormEventHandler } from "react"

/**
 * AISLAMIENTO DEL SUBMIT EN OVERLAYS PORTALEADOS (Dialog / Drawer / Sheet /
 * AlertDialog).
 *
 * EL PROBLEMA (contraintuitivo — no "limpiar" esto sin leer):
 * un `<form>` que vive adentro de un modal NO tiene que enviar jamás el
 * `<form>` de la página que quedó atrás. En el DOM eso parece imposible: el
 * contenido del modal se monta en un portal colgado del `<body>`, así que los
 * dos forms NO están anidados. Pero React NO propaga los eventos sintéticos
 * por el árbol del DOM sino por el ÁRBOL DE REACT, y en ese árbol el
 * `<DialogContent>` sigue siendo hijo de donde se lo escribió. Resultado: el
 * `submit` del form del modal burbujea hasta el `onSubmit` del form de la
 * página, y esta se guarda sola.
 *
 * `e.preventDefault()` en el handler del modal NO alcanza: cancela el envío
 * nativo, no la propagación. Hace falta `stopPropagation()`.
 *
 * POR QUÉ VIVE EN EL PRIMITIVE Y NO EN CADA CALL-SITE:
 * no existe un caso donde el form de la página DEBA enterarse del submit del
 * modal, así que es una propiedad del contenedor, no una responsabilidad de
 * quien lo usa. Hay decenas de `<form>` dentro de modales y cualquiera que
 * hoy o mañana quede bajo un form de página hereda el defecto (bug reportado
 * por el owner 2026-08-24: ajustar stock sin motivo mostraba el error del
 * ajuste y acto seguido guardaba el artículo entero).
 *
 * ORDEN DE EJECUCIÓN: el handler propio del `<form>` interno corre PRIMERO —
 * es el target del evento y la propagación va de adentro hacia afuera; este
 * handler está en el contenedor, o sea más arriba. Cortar acá no puede
 * impedir que el modal haga su trabajo, solo que se lo cuente al de atrás.
 *
 * Compone en vez de reemplazar: si el call-site le pasó su propio `onSubmit`
 * al content, se ejecuta antes del corte.
 *
 * EL CORTE VA EN `finally`: si el handler del call-site lanza de forma
 * SÍNCRONA, un `stopPropagation()` en la línea siguiente nunca corre y el
 * submit sigue viaje hasta el form de la página — o sea, el bug original
 * vuelve exactamente en el caso donde el modal falló. Que hoy ningún
 * call-site lance no es una garantía: el `finally` la convierte en una
 * propiedad del contenedor, que es lo que este módulo promete.
 */
export function isolateOverlaySubmit<T extends HTMLElement>(
  onSubmit?: FormEventHandler<T>
): FormEventHandler<T> {
  return (event: FormEvent<T>) => {
    try {
      onSubmit?.(event)
    } finally {
      event.stopPropagation()
    }
  }
}
