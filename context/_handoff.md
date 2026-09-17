# Hand-off — 2026-09-17

## Objetivo

Serie SIFEN en la UI del panel. Buscador del panel sin falsos positivos.
Documentación de ayuda para clientes + sitio `docs.punto.la`. Módulo RRHH
nuevo desde cero (legajo, marcación de asistencia, reconocimiento facial).

## Estado al cerrar

Todo mergeado, pusheado y deployado: Backend y Front `running:healthy`
(verificado con `list_applications`). Migs 229 (`rrhh_empleados`), 230
(quiosco/asistencia) y 231 (reconocimiento facial) verificadas aplicadas en
prod vía `psql` directo.

**PENDIENTE sin resolver al cierre**: `docs.punto.la` devuelve 503 "no
available server" (Traefik) aunque el owner agregó el dominio a Punto Front
en Coolify y el DNS de Cloudflare ya resuelve. Diagnosticado pero no
arreglado — ver Callejones sin salida.

## Archivos y cambios

- `frontend/lib/navigation/search.ts` (`07e40842`) — ranking propio del
  buscador cmdk: palabra-prefijo + sinónimos + plural, reemplaza el match
  por subsecuencia; items llevan `keywords`; tests de regresión contra el
  registro real de rutas.
- Serie SIFEN (`bd73a33a`,`1c791c69`,`1b49a58a`) — `dSerieNum` configurable
  por punto de expedición; UI: campo solo visible con FE activa, select
  AA..ZZ (nunca texto libre), sin leyendas ni diálogos explicativos (regla
  de copy nueva en `context/14-ui-conventions.md` §Regla 8);
  `error_message` se limpia al emitir y el detalle refetchea.
- `frontend/content/ayuda/` — 28 artículos con frontmatter para RAG
  (movidos desde `docs/ayuda/` una vez que el sitio nació ahí).
- `frontend/middleware.ts` (`DOCS_HOSTS`), route group `(docs)` — sitio
  `docs.punto.la` en la misma app Front, buscador reusa `paletteScore`,
  links `panel:` resueltos contra el registro de rutas con test de
  integridad en `frontend/lib/docs/__tests__/ayuda-integrity.test.ts`.
  `/ayuda` en hosts del panel redirige 308.
- `context/83-rrhh.md` (nuevo, D1-D10 cerradas por el owner) — plan RRHH.
- `employee` (mig 229, merge `8cf0680c`) — legajo en el panel, módulo
  togglable en F0, corregido a CORE en `3dcc502d` (regla general: módulos
  base de todo rubro nunca son activables).
- Quiosco de marcación (mig 230, merge `44ae7bf1`) — PIN+foto offline,
  reporte de asistencia, muerte del verificador QR legacy
  (`api/v1/attendance.php`) — resuelve el pendiente de `context/10-roadmap.md`.
- Reconocimiento facial on-device (mig 231, merge `e8d99ad1`) —
  `@vladmandic/face-api`, modelos lazy en `public/models/face`, enrolamiento
  autorizado desde el panel.
- `frontend/__tests__/offline-boot.test.ts` (`0c38f408`) — actualizado a
  DB_VERSION v7 (store `opBlobs` que subió la F1 de RRHH).
- `context/10-roadmap.md` — ítem de `api/v1/attendance.php` marcado
  RESUELTO (tachado + fecha).

## Callejones sin salida

- `mcp__coolify__control` con `restart` sobre Punto Front: no regeneró las
  labels de Traefik para `docs.punto.la` — el 503 persiste después.
- Descartado que sea DNS o certificado: Cloudflare resuelve el dominio y la
  API de Coolify confirma que `docs.punto.la` está en el fqdn de la app; el
  error es específicamente "no available server" de Traefik, o sea que el
  router no existe o no está actualizado — apunta a que hace falta un
  REDEPLOY completo (no un restart) para que Coolify regenere los labels, o
  que la entrada extra `www.docs.punto.la` (sub-sub-dominio) esté
  confundiendo el matching de Traefik.

## Próximo paso

Resolver el 503 de `docs.punto.la`: probar `mcp__coolify__deploy` (redeploy
completo, no restart) sobre Punto Front (`nzmay2ytcdup3sgylspq39z6`) y si
persiste, revisar si la entrada `www.docs.punto.la` en la config de dominios
de Coolify está rompiendo el router de Traefik (probar sacándola). Después
de eso: RRHH F3 (ausencias/vacaciones) y F4 (adelantos + liquidación +
tarifario de comisiones D10, con congelado por línea en `SaleService`, mismo
patrón que el costo congelado del migrador ENCOM).

## Trampas conocidas

1. Dos migraciones con número 229 (`229_reposicion_origen_lote` de una
   sesión paralela + `229_rrhh_empleados` de esta): el runner trackea por
   FILENAME, así que ambas corren sin conflicto — precedente aceptado, NO
   renumerar ninguna de las dos si aparecen juntas en un merge futuro.
2. Tests rojos PREEXISTENTES en `main`, no de esta sesión:
   `no-hardcoded-paraguay` (31 literales en código de einvoice/encom).
3. La foto de enrolamiento facial (RRHH F2) se guarda y tiene endpoint, pero
   ninguna pantalla del panel la muestra todavía.
4. El quiosco de marcación NO pide operador — el device pareado alcanza
   para marcar asistencia. Es una decisión del owner, no un bug de auth.
5. `docs.punto.la` en Coolify: agregado a mano por el owner en la UI (no en
   git) — cualquier cambio de config de dominios para esa app está fuera
   del repo y no queda registrado salvo acá.
