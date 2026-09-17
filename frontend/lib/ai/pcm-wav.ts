/**
 * PCM crudo → WAV, para el TTS del agente (`context/80-voz-del-agente.md`).
 *
 * Gemini TTS vía OpenRouter solo emite `response_format="pcm"` (16-bit LE,
 * sin contenedor — verificado 2026-09-17: pedir mp3 devuelve 400). Un browser
 * no reproduce PCM pelado con `new Audio()`, así que el BFF lo envuelve en un
 * header WAV de 44 bytes antes de responder. Es un prefijo, no un transcode:
 * cero dependencias, costo despreciable.
 */

/**
 * `rate`/`channels` del content-type que manda OpenRouter
 * (`audio/pcm;rate=24000;channels=1`). Se parsea en vez de hardcodear: el rate
 * es del MODELO, no del endpoint, y un default equivocado no falla — suena en
 * cámara lenta o acelerado, que es peor que un error porque nadie lo reporta
 * como bug de código.
 */
export function parsePcmContentType(contentType: string | null): { rate: number; channels: number } {
  const rate = Number(/rate=(\d+)/.exec(contentType ?? "")?.[1])
  const channels = Number(/channels=(\d+)/.exec(contentType ?? "")?.[1])
  return {
    rate: Number.isFinite(rate) && rate > 0 ? rate : 24000,
    channels: Number.isFinite(channels) && channels > 0 ? channels : 1,
  }
}

/** Envuelve PCM 16-bit little-endian en un contenedor WAV (RIFF) reproducible. */
export function pcmToWav(pcm: ArrayBuffer, rate: number, channels: number): ArrayBuffer {
  const bytesPerSample = 2 // 16-bit
  const blockAlign = channels * bytesPerSample
  const byteRate = rate * blockAlign
  const dataSize = pcm.byteLength

  const buf = new ArrayBuffer(44 + dataSize)
  const view = new DataView(buf)
  const writeAscii = (offset: number, s: string) => {
    for (let i = 0; i < s.length; i++) view.setUint8(offset + i, s.charCodeAt(i))
  }

  writeAscii(0, "RIFF")
  view.setUint32(4, 36 + dataSize, true)
  writeAscii(8, "WAVE")
  writeAscii(12, "fmt ")
  view.setUint32(16, 16, true) // tamaño del chunk fmt
  view.setUint16(20, 1, true) // PCM sin compresión
  view.setUint16(22, channels, true)
  view.setUint32(24, rate, true)
  view.setUint32(28, byteRate, true)
  view.setUint16(32, blockAlign, true)
  view.setUint16(34, 16, true) // bits por sample
  writeAscii(36, "data")
  view.setUint32(40, dataSize, true)
  new Uint8Array(buf, 44).set(new Uint8Array(pcm))
  return buf
}
