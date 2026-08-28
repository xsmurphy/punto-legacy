# Hand-off — 2026-08-28

## Objetivo
Cerrar el cutover de auth del panel a Bearer (F3+F4 de `context/54`,
decisión del owner 2026-08-26), sumar filtros al `<DataTable>` genérico,
mejorar el flujo de compras (línea compacta + alta rápida de artículo),
sacar el OCR de facturas de compra del hot path (cola asíncrona), y
empezar a gatear módulos específicos de Paraguay por el país del tenant
(paso previo a vender fuera de PY).

## Estado al cerrar
`main` en `291b52a1`, todo commiteado y pusheado. Deploy disparado por el
push pero **NO verificado** (front/backend corriendo en `963a6348`
confirmado sano; `291b52a1` es posterior y quedó sin chequear). Migración
176 (cola OCR) aplicada en prod. Branches de la sesión borradas (local +
remoto).

- Auth: cutover Bearer 100% completo. Panel y POS son ambos Bearer-only,
  cero cookies de sesión en ningún realm salvo `_jwt_admin`.
- Doc fix de cierre: `context/08` tenía dos secciones `§61` (colisión
  entre esta sesión y una paralela) — el de módulos por país se renumeró
  a **§62**, y las 7 referencias que habían quedado apuntando a §61 en
  código y docs (`modules-catalog.ts`, `module-catalog-panel.tsx`,
  `allowlist.ts`, `ModulesService.php`, roadmap, bitácora) ya están
  corregidas. No queda ninguna.
- Filtros DataTable: implementado y en uso en `/items`.
- Compras: línea compacta + alta rápida en uso.
- Cola OCR: implementada con consumidor real (drain), sin verificar por
  un humano (dropzone, estados, supervivencia al cierre de pestaña).
- Módulos por país: implementado front+back, sin verificar por un humano.

## Archivos y cambios
- `api/lib/services/PanelAuth.php` (`issuePanelSession`) — ya NO emite
  `_jwt_panel`, lo borra.
- `api/bootstrap.php` (`_authAmbientTokens`) — acepta solo `_jwt_admin`.
- `frontend/lib/bff/proxy.ts` — `forwardCookie` eliminado.
- `context/08-convenciones-criticas.md` §60 — reescrito (Bearer en ambos
  realms, ya no "cookie=panel, Bearer=device"); §62 nuevo (módulos
  gateados por país; renumerado de §61 a §62 al detectar colisión con el
  §61 de otra sesión paralela — "Una cookie de sesión, UN solo emisor").
- `api/tests/pos_token_only_precedence_test.php` — 16/16, casos (b)/(f)
  invirtieron aserción, (f2)/(f3) nuevos.
- `frontend/components/ui/data-table.tsx` — `filtersSlot`/
  `activeFilterCount`/`onClearFilters` + `FilterField`.
- `context/14-ui-conventions.md` — Regla #2.2 (prohibía Sheet lateral)
  ELIMINADA por decisión del owner.
- `frontend/components/items/quick-create-item-dialog.tsx` — nuevo, alta
  rápida de artículo desde el buscador de compras.
- `frontend/lib/types/item.ts` — `emptyItemValues()` (movida desde
  `app/(panel)/items/[id]/page.tsx`).
- Migración `176` — cola OCR (`queued`/`processing`/`failed`/`attempts`/
  `processing_at`).
- `api/lib/services/PurchaseDraftService.php` —
  `claim()/completeExtraction()/requeueStale()/retry()`.
- `frontend/app/api/ocr-invoice/route.ts` — crea el draft y responde,
  IA corre en `after()`.
- `frontend/app/api/ocr-invoice/drain/route.ts` — NUEVO, consumidor real
  de la cola (disparado por el front, no por crond — la extracción vive
  en Next, no en PHP).
- `frontend/lib/ai/extract-invoice.ts` — prompt OCR movido acá (2
  consumidores).
- `frontend/lib/modules/*` (`ModuleCatalogEntry.countries`,
  `modulesForCountry`, `catalogByKind`) — `einvoicePy`/`bancard`/`upay`
  marcados PY-only.
- `frontend/lib/__tests__/country-gated-modules.test.ts` — guard nuevo.
- `api/lib/services/ModulesService.php` (`COUNTRY_ONLY`) — un POST
  directo ya no activa módulo de otro país.
