"use client"

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { CategoriasSection } from "./categorias-section"
import { CentrosCostoSection } from "./centros-costo-section"
import { MediosPagoSection } from "./medios-pago-section"

export default function FinanzasConfiguracionPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Configuración</h1>
        <p className="text-sm text-muted-foreground">
          Categorías de ingresos/egresos, centros de costo y medios de pago del comercio.
        </p>
      </div>

      <Tabs defaultValue="categorias">
        <TabsList>
          <TabsTrigger value="categorias">Categorías</TabsTrigger>
          <TabsTrigger value="centros-costo">Centros de costo</TabsTrigger>
          <TabsTrigger value="medios-pago">Medios de pago</TabsTrigger>
        </TabsList>
        <TabsContent value="categorias" className="mt-4">
          <CategoriasSection />
        </TabsContent>
        {/* Al lado de categorías: son las dos taxonomías con las que se
            clasifica un gasto, y el operador las carga en la misma sesión. */}
        <TabsContent value="centros-costo" className="mt-4">
          <CentrosCostoSection />
        </TabsContent>
        <TabsContent value="medios-pago" className="mt-4">
          <MediosPagoSection />
        </TabsContent>
      </Tabs>
    </div>
  )
}
