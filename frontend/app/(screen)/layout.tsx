import "@/app/globals.css"
import { ThemeProvider } from "@/components/theme-provider"
import { Toaster } from "@/components/ui/sonner"

export const metadata = { title: "Punto — Pantalla cliente" }

export default function ScreenLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es" suppressHydrationWarning>
      <body className="font-sans">
        {/* forcedTheme=light: el visor al cliente debe ser SIEMPRE claro
            sin importar el theme del panel o del POS (compartían storage
            antes y el screen heredaba dark al cambiar tono en otra pestaña). */}
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
