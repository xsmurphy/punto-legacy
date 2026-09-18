"use client"

/**
 * Ficha de cliente/proveedor — `/contacts/[id]`.
 *
 * Un contacto existente se delega entero en `ContactDetailView` (la misma
 * vista que monta la caja), que arma la ficha con `EntityShell`. El alta
 * (`/contacts/new`) usa el MISMO armazón con solo Datos habilitada y el MISMO
 * formulario (`ContactFormBody`) — antes esta página tenía su propia copia del
 * formulario, sin la búsqueda de RUC ni los campos de crédito.
 */

import * as React from "react"
import { useParams, useRouter, useSearchParams } from "next/navigation"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { toast } from "sonner"
import type { CountryCode } from "libphonenumber-js"

import { Card, CardContent } from "@/components/ui/card"
import { Form } from "@/components/ui/form"
import { BackLink } from "@/components/page/back-link"
import { EntityShell } from "@/components/page/entity-shell"
import {
  ContactDetailView,
  ContactFormBody,
  contactSchema,
  emptyContactValues,
} from "@/components/domain/contacts/contact-detail-view"
import { useContact, useCreateContact } from "@/hooks/use-contacts"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { useTenantPhoneCountry } from "@/hooks/use-tenant-phone-country"
import { useAgentPageSnapshot } from "@/lib/agent/use-agent-page-snapshot"
import { ApiError } from "@/lib/api-client"
import type { ContactFormValues } from "@/lib/types/contact"

export default function ContactEditPage() {
  // useSearchParams() requiere Suspense boundary (Next App Router) — mismo
  // patrón que items/page.tsx; ver comentario en pos/layout.tsx.
  return (
    <React.Suspense fallback={null}>
      <ContactEditPageInner />
    </React.Suspense>
  )
}

function ContactEditPageInner() {
  const params = useParams<{ id: string }>()
  const id = params.id
  const isNew = id === "new"
  const router = useRouter()
  const searchParams = useSearchParams()
  const contactType = searchParams.get("type") === "2" ? 2 : 1
  const back =
    contactType === 2
      ? { href: "/contacts?type=2", label: "Volver a proveedores" }
      : { href: "/contacts", label: "Volver a clientes" }

  // Una persona del comercio (type=0) tiene su ficha en `/employees/[id]`, con
  // su legajo y su acceso (context/83 §9). Esta página nunca supo mostrarlas
  // —siempre las trató como clientes— así que el link viejo se redirige en vez
  // de pintar una ficha de cliente con los datos de un usuario.
  const isUser = searchParams.get("type") === "0"
  React.useEffect(() => {
    if (isUser && !isNew) router.replace(`/employees/${id}`)
  }, [isUser, isNew, id, router])
  const { data, error } = useContact(isNew ? undefined : id)
  const create = useCreateContact()
  // País del selector de teléfono. Arranca en el país del TENANT y solo se
  // fija cuando el usuario elige uno distinto — por eso el estado guarda
  // `null` en vez de sembrarse con un default: el bootstrap puede no haber
  // llegado en el primer render, y un `useState(valorInicial)` se quedaría
  // congelado con el fallback aunque después llegue el país real.
  const tenantPhoneCountry = useTenantPhoneCountry()
  const [pickedCountry, setCountry] = React.useState<CountryCode | null>(null)
  const country = pickedCountry ?? tenantPhoneCountry
  const { data: bootstrap } = useBootstrap()

  useAgentPageSnapshot(
    isNew
      ? {
          route: "/contacts/new",
          routeLabel: contactType === 2 ? "Creando proveedor nuevo" : "Creando cliente nuevo",
          summary: { tipo: contactType === 2 ? "proveedor" : "cliente" },
        }
      : data
      ? {
          route: `/contacts/${id}`,
          routeLabel: `Editando ${contactType === 2 ? "proveedor" : "cliente"}: ${data.fullname ?? data.name}`,
          summary: {
            contactId: id,
            nombre: data.fullname ?? data.name,
            tipo: contactType === 2 ? "proveedor" : "cliente",
            telefono: data.phone ?? null,
            email: data.email ?? null,
          },
        }
      : null,
    [id, isNew, contactType, data?.name, data?.fullname, data?.phone, data?.email],
  )

  const form = useForm<ContactFormValues>({
    resolver: zodResolver(contactSchema),
    defaultValues: emptyContactValues(),
  })

  const onCreate = async (values: ContactFormValues) => {
    try {
      const created = await create.mutateAsync({ values, type: contactType as 1 | 2 })
      toast.success(contactType === 2 ? "Proveedor creado" : "Cliente creado")
      router.push(`/contacts/${created.id}?type=${contactType}`)
    } catch (e) {
      toast.error("No se pudo crear", {
        description: e instanceof Error ? e.message : undefined,
      })
    }
  }

  if (error) {
    const isNotFound = error instanceof ApiError && error.status === 404
    return (
      <div className="flex flex-col gap-4">
        <BackLink href={back.href} label={back.label} />
        <Card>
          <CardContent className="p-8 text-center text-sm text-muted-foreground">
            {isNotFound ? "Contacto no encontrado." : `No se pudo cargar el contacto. ${error.message}`}
          </CardContent>
        </Card>
      </div>
    )
  }

  // Contacto existente → la ficha completa.
  if (!isNew) {
    return <ContactDetailView customerId={id} variant="panel" nav="tabs" back={back} />
  }

  // Alta → mismo armazón, solo Datos habilitada.
  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onCreate)}>
        <EntityShell
          isNew
          back={back}
          title={contactType === 2 ? "Nuevo proveedor" : "Nuevo cliente"}
          summary={null}
          data={
            <ContactFormBody
              form={form}
              kind={form.watch("kind")}
              country={country}
              setCountry={setCountry}
              tenant={bootstrap}
            />
          }
          save={{ pending: create.isPending }}
        />
      </form>
    </Form>
  )
}
