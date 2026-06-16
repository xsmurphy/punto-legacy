/**
 * Shape de `GET /v1/bootstrap` — mismo contract que `panel/API/v1/bootstrap.php`,
 * preservado al portarlo a `/api/v1/bootstrap.php`. El front depende del
 * shape exacto (muchos a_*.js legacy lo consumen).
 */
export interface Bootstrap {
  currency: string
  /** 'yes' | 'no' — si se muestran decimales en la moneda local. */
  decimal: string
  /** 'comma' | 'dot' — separador de miles (notación, no símbolo). */
  thousand: "comma" | "dot"
  /** Etiqueta del impuesto fiscal (ej. "IVA"). */
  taxName: string
  /** Etiqueta del documento fiscal del cliente (ej. "RUC"). */
  tinName: string
  /** Código ISO de país (ej. "PY"). */
  country: string
  companyName: string
  companyId: string | number
  /** Base URL de screens standalone (links de impresión, KDS, etc). */
  publicUrl: string
  user: {
    id: string | number
    role: number
    /** Nombre legible del usuario logueado (ej. "Christian Murphy").
     *  Opcional — TODO backend: exponerlo desde /v1/bootstrap.php (hoy solo
     *  trae id+role). Mientras tanto el menú de usuario del POS oculta la
     *  línea cuando no llega. */
    name?: string
    /** Etiqueta legible del rol (ej. "Cajero"). Opcional — mismo TODO que `name`. */
    roleName?: string
  }
  /** UUID del outlet activo (claim `oid` del JWT). '' si el tenant aún no tiene outlets. */
  activeOutletId: string
  /** Nombre legible del outlet activo, para el subtitle del sidebar. */
  activeOutletName: string
  /** Sucursales activas del tenant. Pintar selector solo cuando `length > 1`. */
  outlets: Array<{ id: string; name: string }>
}
