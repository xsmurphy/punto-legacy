import type { MetadataRoute } from "next"

export default function manifest(): MetadataRoute.Manifest {
  return {
    name: "Punto Caja",
    short_name: "Caja",
    description: "Caja registradora del comercio",
    start_url: "/pos",
    scope: "/pos",
    display: "standalone",
    orientation: "any",
    theme_color: "#0a0a0a",
    background_color: "#0a0a0a",
    icons: [
      { src: "/icons/pos-192.png", sizes: "192x192", type: "image/png" },
      { src: "/icons/pos-512.png", sizes: "512x512", type: "image/png" },
      {
        src: "/icons/pos-512-maskable.png",
        sizes: "512x512",
        type: "image/png",
        purpose: "maskable",
      },
    ],
  }
}
