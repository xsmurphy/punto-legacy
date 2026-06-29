/**
 * Layout para rutas de auth (login, signup, password recovery).
 *
 * Sin sidebar; centrado vertical/horizontal en viewport. Mantiene el theme
 * provider del root layout. Server Component (no necesita estado client).
 */
export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-background p-4">
      <div className="w-full max-w-sm">{children}</div>
    </div>
  )
}
