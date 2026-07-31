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
    line(text: string): this
    newline(): this
    rule(opts?: { style?: "single" | "double" }): this
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
