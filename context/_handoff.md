# Hand-off — 2026-08-29 (2)

## Objetivo
Continuación de la sesión "Punto bugs" sobre impresión de tickets: cerrar
2 gaps de paridad visual que quedaron del bloque anterior (hueco duplicado
bajo el logo, bloques de orden que no imprimían fuera de `docType==="order"`)
y ajustar vocabulario de 2 títulos por pedido del owner. Sigue siendo
respuesta a reportes de bugs en producción, no roadmap planificado.

## Estado al cerrar
`origin/main` en `0a5d9283`. Los 2 commits del rango `4bcd1c6d..0a5d9283`
están commiteados, pusheados y **DEPLOYADOS** — front y backend corren
`0a5d9283`, healthy. Migraciones 179 y 180 corridas y verificadas contra
la BD de prod (179 rotuló 4 plantillas, 180 renombró 2 literales).

- Hueco duplicado tras el logo: corregido — `RollGraphic.rows` es ahora la
  única fuente del alto del bloque gráfico; los 3 renderers dibujan DE ese
  alto y saltean filas reservadas sin texto.
- `order_number`/`order_destination`/`table_number`: gate `docType==="order"`
  eliminado (contradecía context/20 y regla 1 del módulo de impresión).
- `document_number` sin título propio: título dinámico por `docType`
  (`DOC_NUMBER_LABELS`), backfill mig 179 a TODOS los doctypes (174/178
  solo cubrían venta).
- Vocabulario: "Cajero:"→"Usuario:", "Mesa:"→"Espacio:" (catálogo +
  mig 180, solo literales exactos auto-estampados — títulos editados a
  mano no se tocaron).
- 438/438 vitest. Verificación visual del bloque de logo/gráficos hecha
  ANTES de deployar (mismo método que el bloque anterior — ver Callejones).

## Archivos y cambios
- `frontend/lib/hardware/printers/render-template.ts` — `RollGraphic.rows`
  nuevo campo; HTML dibuja con altura en mm + `object-fit`; ESC/POS con
  `rows*24` puntos y ancho por aspecto capado al papel; filas reservadas
  sin texto se saltean en ambos renderers.
- `frontend/lib/hardware/printers/blocks.ts` — gate `docType==="order"`
  eliminado de `order_number`/`order_destination`/`table_number`;
  `DOC_NUMBER_LABELS` nuevo (título dinámico por docType para
  `document_number`, sale de `DEFAULT_BLOCK_LABELS`); labels de
  `user_name`/`table_number` actualizados a "Usuario:"/"Espacio:".
- Mig `179` — backfill de títulos para TODOS los doctypes (no solo venta).
- Mig `180` — renombra SOLO los literales exactos "Cajero:"/"Mesa:"
  auto-estampados; no toca títulos editados a mano.
- `context/modules/18-impresion.md` — reglas 12/13/14 nuevas (alto de
  gráficos vía `rows`, gates de orden eliminados, título dinámico de
  `document_number` + trampa del bloque angosto); reglas viejas 11+
  renumeradas a 15+.
- `context/_session-log.md` / `context/_handoff.md` — este cierre.

## Callejones sin salida
- Nada nuevo en este bloque — ver el hand-off anterior (ya reemplazado)
  para los callejones del bloque previo (wrap genérico vs relleno
  pre-calculado, margen restado de las columnas, "caché del browser" como
  diagnóstico equivocado). Siguen vigentes como conocimiento del módulo,
  documentados en `context/modules/18-impresion.md`.

## Próximo paso
Sigue siendo el mismo pendiente de fondo: pedirle al owner que imprima un
ticket con logo en una térmica FÍSICA (el pipeline completo, incluida la
corrección de alto de este bloque, solo se verificó en browser). Si sale
mal, el punto de entrada es `renderTemplateToEscPos` en
`frontend/lib/hardware/printers/render-template.ts`.

## Trampas conocidas
- **Nueva**: `document_number` en un bloque ANGOSTO (media columna) con
  título dinámico wrapea por palabras y con `textwrap:"cut"` puede perder
  el NÚMERO, dejando solo el rótulo (ej. "Factura Nro.:" sin número). El
  golden test lo cubre; en plantillas reales los bloques de número son
  full-width, pero si un comercio reporta este síntoma es esto — fix del
  operador: título corto propio o ensanchar el bloque, no un bug de datos.
- Deploy de Punto Front sigue con webhook de auto-deploy ACTIVO en Coolify
  (decisión del owner, sin cambios). Backend no tiene el webhook.
- `fe_cdc` en blanco en el primer ticket no es bug (emisión asíncrona).
- P2s de la auditoría de seguridad del 26 siguen abiertos; `TZ
  America/Asuncion` literal en migs 157/160/period-close sigue sin migrar;
  "Bloquear sesión luego de" en Ajustes sigue mock.
- El arnés de facturación electrónica (guard del caso vale) sigue OFRECIDO
  al owner y sin hacer — no asumir que existe.
- Logo en ESC/POS sigue sin probarse contra impresora térmica física
  (solo verificado en browser, dos bloques seguidos ahora).
