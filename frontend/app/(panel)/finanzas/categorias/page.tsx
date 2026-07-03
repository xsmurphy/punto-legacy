import { redirect } from "next/navigation"

// Categorías se mudó a Configuración (sub-tab) — redirect para no romper
// links/bookmarks viejos.
export default function FinanzasCategoriasRedirect() {
  redirect("/finanzas/configuracion")
}
