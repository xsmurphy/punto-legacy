import { NextRequest } from "next/server"
import { bffProxy } from "@/lib/bff/proxy"

export const runtime = "nodejs"
export const dynamic = "force-dynamic"

export async function POST(req: NextRequest) {
  return bffProxy(req, {
    upstreamPath: "/v1/print.php",
    contentType: "application/json",
    requireBearer: true,
  })
}
