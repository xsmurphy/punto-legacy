/**
 * Copia los modelos del reconocimiento facial a `public/models/face/`
 * (RRHH F2, context/83 D5).
 *
 * ── Por qué se COPIAN y no se sirven de un CDN ─────────────────────────────
 *
 * El quiosco de marcación es una PWA que tiene que funcionar sin internet, y la
 * biometría no puede depender de un tercero: las dos cosas se rompen con un
 * `<script src="https://cdn...">`. Los modelos salen del MISMO deploy, por el
 * mismo origen que el resto de la app, y quedan cacheados por el service worker
 * la primera vez que alguien entra a marcar.
 *
 * ── Por qué se copian en el BUILD y no se commitean ────────────────────────
 *
 * Son 6,6 MB de binarios que no editamos nunca: viven en el paquete npm, con su
 * versión fijada en `package-lock.json`. Meterlos en git sería versionar dos
 * veces el mismo artefacto y engordar el repo con cada bump.
 *
 * Corre en `prebuild`, antes de `next build`, y por lo tanto antes de que
 * `next.config.ts` glob-ee `public/` para armar el precache — que es justo por
 * lo que ESE archivo tiene que excluir esta carpeta a mano (ver
 * `publicPrecacheEntries()`): precachear 6,6 MB en cada dispositivo, use o no la
 * marcación, sería pagar la instalación de la PWA en todas las cajas por una
 * pantalla que casi ninguna abre.
 *
 * `next dev` NO dispara `prebuild`. Si hace falta en desarrollo:
 *   npm run models:face
 * Sin los archivos, el quiosco cae al flujo por código, que es el camino que la
 * D4 garantiza igual.
 *
 * ── Solo tres modelos, de los siete que trae el paquete ────────────────────
 *
 * Detección + puntos faciales + descriptor. Edad, género y expresiones no se
 * usan y no se sirven: son megabytes que nadie baja y datos que nadie pidió.
 */

import fs from "node:fs"
import path from "node:path"
import { fileURLToPath } from "node:url"

const HERE = path.dirname(fileURLToPath(import.meta.url))
const ROOT = path.join(HERE, "..")
const SRC = path.join(ROOT, "node_modules", "@vladmandic", "face-api", "model")
const DEST = path.join(ROOT, "public", "models", "face")

/**
 * Los modelos que el quiosco carga, con el prefijo de sus archivos.
 *
 * `face-api` resuelve cada uno como `<prefijo>-weights_manifest.json` más los
 * `.bin` que el manifiesto nombre, así que se copia el manifiesto y todos los
 * binarios que empiecen con el mismo prefijo.
 */
const MODELS = [
  // Encuentra caras en el frame. El "tiny" y no el SSD: corre a varios cuadros
  // por segundo en una tablet barata, que es el hardware real de un quiosco.
  "tiny_face_detector_model",
  // Los 68 puntos de la cara. Alinean el recorte para el descriptor y —lo que
  // no hace ninguno de los otros— dan los contornos de los ojos, de donde sale
  // el parpadeo.
  "face_landmark_68_model",
  // El descriptor: la cara convertida en 128 números.
  "face_recognition_model",
]

function main() {
  if (!fs.existsSync(SRC)) {
    // No se aborta el build. Sin modelos el quiosco marca por código igual (D4)
    // y el resto de la app no se entera de que esto existe; voltear un deploy
    // entero por una pantalla que degrada sola sería peor.
    console.warn("[face-models] no están los modelos en node_modules — se omite la copia")
    return
  }

  fs.mkdirSync(DEST, { recursive: true })

  let copied = 0
  let bytes = 0
  for (const name of fs.readdirSync(SRC)) {
    if (!MODELS.some((m) => name.startsWith(m))) continue
    const from = path.join(SRC, name)
    const to = path.join(DEST, name)
    const src = fs.statSync(from)
    // Idempotente: en una rebuild local no se reescribe lo que ya está igual.
    if (fs.existsSync(to) && fs.statSync(to).size === src.size) {
      copied++
      bytes += src.size
      continue
    }
    fs.copyFileSync(from, to)
    copied++
    bytes += src.size
  }

  console.log(`[face-models] ${copied} archivos en public/models/face (${(bytes / 1024 / 1024).toFixed(1)} MB)`)
}

main()
