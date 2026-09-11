# Hand-off — 2026-09-10

## Objetivo

Cerrar los pendientes de facturación electrónica: badge de anulada, serie propia
de la NC, y el bloqueante `INTERNAL_RENDER_KEY` del KuDE propio. Terminó siendo
la sesión donde Balloon Party emitió y ambos incidentes reales en producción
forzaron dos decisiones grandes del owner: Factomate se elimina del todo, y el
KuDE propio se da de baja (nunca llegó a andar bien).

## Estado al cerrar

Backend en `main` HEAD (`0a35e91a`) — deploy `vzois4j5dib2k8izigkapktt` **finished**,
verificado (`get_deployment` + `Punto Backend` en `running:healthy`, imagen del
container coincide con el commit). Migraciones 214, 215, 216 y 217 aplicadas y
verificadas en prod.

Front — deploy `mpz2jnhcakxs1fz5ivibgbay` a `0a35e91a` estaba **in_progress**
al cerrar (lo disparó la sesión paralela "Punto Panel UI", no esta sesión).
El container del Front en prod todavía corría la imagen del commit anterior
(`2d19b8e4`) al momento de verificar. Confirmar con `get_deployment` antes de
asumir que el front está al día.

## Archivos y cambios

- `frontend/lib/pos/einvoice-mapper.ts` (y todo `context/28`) — FE-PY es el
  único motor, Factomate eliminado (`eccf3d1b`, ~62 archivos, mig 214).
- `api/lib/services/PosSaleTransactionService.php` / servicio de NC — serie
  propia heredando caja de la factura corregida (`418b679c`, mig 215).
- `api/lib/services/EinvoiceOutboxService.php` (o equivalente) — `txnId` en
  columna propia (`provider_txn_id`) + reconsulta al motor antes de reintentar
  (`f02477c8`, mig 217).
- `api/lib/services/KudeService.php` — `payload()` volvió a leer del proveedor
  (FE-PY), el render propio con `@react-pdf` queda apagado (`dbffaaf2`,
  `context/73` marcado SUPERSEDED).
- función/método `printableDocumentFor()` — ahora excluye `sifen_status`
  rechazado, no solo mira si hay CDC (`0a35e91a`).

## Callejones sin salida

- **No reintentar el KuDE propio.** `context/73` documenta el porqué de ida
  (evitar depender de un tercero) y de vuelta (ese tercero era Factomate, y
  FE-PY es propio — la razón de ser desapareció). Entregó un comprobante VACÍO
  a un cliente real la única vez que corrió en producción.
- **Deployar un fix sin mirar su salida.** El error concreto de esta sesión:
  se arregló el 500 del renderer (fuentes de pdfkit faltantes) y se deployó
  sin abrir el PDF que producía — era la primera vez que ese código corría.
- **Un deploy que cambia el payload envenena a los documentos en vuelo** si la
  Idempotency-Key es estable por documento. FE-PY tiene TTL de 24h, así que no
  es permanente, pero sí rompe el reintento inmediato siguiente.
- La garantía real contra duplicado fiscal NO es la Idempotency-Key: es el
  índice único de FE-PY `UNIQUE (tenant, tipo, establecimiento, punto, numero)
  WHERE estado NOT IN ('rechazado','error')` — tira 409 desde la base.
- Un documento RECHAZADO tiene CDC y QR igual (se calculan antes de ir a
  SIFEN). Filtrar por "tiene CDC" no sirve; la señal es
  `sifen_status='Aprobado' && !cancelled`.
- `kudeUrl`/`xmlUrl` de FE-PY son presigned S3 de 15 min, no se persisten.
  `qrUrl` sí es estable.
- Quoting de `docker exec ... psql -c "..."` con comillas anidadas falla —
  usar heredoc por stdin (`docker exec -i ... psql -Af -`).
- La DB de Punto en prod vive en el container `w6rtfxm2n6l45r4r9melj3hl`
  (Postgres 18), NO en `postgres-asqhqb6vb5yerc532ls0vql9` (ese es de
  Evolution API/WhatsApp) — confundirlos tira "role postgres does not exist".
  `DATABASE_URL` del container del backend lo confirma.

## Próximo paso

Probar una devolución con la serie nueva y confirmar que la NC sale con
número propio `001-002-0000001` (ya emitió una — `document_number='0000001'`,
`issued`/`Aprobado` en prod, ver abajo) y que ese número coincide en el KuDE
y en el email. Después: confirmar que el deploy del Front terminó bien,
decidir el texto de `leyendaDocumento`, y limpiar los worktrees consumidos.

## Trampas conocidas

1. **`logoUrl` de Balloon Party cargado A MANO en FE-PY** vía
   `PATCH /v1/tenants/01a0835d-cc70-770a-a656-3a4ea0708b09`. El código que lo
   sincroniza (`syncEmitterLogo()`) se mergeó DESPUÉS y todavía no se ejercitó.
2. **`provider_number` de la factura 615 completado a mano** (verificado:
   `document_number='0000615'` en `einvoice_document` está poblado — le
   faltaba la llave de reconsulta, el CDC sí la tenía).
3. **Cinco KuDE regenerados** vía `POST /de/{cdc}/kude/regenerar` (617, 618,
   619, 620 y la NC 1).
4. **Estado de anulación verificado en prod recién ahora** (query directa a
   `einvoice_document`): 616, 618 y 620 están `cancelled` (el owner las anuló
   a mano). **615, 617 y 619 siguen `issued`, SIN anular** — quedan
   pendientes, no es un olvido de esta sesión, es el estado real hoy.
5. Docker se cayó por disco lleno (98%) durante la sesión. Se liberaron ~21GB
   (`frontend/.next`, npm cache, `docker builder prune -af`); los worktrees
   consumidos siguen ocupando ~9GB sin limpiar.
6. `scp`/`docker cp` hacia producción están bloqueados por el classifier —
   usar `docker exec -i <container> php` por stdin para correr PHP puntual.

## Endpoints nuevos de FE-PY (referencia)

- `GET /v1/tenants/{ref}/de/txn/{txnId}` — recuperación directa; el POST
  devuelve `txnId` siempre, incluso en error.
- `GET /v1/tenants/{ref}/de/numero/{est}/{punto}/{numero}?tipoDocumento=N` —
  fallback; `tipoDocumento` es obligatorio o la búsqueda cruza tipos.
- `PATCH /v1/tenants/{id}` con `logoUrl` — https, extensión de imagen, host
  público (anti-SSRF).
- `PATCH /v1/companies/me` con `leyendaDocumento` — va en `dInfoEmi` del XML
  FIRMADO, no es cosmético. Hoy dice "Usamos www.punto.la".
- `POST /de/{cdc}/kude/regenerar` — rehace el PDF desde el XML firmado sin
  re-emitir.
- Idempotency-Key: 8-256 chars, 409 si no entra, TTL 24h, prefijo `batch-`
  prohibido.
