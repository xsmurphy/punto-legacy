"use client"

import * as React from "react"
import Link from "next/link"
import { usePathname } from "next/navigation"

import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs"

const TABS = [
  { href: "/finanzas", label: "Resumen" },
  { href: "/finanzas/movimientos", label: "Movimientos" },
  { href: "/finanzas/cuentas", label: "Cuentas" },
  { href: "/finanzas/categorias", label: "Categorías" },
  { href: "/finanzas/ajustes", label: "Ajustes" },
] as const

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
        <TabsList>
          {TABS.map((tab) => (
            <TabsTrigger key={tab.href} value={tab.href} asChild>
              <Link href={tab.href}>{tab.label}</Link>
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      {children}
    </div>
  )
}
