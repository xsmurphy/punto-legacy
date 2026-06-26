"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Eye, EyeOff, Loader2 } from "lucide-react"
import { toast } from "sonner"
import type { CountryCode } from "libphonenumber-js"

import { PuntoLogo } from "@/components/layout/punto-logo"
import { PhoneInput } from "@/components/forms/phone-input"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import { api, ApiError } from "@/lib/api-client"

// Schema. El campo `phone` guarda el valor NACIONAL que ve el usuario;
// la validación cruza por libphonenumber-js a través del PhoneInput
// (que setea phoneE164 cuando es válido). El submit usa `phoneE164`.
const loginSchema = z.object({
  phone: z.string().min(1, "Ingresá tu número"),
  phoneE164: z.string().nullable(),
  country: z.string(),
  password: z.string().min(1, "Ingresá tu contraseña"),
})

type LoginValues = z.infer<typeof loginSchema>

/**
 * Whitelist anti-open-redirect: solo aceptamos `next` que sea una ruta
 * interna (empieza con `/` pero NO con `//` ni `/\`). Cualquier otra cosa
 * cae al default `/`. Esto bloquea `next=//evil.com` y `next=https://...`.
 */
function safeNext(next: string | null): string {
  if (!next) return "/"
  if (!next.startsWith("/")) return "/"
  if (next.startsWith("//") || next.startsWith("/\\")) return "/"
  return next
}

export default function LoginPage() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const next = safeNext(searchParams.get("next"))
  const [showPassword, setShowPassword] = React.useState(false)
  const [submitting, setSubmitting] = React.useState(false)

  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: {
      phone: "",
      phoneE164: null,
      country: DEFAULT_COUNTRY,
      password: "",
    },
  })

  const onSubmit = async (values: LoginValues) => {
    if (!values.phoneE164) {
      form.setError("phone", { message: "Número de celular no válido" })
      return
    }
    setSubmitting(true)
    try {
      // Convención: al backend siempre va E.164.
      // Endpoint: el legacy usa POST /login?login=true con campo `phone`;
      // la nueva API esperamos `/v1/login` con JSON {phone: E164, password}.
      // Si el endpoint nuevo no existe todavía, el catch muestra el error.
      await api.post("/v1/login", {
        phone: values.phoneE164,
        password: values.password,
      })
      toast.success("Sesión iniciada")
      router.push(next)
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        toast.error("Credenciales inválidas")
        form.setError("password", { message: "Verificá tu contraseña" })
      } else if (err instanceof ApiError && err.status === 404) {
        toast.error("Endpoint /v1/login todavía no portado del legacy")
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
    <Card className="border-border/60">
      <CardHeader className="flex flex-col items-center gap-3 pt-8 pb-2">
        <PuntoLogo variant="wordmark" className="h-9 w-[120px]" />
        <p className="text-sm text-muted-foreground">Iniciá sesión en tu panel</p>
      </CardHeader>
      <CardContent className="pt-4 pb-8">
        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4">
            <FormField
              control={form.control}
              name="phone"
              render={({ field, fieldState }) => (
                <FormItem>
                  <FormLabel>Número de celular</FormLabel>
                  <FormControl>
                    <PhoneInput
                      value={field.value}
                      country={form.watch("country") as CountryCode}
                      onChange={(v) => {
                        field.onChange(v.value)
                        form.setValue("phoneE164", v.e164)
                        form.setValue("country", v.country)
                      }}
                      onBlur={field.onBlur}
                      aria-invalid={!!fieldState.error}
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
                  <div className="flex items-center justify-between">
                    <FormLabel>Contraseña</FormLabel>
                    <Link
                      href="/recovery"
                      className="text-xs text-muted-foreground hover:text-foreground"
                    >
                      ¿La olvidaste?
                    </Link>
                  </div>
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
                        {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                      </button>
                    </div>
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <Button type="submit" disabled={submitting} className="mt-2 w-full">
              {submitting && <Loader2 className="mr-2 size-4 animate-spin" />}
              Iniciar sesión
            </Button>

            <p className="mt-1 text-center text-sm text-muted-foreground">
              ¿No tenés cuenta?{" "}
              <Link href="/signup" className="font-medium text-foreground hover:underline">
                Crear una
              </Link>
            </p>
          </form>
        </Form>
      </CardContent>
    </Card>
  )
}
