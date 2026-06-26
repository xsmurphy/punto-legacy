export async function sendBytesViaNetwork(
  host: string,
  port: number,
  bytes: Uint8Array,
): Promise<void> {
  let binary = ""
  const len = bytes.length
  for (let i = 0; i < len; i++) {
    binary += String.fromCharCode(bytes[i])
  }
  const b64 = btoa(binary)

  const res = await fetch("/api/pos/print", {
    method: "POST",
    headers: { "content-type": "application/json" },
    body: JSON.stringify({ host, port, bytes: b64 }),
  })

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
