"use client"

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { MODULE_MOCKUPS } from "@/components/site/mockups"
import { MODULE_TABS } from "@/lib/site/modules"

/**
 * Sección de módulos: tabs con un bloque grande por tab. El texto va
 * SIEMPRE a la izquierda y el mockup a la derecha — alternar los lados al
 * cambiar de tab hace saltar la lectura.
 */
export function ModulesTabs() {
  return (
    <section className="mx-auto w-full max-w-6xl px-4 py-24 md:px-6 md:py-32">
      <div className="mx-auto max-w-2xl text-center">
        {/* razón: escala display de marketing, no aplica escala panel (§14) */}
        <h2 className="text-balance text-4xl font-semibold tracking-tight md:text-6xl">
          El sistema no termina en el Punto de Venta
        </h2>
        <p className="mt-4 text-base text-muted-foreground md:text-lg">
          Facturación, stock, clientes y reportes trabajando juntos, sin
          planillas al costado.
        </p>
      </div>

      <Tabs defaultValue={MODULE_TABS[0].key} className="mt-12">
        <TabsList className="mx-auto flex h-auto w-fit max-w-full flex-wrap justify-center gap-1 rounded-full bg-muted p-1.5">
          {MODULE_TABS.map((tab) => (
            <TabsTrigger
              key={tab.key}
              value={tab.key}
              // razón: pill activo en foreground sólido — sobre el body claro
              // el `bg-background` del primitive no se distingue del track
              className="rounded-full px-4 py-2 text-sm data-[state=active]:bg-foreground data-[state=active]:text-background"
            >
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>

        {MODULE_TABS.map((tab) => {
          const Mockup = MODULE_MOCKUPS[tab.mockup]
          return (
            <TabsContent key={tab.key} value={tab.key} className="mt-8">
              {/* Escena oscura fija sobre el body claro (acento, como el
                  hero) — no depende del tema del visitante. */}
              <div className="grid overflow-hidden rounded-3xl bg-neutral-950 text-white md:grid-cols-2">
                <div className="flex flex-col justify-center gap-4 p-8 md:p-14">
                  <h3 className="text-balance text-3xl font-semibold tracking-tight md:text-4xl">
                    {tab.title}
                  </h3>
                  <p className="text-base text-white/65">{tab.description}</p>
                  <p className="text-sm text-white/50">
                    Pensado para: {tab.idealFor}
                  </p>
                </div>
                <div className="flex items-center justify-center bg-[radial-gradient(100%_100%_at_80%_20%,rgba(1,215,161,0.16)_0%,transparent_60%)] p-8 md:p-14">
                  <Mockup />
                </div>
              </div>
            </TabsContent>
          )
        })}
      </Tabs>
    </section>
  )
}
