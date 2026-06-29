export type AttachmentKind = "tabular" | "image" | "pdf" | "doc" | "unknown"

export type AttachmentDraft = {
  id: string
  file: File
  kind: AttachmentKind
  status: "pending" | "uploading" | "ready" | "error"
  sessionId?: string
  columns?: string[]
  rowCount?: number
  sample?: string[][]
  filename?: string
  thumbnailDataUrl?: string
  error?: string
}

export function detectKind(file: File): AttachmentKind {
  const name = file.name.toLowerCase()
  if (/\.(xlsx|xls|csv|tsv|txt)$/.test(name)) return "tabular"
  if (/\.(png|jpe?g|webp|heic|gif)$/.test(name)) return "image"
  if (/\.pdf$/.test(name)) return "pdf"
  if (/\.(md|markdown)$/.test(name)) return "doc"
  return "unknown"
}
