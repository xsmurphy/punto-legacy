import Link from "next/link"

import { Button } from "@/components/ui/button"
import { ModuleCatalogPanel } from "@/components/modules/module-catalog-panel"

export default function ModulesPage() {
  return (
    <div className="mx-auto max-w-7xl px-4 py-6">
      <div className="flex flex-col gap-6">
        <header className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-2xl font-semibold">Módulos</h1>
            <p className="text-sm text-muted-foreground">
              Activá las funciones que tu negocio necesita.
            </p>
          </div>
          <Button variant="outline" asChild>
            <Link href="/integraciones">Ver integraciones</Link>
          </Button>
        </header>
        <ModuleCatalogPanel kind="module" />
      </div>
    </div>
  )
}
