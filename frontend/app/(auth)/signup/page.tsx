"use client"

import * as React from "react"
import Link from "next/link"
import { useRouter } from "next/navigation"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { z } from "zod"
import { Eye, EyeOff, Loader2, ArrowLeft } from "lucide-react"
import { toast } from "sonner"
import type { CountryCode } from "libphonenumber-js"

import { PuntoLogo } from "@/components/layout/punto-logo"
import { PhoneInput } from "@/components/forms/phone-input"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  InputOTP,
  InputOTPGroup,
  InputOTPSlot,
} from "@/components/ui/input-otp"
import { cn } from "@/lib/utils"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import { COMPANY_CATEGORIES } from "@/lib/company-categories"
import { api, ApiError } from "@/lib/api-client"

// El form vive como una sola fuente de verdad para los 3 pasos. La
// validación se hace por paso vía `form.trigger(fieldNames)` antes de
// avanzar — RHF no valida campos no tocados de pasos siguientes.
const signupSchema = z.object({
  phone: z.string().min(1, "Ingresá tu número"),
  phoneE164: z.string().nullable(),
  country: z.string(),
  otp: z.string().length(4, "El código tiene 4 dígitos"),
  storename: z.string().min(1, "Ingresá el nombre de la empresa"),
  category: z.string().min(1, "Elegí un rubro"),
  username: z.string().min(1, "Ingresá tu nombre y apellido"),
  password: z.string().min(6, "Mínimo 6 caracteres"),
})

type SignupValues = z.infer<typeof signupSchema>
type Step = 1 | 2 | 3

