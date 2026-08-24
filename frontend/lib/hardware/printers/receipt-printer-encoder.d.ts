declare module "@point-of-sale/receipt-printer-encoder" {
  interface EncoderOptions {
    columns?: number
    language?: string
  }

  type AlignValue = "left" | "center" | "right"

  interface Encoder {
    initialize(): this
    align(value: AlignValue): this
    bold(value: boolean): this
    /** Escribe SIN salto — `render-template.ts` arma cada fila de la grilla
     *  por tramos (negrita on/off) y cierra con `newline()`. */
    text(value: string): this
    line(text: string): this
    newline(): this
    /** Pulso del cajón de dinero: dispositivo, ms encendido, ms apagado. */
    pulse(device: number, on: number, off: number): this
    /** `width` en CARACTERES de la grilla monoespaciada; default = columns. */
    rule(opts?: { style?: "single" | "double"; width?: number }): this
    barcode(value: string, symbology: string, opts?: { height?: number }): this
    qrcode(
      value: string,
      opts?: { model?: 1 | 2; size?: number; errorlevel?: "l" | "m" | "q" | "h" },
    ): this
    cut(): this
    encode(): Uint8Array
  }

  const ReceiptPrinterEncoder: new (opts?: EncoderOptions) => Encoder
  export default ReceiptPrinterEncoder
}
