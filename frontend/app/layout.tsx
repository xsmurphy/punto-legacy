import type { Metadata, Viewport } from "next"
import { Inter, JetBrains_Mono } from "next/font/google"

import "./globals.css"
import { ThemeProvider } from "@/components/theme-provider"
import { Providers } from "@/components/providers"
import { cn } from "@/lib/utils"

const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
})

const jetbrainsMono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-jetbrains-mono",
  display: "swap",
})

/**
 * Metadata de la PWA. Todo esto se declara con la API de metadata de Next
 * —no con `<meta>` a mano en el `<head>`— porque Next deduplica, resuelve las
 * URLs de los iconos con su hash de build y deja que una ruta hija pise un
 * campo puntual sin repetir el resto.
 *
 * `appleWebApp` emite, en Next 16:
 *   capable        → <meta name="mobile-web-app-capable">
 *   title          → <meta name="apple-mobile-web-app-title">
 *   statusBarStyle → <meta name="apple-mobile-web-app-status-bar-style">
 *
 * Ojo con el primero: Next ya NO emite `apple-mobile-web-app-capable` (Chrome
 * lo deprecó a favor del nombre sin prefijo). Safari recién entiende el nombre
 * nuevo desde iOS 15.4, así que el viejo va a mano en `other` — sin él, un
 * iPhone con iOS anterior abre la app instalada en una pestaña de Safari con
 * barra de direcciones en lugar de a pantalla completa. Son dos tags, no una
 * duplicada.
 *
 * `apple-mobile-web-app-title` es el nombre bajo el icono en iOS y le gana al
 * `short_name` del manifest; por eso dice "Punto", igual que el manifest.
 *
 * El icono de home screen de iOS NO sale del manifest —iOS lo ignora en
 * "Agregar a inicio"— sino de `<link rel="apple-touch-icon">`, que Next emite
 * solo por existir `app/apple-icon.png`. Sin ese archivo iOS usaba una
 * miniatura de la pantalla, que es lo que reportó el owner. Igual que
 * `app/icon.png` (favicon), es convención de archivo: no se declara acá.
 *
 * Splash screens de iOS (`appleWebApp.startupImage`): NO están. iOS no las
 * genera solo, así que al abrir la app queda un fondo liso hasta el primer
 * paint. No bloquea instalar ni usar; agregarlas es una imagen por tamaño de
 * pantalla y se decide aparte.
 */
export const metadata: Metadata = {
  title: "Punto",
  description: "Punto POS — panel de administración",
  applicationName: "Punto",
  appleWebApp: {
    capable: true,
    title: "Punto",
    statusBarStyle: "black-translucent",
  },
  other: {
    "apple-mobile-web-app-capable": "yes",
  },
}

/**
 * `themeColor` vive acá y no en `metadata`: Next lo deprecó de `metadata` y
 * avisa en cada build mientras siga ahí.
 *
 * `viewportFit: "cover"` es necesario para que el contenido llegue debajo del
 * notch y de la barra de gestos, que es lo que hace que la app instalada se
 * vea como app y no como una página con marcos negros. Va de la mano del
 * `statusBarStyle: "black-translucent"` de arriba. Las áreas seguras se
 * respetan con `env(safe-area-inset-*)`.
 *
 * No se toca `userScalable`: la caja es táctil y bloquear el zoom es una
 * barrera de accesibilidad, no una mejora de UX.
 */
export const viewport: Viewport = {
  width: "device-width",
  initialScale: 1,
  viewportFit: "cover",
  themeColor: "#0a0a0a",
}

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode
}>) {
  return (
    <html
      lang="es"
      suppressHydrationWarning
      className={cn("antialiased", inter.variable, jetbrainsMono.variable)}
    >
      <body className="font-sans">
        <ThemeProvider defaultTheme="dark">
          <Providers>{children}</Providers>
        </ThemeProvider>
      </body>
    </html>
  )
}
