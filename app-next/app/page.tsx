import { redirect } from "next/navigation"

/**
 * Root redirect → pantalla de caja.
 * El POS siempre arranca en /register.
 */
export default function RootPage() {
  redirect("/register")
}
