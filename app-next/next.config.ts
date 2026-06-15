import type { NextConfig } from "next"

const nextConfig: NextConfig = {
  output: "standalone",
  turbopack: {
    root: __dirname,
  },
  // Whitelist de hosts para next/image. DO Spaces (S3-compatible) + AWS S3 genéricos.
  images: {
    remotePatterns: [
      { protocol: "https", hostname: "**.digitaloceanspaces.com" },
      { protocol: "https", hostname: "**.amazonaws.com" },
    ],
  },
}

export default nextConfig
