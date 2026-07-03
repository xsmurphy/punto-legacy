"use client"

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { CategoriasSection } from "./categorias-section"
import { MediosPagoSection } from "./medios-pago-section"

export default function FinanzasConfiguracionPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="text-2xl font-semibold">Configuración</h1>
        <p className="text-sm text-muted-foreground">
          Categorías de ingresos/egresos y medios de pago del comercio.
        </p>
      </div>

      <Tabs defaultValue="categorias">
        <TabsList>
          <TabsTrigger value="categorias">Categorías</TabsTrigger>
          <TabsTrigger value="medios-pago">Medios de pago</TabsTrigger>
        </TabsList>
        <TabsContent value="categorias" className="mt-4">
          <CategoriasSection />
        </TabsContent>
        <TabsContent value="medios-pago" className="mt-4">
          <MediosPagoSection />
        </TabsContent>
      </Tabs>
    </div>
  )
}
