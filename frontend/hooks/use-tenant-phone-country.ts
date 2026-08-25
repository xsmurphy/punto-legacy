"use client"

import type { CountryCode } from "libphonenumber-js"
import { useBootstrap } from "@/hooks/use-bootstrap"
import { DEFAULT_COUNTRY } from "@/lib/countries"
import { resolvePhoneCountry } from "@/lib/tenant-locale"

/**
 * País con el que arranca un `<PhoneInput>` del panel.
 *
 * Un selector de teléfono necesita SIEMPRE un país seleccionado —
 * libphonenumber no puede interpretar "0981 234 567" sin saber de dónde es —
 * así que acá no existe la opción "ninguno" que sí usan los demás resolvers
 * de `lib/tenant-locale.ts`.
 *
 * Lo que sí se puede evitar es arrancar en Paraguay para todo el mundo: el
 * país del TENANT ya viaja en el bootstrap. Un comercio brasileño abre el
 * alta de cliente con Brasil preseleccionado y tipea su número local sin
 * tocar el selector; antes tenía que corregirlo en cada formulario, y si se
 * olvidaba el número quedaba guardado con prefijo +595.
 *
 * `DEFAULT_COUNTRY` queda solo como último recurso para el instante en que el
 * bootstrap todavía no cargó.
 */
export function useTenantPhoneCountry(): CountryCode {
  const { data: bootstrap } = useBootstrap()
  return resolvePhoneCountry(bootstrap) ?? DEFAULT_COUNTRY
}
