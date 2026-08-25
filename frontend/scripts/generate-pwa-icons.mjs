/**
 * Genera los iconos de la PWA a partir del logo oficial de Punto.
 *
 * Fuente única: `public/logos/icon_bg_dark.png` — la variante `mark` del
 * logo (la "o" blanca con el punto verde) pensada PARA fondo oscuro. Es la
 * misma imagen que sirve `<PuntoLogo variant="mark" />`, así que el icono de
 * la home screen y el del sidebar no pueden divergir.
 *
 * Por qué existe este script y no un PNG pegado a mano: los iconos son
 * artefactos DERIVADOS del logo. Si el logo cambia, se vuelve a correr esto y
 * los seis salen consistentes. Antes había tres PNG de 400-1500 bytes que eran
 * literalmente un cuadrado `#0a0a0a` liso —un solo color, cero pixeles de
 * logo—, y por eso iOS y Android no mostraban nada reconocible.
 *
 * Correr a mano cuando cambie el logo:
 *
 *     node scripts/generate-pwa-icons.mjs
 *
 * NO corre en el build. `sharp` llega por el árbol de dependencias de Next
 * (no está declarada en package.json), así que esto es una herramienta de
 * desarrollo, no un paso de CI.
 *
 * Geometría — las dos familias NO son la misma imagen escalada:
 *
 *   - `any` (192, 512): el launcher puede recortar las esquinas con un radio
 *     modesto. El logo va al 84% del lienzo, centrado.
 *   - `maskable` (192, 512): Android recorta con una máscara arbitraria y solo
 *     garantiza el círculo interior del 80% del lado ("safe zone"). El logo va
 *     al 68% para entrar entero en ese círculo con margen. Si se sube a 84%,
 *     un launcher con máscara circular le come el anillo.
 *   - `apple-icon` (180): iOS aplica su propia máscara y NO respeta el canal
 *     alfa (pinta el transparente de negro), así que sale aplanado sobre el
 *     fondo de marca y sin alfa.
 */

import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const here = path.dirname(fileURLToPath(import.meta.url))
const frontend = path.join(here, "..")

let sharp
try {
  sharp = (await import("sharp")).default
} catch {
  console.error(
    "Falta `sharp`. Llega por las dependencias de Next: corré `npm install` en frontend/ y volvé a intentar.",
  )
  process.exit(1)
}

/** Fondo de marca. Mismo valor que `background_color`/`theme_color` del manifest. */
const BG = "#0a0a0a"

const SOURCE = path.join(frontend, "public", "logos", "icon_bg_dark.png")
const ICONS_DIR = path.join(frontend, "public", "icons")

/**
 * Compone el logo centrado sobre el fondo de marca.
 *
 * `fit: "contain"` con fondo transparente conserva la relación de aspecto del
 * mark (es cuadrado, pero no se asume acá) antes de pegarlo sobre el lienzo.
 */
async function renderIcon({ size, coverage, out, alpha = true }) {
  const inner = Math.round(size * coverage)
  const mark = await sharp(SOURCE)
    .resize(inner, inner, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .toBuffer()

  let image = sharp({
    create: { width: size, height: size, channels: 4, background: BG },
  }).composite([{ input: mark, gravity: "centre" }])

  if (!alpha) image = image.flatten({ background: BG }).removeAlpha()

  await image.png({ compressionLevel: 9 }).toFile(out)

  const { size: bytes } = fs.statSync(out)
  console.log(`  ${path.relative(frontend, out)} — ${size}x${size}, ${bytes} bytes`)
}

async function main() {
  if (!fs.existsSync(SOURCE)) {
    console.error(`No encuentro el logo fuente: ${SOURCE}`)
    process.exit(1)
  }
  fs.mkdirSync(ICONS_DIR, { recursive: true })

  console.log("Iconos `any` (84% de cobertura):")
  await renderIcon({ size: 192, coverage: 0.84, out: path.join(ICONS_DIR, "pos-192.png") })
  await renderIcon({ size: 512, coverage: 0.84, out: path.join(ICONS_DIR, "pos-512.png") })

  console.log("Iconos `maskable` (68% — entra en la safe zone del 80%):")
  await renderIcon({
    size: 192,
    coverage: 0.68,
    out: path.join(ICONS_DIR, "pos-192-maskable.png"),
  })
  await renderIcon({
    size: 512,
    coverage: 0.68,
    out: path.join(ICONS_DIR, "pos-512-maskable.png"),
  })

  console.log("apple-touch-icon (180, sin alfa — iOS pinta el transparente de negro):")
  await renderIcon({
    size: 180,
    coverage: 0.84,
    alpha: false,
    out: path.join(frontend, "app", "apple-icon.png"),
  })
}

await main()
