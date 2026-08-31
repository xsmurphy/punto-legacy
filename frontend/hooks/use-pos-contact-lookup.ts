"use client"

/**
 * Búsqueda de clientes del TENANT desde el POS, contra el backend.
 *
 * Por qué existe si el POS ya busca clientes en memoria: el bootstrap baja
 * los primeros 500 clientes (`/v1/contacts?type=1&limit=500&offset=0` en
 * `app/api/pos/bootstrap/route.ts`), no la cartera entera. Para un comercio
 * con más que eso, `searchCustomers()` sobre el store devuelve "no existe"
 * para clientes que sí existen — y esa respuesta, en el alta, termina en un
 * cliente duplicado. El store sirve para el buscador libre (instantáneo,
 * offline); esta consulta es para el momento puntual en que estamos por
 * CREAR y necesitamos saber si ya lo tenemos.
 *
 * Endpoint: el que ya existe. `/v1/contacts` acepta el realm `pos-app`
 * (`apiAuthTenant(['panel','pos-app','api'])`, api/v1/contacts.php:31) y el
 * rol seed `device` tiene `contacts.customer.view` — es exactamente la misma
 * llamada que el bootstrap del POS ya hace con la credencial del device.
 * Sin endpoint nuevo, sin BFF nuevo.
 *
 * Cliente: `posApi` (Bearer del device). El POS es token-only — ver el
 * invariante de realm en `lib/api-client.ts` y `context/54`.
 */

import { useMutation } from "@tanstack/react-query"
import { posApi } from "@/lib/api/pos-client"
import { reshapeCustomer, type UpstreamContactRow } from "@/lib/pos-bff/reshape"
import type { PosCustomer } from "@/lib/types/pos-bootstrap"

interface ContactsListResponse {
  contacts: UpstreamContactRow[]
  total: number
}

/**
 * Cuántos resultados locales mostrar. Es una lista de desambiguación, no un
 * listado: si el identificador fiscal devuelve más de un puñado, el cajero no
 * los va a leer — busca por nombre en el buscador de arriba.
 */
const LOCAL_MATCH_LIMIT = 6

/**
 * Busca clientes del tenant por texto libre (el backend hace ILIKE sobre
 * nombre, identificador fiscal, documento personal y teléfono —
 * `ContactRepository::buildListWhere`).
 *
 * Mutation y no query porque se dispara con un toque del cajero, igual que
 * `useTaxpayerLookup`.
 */
export function usePosContactSearch() {
  return useMutation<PosCustomer[], Error, string>({
    mutationFn: async (query) => {
      const params = new URLSearchParams({
        type: "1",
        q: query,
        limit: String(LOCAL_MATCH_LIMIT),
        offset: "0",
      })
      const res = await posApi.get<ContactsListResponse>(`/v1/contacts?${params.toString()}`)
      // Mismo reshaper que el bootstrap y el sync quirúrgico: si acá el shape
      // divergiera, un cliente elegido desde este panel entraría al carrito
      // con campos distintos a los del store (ver docblock de reshape.ts).
      return (res.contacts ?? []).map(reshapeCustomer)
    },
  })
}

/**
 * Trae UN cliente por id, ya en shape `PosCustomer`.
 *
 * Lo usa el choque de unicidad: el backend devuelve el `contactId` del
 * contacto que ya tiene ese documento o teléfono, y el cajero tiene que poder
 * pasar a usarlo con un toque. El contacto en conflicto puede perfectamente
 * NO estar en el store (por el corte de 500 del bootstrap), así que
 * resolverlo contra el store y rendirse si no está dejaría el atajo muerto
 * justo en los comercios grandes, que son los que más duplicados tienen.
 */
export function usePosContactById() {
  return useMutation<PosCustomer, Error, string>({
    mutationFn: async (contactId) => {
      const row = await posApi.get<UpstreamContactRow>(
        `/v1/contacts?id=${encodeURIComponent(contactId)}`,
      )
      return reshapeCustomer(row)
    },
  })
}
