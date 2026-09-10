import { redirect } from "next/navigation"

// El corte de ingresos/egresos por categoría, centro de costo y cuenta se
// mudó a /reports (2026-09-10): es LECTURA agregada, y su lugar es junto a
// Balance y Flujo de efectivo, no suelto adentro del módulo de operación.
// Redirect para no romper links viejos — mismo trato que /finanzas/ajustes
// y /finanzas/categorias cuando se fueron a Configuración.
export default function FinanzasReportesRedirect() {
  redirect("/reports/finance-breakdown")
}
