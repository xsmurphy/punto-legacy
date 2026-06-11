import type { NextConfig } from "next"

const nextConfig: NextConfig = {
  output: "standalone",
  turbopack: {
    root: __dirname,
  },
  // Whitelist de hosts para next/image. DO Spaces (S3-compatible) + AWS S3 genéricos.
  // En las imágenes de items usamos `unoptimized` igual — el backend ya las redimensiona —
  // pero el remotePatterns es requerido por Next aunque se opte por unoptimized.
  images: {
    remotePatterns: [
      { protocol: "https", hostname: "**.digitaloceanspaces.com" },
      { protocol: "https", hostname: "**.amazonaws.com" },
    ],
  },
}

export default nextConfig
