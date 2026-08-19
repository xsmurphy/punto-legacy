import type { Metadata } from "next"

import { CtaFinal, QuickLinks } from "@/components/site/cta-final"
import { FeaturesGrid } from "@/components/site/features-grid"
import { SiteHero } from "@/components/site/hero"
import { ModulesTabs } from "@/components/site/modules-tabs"
import { ShowcaseCarousel } from "@/components/site/showcase-carousel"

export const metadata: Metadata = {
  title: "Punto — Tu negocio, en un solo punto",
  description:
    "Ventas, caja, facturación electrónica SIFEN, stock y clientes en un solo sistema. Para minimarkets, restaurantes, farmacias y más.",
  openGraph: {
    title: "Punto — Tu negocio, en un solo punto",
    description:
      "Ventas, caja, facturación electrónica SIFEN, stock y clientes en un solo sistema.",
    locale: "es_PY",
    type: "website",
  },
}

export default function HomePage() {
  return (
    <>
      <SiteHero />
      <ShowcaseCarousel />
      <ModulesTabs />
      <FeaturesGrid />
      <QuickLinks />
      <CtaFinal />
    </>
  )
}