export default function SignupPage() {
  const router = useRouter()
  const [step, setStep] = React.useState<Step>(1)
  const [showPassword, setShowPassword] = React.useState(false)
  const [submitting, setSubmitting] = React.useState(false)

  const form = useForm<SignupValues>({
    resolver: zodResolver(signupSchema),
    mode: "onTouched",
    defaultValues: {
      phone: "",
      phoneE164: null,
      country: DEFAULT_COUNTRY,
      otp: "",
      storename: "",
      category: "",
      username: "",
      password: "",
    },
  })

  // ── Step 1: enviar OTP al teléfono ─────────────────────────────────────
  const submitStep1 = async () => {
    const ok = await form.trigger(["phone"])
    const e164 = form.getValues("phoneE164")
    if (!ok || !e164) {
      form.setError("phone", { message: "Número de celular no válido" })
      return
    }
    setSubmitting(true)
    try {
      await api.post("/v1/signup/start", { phone: e164 })
      toast.success("Código enviado por WhatsApp")
      setStep(2)
    } catch (err) {
      toast.error("No se pudo enviar el código", {
        description: err instanceof Error ? err.message : "Error desconocido",
      })
    } finally {
      setSubmitting(false)
    }
  }

  // ── Step 2: verificar OTP ──────────────────────────────────────────────
  const submitStep2 = async () => {
    const ok = await form.trigger(["otp"])
    if (!ok) return
    setSubmitting(true)
    try {
      await api.post("/v1/signup/verify", {
        phone: form.getValues("phoneE164"),
        code: form.getValues("otp"),
      })
      setStep(3)
    } catch (err) {
      if (err instanceof ApiError && err.status === 401) {
        form.setError("otp", { message: "Código inválido o expirado" })
      } else {
        toast.error("No se pudo verificar el código")
      }
    } finally {
      setSubmitting(false)
    }
  }

  const resendOtp = async () => {
    try {
      await api.post("/v1/signup/start", { phone: form.getValues("phoneE164") })
      toast.success("Código reenviado")
    } catch {
      toast.info("Si no llega en unos segundos, revisá el número")
    }
  }

  // ── Step 3: crear empresa ──────────────────────────────────────────────
  const submitStep3 = async (values: SignupValues) => {
    setSubmitting(true)
    try {
      await api.post("/v1/signup", {
        phone: values.phoneE164,
        code: values.otp,
        storename: values.storename,
        category: values.category,
        username: values.username,
        password: values.password,
        country: values.country,
      })
      toast.success("¡Empresa creada! Iniciando sesión…")
      router.push("/")
    } catch (err) {
      toast.error("No se pudo crear la empresa", {
        description: err instanceof Error ? err.message : "Error desconocido",
      })
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card className="border-border/60">
      <CardHeader className="flex flex-col items-center gap-3 pt-8 pb-2">
        <PuntoLogo variant="wordmark" className="h-9 w-[120px]" />
        <p className="text-sm text-muted-foreground">
          {step === 1 && "Creá tu cuenta en Punto"}
          {step === 2 && "Verificá tu número"}
          {step === 3 && "Contanos sobre tu empresa"}
        </p>
        <StepDots active={step} />
      </CardHeader>

      <CardContent className="pt-4 pb-8">
        <Form {...form}>
          {/* Step 1: phone ----------------------------------------------- */}
          {step === 1 && (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                submitStep1()
              }}
              className="flex flex-col gap-4"
            >
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
                        autoFocus
                        aria-invalid={!!fieldState.error}
                      />
                    </FormControl>
                    <p className="text-xs text-muted-foreground">
                      Te enviaremos un código por WhatsApp para confirmar.
                    </p>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button type="submit" disabled={submitting} className="mt-2 w-full">
                {submitting && <Loader2 className="mr-2 size-4 animate-spin" />}
                Continuar
              </Button>

              <p className="mt-1 text-center text-sm text-muted-foreground">
                ¿Ya tenés cuenta?{" "}
                <Link href="/login" className="font-medium text-foreground hover:underline">
                  Iniciar sesión
                </Link>
              </p>
            </form>
          )}

          {/* Step 2: OTP ------------------------------------------------- */}
          {step === 2 && (
            <form
              onSubmit={(e) => {
                e.preventDefault()
                submitStep2()
              }}
              className="flex flex-col gap-4"
            >
              <FormField
                control={form.control}
                name="otp"
                render={({ field, fieldState }) => (
                  <FormItem className="items-center">
                    <FormLabel className="self-start">Código de verificación</FormLabel>
                    <FormControl>
                      <InputOTP
                        maxLength={4}
                        value={field.value}
                        onChange={field.onChange}
                        containerClassName="justify-center"
                        aria-invalid={!!fieldState.error}
                      >
                        <InputOTPGroup>
                          <InputOTPSlot index={0} />
                          <InputOTPSlot index={1} />
                          <InputOTPSlot index={2} />
                          <InputOTPSlot index={3} />
                        </InputOTPGroup>
                      </InputOTP>
                    </FormControl>
                    <p className="text-center text-xs text-muted-foreground">
                      Enviado al {form.getValues("phone") || "número ingresado"}
                    </p>
                    <FormMessage className="text-center" />
                  </FormItem>
                )}
              />

              <Button type="submit" disabled={submitting} className="mt-2 w-full">
                {submitting && <Loader2 className="mr-2 size-4 animate-spin" />}
                Verificar
              </Button>

              <div className="flex items-center justify-between text-sm">
                <button
                  type="button"
                  onClick={() => setStep(1)}
                  className="flex items-center gap-1 text-muted-foreground hover:text-foreground"
                >
                  <ArrowLeft className="size-3.5" />
                  Cambiar número
                </button>
                <button
                  type="button"
                  onClick={resendOtp}
                  className="text-muted-foreground hover:text-foreground"
                >
                  Reenviar código
                </button>
              </div>
            </form>
          )}

          {/* Step 3: company form ---------------------------------------- */}
          {step === 3 && (
            <form
              onSubmit={form.handleSubmit(submitStep3)}
              className="flex flex-col gap-4"
            >
              <FormField
                control={form.control}
                name="storename"
                render={({ field, fieldState }) => (
                  <FormItem>
                    <FormLabel>Nombre de la empresa</FormLabel>
                    <FormControl>
                      <Input
                        placeholder="Ej: Panadería Don Pedro"
                        autoComplete="organization"
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
                name="category"
                render={({ field, fieldState }) => (
                  <FormItem>
                    <FormLabel>Rubro</FormLabel>
                    <Select onValueChange={field.onChange} value={field.value}>
                      <FormControl>
                        <SelectTrigger
                          className="w-full"
                          aria-invalid={!!fieldState.error}
                        >
                          <SelectValue placeholder="Seleccionar…" />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {COMPANY_CATEGORIES.map((g) => (
                          <SelectGroup key={g.group}>
                            <SelectLabel>{g.group}</SelectLabel>
                            {g.items.map((item) => (
                              <SelectItem key={item.value} value={item.value}>
                                {item.label}
                              </SelectItem>
                            ))}
                          </SelectGroup>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="username"
                render={({ field, fieldState }) => (
                  <FormItem>
                    <FormLabel>Tu nombre y apellido</FormLabel>
                    <FormControl>
                      <Input
                        placeholder="Ej: Ana García"
                        autoComplete="name"
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
                          autoComplete="new-password"
                          placeholder="Mínimo 6 caracteres"
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
                Crear empresa
              </Button>

              <p className="text-center text-xs text-muted-foreground">
                Al registrarte aceptás los{" "}
                <a
                  href="/assets/terminos.pdf"
                  target="_blank"
                  rel="noreferrer"
                  className="underline hover:text-foreground"
                >
                  Términos y Condiciones
                </a>
              </p>
            </form>
          )}
        </Form>
      </CardContent>
    </Card>
  )
}

// Progress dots — 3 puntitos, el activo es brand y los pendientes son
// muted. Espejo del `step-dots` del legacy.
function StepDots({ active }: { active: Step }) {
  return (
    <div className="flex items-center gap-1.5" role="progressbar" aria-valuenow={active} aria-valuemax={3} aria-valuemin={1}>
      {[1, 2, 3].map((n) => (
        <span
          key={n}
          className={cn(
            "h-1.5 rounded-full transition-all duration-300",
            n === active ? "w-6 bg-primary" : "w-1.5 bg-muted",
          )}
        />
      ))}
    </div>
  )
}
