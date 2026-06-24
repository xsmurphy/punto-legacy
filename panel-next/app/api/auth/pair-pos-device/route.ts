import { NextRequest } from "next/server"
import { cookies } from "next/headers"

export async function POST(req: NextRequest) {
  const apiUrl = process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? ""
  const body   = await req.json()

  const cookieStore = await cookies()
  const cookieHeader = cookieStore.getAll().map((c) => `${c.name}=${c.value}`).join("; ")

  const res = await fetch(`${apiUrl}/v1/auth/pair-pos-device`, {
    method:  "POST",
    headers: {
      "Content-Type": "application/json",
      "Cookie":       cookieHeader,
    },
    body: JSON.stringify(body),
  })

  const data = await res.json().catch(() => ({}))

  const responseInit: ResponseInit = { status: res.status }
  const setCookie = res.headers.get("set-cookie")
  if (setCookie) {
    responseInit.headers = { "Set-Cookie": setCookie }
  }

  return Response.json(data, responseInit)
}
