import "@/app/globals.css"
import { ThemeProvider } from "@/components/theme-provider"
import { Toaster } from "@/components/ui/sonner"

export const metadata = { title: "Punto — Pantalla cliente" }

export default function ScreenLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es" suppressHydrationWarning>
      <body className="font-sans">
        {/* forcedTheme=light: piso neutro para todo este grupo de rutas —
            nunca deben heredar el theme del panel/POS (compartían storage
            antes y una pantalla heredaba dark al cambiar tono en otra
            pestaña). Cada pantalla (kds, display, print, checkout) elige su
            PROPIO claro/oscuro/automático con un wrapper que agrega la clase
            `.dark` de forma local (ver lib/screens/theme.ts + ScreenThemeToggle),
            nunca vía next-themes/localStorage compartido — así el selector de
            cada pantalla no puede filtrarse a las demás ni al panel. */}
        <ThemeProvider forcedTheme="light" enableSystem={false} storageKey="punto-screen-theme">
          <div className="min-h-screen bg-background text-foreground overflow-hidden">
            {children}
          </div>
          <Toaster />
        </ThemeProvider>
      </body>
    </html>
  )
}
