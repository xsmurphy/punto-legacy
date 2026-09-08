import type { AttachmentDraft } from "./attachment-types"

/**
 * Los adjuntos listos para viajar al modelo, como `file parts`.
 *
 * Definición ÚNICA, compartida por las dos superficies del chat (la página
 * `/chat` y el FAB `agent-chat-content.tsx`). Vivía duplicada en las dos y esa
 * es exactamente la forma en que una arregla un caso y la otra no —el bug del
 * 2026-09-08 fue justamente que una de las dos ni siquiera cableaba adjuntos.
 *
 * Solo `image` y `pdf`: los tabulares (Excel/CSV) NO van por acá — se suben
 * aparte y viajan como `sessionId` dentro del texto, porque el modelo no
 * necesita el archivo sino sus filas ya parseadas.
 *
 * ── El tope TOTAL, y por qué no alcanza el de cada archivo ──────────────────
 *
 * Cada adjunto ya se corta en 8 MB al procesarse, pero el caso real del owner
 * es mandar VARIAS facturas juntas: cinco archivos que pasan el filtro
 * individual arman un body de ~40 MB en base64 y el envío muere sin mensaje
 * útil. El techo total se aplica acá, en el único lugar por donde pasan todos
 * los adjuntos de un mensaje, y devuelve qué entra y qué queda afuera para que
 * la UI lo diga ANTES de enviar — no después de un 413 mudo.
 *
 * Los que no entran se devuelven en `rejected`: NO se descartan en silencio.
 * El orden se respeta (los primeros elegidos entran primero), así que el
 * usuario puede mandar el resto en un segundo mensaje.
 */
export const MAX_TOTAL_ATTACHMENT_BYTES = 16 * 1024 * 1024

export interface AgentFilePart {
  type: "file"
  mediaType: string
  filename?: string
  url: string
}

export interface CollectedFiles {
  files: AgentFilePart[]
  /** Nombres de los adjuntos que no entraron por el techo total. */
  rejected: string[]
}

export function collectReadyFiles(attachments: AttachmentDraft[] | undefined): CollectedFiles {
  const ready = (attachments ?? []).filter(
    (a) => (a.kind === "image" || a.kind === "pdf") && a.status === "ready" && a.dataUrl,
  )

  const files: AgentFilePart[] = []
  const rejected: string[] = []
  let total = 0

  for (const a of ready) {
    const name = a.filename ?? a.file.name
    if (total + a.file.size > MAX_TOTAL_ATTACHMENT_BYTES) {
      rejected.push(name)
      continue
    }
    total += a.file.size
    files.push({
      type: "file",
      mediaType: a.file.type || (a.kind === "pdf" ? "application/pdf" : "image/png"),
      filename: name,
      url: a.dataUrl as string,
    })
  }

  return { files, rejected }
}
