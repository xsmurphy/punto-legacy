import { ImageResponse } from "next/og"

export const alt = "Punto — Sistema de punto de venta y facturación electrónica"
export const size = { width: 1200, height: 630 }
export const contentType = "image/png"

/** Imagen de OpenGraph del sitio: lo que se ve al compartir el link. */
export default function OgImage() {
  return new ImageResponse(
    (
      <div
        style={{
          height: "100%",
          width: "100%",
          display: "flex",
          flexDirection: "column",
          justifyContent: "space-between",
          background: "#060A0E",
          padding: 80,
        }}
      >
        <div style={{ display: "flex", fontSize: 44, color: "#01D7A1", letterSpacing: -1 }}>
          punto
        </div>
        <div
          style={{
            display: "flex",
            fontSize: 76,
            color: "white",
            lineHeight: 1.1,
            letterSpacing: -2,
            maxWidth: 900,
          }}
        >
          Todo tu negocio pasa por un punto.
        </div>
        <div style={{ display: "flex", fontSize: 30, color: "rgba(255,255,255,0.6)" }}>
          Punto de venta · Facturación electrónica · Stock · Reportes
        </div>
      </div>
    ),
    size,
  )
}
