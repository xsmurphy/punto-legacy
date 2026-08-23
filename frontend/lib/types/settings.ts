/**
 * Shapes de `/v1/settings?view=general` — mirror exacto de
 * SettingsService::general() (api/lib/Settings/SettingsService.php:42).
 *
 * Settings es la configuración de la EMPRESA (no del usuario): perfil,
 * localización, comportamiento del POS, redes sociales, plantillas de
 * impresión. Una sola fila por tenant.
 */

export interface SettingsGeneral {
  // Perfil empresa
  /** URL del logo (endpoint de resize con cache-bust `?v=`). null si la
   *  empresa no subió logo todavía. */
  logo: string | null
  /** Espejo del flag persistido en settingObj — el front no debe inferir desde
   *  `logo === null` porque el helper podría devolver una URL fija default. */
  hasLogo: boolean
  name: string
  address: string
  email: string
  billingName: string
  ruc: string
  billDetail: string
  website: string
  social: {
    facebook: string
    instagram: string
    youtube: string
    twitter: string
  }
  /** Código numérico de la categoría (rubro) — ej. "1.7". */
  category: string
  /** Identificador único de la empresa (URLs públicas). '' = sin slug asignado.
   *  Normalizado y validado server-side (SettingsService::updateGeneral). */
  slug: string
  phone: string
  city: string
  country: string
  language: string
  timeZone: string

  // Parámetros del POS
  /** Símbolo de moneda (₲, $, etc.). */
  currency: string
  /** 'dot' o 'comma' — separador de miles. */
  thousandSeparator: string
  /** Etiqueta del impuesto fiscal local (ej. "IVA"). */
  taxName: string
  /** Etiqueta del documento fiscal del cliente (ej. "RUC"). */
  tin: string
  /** Cap de ítems por venta — vacío = sin límite. */
  itemsSaleLimit: string

  // Toggles del comportamiento (boolean en BD pueden venir como bool/0-1/yes-no).
  decimal: boolean
  sellsoldout: boolean
  itemSerialized: boolean
  drawerEmail: boolean
  drawerBlind: boolean
  settingRemoveTaxes: boolean
  paymentId: boolean
  creditLine: boolean
  storeCredit: boolean
  ignoreInternal: boolean
  stockCountBlind: boolean
  blockUsedDocNo: boolean
  autoSendDocs: boolean
  taxPy: boolean
  weightBarcodes: boolean
  deletedItemsHistory: boolean

  // D7/E1b de context/48-escalamiento-de-datos.md — ancho de la ventana
  // abierta de cierre de período (mes en curso + N meses anteriores).
  // Clampeado 1..12 server-side (default 1).
  settingPeriodCloseMonths: number
  /**
   * Tolerancia de cuadre del arqueo, en moneda del comercio. 0 = arquear
   * exacto (default). El backend nunca clasifica por debajo de una unidad
   * mínima de la moneda, así que 0 no significa "todo es faltante".
   */
  settingDrawerTolerance: number

  // Asistente IA (por empresa)
  /** Nombre del asistente. Vacío = default "Asistente". Máx 40 caracteres. */
  agentName: string
  /** Personalidad del asistente — matiz de TONO server-side, nunca contradice
   *  las reglas duras del prompt (anti-invento, idioma, guardrails). */
  agentPersonality: "professional" | "friendly" | "direct" | "teacher"
}

/** Lo que el form de frontend manda al backend. Mismo shape que el GET,
 *  ajustado para form: cleartext donde el GET trae bool, etc. */
export type SettingsFormValues = Omit<SettingsGeneral, "logo" | "hasLogo">
