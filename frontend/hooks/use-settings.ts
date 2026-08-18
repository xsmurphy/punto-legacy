"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { SettingsFormValues, SettingsGeneral } from "@/lib/types/settings"

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

const SERIALIZE_BOOL_FIELDS: (keyof SettingsFormValues)[] = [
  "decimal", "sellsoldout", "itemSerialized", "drawerEmail", "drawerBlind",
  "settingRemoveTaxes", "paymentId", "creditLine", "storeCredit",
  "ignoreInternal", "stockCountBlind", "blockUsedDocNo", "autoSendDocs",
  "taxPy", "weightBarcodes", "deletedItemsHistory",
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
  for (const key of SERIALIZE_BOOL_FIELDS) {
    if (values[key] !== undefined) out[key] = values[key] ? 1 : 0
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
