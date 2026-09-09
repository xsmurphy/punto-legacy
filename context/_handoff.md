# Hand-off — 2026-09-09

## Objetivo

Dejar la facturación electrónica operativa en producción real con **FE-PY**
(motor propio, https://fepy.punto.la) como único proveedor. Hoy fue el día
del cutover: Balloon Party emitió sus **primeras facturas electrónicas
REALES** (615-619), aprobadas por SIFEN con protocolo de autorización. Cada
intento destapó un bug distinto — la sesión fue una cadena de "se emitió
pero X salió mal" (CDC, QR, ambiente, gate de entrega, numeración).

**Punto está en producción con clientes reales** (no relitigar permisos por
datos de prueba). Las facturas 615-619 son documentos fiscales legales, pero
**ningún cliente las recibió** — fueron pruebas del owner.

## Contexto que manda sobre todo lo demás (no relitigar)

1. **Factomate NO EXISTE.** Decisión del owner, dicha con énfasis. El código
   de Factomate que queda en el repo es deuda a remover, no un plan B — no
   lo menciones como fallback.
2. **La serie fiscal es (timbrado, punto de expedición, número).** El
   correlativo no existe solo. Cambiar timbrado o punto abre una serie
   nueva, nunca resetea nada.
3. **Un comprobante con número se anula, nunca se borra.** Regla por
   NÚMERO, no por tipo de documento.
4. **Punto es dueño de la numeración fiscal.** FE-PY es un intermediario:
   el documento va a SIFEN con el número que congeló la caja, nunca
   `number:-1` ni correlativo de proveedor.
5. **D1 del ticket-no-es-KuDE, CERRADA por el owner, SIN IMPLEMENTAR**: el
   ticket de caja deja de pretender ser un KuDE — pasa a ser un comprobante
   interno con leyenda de que no es válido fiscalmente + link al portal
   (Decreto 872/2023 permite entrega a pedido). Motivo normativo: el QR del
   KuDE (§13.8.2 del Manual Técnico DNIT v150) lleva el `DigestValue` de la
   firma XML — **no se puede calcular antes de emitir**, ni con el CSC en
   el dispositivo. Verificado contra el manual oficial; una lectura
   apresurada de la v141 (que no lleva QR en cinta de papel) hizo concluir
   lo contrario a mitad de sesión — la v150 sí lo lleva (pág. 203), y es la
   vigente.

## Estado al cerrar

**Mergeado y deployado** (`main`, commits `67d08f5a..92e6af91`, 26 commits):
- Series fiscales (migs 209+210): identidad de numeración = (timbrado,
  punto, número) en `document_sequence`, contador local del POS,
  `transaction` congelada y lo declarado a FE-PY.
- Realtime del POS: excepción de scope para `drawer`, queryKeys que no
  matcheaban, `apiAuthPosContext()` publicando en `register_shutdown_function`.
- Emisión manual de factura (`issueForSaleOnDemand`) + botón en detalle.
- `DELETE /v1/transactions` ya no borra — pasa por la cadena de anulación.
- KuDE descargable desde el POS (realm `pos-app`, solo `GET resource=kude`),
  con `kudeDeliveryBlocker` compartido por email/portal/POS (numeración,
  reemplazo, anulación, veredicto SIFEN); panel sin gate (inspección, no
  entrega).
- Outbox: corte real de reintentos (WHERE del drainer, no `next_retry_at`
  NULL — esa columna es NOT NULL) + `retry()` resetea `attempts`.
- CDC en su columna (mig 211: `provider_number` a 44 chars, el bulkId de
  FE-PY ES el CDC).
- QR recuperado (`extractQrUrl()` leía el shape anidado del proveedor
  viejo; FE-PY lo da plano en `qrUrl`; `reconcile()` lo recupera para
  documentos ya emitidos).
- `einvoice_account.environment` se lee del proveedor en cada verificación
  (quedaba `test` con el tenant en `prod` emitiendo real).
- Portal público: logo del comercio arriba, Punto al pie ("Usamos
  www.punto.la"), QR firmado DIBUJADO (no solo enlazado), moneda vía
  `resolveCurrency()`.
- Catálogo geográfico (fuente SIFEN) + bot `resolve_geo_codes`, de la tanda
  anterior, ya en prod.

**Nada en vuelo.** El agente de la anulación desde el panel terminó y su
trabajo YA ESTÁ MERGEADO en `main` — revisado antes de mergear: el hook y
el diálogo se comparten con la caja inyectando el cliente por realm, y el
módulo compartido importa solo el cliente del panel (nada del token del
device entra a su bundle). Arneses en verde: sale_void, api_realm 29/29,
permission_enforcement 359/359.

**Deploy verificado al cierre**: Backend en `cc9d0686` (finished). Front
tenía un deploy `in_progress` para `92e6af91` (el HEAD, cambio de portal) —
**confirmar que terminó `finished` antes de asumir que está en prod**.

## Archivos y cambios

- Series fiscales: migs 209/210, `document_sequence`, contador local del
  device, mapper de FE-PY — ver commits `67d08f5a`,`dc7b7e77`,`f9b06329`,
  `e324d804`.
- `SaleToFePyMapper` — `resolveDocumentNumber()` y `cdcMismatchFor()` NO
  cubren nota de crédito (tipo 5): ver cola punto 2.
- KuDE: predicado `kudeDeliveryBlocker` compartido, `resource=kude` en
  realm `pos-app` — commits `3d76aafb`,`2eac8b20`.
- Outbox: drainer + `retry()` — commit `8396c1ac`. Mig 211 (`provider_number`
  44 chars).
- Portal público del comprador — commit `92e6af91`.
- `.claude/worktrees/anular-desde-panel` — MERGEADO a `main`, el worktree se
  puede borrar.

## Callejones sin salida

- Leer el QR antes de emitir, o calcularlo del lado del dispositivo — el
  `DigestValue` es de la firma que hace SIFEN, no existe hasta la
  aprobación.
- Confiar en la v141 del Manual Técnico DNIT para el formato de cinta de
  papel del KuDE — no lleva QR ahí; la v150 (vigente) sí. Usar siempre la
  v150 como fuente.
- FE-PY no expone `CurrentNumber` (null a propósito) — el próximo número
  fiscal no se puede derivar del emisor, tiene que ser 100% propio de
  Punto (heredado de la sesión anterior, sigue vigente).

## La cola del owner, en orden acordado

1. ~~Anular desde el panel~~ — HECHO y mergeado. Falta deployar el Front.
2. **Serie propia para la nota de crédito** — hoy la numera FE-PY:
   `SaleToFePyMapper::resolveDocumentNumber()` omite el número para tipo 5 y
   `cdcMismatchFor()` ni compara para NC. Contradice que Punto sea dueño de
   la numeración. Prerequisito del punto 3.
3. **NC desde la caja (UI)**, encima del 2.
4. **Logo del tenant en el KuDE** — FE-PY ya expone `logoUrl` en el tenant
   (POST/PATCH); falta que Punto lo mande.
5. **Email del KuDE con la marca de Punto** — ver roadmap "El email al
   comprador como canal propio" (commit `4b9a5698`).

## Próximo paso

Deployar el Front (la anulación desde el panel está en `main` sin deployar) y
seguir por el punto 2 de la cola: serie propia para la nota de crédito.
(serie propia para NC) — es prerequisito del punto 3 y toca numeración
fiscal, así que pasa por `code-reviewer` antes de mergear.

## Trampas conocidas

- **Cambios A MANO en producción, no están en git**:
  - `document_sequence.nextnumber = 615` para la caja
    `01a067cb-9017-759a-b372-873af6cd9278` (corrección del incidente de
    numeración de la sesión anterior).
  - Tenencia de caja liberada por servicio (`RegisterLeaseService::close`)
    para destrabar al owner.
  - PATCH a FE-PY del emisor de Balloon Party con teléfono `0994285744`,
    `numeroCasa "0"` y email — el XSD de SIFEN exige `dTelEmi` de 6 chars
    mínimo. Espejado en nuestro `einvoice_account`/`fiscal`.
  - Reconciliación a mano de la factura 615 (quedó `error` con el
    documento ya aprobado por SIFEN, antes del fix del outbox).
  - Heredado y vigente: `platform_config` key `integration.fepy`
    (`baseUrl` SIN `/v1` — con `/v1` da 404), tenant CLI purgado en FE-PY
    (alta 100% UI, VETADO por CLI), códigos geográficos de Asunción
    (depto 1 / distrito 1 / ciudad 1).
- **Worktrees consumidos, se pueden borrar** (contenido ya integrado en
  `main`): `.claude/worktrees/serie-fiscal` (mergeado en `dc7b7e77`),
  `.claude/worktrees/kude-en-pos` (mergeado en `2eac8b20`),
  `.claude/worktrees/realtime-pos-gaps` (mergeado antes del cierre previo).
  `frontend-numeracion-fe-atada` (branch `frontend/numeracion-fe-atada`,
  worktree `.claude/worktrees/frontend-numeracion-fe-atada`) **NO mergear
  tal cual** — su decisión central (arrastrar el contador al punto nuevo)
  es la causa del incidente que se corrigió; rescatable de ahí:
  `NumberingAdvisor` y el hallazgo de `CurrentNumber` null en FE-PY.
  `geo-sifen-y-bot-fe` — estado sin verificar esta sesión, confirmar si ya
  quedó absorbido por el catálogo geográfico mergeado.
- Vitest: 5 fallas preexistentes (`contact-id-types`, `no-hardcoded-paraguay`)
  que fallan igual en `main` limpio — no son regresiones.
- Arnés `einvoice_emitter_numbering_test`: falla 1 de 33 en el caso E3,
  sobre el kill-switch `legacyAutoNumbering` — preexistente (verificado
  contra el commit anterior), palanca de la época de Factomate;
  recomendación: BORRARLA, no arreglar el test.
- Deploy del Front puede ir unos commits atrás de `main` si lo último fue
  API-only — verificar con `git log --stat` antes de asumir que sirve.
- Más antiguas: `psql`/SSH a BD bloqueados por el classifier, `npx vitest`
  correr desde `frontend/`.
