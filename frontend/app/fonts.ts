/**
 * Las fuentes de la app, UNA sola vez.
 *
 * Hay tres raíces HTML (panel en `app/layout.tsx`, POS en `(pos)/layout.tsx`,
 * pantallas pareadas en `(screen)/layout.tsx`) y cada una tiene que colgar las
 * MISMAS variables en su `<html>`: `font-sans`/`font-mono` resuelven a
 * `var(--font-inter)`/`var(--font-jetbrains-mono)`, y una raíz sin las
 * variables deja la font-family inválida — el navegador cae a la serif del
 * sistema (el "Times New Roman" que se veía en el KDS y el reloj recién
 * pareados). Definirlas acá y no en cada layout evita que la próxima raíz
 * nazca sin fuentes.
 */
import { Inter, JetBrains_Mono } from "next/font/google"

export const inter = Inter({
  subsets: ["latin"],
  variable: "--font-inter",
  display: "swap",
})

export const jetbrainsMono = JetBrains_Mono({
  subsets: ["latin"],
  variable: "--font-jetbrains-mono",
  display: "swap",
})

/** Para el `className` del `<html>` de cada raíz. */
export const fontVariables = `${inter.variable} ${jetbrainsMono.variable}`
