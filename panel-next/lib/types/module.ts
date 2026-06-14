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

export type ModuleConfig =
  | LoyaltyConfig
  | TablesConfig
  | OrdersConfig
  | FeedbackConfig
  | CrmConfig

export interface ModuleState {
  enabled: boolean
  config?: ModuleConfig
}

/** Mapa completo de módulos devuelto por GET /v1/modules. */
export type ModulesMap = Record<string, ModuleState>
