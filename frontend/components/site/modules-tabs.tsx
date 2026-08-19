"use client"

import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { MODULE_MOCKUPS } from "@/components/site/mockups"
import { MODULE_TABS } from "@/lib/site/modules"
import { cn } from "@/lib/utils"

/**
 * Sección "Tu negocio puede ser mucho más": tabs de módulos con un bloque
 * grande por tab — texto a un lado, mockup de UI al otro, lados alternados.
 */
export function ModulesTabs() {
  return (
    <section className="mx-auto w-full max-w-6xl px-4 py-24 md:px-6 md:py-32">
      <div className="mx-auto max-w-2xl text-center">
        {/* razón: escala display de marketing, no aplica escala panel (§14) */}
        <h2 className="text-balance text-3xl font-semibold tracking-tight md:text-5xl">
          Tu negocio puede ser mucho más
        </h2>
        <p className="mt-4 text-base text-muted-foreground md:text-lg">
          Ventas, caja, facturación y stock, desde el mismo sistema.
        </p>
      </div>

      <Tabs defaultValue={MODULE_TABS[0].key} className="mt-10">
        <TabsList className="mx-auto flex h-auto w-fit max-w-full flex-wrap justify-center gap-1 rounded-full p-1">
          {MODULE_TABS.map((tab) => (
            <TabsTrigger
              key={tab.key}
              value={tab.key}
              className="rounded-full px-4 py-1.5"
            >
              {tab.label}
            </TabsTrigger>
          ))}
        </TabsList>

        {MODULE_TABS.map((tab, index) => {
          const Mockup = MODULE_MOCKUPS[tab.mockup]
          const reversed = index % 2 === 1
          return (
            <TabsContent key={tab.key} value={tab.key} className="mt-8">
              <div className="grid overflow-hidden rounded-3xl border bg-muted/40 md:grid-cols-2">
                <div
                  className={cn(
                    "flex flex-col justify-center gap-4 p-8 md:p-14",
                    reversed && "md:order-2",
                  )}
                >
                  <h3 className="text-balance text-2xl font-semibold tracking-tight md:text-3xl">
                    {tab.title}
                  </h3>
                  <p className="text-base text-muted-foreground">
                    {tab.description}
                  </p>
                  <p className="text-sm text-muted-foreground">
                    Ideal para: {tab.idealFor}
                  </p>
                </div>
                <div
                  className={cn(
                    "flex items-center justify-center bg-gradient-to-br from-chart-1/15 via-transparent to-transparent p-8 md:p-14",
                    reversed && "md:order-1",
                  )}
                >
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
