import type { Metadata } from "next"

/**
 * El reloj de marcación se instala como SU PROPIA app ("Asistencia"), separada
 * de la PWA de la caja (context/83 §9.2: es un aparato dedicado de la entrada,
 * no una función del POS). Por eso este segmento pisa el manifest global de
 * `app/manifest.ts` —que es la identidad de la caja, id "/pos", y NO se toca—
 * con uno propio: id y scope "/marcacion", nombre "Asistencia" bajo el icono.
 *
 * `appleWebApp` + el tag viejo en `other`: mismo patrón (y mismos porqués) que
 * `app/layout.tsx` — sin el nombre con prefijo, un iOS anterior a 15.4 abre la
 * app instalada como pestaña de Safari con barra de direcciones.
 *
 * El pareo vive en localStorage del origen: instalar desde Safari (no desde el
 * visor de links de otra app, que es efímero) conserva el reloj conectado
 * entre aperturas.
 */
export const metadata: Metadata = {
  title: "Asistencia",
  manifest: "/manifest-asistencia.webmanifest",
  appleWebApp: {
    capable: true,
    title: "Asistencia",
    statusBarStyle: "black-translucent",
  },
  other: {
    "apple-mobile-web-app-capable": "yes",
  },
}

export default function MarcacionLayout({ children }: { children: React.ReactNode }) {
  return children
}
