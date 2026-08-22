import { CONTACTO } from "@/lib/site/contacto"
import { SITE_URL } from "@/lib/site/links"
import { getMarket } from "@/lib/site/markets"

/*
 * Datos estructurados (schema.org). Le dicen a Google qué es Punto, dónde
 * está y cuánto cuesta — habilita el panel de conocimiento, el mapa en la
 * búsqueda local y el precio en el resultado.
 */

function JsonLd({ data }: { data: Record<string, unknown> }) {
  return (
    <script
      type="application/ld+json"
      // El contenido es nuestro y estático: no hay entrada de usuario acá.
      dangerouslySetInnerHTML={{ __html: JSON.stringify(data) }}
    />
  )
}

/** La empresa y su ubicación — va en el layout del sitio. */
export function OrganizationJsonLd() {
  const market = getMarket()
  return (
    <JsonLd
      data={{
        "@context": "https://schema.org",
        "@type": "Organization",
        name: "Punto",
        url: SITE_URL,
        logo: `${SITE_URL}/logos/logo_bg_light.png`,
        description: `Sistema de punto de venta, facturación electrónica y gestión para comercios de ${market.pais}.`,
        address: {
          "@type": "PostalAddress",
          streetAddress: CONTACTO.direccion,
          addressLocality: "Asunción",
          addressCountry: market.code,
        },
        contactPoint: {
          "@type": "ContactPoint",
          telephone: CONTACTO.telefono.replace(/\s/g, ""),
          contactType: "sales",
          areaServed: market.code,
          availableLanguage: "Spanish",
        },
      }}
    />
  )
}

/** El producto y su precio — va en la página de precios. */
export function ProductJsonLd() {
  const market = getMarket()
  return (
    <JsonLd
      data={{
        "@context": "https://schema.org",
        "@type": "SoftwareApplication",
        name: "Punto",
        applicationCategory: "BusinessApplication",
        operatingSystem: "Web, Android, iOS",
        description:
          "Punto de venta, facturación electrónica, stock, clientes y reportes en un mismo sistema.",
        url: `${SITE_URL}/precios`,
        offers: {
          "@type": "Offer",
          price: market.plan.precio,
          priceCurrency: market.moneda.codigo,
          url: `${SITE_URL}/precios`,
        },
      }}
    />
  )
}

/** Las preguntas frecuentes — habilita el bloque desplegable en Google. */
export function FaqJsonLd({ faqs }: { faqs: { q: string; a: string }[] }) {
  return (
    <JsonLd
      data={{
        "@context": "https://schema.org",
        "@type": "FAQPage",
        mainEntity: faqs.map((faq) => ({
          "@type": "Question",
          name: faq.q,
          acceptedAnswer: { "@type": "Answer", text: faq.a },
        })),
      }}
    />
  )
}
