"use client"

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { api } from "@/lib/api-client"
import type { SettingsFormValues, SettingsGeneral } from "@/lib/types/settings"

export function useSettings() {
  return useQuery<SettingsGeneral>({
    queryKey: ["settings", "general"],
    queryFn: () => api.get<SettingsGeneral>("/v1/settings?view=general"),
    staleTime: 60 * 1000,
  })
}

export function useUpdateSettings() {
  const qc = useQueryClient()
  return useMutation<unknown, Error, SettingsFormValues>({
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
 * Aplana los nested keys (social) + serializa booleans como 1/0 que el
 * backend espera vía validateHttp. Strings van as-is.
 */
function serialize(values: SettingsFormValues): Record<string, unknown> {
  return {
    address: values.address,
    website: values.website,
    email: values.email,
    ruc: values.ruc,
    phone: values.phone,
    city: values.city,
    country: values.country,
    language: values.language,
    timeZone: values.timeZone,
    currency: values.currency,
    taxName: values.taxName,
    billingName: values.billingName,
    tin: values.tin,
    billDetail: values.billDetail,
    category: values.category,
    thousandSeparator: values.thousandSeparator,
    itemsSaleLimit: values.itemsSaleLimit,
    facebook: values.social.facebook,
    instagram: values.social.instagram,
    youtube: values.social.youtube,
    twitter: values.social.twitter,
    decimal: values.decimal ? 1 : 0,
    sellsoldout: values.sellsoldout ? 1 : 0,
    itemSerialized: values.itemSerialized ? 1 : 0,
    drawerEmail: values.drawerEmail ? 1 : 0,
    drawerBlind: values.drawerBlind ? 1 : 0,
    settingRemoveTaxes: values.settingRemoveTaxes ? 1 : 0,
    paymentId: values.paymentId ? 1 : 0,
    creditLine: values.creditLine ? 1 : 0,
    storeCredit: values.storeCredit ? 1 : 0,
    ignoreInternal: values.ignoreInternal ? 1 : 0,
    stockCountBlind: values.stockCountBlind ? 1 : 0,
    blockUsedDocNo: values.blockUsedDocNo ? 1 : 0,
    autoSendDocs: values.autoSendDocs ? 1 : 0,
    taxPy: values.taxPy ? 1 : 0,
    weightBarcodes: values.weightBarcodes ? 1 : 0,
    deletedItemsHistory: values.deletedItemsHistory ? 1 : 0,
  }
}
