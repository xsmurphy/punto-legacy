import { NextRequest, NextResponse } from "next/server"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

function getApiBase(): string {
  const url = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL
  if (!url) throw new Error("API base URL missing.")
  return url.replace(/\/$/, "")
}

const HOST_OVERRIDE = process.env.PUNTO_SHARED_API_HOST

export async function POST(req: NextRequest): Promise<NextResponse> {
  let body: unknown
  try {
    body = await req.json()
  } catch {
    return NextResponse.json({ ok: false, error: { message: "Body JSON inválido" } }, { status: 400 })
  }

  const base = getApiBase()
  const cookieHeader = req.headers.get("cookie") ?? ""
  const headers = new Headers()
  headers.set("cookie", cookieHeader)
  headers.set("accept", "application/json")
  headers.set("content-type", "application/json")
  if (HOST_OVERRIDE) headers.set("host", HOST_OVERRIDE)

  let upstream: Response
  try {
    upstream = await fetch(`${base}/v1/print.php`, {
      method: "POST",
      headers,
      body: JSON.stringify(body),
      cache: "no-store",
      redirect: "manual",
    })
  } catch (err) {
    console.error("[bff /api/pos/print] upstream fetch failed", err instanceof Error ? err.message : err)
    return NextResponse.json(
      { ok: false, error: { message: "BFF no pudo contactar la API", code: 502 } },
      { status: 502 },
    )
  }

  const text = await upstream.text()
  const respHeaders = new Headers()
  upstream.headers.forEach((v, k) => {
    const lower = k.toLowerCase()
    if (["transfer-encoding", "content-encoding", "connection"].includes(lower)) return
    respHeaders.set(k, v)
  })
  if (!respHeaders.has("content-type")) respHeaders.set("content-type", "application/json")

  return new NextResponse(text, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: respHeaders,
  })
}
