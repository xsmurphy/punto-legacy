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
    cut(): this
    encode(): Uint8Array
  }

  const ReceiptPrinterEncoder: new (opts?: EncoderOptions) => Encoder
  export default ReceiptPrinterEncoder
}
