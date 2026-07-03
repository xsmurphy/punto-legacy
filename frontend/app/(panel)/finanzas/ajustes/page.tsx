import { redirect } from "next/navigation"

// Ajustes se renombró a Configuración (sub-tab "Medios de pago") — redirect
// para no romper links/bookmarks viejos.
export default function FinanzasAjustesRedirect() {
  redirect("/finanzas/configuracion")
}
