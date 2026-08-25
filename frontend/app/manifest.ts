import type { MetadataRoute } from "next"

/**
 * Manifest de la PWA de la caja. Next lo sirve en `/manifest.webmanifest` con
 * el MIME correcto y enlaza `<link rel="manifest">` en TODAS las rutas del
 * app router (no hace falta declararlo a mano en ningún layout).
 *
 * `short_name` es lo que iOS y Android pintan DEBAJO del icono en la pantalla
 * de inicio, donde no entran más de ~12 caracteres. Decía "Caja" (y `name`,
 * "Punto Caja"), así que la app instalada se llamaba "Caja" en el teléfono del
 * comerciante: la marca es Punto. En iOS ese texto lo puede pisar
 * `apple-mobile-web-app-title`, que sale de `metadata.appleWebApp.title` en
 * `app/layout.tsx` — los dos dicen "Punto" a propósito.
 *
 * OJO: `name`/`short_name` se leen en el momento de instalar. Cambiarlos no
 * renombra una app YA instalada; hay que borrarla del teléfono y volver a
 * agregarla a la pantalla de inicio.
 *
 * `id` fija la identidad de la PWA de forma independiente de `start_url`. Sin
 * él, el id ES el `start_url`, y cualquier cambio futuro de ruta de arranque
 * haría que el navegador la trate como una app distinta (se instalaría dos
 * veces en lugar de actualizarse). Vale "/pos" —el default de hoy— así que
 * fijarlo ahora no altera ninguna instalación existente.
 *
 * `scope: "/pos"` es deliberado: esta PWA es la CAJA. Chrome solo ofrece
 * instalar cuando la página visitada cae dentro del scope, así que el panel
 * (`/`) NO es instalable con este manifest. Si el panel tiene que serlo, es
 * otra PWA con su propio manifest e id — decisión de producto, no un
 * ajuste de este archivo.
 *
 * Los iconos son artefactos derivados del logo: se regeneran con
 * `node scripts/generate-pwa-icons.mjs`. Van los cuatro tamaños porque `any` y
 * `maskable` NO son la misma imagen — ver el docblock de ese script.
 */
export default function manifest(): MetadataRoute.Manifest {
  return {
    id: "/pos",
    name: "Punto",
    short_name: "Punto",
    description: "Caja registradora del comercio",
    lang: "es",
    dir: "ltr",
    start_url: "/pos",
    scope: "/pos",
    display: "standalone",
    orientation: "any",
    theme_color: "#0a0a0a",
    background_color: "#0a0a0a",
    icons: [
      { src: "/icons/pos-192.png", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/icons/pos-512.png", sizes: "512x512", type: "image/png", purpose: "any" },
      {
        src: "/icons/pos-192-maskable.png",
        sizes: "192x192",
        type: "image/png",
        purpose: "maskable",
      },
      {
        src: "/icons/pos-512-maskable.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "maskable",
      },
    ],
  }
}
