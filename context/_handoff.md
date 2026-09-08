# Hand-off — 2026-09-08

## Objetivo

Día del cutover al motor de FE PROPIO (FE-PY, hosteado por la sesión paralela
"FE" en https://fepy.punto.la). Se cerró el readiness gate del proveedor, se
corrigió el proceso de alta de tenant (debe ser 100% UI, nunca CLI) y se
completó el wizard de régimen tributario + establecimientos. Quedó un agente
de dropdowns geográficos SIFEN detenido a mitad por saturación de la Mac.

## Estado al cerrar

`origin/main` = `e6a52d64`. Backend deployado y verificado en `e6a52d64`
(HEAD). Front deployado en `b924b989` (los commits posteriores al merge son
API-only, no requieren redeploy de frontend/). Árbol limpio, nada pusheado
sin deployar.

## Archivos y cambios (commits `97a08a02..e6a52d64`)

- `82fd2892` — readiness gate: `FePyProvider::readiness()` + branch `fepy` en
  `testConnection`.
- `e9a93acd` — emisores del motor propio nacen con env `'prod'` (el default
  global `'test'` es de Factomate, no aplica al proveedor nuevo).
- `17be7bf1` + `3b9b3fa5` (merge `b924b989`) — wizard de alta: campos régimen
  tributario + `establecimientos[]` persistidos en el espejo fiscal. Bug real:
  `validateForm()` normalizaba y DESCARTABA los campos nuevos antes de que
  llegaran al guardado.
- `e6a52d64` — `provisioned` se mide contra el proveedor ACTIVO de
  `einvoice_account`, no contra cualquier id de proveedor presente en la fila
  — el id de Factomate tapaba el botón Guardar del alta del motor nuevo.
- `context/73-kude-propio.md` ya tiene fila en `CLAUDE.md`; no se tocaron más
  docs esta sesión (el detalle vive en commits + este hand-off).

## Trabajo en vuelo INTERRUMPIDO (crítico)

Agente de dropdowns geográficos DETENIDO a mitad — la Mac del owner se saturó
por el Docker local de FE-PY (ya no hace falta, FE-PY está hosteado; se le
indicó cerrar Docker Desktop). Parcial SIN pushear en:

- Worktree: `/Users/xstian/Dropbox/Punto/system/.claude/worktrees/agent-aa0fb08127953c581`
- Branch: `worktree-agent-aa0fb08127953c581`
- Contenido: script de extracción del catálogo SIFEN (18 depto / ~270
  distrito / ~5800 ciudad) desde
  `/Users/xstian/Dropbox/Factura Electrónica/FE-PY/src/services/constants.service.ts`
  + JSON generado + loader. Falta: la UI en cascada y tests.

Al retomar: confirmar que la Mac se recuperó, entrar al worktree, seguir desde
ahí — no relanzar desde cero.

## Cambios A MANO en prod (no están en git — leer antes de tocar nada)

- `platform_config` key `integration.fepy`:
  `{keyEnc: <API key de FE-PY cifrada con CredentialVault>, baseUrl: "https://fepy.punto.la"}`
  (SIN `/v1` — el cliente lo agrega; con `/v1` da 404 por doble prefijo).
  Ingerida desde `/root/fepy-handoff.json`, que fue borrado tras la ingesta.
- **Balloon Party** (`companyid 01a067cb-8fff-72cd-bb12-0b483dcb7dbf`)
  `einvoice_account` reseteado a cero: `provider='fepy'`,
  `provider_tenant_ref=NULL`, `status='provisioning'`, `emitter='{}'`,
  `stamp='{}'`, checkpoints `fepy*` borrados, `fiscal` limpiado de
  `taxpayerType`/`regimeId`/`establecimientos` (quedaron `email`/
  `actividades`/`cscId`/`infoAdicional`). Columnas de Factomate
  (`factomate_tenant_id=6`, `username`, `password_enc`, `login_enc`) INTACTAS
  — es el fallback.
- **FE-PY prod**: tenant CLI `01a080c1-a13f-75fa-82f4-7ce2b20a606a` PURGADO
  físicamente. Company de Punto en FE-PY:
  `01a08081-413a-7025-b64e-9f3bd112e2c2`. RUC `3595193-1` libre para el alta
  real. Numeración objetivo: FE=614 / NC=2 (próximos a emitir: 615 / 3).
- **Custodia local de cert/CSC de Balloon**: VACÍA — se recarga durante el
  alta por UI.

## Callejones sin salida

- Alta de tenant por CLI con datos inyectados — VETADA por el owner, el
  tenant se purgó. No repetirla bajo ningún motivo.
- `baseUrl` con `/v1` en `platform_config.integration.fepy` → 404 (doble
  prefijo, el cliente ya agrega `/v1`).
- `PlatformConfig::set(..., 'session-system-82')` revienta — `updatedBy` es
  uuid, pasar `null`.
- `fiscal` jsonb: `-` con guiones encadenados falla en el wrapper de DB — usar
  `- ARRAY[...]::text[]`. `emitter` y `stamp` son NOT NULL — resetear con
  `'{}'::jsonb`, nunca `NULL`.

## Próximo paso

1. Confirmar con el owner que la Mac se recuperó.
2. Retomar el agente de dropdowns geográficos desde el worktree parcial
   (arriba) — default Asunción (dep 1 / dist 1 / ciudad 1).
3. Cola siguiente, UN agente a la vez (regla dura tras el incidente de Mac
   saturada):
   - tool `lookup_sifen_geo` para el bot (buscar código por nombre sobre el
     mismo JSON del catálogo).
   - rediseño UX completo del wizard de FE (pasos guiados, sin siglas,
     pre-llenado desde la sucursal, régimen pre-cargado en Contable/8,
     IdCSC default `0001`).
   - adjuntos multimodales del chat del bot: los PDF mueren con "Solo
     Excel/CSV e imágenes por ahora" en `processAttachment`
     (`frontend/lib/agent/use-agent-chat.ts:250`); las imágenes generan
     thumbnail pero NUNCA viajan al modelo (`handleSend` solo empaqueta
     tabulares) — falta pasar `files` en `sendMessage` + forzar modelo con
     visión cuando hay adjuntos (OpenRouter: DeepSeek sin visión, Gemini sí).
   - bug del bot: `get_einvoice_setup` dice "falta el RUC" con el RUC
     cargado — `read.get_settings.execute({})` en
     `frontend/lib/agent/einvoice-setup.ts:448` no trae `ruc`/`billingName`.
4. Coordinar con la sesión paralela "FE" (`local_49a34065-a306-48f5-9dad-1b856c9c1ece`)
   antes de tocar nada en FE-PY.
5. Deploy → el owner corre el alta 100% UI (régimen Contable, IdCSC `0001`,
   Asunción) → cert + CSC → verificar estado verde → ciclo de prueba ≤500 Gs
   (factura esperada 615, cancelación 0600), UN solo ciclo.

## Trampas conocidas

- `context/73-kude-propio.md` — confirmar que sigue listada en la tabla de
  `CLAUDE.md` si se retoca KuDE (ya estaba agregada al cierre anterior).
- Deploy del Front puede ir unos commits atrás de `main` si lo último fue
  API-only — verificar con `git log --stat` antes de asumir que sirve.
- Heredadas de cierres previos: sin backfill del histórico de fecha,
  Cloudflare "Block AI bots" desactivada a mano, flags de comercio
  system-wide sin scope por sucursal, `psql`/SSH a BD bloqueados por el
  classifier, `npx vitest` correr desde `frontend/`.
