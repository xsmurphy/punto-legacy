import { api } from "@/lib/api-client"

export async function uploadTabular(csvFile: File): Promise<{
  sessionId: string
  columns: string[]
  rowCount: number
  sample: string[][]
  filename: string
}> {
  const formData = new FormData()
  formData.append("file", csvFile)

  const data = await api.postForm<{
    sessionId: string
    columns: string[]
    rowCount: number
    sample: string[][]
    filename: string
  }>("/v1/imports/upload", formData)

  return data
}

export async function generateImageThumbnail(file: File, maxPx = 200): Promise<string> {
  return new Promise((resolve) => {
    try {
      const img = new Image()
      const url = URL.createObjectURL(file)
      img.onload = () => {
        URL.revokeObjectURL(url)
        const canvas = document.createElement("canvas")
        const scale = Math.min(1, maxPx / Math.max(img.width, img.height))
        canvas.width  = Math.round(img.width  * scale)
        canvas.height = Math.round(img.height * scale)
        const ctx = canvas.getContext("2d")
        if (!ctx) { resolve(placeholderSvg()); return }
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
        resolve(canvas.toDataURL("image/jpeg", 0.7))
      }
      img.onerror = () => { URL.revokeObjectURL(url); resolve(placeholderSvg()) }
      img.src = url
    } catch {
      resolve(placeholderSvg())
    }
  })
}

function placeholderSvg(): string {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><rect width="40" height="40" fill="#e5e7eb"/><text x="20" y="26" font-size="18" text-anchor="middle" fill="#9ca3af">?</text></svg>`
  return `data:image/svg+xml;base64,${btoa(svg)}`
}
