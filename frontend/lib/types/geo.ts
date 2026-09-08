/**
 * Catálogo geográfico fiscal (departamento → distrito → ciudad).
 *
 * Es dato de PLATAFORMA, no del comercio: el mapa de un país es el mismo para
 * todos los tenants. Sale del catálogo de SIFEN —el mismo contra el que FE-PY
 * valida los códigos antes de armar el XML— por un seed versionado del repo
 * (migs 207/208), y se lee desde `/v1/geo`. La pantalla nunca sale a la red por
 * esto.
 */

/** Un nodo de cualquiera de los tres niveles. `code` es el código FISCAL. */
export interface GeoNode {
  /**
   * Código del catálogo de la autoridad tributaria — el que viaja en el
   * documento electrónico. No es la PK interna del proveedor.
   */
  code: number
  name: string
  countryCode: string
}

/**
 * Cobertura del catálogo. Es lo que decide si el formulario puede ofrecer los
 * selectores en cascada o tiene que degradar a la carga manual de códigos.
 */
export interface GeoCatalogStatus {
  departments: number
  districts: number
  cities: number
  /** Última vez que el job corrió, o null si nunca corrió. */
  syncedAt: string | null
  countries: string[]
}

/** Resultado de la recarga manual: los conteos del job más el estado resultante. */
export interface GeoCatalogSyncResult {
  /** Clave de la fuente que cargó estas filas (hoy `sifen`). */
  source: string
  /** País del catálogo cargado. Lo declara la fuente, no se deduce del dato. */
  countryCode: string
  departments: number
  districts: number
  cities: number
  /** Filas que la fuente dejó de mencionar: se marcan inactivas, nunca se borran. */
  deactivated: number
  /** Filas descartadas por no tener un padre declarado en la misma fuente. */
  skipped: number
  status: GeoCatalogStatus
}
