"use client"

/**
 * Cuentas por cobrar y pagar.
 *
 * Tres pestañas (2026-09-11): Dashboard (el resumen de las dos puntas juntas),
 * Por cobrar y Por pagar (el listado de siempre, filtrado por lado). Antes eran
 * dos listados separados por un toggle y no había ningún lugar donde ver el
 * neto entre lo que entra y lo que sale.
 *
 * ── La URL ───────────────────────────────────────────────────────────────────
 * `?tab=dashboard|cobrar|pagar`. `?state=income|outcome` —el parámetro viejo—
 * se sigue entendiendo: los links del sidebar, el buscador de comandos, el
 * agente y cualquier favorito del usuario apuntan ahí, y romperlos para
 * renombrar un query param no le arregla nada a nadie.
 *
 * ── Por qué las pestañas se ocultan por permiso ──────────────────────────────
 * El backend gatea cobrar con `reports.sales.view` y pagar con
 * `reports.purchases.view`, y el Dashboard —que devuelve las dos cosas en una
 * respuesta— exige LAS DOS. Mostrar una pestaña que solo puede terminar en 403
 * es ofrecer algo que no existe; el gate real sigue siendo el del endpoint,
 * esto es nada más no invitar a chocarse.
 */

import * as React from "react"
import Link from "next/link"
import { useSearchParams, useRouter } from "next/navigation"
import { ArrowLeft } from "lucide-react"

import { Button } from "@/components/ui/button"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { OpenInvoicesDashboardTab } from "@/components/domain/reports/open-invoices/open-invoices-dashboard-tab"
import { OpenInvoicesListTab } from "@/components/domain/reports/open-invoices/open-invoices-list-tab"

type TabId = "dashboard" | "cobrar" | "pagar"

export default function OpenInvoicesReportPage() {
  // useSearchParams() requiere Suspense boundary (Next App Router) — mismo
  // patrón que items/page.tsx; ver comentario en pos/layout.tsx.
  return (
    <React.Suspense fallback={null}>
      <OpenInvoicesReportPageInner />
    </React.Suspense>
  )
}

function OpenInvoicesReportPageInner() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const { data: bootstrap } = useBootstrap()

  // Mientras el bootstrap carga no se sabe qué permisos hay. Se asume que sí:
  // ocultar todo por un instante haría parpadear la barra de pestañas en cada
  // visita, y quien no tenga el permiso se lo va a encontrar igual en el 403
  // del endpoint, que es el gate de verdad.
  const perms = bootstrap?.user?.permissions
  const canSales = perms ? perms.includes("reports.sales.view") : true
  const canPurchases = perms ? perms.includes("reports.purchases.view") : true

  const allowed = React.useMemo<TabId[]>(() => {
    const out: TabId[] = []
    if (canSales && canPurchases) out.push("dashboard")
    if (canSales) out.push("cobrar")
    if (canPurchases) out.push("pagar")
    return out
  }, [canSales, canPurchases])

  const requested = resolveTab(searchParams.get("tab"), searchParams.get("state"))
  const tab: TabId = allowed.includes(requested) ? requested : (allowed[0] ?? "dashboard")

  const onTabChange = (next: string) => {
    const v = resolveTab(next, null)
    const sp = new URLSearchParams(searchParams.toString())
    sp.set("tab", v)
    // `state` queda obsoleto en cuanto el usuario elige una pestaña: si
    // sobreviviera en la URL, un refresh podría contradecir lo que se ve.
    sp.delete("state")
    router.replace(`/reports/open-invoices?${sp.toString()}`, { scroll: false })
  }

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <BackLink />
        <h1 className="text-2xl font-semibold">Cuentas por cobrar y pagar</h1>
        <p className="text-sm text-muted-foreground">
          Ventas y compras a crédito sin saldar: cuánto falta cobrar, cuánto falta pagar y
          cuándo vence cada cosa.
        </p>
      </header>

      <Tabs value={tab} onValueChange={onTabChange} className="flex flex-col gap-4">
        <TabsList>
          {allowed.includes("dashboard") && <TabsTrigger value="dashboard">Dashboard</TabsTrigger>}
          {allowed.includes("cobrar") && <TabsTrigger value="cobrar">Por cobrar</TabsTrigger>}
          {allowed.includes("pagar") && <TabsTrigger value="pagar">Por pagar</TabsTrigger>}
        </TabsList>

        <TabsContent value="dashboard" className="m-0">
          <OpenInvoicesDashboardTab />
        </TabsContent>
        <TabsContent value="cobrar" className="m-0">
          <OpenInvoicesListTab state="income" />
        </TabsContent>
        <TabsContent value="pagar" className="m-0">
          <OpenInvoicesListTab state="outcome" />
        </TabsContent>
      </Tabs>
    </div>
  )
}

/** `?tab=` manda; `?state=` es el deep link viejo y se sigue respetando. */
function resolveTab(tab: string | null, state: string | null): TabId {
  if (tab === "cobrar" || tab === "pagar" || tab === "dashboard") return tab
  if (state === "outcome") return "pagar"
  if (state === "income") return "cobrar"
  return "dashboard"
}

function BackLink() {
  return (
    <Button
      asChild
      variant="ghost"
      size="sm"
      className="w-fit h-7 -ml-2 text-xs text-muted-foreground hover:text-foreground"
    >
      <Link href="/reports">
        <ArrowLeft className="size-3.5" />
        Volver a reportes
      </Link>
    </Button>
  )
}
