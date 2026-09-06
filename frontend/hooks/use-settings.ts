"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { SettingsFormValues, SettingsGeneral } from "@/lib/types/settings"
import type { TaxpayerLookup } from "@/lib/types/einvoice"

/** Una moneda con cotización configurable. ccode = código país (AR/BR/PY/...),
 *  code = código ISO 4217 (ARS/BRL/PYG/...), value = tasa al PYG. */
export interface SettingsCurrency {
  ccode: string
  code: string
  value: number
}

export function useSettingsCurrencies() {
  return useQuery<{ rows: SettingsCurrency[] }>({
    queryKey: ["settings", "currencies"],
    queryFn: () => api.get("/v1/settings?view=currencies"),
    staleTime: 60 * 1000,
  })
}

export function useUpdateCurrencies() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, SettingsCurrency[]>({
    mutationFn: (currencies) =>
      api.post("/v1/settings", {
        action: "update",
        type: "currencies",
        currencies: JSON.stringify(currencies),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["settings", "currencies"] })
      qc.invalidateQueries({ queryKey: ["currencies"] })
    },
  })
}

export function useSettings() {
  return useQuery<SettingsGeneral>({
    queryKey: ["settings", "general"],
    queryFn: () => api.get<SettingsGeneral>("/v1/settings?view=general"),
    staleTime: 60 * 1000,
  })
}

/**
 * Guarda ajustes generales. Acepta un objeto PARCIAL: solo las keys presentes
 * viajan al backend y solo esas se tocan (merge parcial en
 * SettingsService::updateGeneral — ver api/v1/settings.php). Mandar `{}` es
 * un no-op válido. El caller decide el alcance: el modal de Settings manda
 * las keys de la sección activa (ver SECTION_FIELDS en
 * app/(panel)/settings/page.tsx), AgentSettingsDialog manda solo sus 2 campos.
 */
export function useUpdateSettings() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, Partial<SettingsFormValues>>({
    mutationFn: (values) =>
      api.post("/v1/settings", { action: "update", type: "setting", ...serialize(values) }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["settings"] })
      // Bootstrap también se refresca — los settings cambian el sidebar
      // (company name) y los formatters (currency, decimal, thousand).
      qc.invalidateQueries({ queryKey: ["bootstrap"] })
    },
  })
}

/**
 * Trae del padrón la razón social del RUC del PROPIO comercio.
 *
 * Es una mutación y no una query a propósito: se dispara con un botón, no al
 * tipear. Consultar por keystroke pegaría contra un servicio externo en cada
 * tecla y llenaría el log del backend de intentos a medio escribir.
 *
 * El resultado es una SUGERENCIA editable — el comercio puede corregirla antes
 * de guardar. 404 = "no se encontró" y llega como Error; el caller lo muestra
 * sin bloquear la carga a mano.
 */
export function useTaxpayerLookup() {
  return useMutation<TaxpayerLookup, Error, string>({
    mutationFn: (ruc) =>
      api.get<TaxpayerLookup>(`/v1/settings?view=taxpayer&ruc=${encodeURIComponent(ruc)}`),
  })
}

/**
 * Sube el logo de la empresa. Multipart al endpoint `/v1/settings` con
 * `action=uploadLogo` + `logo=<file>`. Backend: SettingsService::uploadLogo
 * (procesa con GD, sube a S3 con la convención legacy `{companyId}.jpg`).
 */
export function useUploadCompanyLogo() {
  const qc = useQueryClient()
  return useMutation<{ logo: string; hasLogo: true }, Error, File>({
    mutationFn: (file) => {
      const form = new FormData()
      form.append("logo", file)
      form.append("action", "uploadLogo")
      return api.postForm<{ logo: string; hasLogo: true }>("/v1/settings", form)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["settings"] })
      qc.invalidateQueries({ queryKey: ["bootstrap"] })
    },
  })
}

/** Borra el logo (best-effort en S3 + limpia el flag en settingObj). */
export function useDeleteCompanyLogo() {
  const qc = useQueryClient()
  return useMutation<{ hasLogo: false }, Error, void>({
    mutationFn: () => api.post<{ hasLogo: false }>("/v1/settings", { action: "deleteLogo" }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["settings"] })
      qc.invalidateQueries({ queryKey: ["bootstrap"] })
    },
  })
}

const SERIALIZE_STRING_FIELDS: (keyof SettingsFormValues)[] = [
  "name", "address", "website", "email", "ruc", "phone", "city", "country",
  "language", "timeZone", "currency", "taxName", "billingName", "tin",
  "billDetail", "category", "slug", "thousandSeparator", "itemsSaleLimit",
  "agentName", "agentPersonality",
]

// D7/E1b de context/48-escalamiento-de-datos.md — mismo passthrough que
// SERIALIZE_STRING_FIELDS (el backend castea con (int) + clamp 1..12).
const SERIALIZE_NUMBER_FIELDS: (keyof SettingsFormValues)[] = [
  "settingPeriodCloseMonths",
  "settingDrawerTolerance",
  "settingOrderItemCancelWindowMinutes",
]

const SERIALIZE_BOOL_FIELDS: (keyof SettingsFormValues)[] = [
  "decimal", "sellsoldout", "itemSerialized", "drawerEmail", "drawerBlind",
  "drawerRequireClosedOrders", "paymentOrderRequireSecondApprover",
  "settingRemoveTaxes", "paymentId", "creditLine", "storeCredit",
  "ignoreInternal", "stockCountBlind", "stockCountRecordOnly",
  "blockUsedDocNo", "autoSendDocs",
  "weightBarcodes", "deletedItemsHistory",
]

/**
 * Aplana los nested keys (social) + serializa booleans como 1/0 que el
 * backend espera vía validateHttp. Solo emite las keys PRESENTES en
 * `values` — es la mitad frontend del merge parcial: una key ausente acá
 * nunca llega al POST, así que el backend (array_key_exists en $_POST) no la
 * toca. Mandar el objeto completo sigue funcionando (todo presente = mismo
 * comportamiento de antes), pero el caller ahora puede mandar un subconjunto
 * a propósito.
 */
function serialize(values: Partial<SettingsFormValues>): Record<string, unknown> {
  const out: Record<string, unknown> = {}

  for (const key of SERIALIZE_STRING_FIELDS) {
    if (values[key] !== undefined) out[key] = values[key]
  }
  for (const key of SERIALIZE_NUMBER_FIELDS) {
    if (values[key] !== undefined) out[key] = values[key]
  }
  for (const key of SERIALIZE_BOOL_FIELDS) {
    if (values[key] !== undefined) out[key] = values[key] ? 1 : 0
  }
  // Listas fijas de conteo (D3). Viajan como UN string JSON, igual que
  // `currencies`: son objetos con un array adentro, y el POST del backend lee
  // `$_POST` plano — no hay forma de transportarlas campo por campo sin
  // inventar una convención de nombres. Se serializa incluso vacío: borrar la
  // última lista es una decisión del dueño, no una ausencia.
  if (values.stockCountLists !== undefined) {
    out.stockCountLists = JSON.stringify(values.stockCountLists)
  }
  if (values.social) {
    const { facebook, instagram, youtube, twitter } = values.social
    if (facebook !== undefined) out.facebook = facebook
    if (instagram !== undefined) out.instagram = instagram
    if (youtube !== undefined) out.youtube = youtube
    if (twitter !== undefined) out.twitter = twitter
  }

  return out
}
