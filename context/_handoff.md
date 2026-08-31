# Hand-off — 2026-08-31 (sitio de marketing)

## Objetivo
Punto no tenía sitio de marketing real — el dominio `punto.la` no servía
nada propio. Esta sesión construyó uno de cero (`frontend/app/(site)/`),
usando pediaca.app SOLO como referencia de estructura (copy propio,
auditado contra lo que Punto realmente ofrece), para tener una presencia
comercial con SEO, páginas por rubro/módulo, y una fuente de contenido para
el futuro agente de atención al cliente vía WhatsApp/Fish.

## Estado al cerrar
`origin/main` = `5630c3d1` (working tree limpio). El trabajo de ESTA sesión
termina en `b91b2991` — las 3 commits entre `b91b2991` y `5630c3d1` son de
una sesión paralela (reportes de clientes), no tocarlas ni asumir que son
tuyas si retomás esto.

**Deploy**: el último confirmado `finished` para el sitio fue `6cb5c50d`
(webchat). El commit final de la sesión (`b91b2991`, fix del link de
Instagram) NO se deployó solo, pero SÍ va incluido en el deploy
`1oag5axpukdg1cnq2wdheqvv` (para el commit `5630c3d1`, disparado por la
sesión paralela) que quedó `in_progress` al momento de cerrar. Primer paso
de la próxima sesión si toca el sitio: `mcp__coolify__get_deployment` con
ese uuid para confirmar `status: finished`, y `list_applications` para
`running:healthy`. Si por algo falló, hace falta un deploy explícito de
Punto Front (`nzmay2ytcdup3sgylspq39z6`).

El sitemap ya fue enviado a Search Console por el owner (19→29 URLs
indexadas en la última corrida). Los redirects de `encom.app` están
cargados en Cloudflare Bulk Redirects (a mano, fuera del repo) y
confirmados funcionando con 301 a destinos específicos.

## Archivos y cambios
- `frontend/middleware.ts` — ruteo por host: `punto.la` reescribe `/` →
  `/home`, `app.punto.la` sigue sirviendo el panel sin cambios.
- `frontend/app/(site)/` — todas las páginas del sitio (home, rubros,
  módulos, precios, contacto).
- `frontend/lib/site/rubros.ts` — 15 rubros, 3 grupos, campo `destacado`.
- `frontend/lib/site/modulos.ts` — 10 minipages, `mercados: ["PY"]` en
  facturación electrónica.
- `frontend/lib/site/markets.ts` — capa de mercado (precio/moneda/
  `{docFiscal}`/`{money:X}`); hoy solo existe `PY`, sin testear con un
  segundo mercado.
- `frontend/lib/site/legacy-redirects.ts` — mapa `encom.app` → URL
  específica en `punto.la`.
- `frontend/scripts/export-site-content.ts` + `scripts/alias-hook.mjs` —
  genera `content/sitio/*.md` en `prebuild`, desde las mismas fuentes que
  renderiza el sitio.
- `content/sitio/faq-ventas.md` — único archivo de esa carpeta escrito a
  mano (`editable: true`), el exportador lo respeta.
- `frontend/app/globals.css` — fix cross-cutting: `@custom-variant dark
  (&:is(.dark *):not(.light *));` (la variante `dark:` se filtraba dentro
  de subtrees forzados a `.light`, no solo del sitio).
- `frontend/components/ui/tabs.tsx` — fix cross-cutting: `data-active:` →
  `data-[state=active]:` (Radix marca `data-state="active"`, no
  `data-active`; el estado activo de Tabs nunca se pintó en NINGÚN lugar
  del proyecto hasta este fix). Vale la pena revisar si el panel tiene
  Tabs cuyo estado activo nadie reportó como bug.
- `frontend/components/site/mockups.tsx` (`MockFrame`) — fija su propio
  color de texto, no hereda del fondo de la escena que lo contiene.
- `context/61-sitio-marketing.md` — doc nuevo, estructura + capa de
  mercado + pipeline de contenido.

## Callejones sin salida
- **Verde de marca en el pill activo de tabs** — violaba `context/14`
  §5 (el verde nunca va en UI interactiva/CTA, solo decorativo). Revertido
  a `bg-foreground` por marca del owner. Chequear §5 ANTES de aplicar
  color a un elemento interactivo, no después.
- **Tamaño de los pills de tabs** — se pasó para chico primero (heredaba
  `h-8` de la densidad del panel) y para grande después (`h-11 px-6`). El
  correcto es `h-9 px-4` (el de `Button size="lg"`). Cuando el primitive
  fija alto con `!important` en la variante horizontal, hay que
  neutralizarlo explícito — no alcanza con tocar el padding.
- **Copy heredado de pediaca.app** arrastró promesas que Punto no cumple:
  "prueba gratis / sin tarjeta" (no hay onboarding autogestionado, los CTA
  van a WhatsApp o al signup real), precio repetido en todos los hero/CTA
  (vive SOLO en `/precios`), "todo incluido" (alcance no cerrado). Cualquier
  copy de referencia externa se audita contra lo que Punto realmente
  ofrece antes de publicar, no se asume que aplica.
- **Sesgo de rubro** — los primeros mockups/ejemplos del home eran
  puramente gastronómicos (lomito, mesas, comanda), alguien de otro rubro
  sentía que el sistema no era para él. Se corrigió reordenando tabs y
  cambiando ejemplos. Al armar contenido de producto genérico, el primer
  ejemplo visible no puede ser de un solo rubro.
- **Menú mobile** faltó toda la sesión hasta que se notó (nav tenía
  `hidden md:flex`, sin botón hamburguesa). Se resolvió con un panel a
  pantalla completa montado vía `createPortal` en `<body>` — el header con
  `backdrop-blur` es contenedor de posicionamiento, así que un `fixed`
  adentro colapsaba a alto 0.

## Próximo paso
Confirmar el deploy `1oag5axpukdg1cnq2wdheqvv` (`mcp__coolify__get_deployment`)
llegó a `finished` y la app quedó `running:healthy` — ese deploy lleva el
fix del link de Instagram (`b91b2991`), el último commit de esta sesión.
Si terminó bien, no queda nada abierto del sitio; si falló, redeployar
Punto Front (`nzmay2ytcdup3sgylspq39z6`) explícito.

## Trampas conocidas
- `content/sitio/*.md` se regenera en CADA build salvo `faq-ventas.md`
  (`editable: true`) — no editar a mano los demás, se pierde en el próximo
  `npm run build`.
- `hero.tsx` tiene un comentario documentando dónde engancha un futuro
  video de fondo — no es dead code, no borrarlo sin leerlo.
- `lib/site/markets.ts` solo tiene la entrada `PY`; el mecanismo para
  agregar mercado está diseñado pero nunca ejercitado con un segundo país
  real — la primera vez que se agregue uno, verificar con cuidado.
- El módulo de facturación electrónica se autooculta por mercado
  (`modulosVisibles()`) porque nombra al organismo fiscal paraguayo — si
  se agrega copy PY-específico en otro lugar del sitio, replicar el mismo
  gating, no asumir que basta con no tocar esa página.
