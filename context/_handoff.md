# Hand-off — 2026-08-25

## Objetivo
Sesión de dos días, multi-eje: arrancó arreglando el roster de PINs del lock
screen del POS y escaló al mandato del owner "el POS es token-only, sin
ambigüedad de realms" (tercer incidente de la misma clase). En paralelo:
auditoría de GetAssoc (bug de colapso de filas), cadena depósito/caja,
arqueo por medio de pago, ítems multi-sucursal, centros de costo, quitar
TODOS los hardcodeos de Paraguay, y 13 fixes de POS móvil (safe areas).

## Estado al cerrar
Todo commiteado y pusheado a `main` (`6896d69b..HEAD`, 108 commits). OJO:
ese rango está entreverado con una sesión PARALELA del owner (impersonate
de `/admin`, migs 172/173, tenant master) — no es trabajo de esta sesión,
no la reabras pensando que quedó a medias.

Migraciones 165-171 todas aplicadas o listas para el deploy automático;
165/166 ya corridas en prod (depósito y caja default, con backfill: 7
depósitos creados + 2 marcados).

## Archivos y cambios
- `context/08-convenciones-criticas.md` §60 — regla madre "cookie=panel,
  Bearer=device"; `api/.../authResolve` prioriza Bearer sobre cookie;
  `bffProxy` no reenvía cookies salvo el catch-all del panel
  (`forwardCookie` default false).
- `api/database/migrations/postgres/165_*.sql` a `171_*.sql` — depósito
  default, caja default, centros de costo (`fin_cost_center`), banner
  comanda, arqueo por medio de pago, `item_outlet` (N-a-N multi-sucursal),
  pairing single-use (CAS + secreto de sesión).
- `frontend/lib/tenant-locale.ts`, `api/lib/Support/TenantLocale/
  CountryDefaults` — resolvers de país/moneda/TZ que reemplazan ~135
  hardcodeos de Paraguay en 86 archivos; guard test con allowlist.
- `context/48-escalamiento-de-datos.md` — anotado el pendiente crítico de
  TZ (ver Trampas).
- `context/53-orden-y-stock-reserva.md` — nuevo, plan de stock
  "comprometido" en órdenes (D1-D4 cerradas, sin implementar).
- POS móvil: safe-areas (squash `6c6e9e83` + cierres `5113cd7c`), teclado
  nativo en teléfono para descuento/precio/cantidad, drawers al borde.
- `context/modules/11-*.md`, `context/14-ui-conventions.md`,
  `context/23-*.md` — actualizados por los agentes de cada slice.

## Callejones sin salida
- El link de pareo por WhatsApp emitía token nuevo en CADA polling de
  `/status` indefinidamente — era un emisor permanente de credenciales
  (el owner lo reprodujo pegando el link en dos navegadores). Se resolvió
  con estado `consumed` vía CAS + secreto de sesión propio (UA/IP no
  sirven: dos tablets del mismo local los comparten) — mig 171.
- `/api/pos/bootstrap` sin Bearer resolvía como panel por la cookie y
  CACHEABA un bootstrap sin roster — bloqueaba un iPhone recién pareado.
  El parche puntual no alcanzaba: escaló al mandato token-only completo.
- Colisión de migración 167 (centros de costo vs arqueo) — arqueo se
  renumeró a 169 con `git mv` + barrido interno. Verificar SIEMPRE
  `git branch -r` además de `ls migrations/`, no solo main local.
- El squash-merge de `pos-safe-areas` fue deliberado: los commits
  intermedios de esa branch no compilaban solos.
- 5 agentes quedaron en bucle esperando sub-agente/build (turnos
  perdidos); 1 worktree se borró antes del commit final (se rehizo el
  trabajo, solo el arnés se perdió de verdad).
- Un reviewer fabricó un resultado ("el barrido delegado volvió limpio")
  antes de autocorregirse — el veredicto final que quedó fue real, pero
  no confiar ciegamente en el primer reporte de un reviewer.
- La Mac del owner llegó a 92% de disco (7 worktrees × ~2.5GB + builds
  paralelos) — regla nueva: máx 2-3 worktrees en paralelo, borrar al
  mergear, un solo build por agente al final.

## Próximo paso
El owner debe probar la checklist móvil del POS en su iPhone (reinstalar
la PWA primero — el storage de iOS PWA es separado de Safari, necesita
re-pareo pegando el link a mano). En paralelo, decidir si revocar las 8
sesiones de device duplicadas que quedaron en prod tras el fix de pairing
single-use (revocarlas desconecta cajas activas — no es automático).

## Trampas conocidas
- Migs 157/160 y `period-close.php` truncan con timezone "Asunción"
  literal — CRÍTICO migrar a TZ por tenant antes de dar de alta el primer
  tenant no-paraguayo (contexto en `context/48`).
- `data.php:98` usa `sha1($company['accountId'])` y la columna `accountid`
  NO existe — deprecación silenciosa en HTTP, fatal en CLI. Bug ajeno,
  no tocado esta sesión, solo anotado.
- Prod corre `php -S` embebido, NO Apache/CGI — cualquier fix que asuma
  `REDIRECT_HTTP_AUTHORIZATION` no aplica acá.
- El histórico del ledger de stock con `locationid` NULL NO se migra
  (decisión del owner, es la cuenta de prueba) — la lectura consolida NULL
  al depósito default; si un conteo de inventario por depósito da números
  raros, revisar `Inventory::ledgerLocationJoin` antes de sospechar bug.
- Mínimo táctil de 44px hoy solo aplica en mobile (corta en 768px) —
  decisión de si extenderlo a tablet queda pendiente del owner.
- Quedan 2 residuales menores de plantillas de impresión: separador
  colgante en comanda sin destino, y filas de QR de reserva dibujadas de
  más.
