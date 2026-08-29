# Hand-off — 2026-08-29

## Objetivo
Sesión "Punto bugs": cadena de fixes reportados por el owner sobre el módulo
de impresión de tickets (fuente/ancho/alineación del rollo térmico, soporte
real de 76mm/TM-U220, logo en ESC/POS) y sobre teléfonos E.164 sin `+` que
salían crudos en listados y tickets. No es trabajo planificado de roadmap —
es respuesta directa a reportes de bugs en producción.

## Estado al cerrar
`origin/main` en `08d8d5b8`. Los 18 commits del rango `31a2efbd..08d8d5b8`
están commiteados, pusheados y **DEPLOYADOS** — front y backend corren
`08d8d5b8`, verificado contra los contenedores (no solo contra el push).
Migraciones 174/177/178 corridas y verificadas contra la BD de prod.

- Teléfonos: arreglado en TODOS los listados y en el ticket impreso.
- Rollo térmico: monoespaciado forzado, ancho de columna correcto, wrap sin
  destruir alineación, snap a grilla — verificado VISUALMENTE antes de
  deployar (ver Callejones).
- 76mm/TM-U220: implementado, plantillas viejas de 76 ahora renderizan a 33
  columnas (corrección, no regresión, decisión del owner).
- Logo: funciona en canvas/preview/HTML/ESC-POS — el pipeline se probó en
  browser, NO contra una impresora térmica física. Ver Próximo paso.
- `fe_cdc` (bloque nuevo, paleta gateada por módulo `einvoicePy`) y
  `TransactionDetailService.einvoiceCdc`/`einvoicePortalUrl`: el primer
  ticket puede salir sin CDC porque la emisión electrónica es asíncrona —
  es diseño documentado en el tipo, no bug.
- Dropzone OCR de la sesión paralela de compras (system-fb) sacado del
  formulario a un diálogo "Leer factura" (`cac7633f`) — no es trabajo de
  esta sesión, solo se movió un componente que estorbaba.

## Archivos y cambios
- `frontend/lib/format-phone.ts` (o equivalente) — repone `+` antes de
  parsear con libphonenumber; tocaba TODOS los listados + el bloque de
  ticket que imprime teléfono.
- `frontend/components/ui/password-input.tsx` / `pin-input.tsx` — ojo
  mostrar/ocultar, PIN nace oculto, margen del ícono corregido
  (`has-[>button]:mr-[-0.3rem]` anulado en `PasswordInput`).
- `frontend/lib/hardware/printers/render-template.ts` — `ROLL_FONT_STACK`,
  `renderTemplateToEscPos` ahora ASYNC (logo: `img.decode` + `crossOrigin
  anonymous` + `encoder.image` con dithering `atkinson`).
- Renderer del rollo (canvas + HTML del preview) — grilla CSS de `columns`
  celdas de `1fr`; `charWidthPx = canvas/columns` (NO `contentColumns`);
  `ROLL_MARGIN_COLS=1`; `distributeRow`/`wrapToWidth` (línea que ya entra
  se devuelve tal cual); `snapBlockToRollRows`; `CHAR_EM_RATIO=0.605`.
- Tipo `PaperWidthMm` → `58|76|80`; selector "76 mm (impacto, TM-U220)" en
  bindings; API acepta 76.
- Mig `178` — backfill de `DEFAULT_BLOCK_LABELS` para plantillas que
  quedaron sin título tras la mig 174 (totales, cliente, por-tasa).
- Endpoint duplicar plantilla (GET detalle + POST copia).
- `frontend/lib/hardware/printers/blocks.ts` — `fe_cdc` nuevo, `fe_py`/
  `fe_cdc` filtrados de la paleta salvo módulo `einvoicePy` activo.
- `context/modules/18-impresion.md` — reglas 9 (monoespaciado forzado +
  grilla de columnas) y 10 (76mm=TM-U220) agregadas; logo ESC/POS anotado
  como no verificado físicamente; `fe_cdc` sumado a la fila de FE.
