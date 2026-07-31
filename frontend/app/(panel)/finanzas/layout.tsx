"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"

import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Separator } from "@/components/ui/separator"

// 3 grupos por naturaleza: Operación (día a día) · Reportes (montos
// agregados) · Configuración (ABM/setup). El separador vertical entre
// grupos es solo visual — TABS sigue siendo una lista plana para el
// grid/scroll responsive existente.
const TABS = [
  { href: "/finanzas", label: "Resumen" },
  { href: "/finanzas/movimientos", label: "Movimientos" },
  { href: "/finanzas/cuentas", label: "Cuentas" },
  { href: "/finanzas/cheques", label: "Cheques" },
  { href: "/finanzas/creditos", label: "Créditos" },
  { href: "/finanzas/prevision", label: "Previsión" },
  { href: "/finanzas/conciliacion", label: "Conciliación" },
  { href: "/finanzas/reportes", label: "Reportes" },
  { href: "/finanzas/configuracion", label: "Configuración" },
] as const

// Índice donde empieza el segundo grupo (Reportes) — divide Operación del resto.
const GROUP_BREAK_INDEX = 7

export default function FinanzasLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname()
  // Match exacto salvo para /finanzas (resumen), que solo matchea la raíz —
  // si no, "/finanzas" (startsWith) quedaría siempre activo para todas las sub-rutas.
  const activeTab =
    TABS.find((t) => (t.href === "/finanzas" ? pathname === t.href : pathname.startsWith(t.href)))
      ?.href ?? "/finanzas"

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Finanzas</h1>
        <p className="text-sm text-muted-foreground">
          Cuentas, movimientos e ingresos/egresos del negocio.
        </p>
      </div>

      <Tabs value={activeTab}>
        <TabsList className="flex w-full justify-start gap-1 overflow-x-auto md:grid md:grid-cols-9 md:overflow-visible">
          {TABS.map((tab, i) => (
            <React.Fragment key={tab.href}>
              {i === GROUP_BREAK_INDEX && (
                <Separator orientation="vertical" className="mx-1 h-5 self-center" />
              )}
              <TabsTrigger value={tab.href} asChild className="shrink-0 md:shrink">
                <Link href={tab.href}>{tab.label}</Link>
              </TabsTrigger>
            </React.Fragment>
          ))}
        </TabsList>
      </Tabs>

      {children}
    </div>
  )
}
