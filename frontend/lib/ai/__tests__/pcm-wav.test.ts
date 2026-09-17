import { describe, expect, it } from "vitest"

import { parsePcmContentType, pcmToWav } from "@/lib/ai/pcm-wav"

describe("parsePcmContentType", () => {
  it("lee rate y channels del content-type real de OpenRouter", () => {
    expect(parsePcmContentType("audio/pcm;rate=24000;channels=1")).toEqual({
      rate: 24000,
      channels: 1,
    })
  })

  it("tolera espacios y otro orden de params", () => {
    expect(parsePcmContentType("audio/pcm; channels=2; rate=44100")).toEqual({
      rate: 44100,
      channels: 2,
    })
  })

  it("cae al default 24000/1 con header ausente o sin params", () => {
    expect(parsePcmContentType(null)).toEqual({ rate: 24000, channels: 1 })
    expect(parsePcmContentType("audio/pcm")).toEqual({ rate: 24000, channels: 1 })
  })

  it("descarta valores no positivos", () => {
    expect(parsePcmContentType("audio/pcm;rate=0;channels=0")).toEqual({
      rate: 24000,
      channels: 1,
    })
  })
})

describe("pcmToWav", () => {
  it("prefija 44 bytes de header y conserva el PCM intacto", () => {
    const pcm = new Uint8Array([1, 2, 3, 4, 5, 6]).buffer
    const wav = pcmToWav(pcm, 24000, 1)
    expect(wav.byteLength).toBe(44 + 6)
    expect(Array.from(new Uint8Array(wav, 44))).toEqual([1, 2, 3, 4, 5, 6])
  })

  it("escribe un header RIFF/WAVE consistente (mono 16-bit 24kHz)", () => {
    const pcm = new ArrayBuffer(1000)
    const wav = pcmToWav(pcm, 24000, 1)
    const view = new DataView(wav)
    const ascii = (o: number, n: number) =>
      String.fromCharCode(...new Uint8Array(wav, o, n))

    expect(ascii(0, 4)).toBe("RIFF")
    expect(view.getUint32(4, true)).toBe(36 + 1000)
    expect(ascii(8, 4)).toBe("WAVE")
    expect(ascii(12, 4)).toBe("fmt ")
    expect(view.getUint16(20, true)).toBe(1) // PCM
    expect(view.getUint16(22, true)).toBe(1) // canales
    expect(view.getUint32(24, true)).toBe(24000)
    expect(view.getUint32(28, true)).toBe(24000 * 2) // byteRate
    expect(view.getUint16(32, true)).toBe(2) // blockAlign
    expect(view.getUint16(34, true)).toBe(16) // bits
    expect(ascii(36, 4)).toBe("data")
    expect(view.getUint32(40, true)).toBe(1000)
  })

  it("estéreo ajusta byteRate y blockAlign", () => {
    const wav = pcmToWav(new ArrayBuffer(8), 44100, 2)
    const view = new DataView(wav)
    expect(view.getUint16(22, true)).toBe(2)
    expect(view.getUint32(28, true)).toBe(44100 * 4)
    expect(view.getUint16(32, true)).toBe(4)
  })
})
