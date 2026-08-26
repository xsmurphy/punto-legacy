# Hand-off — 2026-08-26 (leak cross-tenant + PWA móvil)

## Objetivo
Continuación de la sesión del 25 sobre el eje `/admin` + POS móvil. Dos
disparadores del owner: (1) vio en el panel del tenant Eclock el gráfico de
ingresos con ventas de OTRA empresa (ICAS) — leak cross-tenant, P0; (2) el
"gap de abajo" en la PWA iOS seguía sin cerrar tras 3 intentos previos.

## Estado al cerrar
Todo commiteado, pusheado a `main` y deployado, EXCEPTO la branch
`frontend/print-labels` (trabajo parcial, NO mergeada, no compila — ver
Trampas). La auditoría completa de auth (mandato del owner: panel y /pos
sin dominios de cookies compartidos, migrar panel a Bearer, cero leaks en
cualquier endpoint) quedó delegada a la sesión paralela "Punto Security",
NO se hizo en esta sesión.

## Archivos y cambios
- `api/includes/auth_session.php` (`authSetOpaqueCookie`) y
  `frontend/app/api/admin/[...path]/route.ts` — un solo emisor de
  `_jwt_panel`: el BFF de admin ahora propaga el `Set-Cookie` del upstream
  (con `COOKIE_DOMAIN=.punto.la`) en vez de setear su propia variante
  host-only. Fix del leak (`ad46b4c1`).
- `frontend/app/api/admin/[...path]/route.ts` — regresión inmediata: la
  rama de impersonación mezclaba `res.cookies.set()` (borrado + marca
  `_imp_panel`) con `res.headers.append("set-cookie", …)`. Ahora TODO pasa
  por headers crudos, una sola API (`a8c96774`).
- `frontend/lib/admin/__tests__/impersonation-contract.test.ts` — guard
  ampliado para el caso de las dos cookies/dos emisores.
- `frontend/components/pos/safe-area-calibrator.tsx` (nuevo) — mide
  `innerHeight` contra `screen` y pone `--safe-b` en 0 cuando el viewport
  no llega al borde (cover no está aplicando ahí). Toca SOLO el eje
  inferior — la primera versión tocó también `--safe-t` y rompió el status
  bar (revertido en el propio eje, ver commits `258afca1`/`68e84e7a`).
- POS shell — `aa3d5ae7` revirtió un intento con `h-full` (colapsaba el
  layout, ver Callejones). Quedó en `h-dvh`.
- `frontend/lib/api/*` (api-client y pos-client) — `ee131584`: un sobre
  `{ok:true}` sin `data` dejó de tratarse como contenido válido; ahora es
  error visible en vez de blanco silencioso.
- `frontend/components/pos/viewport-probe.tsx` (nuevo, `?debug=viewport`)
  — inútil en PWA standalone (no hay barra de direcciones para pegar la
  URL). Queda sin vía de activación.
- `context/06-infraestructura.md` — cron `/etc/cron.weekly/docker-builder-
  prune` en el server (poda BuildKit, NO viaja en el repo).

## Callejones sin salida
- **Leak cross-tenant**: el SQL nunca estuvo mal (`Roc::build` filtra bien
  por companyId). La causa era el browser mandando DOS cookies
  `_jwt_panel` con scope distinto y cada capa (PHP vs Next `req.cookies`)
  parseando una distinta.
- **Regresión de la impersonación**: `res.cookies.set()` de Next mantiene
  su propio mapa interno y CADA llamada re-serializa el header
  `Set-Cookie` completo desde ese mapa — pisa cualquier append() previo a
  headers crudos. No mezclar las dos APIs en la misma `NextResponse`.
- **PWA — 4 hipótesis descartadas antes de la real**: faltaban safe areas
  (no), doble descuento shell+CartBottom (no), caché del meta
  `viewport-fit=cover` (no, el owner reinstaló y persistía), `h-full` en
  el shell (empeoró todo — el wrapper de SidebarProvider usa `min-h-svh`,
  que es un mínimo, no una altura; `height:100%` no resuelve contra eso).
- **`?debug=viewport`** no sirve para diagnosticar en producción porque
  una PWA instalada no tiene barra de direcciones donde pegar el query
  param.
- **Reproducir el detalle de transacción con cookie de panel** da 401
  SIEMPRE — ese endpoint exige Bearer de device, no cookie de panel.
- **Árbol compartido**: otra sesión hizo checkout y borró ediciones sin
  commitear (repetido de la sesión anterior) — hubo que rehacerlas.

## Próximo paso
Dar una vía de activación a `viewport-probe.tsx` que no dependa de la URL
(ej. botón oculto en Ajustes POS) para poder diagnosticar el próximo
problema de viewport en la PWA instalada sin depender de reinstalar y
adivinar.

## Trampas conocidas
- Branch `frontend/print-labels` sin mergear, no compila (falta un
  import) — traspasada a la sesión "Punto bugs" junto con el pedido
  original del owner (títulos de campo en tickets + `formatQty`).
- Auditoría de auth completa (Bearer, cero leaks) es mandato del owner
  de hace meses, delegada a sesión paralela "Punto Security" — NO
  asumir que ya se hizo por este fix puntual.
- Símbolo de moneda imprime `?` en la térmica —
  `UNKNOWN_CURRENCY_SIGN = "¤"` (`frontend/lib/tenant-locale.ts:135`) no
  existe en codepage CP437. Sin arreglar.
- TZ "Asunción" literal en migs 157/160 + `period-close.php` — crítico
  antes del primer tenant no-PY. Heredado, sin tocar.
- 8 sesiones de device duplicadas en prod esperando decisión de revocar
  (heredado).
- `SaleToInvoiceMapper.php:195` — venta con vale no factura (heredado).
- "Bloquear sesión luego de" en Ajustes POS sigue mock con TODO backend.
- El owner debe confirmar en su iPhone que el gap quedó cerrado (dijo
  "ahora quedó bien" tras `68e84e7a`, pero no hubo confirmación final
  post-`a8c96774`) y probar impersonar de nuevo tras el fix de la
  regresión.
