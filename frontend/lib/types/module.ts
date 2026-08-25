/**
 * Tipos del dominio de Módulos nativos de Punto.
 * Espeja el shape de GET /v1/modules.
 */

export interface LoyaltyConfig {
  min: number
  value: number
  customerVisible: boolean
}

export interface TablesConfig {
  count: number
}

export interface OrdersConfig {
  averageTime: number
}

export interface FeedbackConfig {
  question: string
}

export interface CrmConfig {
  dontAutoSendDocs: boolean
}

/**
 * Canales del módulo Bancard. El módulo es el paraguas; cada canal se
 * habilita por separado y nace APAGADO (opt-in explícito) — el estado inicial
 * lo declara `api/lib/Modules/ModuleChannels.php`. Prender "Bancard" sin
 * entrar a la config no habilita ningún canal.
 */
export interface BancardConfig {
  /** QR de pago (ePagos/BANCARD_QR_API) — se muestra en la pantalla del cliente. */
  qr: boolean
  /** Terminal físico (Caja POS Android por LAN). La IP se configura por caja. */
  pos: boolean
}

export type ModuleConfig =
  | LoyaltyConfig
  | TablesConfig
  | OrdersConfig
  | FeedbackConfig
  | CrmConfig
  | BancardConfig

export interface ModuleState {
  enabled: boolean
  config?: ModuleConfig
}

/** Mapa completo de módulos devuelto por GET /v1/modules. */
export type ModulesMap = Record<string, ModuleState>
