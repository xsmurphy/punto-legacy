"use client"

import { FileText } from "lucide-react"

/**
 * El adjunto que el usuario mandó, dibujado DENTRO de su mensaje.
 *
 * Sin esto el archivo desaparecía al enviar: el modelo lo recibía y contestaba
 * sobre él, pero en el hilo solo quedaba el texto — el usuario no tenía forma
 * de saber qué documento había mandado, ni al releer la conversación después
 * (reporte del owner, 2026-09-08).
 *
 * Se dibuja desde el `file part` del propio mensaje, que es lo que se persiste
 * en el historial: por eso sobrevive al reload igual que el texto. La imagen se
 * muestra; el PDF va como ficha con su nombre, porque previsualizarlo pediría
 * un visor y acá alcanza con dejar constancia de QUÉ se mandó.
 */
export function MessageAttachment({
  mediaType,
  filename,
  url,
}: {
  mediaType: string
  filename?: string
  url: string
}) {
  const isImage = mediaType.startsWith("image/")

  if (isImage) {
    return (
      // eslint-disable-next-line @next/next/no-img-element -- data URL local del
      // adjunto que el usuario acaba de mandar: no hay origen remoto que
      // optimizar y `next/image` exige dimensiones que acá no conocemos.
      <img
        src={url}
        alt={filename ?? "Imagen adjunta"}
        className="max-h-64 max-w-[85%] rounded-2xl border border-border object-contain"
      />
    )
  }

  return (
    <div className="flex max-w-[85%] items-center gap-2 rounded-2xl border border-border bg-card px-3 py-2">
      <FileText className="size-4 shrink-0 text-muted-foreground" />
      <span className="truncate text-sm text-foreground">{filename ?? "Documento adjunto"}</span>
    </div>
  )
}
