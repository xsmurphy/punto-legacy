-- 150_normalizar_identificadores_camelcase.sql
-- Elimina de raíz la clase de bug de identificadores Postgres mixtos:
-- columnas creadas CON comillas dobles (`"deviceId"`) quedan guardadas en el
-- catálogo con el case exacto que se usó al crearlas, y solo se pueden citar
-- de vuelta con EXACTAMENTE ese case + comillas. El resto del schema se creó
-- SIN comillas, así que Postgres pliega esos nombres a minúsculas — citarlos
-- en camelCase tira "column/relation ... does not exist". Las dos
-- convenciones convivían en el mismo schema y el error no lo atrapa ni el
-- build ni el lint ni el typechecker: explota en runtime. Ya causó al menos
-- cinco incidentes documentados (ReturnService, nota de crédito de compra,
-- endpoint del panel de cajas, EInvoiceService, incidente 2026-08-19 — ver
-- mig previa `db3c83e1`/verify_pg_identifiers.php, que parchó los call-sites
-- pero no tocó el schema).
--
-- Esta migración normaliza el schema a UNA sola convención: todo lowercase,
-- sin comillas, para siempre. Verificado contra el catálogo REAL de
-- producción (2026-08-19, `information_schema.columns` con
-- `column_name <> lower(column_name)`): 143 columnas en 18 tablas.
--
-- Tablas: addon_group, addon_group_option, admin_audit, auth_session,
-- deleted_row, giftcard, inventory_count, inventory_count_item,
-- numbering_lease, parked_sale, print_job, printer_binding, register_lease,
-- station_printer, stock_transfer, stock_transfer_item, tenant_audit,
-- tenant_note.
--
-- Idempotente: cada RENAME COLUMN está guardado por un chequeo de existencia
-- contra information_schema.columns con el nombre camelCase exacto — correrla
-- dos veces no falla, la segunda vez no encuentra nada que renombrar.
--
-- No destructiva: RENAME COLUMN es un cambio de METADATA del catálogo, nunca
-- toca ni una fila de datos — 0 downtime de escritura, 0 riesgo de pérdida.
--
-- Objetos dependientes — auditados contra el catálogo real antes de escribir
-- esta migración (pg_indexes, pg_constraint contype='f', pg_trigger,
-- pg_depend para vistas):
--   - Índices (incl. los ÚNICOS PARCIALES con predicado, ej.
--     `uq_register_lease_active ON register_lease ("registerId") WHERE
--     status='active'`, y `uq_lease_invoice`, `idx_auth_session_device`
--     WHERE "deviceId" IS NOT NULL): Postgres los reconstruye por OID/attnum,
--     no por texto — RENAME COLUMN los actualiza automáticamente, incluido
--     el predicado WHERE. Confirmado con pg_indexes antes/después.
--   - Constraints (PK, UNIQUE, incl. `giftcard_companyId_code_key`): mismo
--     mecanismo, automático. El NOMBRE del constraint (que sí contiene el
--     camelCase original, ej. `inventory_count_item_inventoryCountId_fkey`)
--     NO se renombra solo — no hay código en el repo que lo referencie por
--     nombre (auditado), así que se deja como está; renombrarlo es cosmético,
--     no funcional, y fuera de alcance de esta migración.
--   - Foreign keys (16 en las 18 tablas, incl. auth_session→ninguna,
--     register_lease→company/outlet/register/device,
--     numbering_lease→register_lease): mismo mecanismo, automático.
--   - Vistas: CERO vistas dependen de estas 18 tablas (pg_depend, verificado
--     en vivo) — nada que hacer.
--   - Triggers CON referencia de texto a nombres de columna (la trampa real:
--     RENAME COLUMN NO reescribe cuerpos de función ni argumentos de
--     trigger, solo objetos basados en attnum): `fn_touch_parent()` es
--     genérica y recibe nombres de columna como STRINGS en TG_ARGV
--     (`to_jsonb(NEW) ->> fk_col`, resuelto en runtime contra el nombre
--     FÍSICO de la columna). Dos triggers la usan con argumentos camelCase:
--       trg_addon_group_touch_item        ('item','itemid','itemId')
--       trg_addon_group_option_touch_item ('item','itemid','itemId','addon_group','groupId','groupId')
--     Si no se corrigen, el trigger sigue existiendo y no tira error, pero
--     `to_jsonb(NEW) ->> 'itemId'` da NULL contra la columna ya renombrada a
--     `itemid` — degrada en SILENCIO (deja de tocar `updated_at` del item
--     padre cuando cambia un addon). Se recrean más abajo con los argumentos
--     en lowercase.
--   - `fn_record_deletion()` (mig 138_sync_incremental.sql, triggers
--     trg_item_tombstone/trg_contact_tombstone en item/contact): CASO PEOR
--     que fn_touch_parent — acá las comillas camelCase NO llegan por
--     TG_ARGV, están HARDCODEADAS como texto SQL en el cuerpo de la función
--     (`INSERT INTO deleted_row ("companyId", entity, "rowId", ...)`). Si no
--     se corrige, NO degrada en silencio: revienta con excepción dura
--     ("column \"companyId\" of relation \"deleted_row\" does not exist")
--     en CUALQUIER DELETE sobre item o contact — bloquea todo borrado, no
--     solo el tombstone de sync. Verificado contra pg_proc.prosrc en
--     producción: es la ÚNICA función de toda la BD con una referencia
--     hardcodeada a alguna de las 143 columnas. Se recrea más abajo con
--     `companyid`/`rowid` lowercase.
--
-- auth_session (tabla de sesiones — si se rompe, nadie entra al sistema):
-- mismo mecanismo que el resto (RENAME COLUMN de metadata, sin tocar datos),
-- sin triggers ni vistas dependientes (auditado). Verificación post-deploy:
-- 1) esta migración corre en `migrate.php` ANTES de aceptar tráfico
--    (entrypoint del container) — si el RENAME fallara, `migrate.php` sale
--    con exit 1 y el container NO arranca (fail-fast, ver docblock de
--    migrate.php: "mejor fail-fast que servir requests contra un schema a
--    medio migrar"), así que un error acá bloquea el deploy ANTES de que
--    ningún login lo sufra — no hay ventana de sesiones rotas en producción.
--  2) el código que lee/escribe auth_session (api/lib/Auth/*, ver mig
--     21-auth-rewrite) se actualiza en el mismo commit que esta migración
--     (grep de `"sessionId"|"tokenHash"|"deviceId"|"companyId"|...` contra
--     auth_session, ver commit de código adjunto) — no queda una ventana
--     donde el schema ya cambió pero el código todavía cita camelCase.
--  3) no se pudo levantar el server local para un smoke test real de login
--     (Docker no funciona en esta máquina — ver reporte final); queda como
--     verificación pendiente del primer deploy a este entorno de desarrollo.
--
-- Reversibilidad de diseño (no incluida como DOWN automático — el proyecto
-- no tiene runner de rollback, ver resto de migrations/postgres/*.sql): para
-- revertir, cada bloque de abajo es un RENAME COLUMN inverso trivial
-- (`ALTER TABLE x RENAME COLUMN lowercase TO "CamelCase"`) — no hay pérdida
-- de información en ningún sentido (metadata pura), así que el rollback es
-- mecánico si alguna vez hiciera falta.
--
-- Nunca aborta el deploy con degradación silenciosa (a diferencia de otras
-- migraciones recientes que envuelven el cuerpo en EXCEPTION WHEN OTHERS +
-- RAISE WARNING para backfills opcionales): ESTA migración es estructural,
-- no un backfill de datos — si un RENAME fallara a mitad de camino dejaría
-- el schema en un estado híbrido indetectable por el resto del código, que
-- es exactamente el bug que esta migración existe para eliminar. Se deja
-- que la excepción se propague: migrate.php la loguea y sale 1, bloqueando
-- el deploy — igual que el comportamiento default de cualquier otra
-- migración DDL de este runner.

BEGIN;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group' AND column_name='companyId') THEN
    ALTER TABLE addon_group RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group' AND column_name='groupId') THEN
    ALTER TABLE addon_group RENAME COLUMN "groupId" TO groupid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group' AND column_name='itemId') THEN
    ALTER TABLE addon_group RENAME COLUMN "itemId" TO itemid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group' AND column_name='maxSelect') THEN
    ALTER TABLE addon_group RENAME COLUMN "maxSelect" TO maxselect;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group' AND column_name='minSelect') THEN
    ALTER TABLE addon_group RENAME COLUMN "minSelect" TO minselect;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group_option' AND column_name='groupId') THEN
    ALTER TABLE addon_group_option RENAME COLUMN "groupId" TO groupid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group_option' AND column_name='isDefault') THEN
    ALTER TABLE addon_group_option RENAME COLUMN "isDefault" TO isdefault;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group_option' AND column_name='isLocked') THEN
    ALTER TABLE addon_group_option RENAME COLUMN "isLocked" TO islocked;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group_option' AND column_name='itemId') THEN
    ALTER TABLE addon_group_option RENAME COLUMN "itemId" TO itemid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group_option' AND column_name='maxQty') THEN
    ALTER TABLE addon_group_option RENAME COLUMN "maxQty" TO maxqty;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group_option' AND column_name='optionId') THEN
    ALTER TABLE addon_group_option RENAME COLUMN "optionId" TO optionid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='addon_group_option' AND column_name='priceDelta') THEN
    ALTER TABLE addon_group_option RENAME COLUMN "priceDelta" TO pricedelta;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='admin_audit' AND column_name='adminEmail') THEN
    ALTER TABLE admin_audit RENAME COLUMN "adminEmail" TO adminemail;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='admin_audit' AND column_name='adminId') THEN
    ALTER TABLE admin_audit RENAME COLUMN "adminId" TO adminid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='admin_audit' AND column_name='createdAt') THEN
    ALTER TABLE admin_audit RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='admin_audit' AND column_name='targetId') THEN
    ALTER TABLE admin_audit RENAME COLUMN "targetId" TO targetid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='admin_audit' AND column_name='targetName') THEN
    ALTER TABLE admin_audit RENAME COLUMN "targetName" TO targetname;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='admin_audit' AND column_name='targetType') THEN
    ALTER TABLE admin_audit RENAME COLUMN "targetType" TO targettype;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='companyId') THEN
    ALTER TABLE auth_session RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='createdAt') THEN
    ALTER TABLE auth_session RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='deviceId') THEN
    ALTER TABLE auth_session RENAME COLUMN "deviceId" TO deviceid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='expiresAt') THEN
    ALTER TABLE auth_session RENAME COLUMN "expiresAt" TO expiresat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='ipFirst') THEN
    ALTER TABLE auth_session RENAME COLUMN "ipFirst" TO ipfirst;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='ipLast') THEN
    ALTER TABLE auth_session RENAME COLUMN "ipLast" TO iplast;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='lastSeenAt') THEN
    ALTER TABLE auth_session RENAME COLUMN "lastSeenAt" TO lastseenat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='outletId') THEN
    ALTER TABLE auth_session RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='registerId') THEN
    ALTER TABLE auth_session RENAME COLUMN "registerId" TO registerid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='revokedAt') THEN
    ALTER TABLE auth_session RENAME COLUMN "revokedAt" TO revokedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='revokedBy') THEN
    ALTER TABLE auth_session RENAME COLUMN "revokedBy" TO revokedby;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='roleId') THEN
    ALTER TABLE auth_session RENAME COLUMN "roleId" TO roleid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='sessionId') THEN
    ALTER TABLE auth_session RENAME COLUMN "sessionId" TO sessionid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='tokenHash') THEN
    ALTER TABLE auth_session RENAME COLUMN "tokenHash" TO tokenhash;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='userAgent') THEN
    ALTER TABLE auth_session RENAME COLUMN "userAgent" TO useragent;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='auth_session' AND column_name='userId') THEN
    ALTER TABLE auth_session RENAME COLUMN "userId" TO userid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='deleted_row' AND column_name='companyId') THEN
    ALTER TABLE deleted_row RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='deleted_row' AND column_name='rowId') THEN
    ALTER TABLE deleted_row RENAME COLUMN "rowId" TO rowid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='beneficiaryContactId') THEN
    ALTER TABLE giftcard RENAME COLUMN "beneficiaryContactId" TO beneficiarycontactid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='beneficiaryName') THEN
    ALTER TABLE giftcard RENAME COLUMN "beneficiaryName" TO beneficiaryname;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='companyId') THEN
    ALTER TABLE giftcard RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='createdAt') THEN
    ALTER TABLE giftcard RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='currentBalance') THEN
    ALTER TABLE giftcard RENAME COLUMN "currentBalance" TO currentbalance;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='expiresAt') THEN
    ALTER TABLE giftcard RENAME COLUMN "expiresAt" TO expiresat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='initialBalance') THEN
    ALTER TABLE giftcard RENAME COLUMN "initialBalance" TO initialbalance;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='issuedByTransactionId') THEN
    ALTER TABLE giftcard RENAME COLUMN "issuedByTransactionId" TO issuedbytransactionid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='outletId') THEN
    ALTER TABLE giftcard RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='usedAt') THEN
    ALTER TABLE giftcard RENAME COLUMN "usedAt" TO usedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='giftcard' AND column_name='usedByTransactionId') THEN
    ALTER TABLE giftcard RENAME COLUMN "usedByTransactionId" TO usedbytransactionid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='companyId') THEN
    ALTER TABLE inventory_count RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='docNumber') THEN
    ALTER TABLE inventory_count RENAME COLUMN "docNumber" TO docnumber;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='finishedAt') THEN
    ALTER TABLE inventory_count RENAME COLUMN "finishedAt" TO finishedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='finishedBy') THEN
    ALTER TABLE inventory_count RENAME COLUMN "finishedBy" TO finishedby;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='inventoryCountId') THEN
    ALTER TABLE inventory_count RENAME COLUMN "inventoryCountId" TO inventorycountid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='locationId') THEN
    ALTER TABLE inventory_count RENAME COLUMN "locationId" TO locationid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='outletId') THEN
    ALTER TABLE inventory_count RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='startedAt') THEN
    ALTER TABLE inventory_count RENAME COLUMN "startedAt" TO startedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count' AND column_name='startedBy') THEN
    ALTER TABLE inventory_count RENAME COLUMN "startedBy" TO startedby;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count_item' AND column_name='countedAt') THEN
    ALTER TABLE inventory_count_item RENAME COLUMN "countedAt" TO countedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count_item' AND column_name='countedBy') THEN
    ALTER TABLE inventory_count_item RENAME COLUMN "countedBy" TO countedby;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count_item' AND column_name='countedQty') THEN
    ALTER TABLE inventory_count_item RENAME COLUMN "countedQty" TO countedqty;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count_item' AND column_name='expectedQty') THEN
    ALTER TABLE inventory_count_item RENAME COLUMN "expectedQty" TO expectedqty;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count_item' AND column_name='inventoryCountId') THEN
    ALTER TABLE inventory_count_item RENAME COLUMN "inventoryCountId" TO inventorycountid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count_item' AND column_name='inventoryCountItemId') THEN
    ALTER TABLE inventory_count_item RENAME COLUMN "inventoryCountItemId" TO inventorycountitemid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count_item' AND column_name='itemId') THEN
    ALTER TABLE inventory_count_item RENAME COLUMN "itemId" TO itemid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='inventory_count_item' AND column_name='unitCost') THEN
    ALTER TABLE inventory_count_item RENAME COLUMN "unitCost" TO unitcost;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='companyId') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='consumedAt') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "consumedAt" TO consumedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='expiresAt') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "expiresAt" TO expiresat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='invoiceNo') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "invoiceNo" TO invoiceno;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='leaseId') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "leaseId" TO leaseid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='leasedAt') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "leasedAt" TO leasedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='outletId') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='registerId') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "registerId" TO registerid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='registerLeaseId') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "registerLeaseId" TO registerleaseid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='voidReason') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "voidReason" TO voidreason;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='numbering_lease' AND column_name='voidedAt') THEN
    ALTER TABLE numbering_lease RENAME COLUMN "voidedAt" TO voidedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='parked_sale' AND column_name='companyId') THEN
    ALTER TABLE parked_sale RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='parked_sale' AND column_name='createdAt') THEN
    ALTER TABLE parked_sale RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='parked_sale' AND column_name='outletId') THEN
    ALTER TABLE parked_sale RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='parked_sale' AND column_name='userId') THEN
    ALTER TABLE parked_sale RENAME COLUMN "userId" TO userid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='companyId') THEN
    ALTER TABLE print_job RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='createdAt') THEN
    ALTER TABLE print_job RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='createdByDeviceId') THEN
    ALTER TABLE print_job RENAME COLUMN "createdByDeviceId" TO createdbydeviceid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='docType') THEN
    ALTER TABLE print_job RENAME COLUMN "docType" TO doctype;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='lastError') THEN
    ALTER TABLE print_job RENAME COLUMN "lastError" TO lasterror;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='openDrawer') THEN
    ALTER TABLE print_job RENAME COLUMN "openDrawer" TO opendrawer;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='outletId') THEN
    ALTER TABLE print_job RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='sourceId') THEN
    ALTER TABLE print_job RENAME COLUMN "sourceId" TO sourceid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='sourceKind') THEN
    ALTER TABLE print_job RENAME COLUMN "sourceKind" TO sourcekind;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='stationPrinterId') THEN
    ALTER TABLE print_job RENAME COLUMN "stationPrinterId" TO stationprinterid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='print_job' AND column_name='updatedAt') THEN
    ALTER TABLE print_job RENAME COLUMN "updatedAt" TO updatedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='autoPrint') THEN
    ALTER TABLE printer_binding RENAME COLUMN "autoPrint" TO autoprint;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='categoryIds') THEN
    ALTER TABLE printer_binding RENAME COLUMN "categoryIds" TO categoryids;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='companyId') THEN
    ALTER TABLE printer_binding RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='createdAt') THEN
    ALTER TABLE printer_binding RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='deviceLabel') THEN
    ALTER TABLE printer_binding RENAME COLUMN "deviceLabel" TO devicelabel;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='docTypes') THEN
    ALTER TABLE printer_binding RENAME COLUMN "docTypes" TO doctypes;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='openDrawer') THEN
    ALTER TABLE printer_binding RENAME COLUMN "openDrawer" TO opendrawer;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='outletId') THEN
    ALTER TABLE printer_binding RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='paperWidthMm') THEN
    ALTER TABLE printer_binding RENAME COLUMN "paperWidthMm" TO paperwidthmm;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='printDelay') THEN
    ALTER TABLE printer_binding RENAME COLUMN "printDelay" TO printdelay;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='productId') THEN
    ALTER TABLE printer_binding RENAME COLUMN "productId" TO productid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='registerId') THEN
    ALTER TABLE printer_binding RENAME COLUMN "registerId" TO registerid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='stationPrinterId') THEN
    ALTER TABLE printer_binding RENAME COLUMN "stationPrinterId" TO stationprinterid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='templateId') THEN
    ALTER TABLE printer_binding RENAME COLUMN "templateId" TO templateid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='updatedAt') THEN
    ALTER TABLE printer_binding RENAME COLUMN "updatedAt" TO updatedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='printer_binding' AND column_name='vendorId') THEN
    ALTER TABLE printer_binding RENAME COLUMN "vendorId" TO vendorid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='companyId') THEN
    ALTER TABLE register_lease RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='deviceId') THEN
    ALTER TABLE register_lease RENAME COLUMN "deviceId" TO deviceid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='expiresAt') THEN
    ALTER TABLE register_lease RENAME COLUMN "expiresAt" TO expiresat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='outletId') THEN
    ALTER TABLE register_lease RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='registerId') THEN
    ALTER TABLE register_lease RENAME COLUMN "registerId" TO registerid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='registerLeaseId') THEN
    ALTER TABLE register_lease RENAME COLUMN "registerLeaseId" TO registerleaseid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='releasedAt') THEN
    ALTER TABLE register_lease RENAME COLUMN "releasedAt" TO releasedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='releasedBy') THEN
    ALTER TABLE register_lease RENAME COLUMN "releasedBy" TO releasedby;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='register_lease' AND column_name='takenAt') THEN
    ALTER TABLE register_lease RENAME COLUMN "takenAt" TO takenat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='station_printer' AND column_name='companyId') THEN
    ALTER TABLE station_printer RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='station_printer' AND column_name='createdAt') THEN
    ALTER TABLE station_printer RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='station_printer' AND column_name='deviceId') THEN
    ALTER TABLE station_printer RENAME COLUMN "deviceId" TO deviceid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='station_printer' AND column_name='outletId') THEN
    ALTER TABLE station_printer RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='station_printer' AND column_name='transportConfig') THEN
    ALTER TABLE station_printer RENAME COLUMN "transportConfig" TO transportconfig;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='station_printer' AND column_name='updatedAt') THEN
    ALTER TABLE station_printer RENAME COLUMN "updatedAt" TO updatedat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='companyId') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='createdAt') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='createdBy') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "createdBy" TO createdby;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='docNumber') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "docNumber" TO docnumber;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='fromLocationId') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "fromLocationId" TO fromlocationid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='fromOutletId') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "fromOutletId" TO fromoutletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='stockTransferId') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "stockTransferId" TO stocktransferid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='toLocationId') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "toLocationId" TO tolocationid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer' AND column_name='toOutletId') THEN
    ALTER TABLE stock_transfer RENAME COLUMN "toOutletId" TO tooutletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer_item' AND column_name='itemId') THEN
    ALTER TABLE stock_transfer_item RENAME COLUMN "itemId" TO itemid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer_item' AND column_name='stockTransferId') THEN
    ALTER TABLE stock_transfer_item RENAME COLUMN "stockTransferId" TO stocktransferid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer_item' AND column_name='stockTransferItemId') THEN
    ALTER TABLE stock_transfer_item RENAME COLUMN "stockTransferItemId" TO stocktransferitemid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='stock_transfer_item' AND column_name='unitCost') THEN
    ALTER TABLE stock_transfer_item RENAME COLUMN "unitCost" TO unitcost;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_audit' AND column_name='companyId') THEN
    ALTER TABLE tenant_audit RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_audit' AND column_name='createdAt') THEN
    ALTER TABLE tenant_audit RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_audit' AND column_name='outletId') THEN
    ALTER TABLE tenant_audit RENAME COLUMN "outletId" TO outletid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_audit' AND column_name='targetId') THEN
    ALTER TABLE tenant_audit RENAME COLUMN "targetId" TO targetid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_audit' AND column_name='userId') THEN
    ALTER TABLE tenant_audit RENAME COLUMN "userId" TO userid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_note' AND column_name='authorId') THEN
    ALTER TABLE tenant_note RENAME COLUMN "authorId" TO authorid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_note' AND column_name='companyId') THEN
    ALTER TABLE tenant_note RENAME COLUMN "companyId" TO companyid;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_note' AND column_name='createdAt') THEN
    ALTER TABLE tenant_note RENAME COLUMN "createdAt" TO createdat;
  END IF;
  IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='tenant_note' AND column_name='noteId') THEN
    ALTER TABLE tenant_note RENAME COLUMN "noteId" TO noteid;
  END IF;

  -- ── Triggers con nombres de columna en TG_ARGV (fn_touch_parent) ─────────
  -- RENAME COLUMN no reescribe estos strings — se recrean con los nombres ya
  -- lowercase. DROP+CREATE incondicional: idempotente por diseño (recrear la
  -- misma definición no tiene efecto observable).
  DROP TRIGGER IF EXISTS trg_addon_group_touch_item ON addon_group;
  CREATE TRIGGER trg_addon_group_touch_item
    AFTER INSERT OR DELETE OR UPDATE ON addon_group
    FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid');

  DROP TRIGGER IF EXISTS trg_addon_group_option_touch_item ON addon_group_option;
  CREATE TRIGGER trg_addon_group_option_touch_item
    AFTER INSERT OR DELETE OR UPDATE ON addon_group_option
    FOR EACH ROW EXECUTE FUNCTION fn_touch_parent('item', 'itemid', 'itemid', 'addon_group', 'groupid', 'groupid');

  -- ── fn_record_deletion() (mig 138_sync_incremental.sql): trigger de
  -- tombstones de sync incremental (trg_item_tombstone en item,
  -- trg_contact_tombstone en contact) que escribe en deleted_row — una de
  -- las 18 tablas de esta migración. A diferencia de fn_touch_parent(), acá
  -- las comillas camelCase NO llegan por TG_ARGV — están HARDCODEADAS como
  -- texto SQL en el INSERT/ON CONFLICT del cuerpo de la función
  -- (`INSERT INTO deleted_row ("companyId", entity, "rowId", ...)`).
  -- RENAME COLUMN nunca reescribe cuerpos de función — si esto no se
  -- corrige acá, CUALQUIER DELETE sobre item o contact revienta con
  -- "column \"companyId\" of relation \"deleted_row\" does not exist" en
  -- cuanto el RENAME de arriba corre, bloqueando TODO borrado de ítems y
  -- contactos (no solo el tombstone de sync). Verificado contra el catálogo
  -- real de producción (pg_proc.prosrc): es la ÚNICA función de toda la BD
  -- con una referencia hardcodeada a alguna de las 143 columnas — todas las
  -- demás pasan nombres de columna dinámicamente (fn_touch_parent) o no
  -- tocan estas tablas.
  CREATE OR REPLACE FUNCTION fn_record_deletion() RETURNS trigger AS $fn$
  DECLARE
    v_row_id  UUID;
    v_company UUID;
  BEGIN
    v_row_id  := (to_jsonb(OLD) ->> TG_ARGV[1])::uuid;
    v_company := (to_jsonb(OLD) ->> 'companyid')::uuid;
    IF v_company IS NOT NULL AND v_row_id IS NOT NULL THEN
      INSERT INTO deleted_row (companyid, entity, rowid, deleted_at)
      VALUES (v_company, TG_ARGV[0], v_row_id, now())
      ON CONFLICT (companyid, entity, rowid) DO UPDATE SET deleted_at = EXCLUDED.deleted_at;
    END IF;
    RETURN OLD;
  END;
  $fn$ LANGUAGE plpgsql;

  -- ── Chequeo final: cero columnas camelCase-quoted deben sobrevivir en las
  -- 18 tablas de esta migración. Si algo quedó afuera (nombre no anticipado
  -- por el catálogo auditado el 2026-08-19), abortar la transacción entera
  -- en vez de dejar un normalizado parcial indetectable.
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_schema = 'public'
       AND table_name IN (
         'addon_group','addon_group_option','admin_audit','auth_session',
         'deleted_row','giftcard','inventory_count','inventory_count_item',
         'numbering_lease','parked_sale','print_job','printer_binding',
         'register_lease','station_printer','stock_transfer',
         'stock_transfer_item','tenant_audit','tenant_note'
       )
       AND column_name <> lower(column_name)
  ) THEN
    RAISE EXCEPTION 'mig 150: quedaron columnas camelCase sin normalizar en las 18 tablas objetivo — revisar manualmente antes de reintentar';
  END IF;
END $$;

COMMIT;