- `context/_session-log.md` / `context/_handoff.md` — este cierre.

## Callejones sin salida
- **Deployar impresión sin verificar visualmente = rebote seguro.** Dos
  tandas previas de esta sesión se deployaron mirando solo el código y el
  owner las rebotó. Lo que funcionó: dump del HTML real del renderer con
  un test vitest temporal (`zz-dump.test.ts`, borrado al terminar), servido
  con `python3 -m http.server` en el scratchpad y mirado/medido en el
  Browser pane — los `file://` no screenshotean, hace falta HTTP real.
  Medición fina con `getBoundingClientRect` vía `javascript_tool` cuando el
  ojo no alcanza.
- **"Es caché del browser" fue un diagnóstico equivocado.** El corte a la
  derecha del preview que el owner reportó 2 veces era el mismatch entre
  papel de DISEÑO (76mm) y papel del DISPOSITIVO (80mm) — un bug real, solo
  visible en plantillas guardadas a 76mm. Si el owner insiste con evidencia
  concreta después de un "ya lo arreglé", reproducir SU caso exacto antes
  de descartar.
- **El wrap genérico destruía el relleno pre-calculado.** `wrapToWidth`
  parte por palabras y re-une con un espacio — cualquier línea ya alineada
  por `distributeRow` quedaba amontonada al pasar por el wrap. Fix: una
  línea que ya entra en el ancho se devuelve tal cual, sin tocar.
- **Restar el margen de las columnas del divisor ensancha la celda y
  rompe todo.** El margen del rollo (`ROLL_MARGIN_COLS`) son celdas DEL
  papel, no columnas de contenido — dividir por `contentColumns` (46) en
  vez de `columns` (48) hizo la celda 4% más ancha y el texto desbordaba
  (regresión propia, `61a74215`).
- Coolify drena el contenedor viejo ~1-2 min después del healthy del
  nuevo — verificar el drenaje antes de decirle al owner que pruebe, o va
  a probar contra la versión vieja y reportar que "no cambió nada".

## Próximo paso
Pedirle al owner que imprima un ticket con logo en una térmica FÍSICA
(hoy solo se verificó el pipeline en browser). Si sale mal, el punto de
entrada es `renderTemplateToEscPos` en
`frontend/lib/hardware/printers/render-template.ts` (la parte nueva y
async es el manejo del logo: `img.decode`/`crossOrigin`/`encoder.image`).

## Trampas conocidas
- Deploy de Punto Front sigue con webhook de auto-deploy ACTIVO en Coolify
  (se disparó solo con el push de esta sesión) — contradice la regla nueva
  de deploy manual documentada en `1308a564`/CLAUDE.md. Backend no tiene
  el webhook. Pendiente que el owner decida si lo apaga.
- `fe_cdc` en blanco en el primer ticket no es bug — la emisión electrónica
  es asíncrona, el CDC solo llega en la reimpresión.
- P2s de la auditoría de seguridad del 26 siguen abiertos (`modules.php`
  sin `hasPermission` es el más directo — ver `context/10-roadmap.md`).
  `TZ America/Asuncion` literal en migs 157/160/period-close sigue sin
  migrar. "Bloquear sesión luego de" en Ajustes sigue mock.
- El arnés de facturación electrónica (guard del caso vale) quedó
  OFRECIDO al owner y sin hacer — no asumir que existe.
- Sesión paralela "compras" (system-fb) tenía trabajo en vuelo (cola OCR,
  mig 176) — ya mergeado por ellos antes de este cierre; el dropzone que
  esta sesión movió (`cac7633f`) era de esa sesión, no nuevo acá.
- Fixes de PIN/rol del alta (`769b2d66`+mig 177), título de ventana del
  POS (`6e0083a8`), franquicias `context/55` (`3a0264fb`) y ungroup
  (`ee291a9b`) son de ESTA misma sesión pero de ANTES del rango de arriba
  — ya están documentados en el entry del 2026-08-28, no se repiten acá.
