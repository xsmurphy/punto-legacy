import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * `rows` MANDA sobre `field-sizing-content`.
 *
 * El primitive nace con `field-sizing-content`: el alto lo define el contenido
 * y el piso lo pone `min-h-16` (~2 líneas). Con esa propiedad activa el
 * atributo `rows` NO tiene efecto — el campo arranca chico aunque el call-site
 * pida 4 filas, que es como los comentarios del POS terminaron viéndose como
 * un input de una sola línea (reporte del owner 2026-08-25).
 *
 * Se arregla en el wrapper y no en cada call-site (~15 en panel + POS): quien
 * declara `rows` está pidiendo un alto mínimo explícito, así que ahí se pasa a
 * `field-sizing-fixed` y el atributo vuelve a valer. Sin `rows` el
 * comportamiento es exactamente el de antes.
 */
function Textarea({ className, rows, ...props }: React.ComponentProps<"textarea">) {
  return (
    <textarea
      data-slot="textarea"
      rows={rows}
      className={cn(
        "flex min-h-16 w-full resize-none rounded-2xl border border-transparent bg-input/50 px-2.5 py-2 text-base transition-[color,box-shadow] duration-200 outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40",
        rows ? "field-sizing-fixed" : "field-sizing-content",
        className
      )}
      {...props}
    />
  )
}

export { Textarea }
