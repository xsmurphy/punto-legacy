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
  }
}
