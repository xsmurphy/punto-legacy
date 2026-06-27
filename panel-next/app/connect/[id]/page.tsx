import { headers } from "next/headers"
import { ConnectView } from "./connect-view"
import { InvalidLink } from "./invalid-link"

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

interface ConnectPageProps {
  params: Promise<{ id: string }>
}

export default async function ConnectPage({ params }: ConnectPageProps) {
  const { id } = await params

  if (!UUID_RE.test(id)) {
    return <InvalidLink reason="invalid-format" />
  }

  const apiBase = (
    process.env.API_URL ?? process.env.NEXT_PUBLIC_API_URL ?? ""
  ).replace(/\/$/, "")

  if (!apiBase) {
    return <InvalidLink reason="config-error" />
  }

  const headersList = await headers()
  const ua  = headersList.get("user-agent") ?? ""
  const xff = headersList.get("x-forwarded-for") ?? ""

  let openResult: { userCode: string; module: string } | null = null
  let errorReason = "unknown"

  try {
    const res = await fetch(
      `${apiBase}/v1/device_invitations.php?resource=open&id=${encodeURIComponent(id)}`,
      {
        method: "POST",
        headers: {
          "User-Agent":      ua,
          "X-Forwarded-For": xff,
          "Content-Type":    "application/x-www-form-urlencoded",
        },
        cache: "no-store",
      },
    )

    if (res.status === 404)  { errorReason = "not-found"; throw new Error("not-found") }
    if (res.status === 410)  { errorReason = "expired";   throw new Error("expired") }
    if (!res.ok)             { errorReason = "error";     throw new Error("error") }

    const body = (await res.json()) as {
      ok?: boolean
      data?: { userCode: string; module: string }
    }
    const data = (body?.data ?? body) as { userCode: string; module: string }
    if (!data?.userCode) { errorReason = "error"; throw new Error("no-user-code") }

    openResult = { userCode: data.userCode, module: data.module ?? "pos" }
  } catch {
    // errorReason ya seteado arriba
  }

  if (!openResult) {
    return <InvalidLink reason={errorReason} />
  }

  return (
    <ConnectView
      invitationId={id}
      userCode={openResult.userCode}
      module={openResult.module}
    />
  )
}
