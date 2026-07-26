import { posFetch } from "@/lib/api/pos-fetch"
import type { DeviceModule } from "@/lib/auth/device-token"

/**
 * `module` identifica con qué token de device se autentica el BFF. Default
 * `"pos"` (todos los call-sites de caja); la Estación de Impresión pasa
 * `"print"` — ver comentario en `lib/api/pos-fetch.ts`.
 */
export async function sendBytesViaNetwork(
  host: string,
  port: number,
  bytes: Uint8Array,
  module: DeviceModule = "pos",
): Promise<void> {
  let binary = ""
  const len = bytes.length
  for (let i = 0; i < len; i++) {
    binary += String.fromCharCode(bytes[i])
  }
  const b64 = btoa(binary)

  const res = await posFetch("/api/pos/print", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ host, port, bytes: b64 }),
  }, module)

  if (!res.ok) {
    let msg = `Error ${res.status}`
    try {
      const json = await res.json()
      msg = (json as { error?: { message?: string } })?.error?.message ?? msg
    } catch {
      // ignorar
    }
    throw new Error(`Impresora de red ${host}:${port} — ${msg}`)
  }
}
