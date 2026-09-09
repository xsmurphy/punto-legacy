# Hand-off — 2026-09-09

## Objetivo

Terminar de dejar la facturación electrónica operativa con **FE-PY** (motor
propio, https://fepy.punto.la) como único proveedor — Factomate está FUERA
por decisión explícita del owner ("hacé como que Factomate no existe y no lo
vamos a usar"), no un plan B. En el camino apareció un bug de numeración
fiscal en producción (caja mandó a SIFEN un número duplicado) que pasó a ser
la prioridad: la serie fiscal completa (timbrado + punto de expedición +
número) tiene que vivir bien modelada, no solo el número.

**Punto está en producción con clientes reales.** Hubo comercios sin poder
facturar durante esta ventana — la urgencia de todo lo de abajo viene de ahí.

## Estado al cerrar

Nada llegó a SIFEN todavía como factura legal — las transacciones existentes
son pruebas que quedaron solo en la base de Punto. Eso es lo que permitió
simplificar la corrección manual del incidente de numeración (no hay
documentos fiscales reales en juego, solo el contador).

**Mergeado y deployado** (`main`, commits `04d8894f..0de3923d`):
- Alta de FE con Asunción + Régimen Contable preseleccionados, bot deriva
  tipo de contribuyente del RUC y toma el email de la constancia.
- Fix del crash del chat (React error #31 por el sobre de error mal tipado).
- Rechequeo de `last_error` al cargar cert/CSC.
- Emisión manual de factura (`issueForSaleOnDemand` + botón en el detalle
  de transacción) para ventas que cayeron en una de las 3 salidas silenciosas
  del encolado y quedaban sin factura para siempre.
- Teléfono del establecimiento ahora obligatorio (XSD de SIFEN lo exige).
- Auditoría completa de realtime del POS: excepción de scope para `drawer`,
  queryKeys que no matcheaban, y el embudo `apiAuthPosContext()` que no
  publicaba nada (ventas parkeadas/flush de cola offline mutaban en
  silencio).
- `DELETE /v1/transactions` corregido: un comprobante con número se anula,
  nunca se borra.

**En vuelo, NO mergeado**: agente en `.claude/worktrees/serie-fiscal`,
branch `frontend/serie-fiscal`, construyendo el fix estructural del
incidente de numeración (ver abajo).

**Deploy**: Front en `93bead22`, Backend en `7fa6457c` + deploy encolado
para `0de3923d` (guard del borrado, API-only) disparado al cierre de la
sesión anterior — verificar que terminó `finished` antes de asumir que está
en prod. Los commits `fb114c94`/`bd87109a` (sesión paralela, `context/74`)
son solo docs, no requieren deploy.

## El incidente de numeración (lo más importante de la sesión)

La caja de Balloon Party mandó a SIFEN el número **838** contra el punto de
expedición `001-002`, que iba por 614. Causa raíz: `uq_document_sequence ON
(companyid, doctype, scopetype, scopeid)` — ni el timbrado ni el punto de
expedición forman parte de la clave, así que una caja tiene UNA fila para
toda su vida. Al cambiar el punto de expedición, el prefijo se actualizó
pero el contador siguió la serie vieja.

Agravado por dos reglas de "nunca bajar" que son correctas DENTRO de una
serie y sin sentido ENTRE series: `DocumentNumber::advanceTo()` con
`GREATEST` y `primeInvoiceNumbering()` en el localStorage del device.

**Corregido A MANO en producción**: `document_sequence.nextnumber = 615`
para la caja `01a067cb-9017-759a-b372-873af6cd9278`.

El agente en `frontend/serie-fiscal` construye el fix estructural, cuatro
piezas: (1) clave de `document_sequence` con timbrado y punto de expedición,
con migración; (2) clave del contador local del device por serie; (3)
`transaction` congelando también el punto de expedición (hoy congela solo
el timbrado, por eso una factura vieja se reimprime con el punto nuevo);
(4) `GREATEST` acotado a la serie, y `RegisterAdminService::seedSequence()`
resolviendo a otra fila al cambiar el punto en vez de pisar la que hay.

## Decisiones cerradas por el owner (no relitigar)

- **Factomate está fuera.** FE-PY es el único proveedor. Código de Factomate
  que quede en el repo es deuda a remover, no un fallback vigente.
- **La serie fiscal es (timbrado, punto de expedición, número).** El
  correlativo no existe solo. La caja es el único lugar donde se editan esos
  datos; todo lo demás deriva. Cambiar timbrado o punto **abre una serie
  nueva**, no resetea nada.
- **Una factura se anula, nunca se borra** (regla por número, no por tipo de
  documento).
- El listado de transacciones del POS **no necesita WebSocket**: se pide al
  abrir el menú, `staleTime` de 30s.

## Archivos y cambios

- `frontend/lib/agent/*` (alta FE, `resolve_geo_codes`, `find_section`) —
  mergeado en la sesión previa, sigue vigente.
- `.../confirm-api.ts` + `app/(panel)/error.tsx` — fix del sobre de error
  tipado como string.
- `.../issueForSaleOnDemand` + acción `issueForSale` + botón en detalle de
  transacción — emisión manual.
- Auditoría realtime: ver commits `7fa6457c`/`93bead22` para el listado
  completo de queryKeys y canales tocados.
- `.claude/worktrees/serie-fiscal` (branch `frontend/serie-fiscal`) — las 4
  piezas del fix estructural de numeración, EN VUELO.
- `frontend/numeracion-fe-atada` (branch, sin mergear) — **NO mergear tal
  cual**: su decisión central (arrastrar el contador al punto de expedición
  nuevo) es lo que el owner descartó. Rescatable de ahí: `NumberingAdvisor`
  (clasifica de dónde sale el próximo número por fuente) y el hallazgo de
  que **FE-PY no expone `CurrentNumber`** (devuelve null a propósito) — el
  próximo número no se puede derivar del emisor.

## Callejones sin salida

- Confiar en el proveedor para saber el próximo número de factura — FE-PY
  no lo expone. La numeración tiene que ser 100% propia de Punto, sin
  consultarle nada al emisor.
- Arrastrar el contador de `document_sequence` al cambiar el punto de
  expedición (rama `frontend/numeracion-fe-atada`) — parece intuitivo pero
  es exactamente la causa del incidente: mezcla series distintas bajo un
  mismo contador.

## Próximo paso

Revisar y mergear `frontend/serie-fiscal` cuando el agente termine las 4
piezas — es la prioridad de la sesión siguiente. Antes de mergear, confirmar
con `code-reviewer` (toca schema/migración + lógica de numeración fiscal,
alto riesgo).

## Abierto, esperando decisión del owner

- **FE-PY no expone endpoint para actualizar el timbrado del tenant.**
  Renovar timbrado desde Punto es imposible hoy. ¿Se les pide el endpoint o
  el proceso es re-alta del emisor?
- **La nota de crédito la numera el proveedor**, no Punto —
  `SaleToFePyMapper::resolveDocumentNumber()` omite el campo para el tipo 5
  y `cdcMismatchFor()` excluye la comprobación del número para NC, así que
  la divergencia ni se detecta. Contradice que Punto sea dueño de la
  numeración.
- Permisos del operador cacheados en `sessionStorage` (`lock-store.ts:122`)
  — NO es agujero de seguridad (el servidor evalúa en vivo con
  `OperatorContext::resolve()`), es la UI mostrando un botón que el backend
  igual rechaza. Refrescarlo sin PIN necesita un endpoint que no existe.
- Dos hallazgos de la auditoría de realtime sin atacar: el canal
  `{companyId}:spaces:{outletId}` se publica y nadie lo escucha (los
  espacios sobreviven por poll de 20s), y `price_resolve` emite un broadcast
  a todo el tenant en cada resolución de carrito, sin mapeo. Ruido, no
  pérdida de datos.

## Trampas conocidas

- **Cambios A MANO en prod, no están en git**:
  - `document_sequence.nextnumber = 615` para la caja
    `01a067cb-9017-759a-b372-873af6cd9278` (corrección del incidente de
    numeración, ver arriba).
  - `platform_config` key `integration.fepy`: `{keyEnc: <API key de FE-PY
    cifrada>, baseUrl: "https://fepy.punto.la"}` (SIN `/v1` — con `/v1` da
    404, doble prefijo).
  - Balloon Party (`companyid 01a067cb-8fff-72cd-bb12-0b483dcb7dbf`)
    `einvoice_account` ya NO está en `provisioning`: estado `ok`,
    `provider_tenant_ref = 01a0835d-cc70-770a-a656-3a4ea0708b09`,
    certificado y CSC cargados. Numeración FE=614→615 corregido/NC=2.
  - FE-PY: alta de tenant por CLI VETADA — el tenant CLI ya fue purgado, el
    proceso real es 100% UI.
- `.claude/worktrees/` a limpiar cuando `serie-fiscal` mergee: `agent-*`,
  `geo-sifen-y-bot-fe` y `realtime-pos-gaps` quedaron consumidos y se pueden
  borrar (contenido ya integrado en `main` o descartado).
- Deploy del Front puede ir unos commits atrás de `main` si lo último fue
  API-only — verificar con `git log --stat` antes de asumir que sirve.
- Más antiguas: sin backfill del histórico de fecha, Cloudflare "Block AI
  bots" desactivada a mano, `psql`/SSH a BD bloqueados por el classifier,
  `npx vitest` correr desde `frontend/`.
