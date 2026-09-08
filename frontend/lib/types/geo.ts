/**
 * Catálogo geográfico fiscal (departamento → distrito → ciudad).
 *
 * Es dato de PLATAFORMA, no del comercio: el mapa de un país es el mismo para
 * todos los tenants. Se sincroniza desde el proveedor fiscal a nuestra base
 * (mig 207) y se lee desde `/v1/geo` — la pantalla NUNCA le pega al proveedor.
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

/** Resultado del sync manual: los conteos del job más el estado resultante. */
export interface GeoCatalogSyncResult {
  departments: number
  districts: number
  cities: number
  deactivated: number
  skippedNoCountry: number
  countries: string[]
  status: GeoCatalogStatus
}
