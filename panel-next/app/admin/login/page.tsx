"use client"

import * as React from "react"
import { useRouter } from "next/navigation"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Eye, EyeOff, Loader2 } from "lucide-react"
import { toast } from "sonner"

import { PuntoLogo } from "@/components/layout/punto-logo"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { apiAdmin, AdminApiError } from "@/lib/api-admin"

const loginSchema = z.object({
  email: z.string().email("Ingresá un email válido"),
  password: z.string().min(1, "Ingresá tu contraseña"),
})

type LoginValues = z.infer<typeof loginSchema>

/**
 * Login del realm admin. Standalone — NO bajo AdminAuthGuard.
 *
 * Al hacer submit → POST /api/admin/login.php (BFF).
 * El BFF setea la cookie _jwt_admin HttpOnly y devuelve {ok:true, data:{email,name}}.
 * Nunca el token toca el JS del browser.
 */
export default function AdminLoginPage() {
  const router = useRouter()
  const [showPassword, setShowPassword] = React.useState(false)
  const [submitting, setSubmitting] = React.useState(false)

  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "" },
  })

  const onSubmit = async (values: LoginValues) => {
    setSubmitting(true)
    try {
      await apiAdmin.post("/login.php", {
        email: values.email,
        password: values.password,
      })
      router.push("/admin")
    } catch (err) {
      if (err instanceof AdminApiError && err.status === 429) {
        toast.error("Demasiados intentos", {
          description: "Esperá un minuto antes de volver a intentar.",
        })
      } else if (err instanceof AdminApiError && err.status === 401) {
        toast.error("Credenciales inválidas")
        form.setError("password", { message: "Email o contraseña incorrectos" })
      } else {
        toast.error("No se pudo iniciar sesión", {
          description: err instanceof Error ? err.message : "Error desconocido",
        })
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-background p-4">
      <div className="w-full max-w-sm">
        {/* Borde superior diferenciador */}
        <div className="h-[3px] rounded-t-lg bg-destructive mb-0" />
        <Card className="rounded-t-none border-t-0">
          <CardHeader className="flex flex-col items-center gap-3 pt-8 pb-2">
            <div className="flex items-center gap-2">
              <PuntoLogo variant="wordmark" className="h-9 w-[120px]" />
              <Badge className="bg-destructive text-destructive-foreground border-0 text-[10px] font-semibold">
                ADMIN
              </Badge>
            </div>
            <p className="text-sm text-muted-foreground">Acceso administradores</p>
          </CardHeader>
          <CardContent className="pt-4 pb-8">
            <Form {...form}>
              <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
                <FormField
                  control={form.control}
                  name="email"
                  render={({ field, fieldState }) => (
                    <FormItem>
                      <FormLabel>Email</FormLabel>
                      <FormControl>
                        <Input
                          type="email"
                          autoComplete="email"
                          placeholder="admin@punto.la"
                          aria-invalid={!!fieldState.error}
                          {...field}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <FormField
                  control={form.control}
                  name="password"
                  render={({ field, fieldState }) => (
                    <FormItem>
                      <FormLabel>Contraseña</FormLabel>
                      <FormControl>
                        <div className="relative">
                          <Input
                            type={showPassword ? "text" : "password"}
                            autoComplete="current-password"
                            placeholder="••••••"
                            aria-invalid={!!fieldState.error}
                            className="pr-10"
                            {...field}
                          />
                          <button
                            type="button"
                            onClick={() => setShowPassword((s) => !s)}
                            aria-label={showPassword ? "Ocultar contraseña" : "Mostrar contraseña"}
                            className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-muted-foreground hover:text-foreground"
                          >
                            {showPassword ? (
                              <EyeOff className="size-4" />
                            ) : (
                              <Eye className="size-4" />
                            )}
                          </button>
                        </div>
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <Button type="submit" disabled={submitting} className="mt-2 w-full">
                  {submitting && <Loader2 className="mr-2 size-4 animate-spin" />}
                  Ingresar
                </Button>
              </form>
            </Form>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
