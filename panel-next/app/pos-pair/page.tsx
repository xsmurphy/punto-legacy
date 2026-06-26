import { cookies } from "next/headers"
import { redirect } from "next/navigation"
import { PosPairForm } from "./pair-form"

/**
 * Pairing del POS — server component que valida ANTES de renderear.
 *
 * Sin cookie `_jwt_panel` (sesión panel del admin) → redirect server-side a
 * /login?next=/pos-pair, sin renderear el form ni filtrar datos del tenant.
 *
 * El form en sí (PairForm) corre client-side y carga el bootstrap; si la
 * cookie está presente pero inválida/expirada, el useBootstrap dará 401 y
 * redirige a login también — defensa en profundidad.
 */
export default async function PosPairPage() {
  const cookieStore = await cookies()
  const jwtPanel = cookieStore.get("_jwt_panel")?.value
  if (!jwtPanel) {
    redirect("/login?next=/pos-pair")
  }
  return <PosPairForm />
}
