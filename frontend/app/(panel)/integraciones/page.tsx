import Link from "next/link"

import { Button } from "@/components/ui/button"
import { ModuleCatalogPanel } from "@/components/modules/module-catalog-panel"

/**
 * Integraciones = puentes con sistemas de terceros (pasarelas de cobro,
 * facturación electrónica). Se separaron de /modules porque prenderlas no
 * alcanza: cada una necesita credenciales o un alta afuera de Punto. El
 * catálogo es el mismo archivo — acá se filtra por `kind: "integration"`.
 */
export default function IntegracionesPage() {
  return (
    <div className="mx-auto max-w-7xl px-4 py-6">
      <div className="flex flex-col gap-6">
        <header className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-2xl font-semibold">Integraciones</h1>
            <p className="text-sm text-muted-foreground">
              Conectá Punto con los sistemas externos que usa tu negocio.
            </p>
          </div>
          <Button variant="outline" asChild>
            <Link href="/modules">Ver módulos</Link>
          </Button>
        </header>
        <ModuleCatalogPanel kind="integration" />
      </div>
    </div>
  )
}
