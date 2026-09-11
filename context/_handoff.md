# Hand-off — 2026-09-10

## Objetivo

Cerrar pendientes de facturación electrónica (badge anulada, serie NC,
`INTERNAL_RENDER_KEY`). Terminó en dos incidentes reales en prod que
forzaron al owner a eliminar Factomate entero y dar de baja el KuDE propio.

## Estado al cerrar

Backend en HEAD `0a35e91a` — deploy `vzois4j5dib2k8izigkapktt` **finished**,
verificado (`running:healthy`, imagen = commit). Migs 214/215/216/217
aplicadas y verificadas en prod. Front — deploy `mpz2jnhcakxs1fz5ivibgbay`
(otra sesión, no esta) estaba **in_progress** al cerrar; confirmar antes de
asumir que está al día.

## Archivos y cambios

- Factomate eliminado del código, FE-PY único motor (`eccf3d1b`, mig 214).
- NC con serie propia heredando la caja de la factura (`418b679c`, mig 215).
- `provider_txn_id` propio + reconsulta al motor antes de reintentar
  (`f02477c8`, mig 217).
- `KudeService::payload()` vuelve a leer del proveedor; render propio
  apagado (`dbffaaf2`, `context/73` SUPERSEDED).
- `printableDocumentFor()` excluye rechazados por `sifen_status` (`0a35e91a`).

## Callejones sin salida

- KuDE propio: única vez que corrió, entregó un comprobante VACÍO a un
  cliente real (payload nunca migrado de Factomate a FE-PY). No reabrir.
- Deployar un fix sin abrir lo que produce — fue la causa del incidente de arriba.
- Idempotency-Key estable + payload que cambia en un deploy = reintento
  envenenado (TTL 24h, no permanente). La garantía real es el índice único
  de FE-PY, no la Idempotency-Key.
- Un RECHAZADO tiene CDC y QR igual (se calculan antes de SIFEN) — filtrar
  por "tiene CDC" no sirve.
- DB de prod: container `w6rtfxm2n6l45r4r9melj3hl` (Postgres 18), NO
  `postgres-asqhqb6vb5yerc532ls0vql9` (ese es Evolution API/WhatsApp).
- `docker exec ... psql -c "..."` con comillas anidadas falla — usar heredoc
  por stdin.

## Próximo paso

Probar una devolución con la serie nueva: confirmar NC `001-002-0000001`
(ya emitió una, `issued`/`Aprobado` en prod) coincide en KuDE y email.
Después: confirmar que el Front terminó de deployar, texto de
`leyendaDocumento`, limpiar worktrees consumidos.

## Trampas conocidas

1. `logoUrl` de Balloon Party cargado A MANO en FE-PY (`PATCH /v1/tenants/...`);
   `syncEmitterLogo()` se mergeó después, sin ejercitar.
2. `provider_number` de la factura 615 completado a mano.
3. Cinco KuDE regenerados vía `/de/{cdc}/kude/regenerar` (617-620 + NC 1).
4. Verificado en prod recién ahora: 616/618/620 ya `cancelled`. **615/617/619
   siguen `issued`, sin anular** — no es olvido de esta sesión.
5. Disco lleno (98%) durante la sesión, liberados ~21GB; worktrees viejos
   siguen ocupando ~9GB sin limpiar.