- `api/v1/modules.php` — ahora exige `settings.company.edit` (cierra un
  P2 de la auditoría de seguridad del 2026-08-26).
- `taxPy` — ELIMINADO (front+back), switch manual que ningún cálculo leía.
- `context/10-roadmap.md` — consignación, alquiler, análisis de
  subproductos de producción.

## Callejones sin salida
- El chart de ingresos volvió a romper en prod (`BFF 401`) tras F3/F4:
  `useIncomeChart` usaba `fetch` crudo con `credentials:"include"`,
  funcionaba de casualidad mientras hubo cookie. Regla: al sacar envío
  ambiental de credenciales, todo lo que se autenticaba "solo" queda
  expuesto — hay que grepear `credentials: "include"`, NO por path de
  endpoint (eso ya había fallado una vez con `income-chart` el 2026-08-27
  y volvió a aparecer).
- Di código muerto a `importTemplateUrl()` por un grep case-sensitive
  (`templateUrl` no matchea `importTemplateUrl`) — rompió la descarga de
  plantilla CSV hasta el 3er barrido.
- Cola OCR nació sin consumidor: `requeueStale()` volvía filas a `queued`
  pero nada las procesaba (`after()` corre una sola vez por upload). Un
  drain por crond PHP no sirve — la extracción IA vive en el contenedor
  Next, no en PHP, y necesita credencial del tenant. Se resolvió con
  endpoint drain en Next disparado por el front.
- Tab default `pending` en la UI de OCR dejaba la feature invisible: lo
  recién subido es `queued`, el usuario aterrizaba en lista vacía y el
  polling (atado al filtro) nunca se encendía.
- El arnés de permisos daba falso verde tras F4: autenticaba el realm
  panel leyendo `$_COOKIE['_jwt_panel']` (ya no aceptado), y los casos
  "con la clave → pasa" trataban cualquier respuesta ≠403 como éxito, así
  que un 401 pasaba como verde sin ejercitar el gate real. Migrado a
  Bearer.
- Otra sesión (`system-83`) hizo `git reset --mixed` sobre el árbol
  compartido mientras esta sesión trabajaba; su commit de users/PIN quedó
  un rato encima del mío. Nada se perdió, pero reafirma la regla de
  worktrees para sesiones paralelas. Colisión de numeración de migs
  resuelta a mano: esta sesión usó 176, la otra 177.

## Próximo paso
Verificar que el deploy de Coolify disparado por `291b52a1` (módulos por
país + `taxPy` + permiso de `modules.php`) llegó sano a prod — Coolify
rollbackea silencioso si el build falla y no se ve desde afuera.

## Trampas conocidas
- **Deuda grande de §61**: timbrado/punto de expedición/numeración fiscal
  viven en el core de emisión, no son un flag por módulo. Sacarlos para
  un tenant no-PY es rediseño (`context/29`), no trabajo de esta sesión.
  Es LA deuda para vender fuera de Paraguay.
- TZ `America/Asuncion` literal en migs 157/160 y `period-close.php`
  sigue sin migrar — rompe silenciosamente con el primer tenant no-PY
  (heredado, no tocado hoy).
- Sin verificar por un humano: alta rápida de artículo, cola OCR completa
  (dropzone/estados/supervivencia al cierre de pestaña), filtros de
  `/items`, filtrado de módulos por país.
- 7 P2 de la auditoría de seguridad del 2026-08-26 siguen sin arreglar
  (ver `context/10-roadmap.md`); el de `api/v1/modules.php` se cerró hoy,
  quedan 6.
- WebSocket de realtime sigue sin autenticación (preexistente, no filtra
  datos entre tenants).
- Dos worktrees ajenos sin limpiar en
  `/private/tmp/claude-501/-Users-xstian-Dropbox-Punto-system/7b5abdf0-.../scratchpad/`:
  `off-wt` y `wt-kude`. NO son de esta sesión — confirmar que esa otra
  sesión cerró antes de borrarlos.
- Atajo "Reprocesar" del roadmap de producción (`5ab381fb`) queda casi
  gratis con el motor actual, esperando OK del owner para implementar.
- Costo de inventario va CON IVA incluido — decisión del owner, no bug.
