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
  /** Exigir órdenes y espacios cerrados para poder cerrar el turno. */
  drawerRequireClosedOrders: boolean
  /**
   * Minutos durante los cuales el operador puede anular algo de una comanda por
   * su cuenta — un ítem, la orden entera o la sesión de una mesa. `0` = sin
   * límite (default). Pasada la ventana la anulación queda para un encargado
   * (permiso `pos.order.item.cancel.late`).
   *
   * La clave conserva el `Item` del nombre por historia: la ventana empezó
   * cubriendo solo el grano ítem. Renombrarla obligaría a migrar el JSONB de
   * cada tenant para no cambiar nada de comportamiento.
   */
  settingOrderItemCancelWindowMinutes: number
  settingRemoveTaxes: boolean
  paymentId: boolean
  creditLine: boolean
  storeCredit: boolean
  ignoreInternal: boolean
  /** D2 (context/63): el operador no ve el stock teórico mientras cuenta. */
  stockCountBlind: boolean
  /**
   * D9 (context/63): al finalizar, el conteo NO ajusta el stock — las
   * diferencias quedan registradas y nada más. Ortogonal al anterior.
   */
  stockCountRecordOnly: boolean
  /**
   * D3 (context/63): listas fijas de conteo. Qué se cuenta en el mostrador, lo
   * decide el dueño de antemano. Guardan solo ids — el nombre del artículo se
   * resuelve contra el catálogo, nunca se copia adentro.
   */
  stockCountLists: Array<{ id: string; name: string; itemIds: string[] }>
  blockUsedDocNo: boolean
  autoSendDocs: boolean
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
