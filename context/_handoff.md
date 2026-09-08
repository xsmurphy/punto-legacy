# Hand-off — 2026-09-08 (madrugada)

## Objetivo

Sesión larga (2026-09-06 → 08) con foco en facturación electrónica: MCP
escribe (M6/M7), onboarding LATAM corregido, N2 (reemisión de rechazados) y
KuDE (email + propio), y una auditoría de emisión contra la guía real de
Factomate. Terminó en un PIVOT del owner: el motor de FE pasa a ser propio
(proyecto FE-PY, sesión paralela "FE", ya emitiendo en SIFEN real), Factomate
queda de fallback. Corrió intercalada con la sesión paralela "Punto Bugs"
(CDC propio, bloques de ticket, numeración del emisor — no reclamado acá).

## Estado al cerrar

`origin/main` = `e9f665d5`. Backend y Front deployados y verificados
`running:healthy` (Front en `f1a6aedd`+, último con contenido de frontend/
fue el KuDE propio ~`43a6fed7`; los commits posteriores a ese son API-only o
docs — si al retomar hay dudas, correr `git log --stat` sobre el rango para
confirmar que ningún cambio de `frontend/` quedó sin deploy). Migs 201, 202,
205, 206 aplicadas en prod (confirmado en logs `[migrate]`). Árbol limpio.

## Archivos y cambios

- MCP escritura: `api/v1/ai/confirm.php`+`execute.php` vía `punto_register_actions`/
  `punto_execute_actions` (M6); `set_fiscal_data`/`provision_einvoice`/
  `lookup_taxpayer`/`get_einvoice_setup` (M7). Docs: `context/58`, `context/66`.
- Onboarding: `api/lib/Signup/CountryDefaults.php` (nuevo) — miles/decimales/
  taxName/TIN/rubros/precios demo por país, primer-match-gana; `SettingsService`
  ya no permite blanquear `settingCountry`.
- `context/201*.sql` — `superseded_by` + índice parcial, N2 reemisión de
  rechazados; 3 call-sites externos corregidos para filtrar `superseded_by`.
- `context/202*.sql` `notification_outbox` + `EmailAdapter`/`KudeEmailBuilder`
  + cron `notification-drain`.
- `frontend/.../kude/render` (K1-K3) — renderer A4 propio con `@react-pdf`,
  `INTERNAL_RENDER_KEY`, caché S3, fallback a `getkude`. Doc nuevo
  `context/73-kude-propio.md`.
- Auditoría Factomate: mig 205 `login_enc` (`EmitterIdentity` self-healing),
  unitario exacto, serie real del timbrado, `issuedDate=transactionDate`,
  receptor completo, `security_code` congelado en reintento.
- FE-PY adapter: `FePyProvider` + `SaleToFePyMapper` + factory por
  `einvoice_account.provider` (`factomate` default / `fepy`) +
  `FePyProvisioningService` + mig 206 `provider_tenant_ref`; credencial en
  `platform_config.integration.fepy.keyEnc` cifrada (patrón Resend), fallback
  a env.
- `api/lib/Fiscal/FiscalSecretStore.php` — bug real: chequeaba `is_array()`
  sobre un `CaseInsensitiveArray` (RecordsetIterator), lo trataba como
  "vacío" y pisó la custodia real del cert de Balloon Party con un dummy.
  **Pendiente**: el cert en Factomate quedó bien, pero la custodia local
  quedó vacía — el owner debe re-subir el P12 una vez.
- Docs tocados: `context/28`, `57`, `58`, `66`, `73` (nuevo), `10-roadmap`, `42`.

## Cambios A MANO en prod (no están en git — leer antes de tocar nada)

- **Balloon Party** (`companyid 01a067cb-8fff-72cd-bb12-0b483dcb7dbf`):
  `settingCountry='PY'` backfilleado por SQL directo;
  `einvoice_account.provider_tenant_ref='01a07f03-7335-7739-9869-296d3607797d'`
  pre-cargado, pero `provider` SIGUE `'factomate'` (NO flipeado a `fepy`).
- **Factomate DEV, tenant 6**: sucursal Id 5 creada, timbrados 17/18
  vinculados a ella, actividad 47640 agregada, `TaxpayerType=1` seteado —
  todo por API en vivo, reparando el estado que dejó el 500.
- **Custodia del cert de Balloon**: vacía (ver bug de `FiscalSecretStore`
  arriba). El cert en Factomate está bien, la custodia local no.
- Env `INTERNAL_RENDER_KEY` cargada por el owner en ambas apps de Coolify.

## Callejones sin salida

- Factomate DEV: `/Bulk`, sincro/config, `Consulta`, `UploadCert` (base64)
  dan TODOS 500 genérico (`NullReferenceException HelpersDE.ValidaDE:2471`).
  Probado: 7 variantes de payload, estado completo del tenant. No es nuestro
  — reportado a soporte de Factomate. No insistir; el pivot a FE-PY lo
  vuelve irrelevante salvo como fallback.
- `PhoneLogin` de Factomate NO es la puerta del tenant — es `/Token` con
  email+password de `CreateExternal`. No reintentar por ahí.
- Monitorear logs por SSH con `docker logs -f` muere en cada redeploy (el
  nombre del contenedor cambia) — resolver el nombre DENTRO del comando.

## Próximo paso (coordinado con la sesión paralela "FE")

1. Esperar confirmación de "FE" del PATCH `timbradoFecha=2025-08-26` en su
   tenant prod.
2. El owner sube `/root/fepy-handoff.json` (`apiKey`+`tenantId`) por `scp` →
   ingerirlo CIFRADO a `platform_config.integration.fepy` y BORRAR el archivo.
3. Flip `provider='fepy'` para Balloon Party.
4. UN ciclo de prueba ≤500 Gs: venta real vía `SaleService` → outbox → FE-PY
   → esperado 0260 número 615 → cancelación (0600). Reglas del owner:
   facturas de prueba ≤500 Gs, un solo ciclo, no romper nada, él no está
   para confirmar en el momento.

## Pendientes (no son de mañana)

N1 (aviso de rechazo al comercio, decidido que NO se avisa al cliente final —
ver `context/28` §F7), M8 (URL de un solo uso para secretos por MCP), P3
gracia solo-lectura, cuotas/tipo de cambio en el template del KuDE, variantes
de receptor RUC/CI de FE-PY sin emisión real (hoy emiten con warn), preguntas
a Automate (DCarQR completo, apagar su auto-email), decisión del owner sobre
reparto de puntos de expedición 001-001 (Factomate fallback) / 001-002
(FE-PY).

## Trampas conocidas

- `context/73-kude-propio.md` es nuevo — no está listado todavía en la tabla
  de docs del `CLAUDE.md` del proyecto; agregar la fila si se vuelve a tocar
  KuDE.
- El deploy del Front puede estar unos commits atrás del HEAD de `main` si
  los últimos commits fueron API-only — verificar con `git log --stat` antes
  de asumir que un cambio de `frontend/` ya está sirviendo.
- Ver también trampas heredadas del hand-off anterior (2026-09-03): sin
  backfill del histórico de fecha, Cloudflare "Block AI bots" desactivada a
  mano, flags de comercio system-wide sin scope por sucursal, `psql`/SSH a
  BD bloqueados por el classifier, `npx vitest` correr desde `frontend/`.
