# Sitio de marketing (punto.la)

Construido de cero 2026-08-31, commits `53ce1895..b91b2991` (60). Corre en el
MISMO container que el panel — no es un subproyecto ni deploy aparte.

## Ruteo

`frontend/middleware.ts` rutea por host: `punto.la` reescribe `/` → `/home`;
`app.punto.la` sigue sirviendo el panel sin cambios. Un solo Next app, dos
dominios.

Desde 2026-09-16 hay un tercero, el sitio de ayuda (`docs.punto.la`, hosts en
`DOCS_HOSTS`, origen canónico en `NEXT_PUBLIC_DOCS_URL`): TODA ruta de ese host
se reescribe bajo `/ayuda` (route group `(docs)`), que genera en build una
página estática por artículo de `frontend/content/ayuda/*.md`. En los otros
hosts `/ayuda/*` redirige 308 al host de ayuda. El matcher del middleware dejó
de ser solo `/`: excluye `/_next/`, `/api/` y assets por extensión (no `.xml`
ni `.txt`, que el host de ayuda necesita reescribir).

## Estructura

- `frontend/app/(site)/` — home, precios, contacto, rubros, módulos.
- `frontend/components/site/` — incluye `mockups.tsx` (`MockFrame`, compone UI
  con tokens del design system para las minipages; fija su propio color de
  texto, no hereda del fondo de la escena).
- `frontend/lib/site/rubros.ts` — 15 rubros en 3 grupos (Gastronomía, Retail,
  Salud y belleza); campo `destacado: true` marca los 4 por grupo que el menú
  principal muestra, el resto vive en footer/menú mobile/"otros rubros" (los
  15 están en el sitemap, ninguno pierde SEO).
- `frontend/lib/site/modulos.ts` — 10 minipages de módulo. `mercados: ["PY"]`
  oculta una minipage en mercados donde no aplica (hoy solo
  `facturacion-electronica`, nombra al organismo fiscal) vía
  `modulosVisibles()`.
- `frontend/lib/site/markets.ts` — **todo lo que cambia por país vive acá**:
  precio, moneda, nombre del documento fiscal (token `{docFiscal}` en el
  copy), contacto, y escala de montos de ejemplo (token `{money:145000}` vía
  `applyMarketTerms()`). Hoy solo existe `PY`. Agregar mercado = entrada nueva
  acá, sin tocar componentes — pero sin testear todavía porque no hay un
  segundo mercado real.
- `frontend/lib/site/legacy-redirects.ts` — mapa `encom.app` → URL específica
  en `punto.la` (no catch-all al home). Cargado a mano en Cloudflare Bulk
  Redirects por el owner, confirmado funcionando.

## Pipeline de contenido para IA

`frontend/scripts/export-site-content.ts` genera `content/sitio/*.md`, un
archivo por página, DESDE las mismas fuentes que renderiza el sitio
(`rubros.ts`/`modulos.ts`/`markets.ts`) — alimenta un agente de atención al
cliente. Corre con Node 25 type-stripping nativo + `scripts/alias-hook.mjs`
(resuelve `@/`), sin dependencias nuevas. `npm run build` dispara `prebuild` →
`export:content` automático, así el contenido nunca queda desincronizado.

`content/sitio/faq-ventas.md` es el ÚNICO archivo de esa carpeta escrito a
mano (frontmatter `editable: true`) — el exportador lo detecta y nunca lo
sobreescribe. Cualquier otro archivo en `content/sitio/` se regenera en cada
build; no editarlo a mano.

## SEO

`sitemap.ts`, `robots.ts` (bloquea rutas operativas del panel), canónicas por
página, OG image generada con `next/og`, JSON-LD (Organization en el layout,
Product+FAQ en `/precios`). Sitemap enviado a Search Console por el owner.

## Detalle completo

Highlights de sesión, callejones sin salida y 2 fixes cross-cutting al
design system (variante `dark:` de Tailwind, estado activo de `<Tabs>`) están
en el entry de bitácora del 2026-08-31 y en el rango de commits de arriba —
no repetidos acá.
