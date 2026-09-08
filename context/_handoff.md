# Hand-off — 2026-09-08 (continuación)

## Objetivo

Vivo, textual del owner: **"lo que quiero es que el bot pueda configurar 100%
la facturación electrónica"** — el operador carga la constancia de RUC (PDF)
y el domicilio, y el agente resuelve todo lo demás; lo único que sigue
cargándose a mano es el certificado `.p12` y el CSC (secretos fiscales, no
viajan por chat). El catálogo geográfico SIFEN es un paso de ese objetivo,
no un fin en sí mismo.

## Estado al cerrar

`main` = post-merge de `frontend/geo-sifen-y-bot-fe`. El catálogo geográfico
fiscal quedó COMPLETO y sobre la fuente correcta, y el bot ya no le pide
códigos numéricos al usuario.

Cómo se llegó acá, porque el error importa más que el resultado: el catálogo
se mergeó primero (mig 207, `8accf37b`) **sincronizado contra Factomate**, que
Punto ya no usa — el motor vigente es FE-PY propio (https://fepy.punto.la).
Los catálogos no coinciden (Factomate 6419 ciudades vs SIFEN/FE-PY 6766) y el
que valida el XML es FE-PY. Peor: la extracción SIFEN correcta YA EXISTÍA en
un worktree parado que el hand-off anterior mencionaba, y no se leyó antes de
lanzar el agente. **Lección: leer `_handoff.md` ANTES de lanzar un agente
sobre un tema que quedó a medias.**

Lo que corrigió el merge siguiente:

1. **Fuente SIFEN.** Seed versionado `api/database/seeds/sifen-geo.json`
   (18 depto / 272 distrito / 6766 ciudad) extraído de `constants.service.ts`
   de FE-PY con `scripts/extract-sifen-geo.mjs`. Carga al boot del container
   (`api/docker-entrypoint.sh` → `seed_geo_catalog.php`, upsert con
   `unnest()`, 7056 filas en 0,19s, idempotente); el cron semanal salió —un
   seed del repo cambia con el deploy, no los domingos—. `FactomateGeoSource`
   borrado y los métodos de geo sacados de `FactomateProvider`. Mig 208: las
   filas de Factomate quedan `active=FALSE` (no se borran: un código ya
   guardado tiene que poder resolverse a su nombre) y `providerid` dropeada.
   Verificado en prod ANTES de tocar: cero códigos geográficos guardados, las
   tablas `geo_*` ni existían.
2. **`resolve_geo_codes`** en `frontend/lib/agent/read-tools.ts` (compartido
   panel+MCP): nombre de ciudad → códigos, insensible a acentos por
   `searchname`. **666 nombres de ciudad están repetidos** en el catálogo
   ("SAN ANTONIO" 35 veces en departamentos distintos), así que ante
   homónimos devuelve TODAS las candidatas con `resolved: null` y el bot
   pregunta — elegir una sería declarar el domicilio fiscal en el
   departamento equivocado. `confirm-tool.ts` reescrito: se eliminó la
   instrucción que le ORDENABA pedirle los códigos al usuario, y `regimeId`
   y `taxpayerType` ahora viajan con su lista de valores (el modelo leía
   "Régimen Contable" y no tenía cómo saber que es el 8).

Verificación: build `✓ 18.5s`, `tsc --noEmit` limpio, arnés
`run_geo_catalog_test.sh` 31/31, `code-reviewer` sin P0 ni P1. Las 6 fallas de
vitest son idénticas en `main` y preexistentes.

**Lo que el bot todavía pide a mano** (y por qué, para no "arreglarlo"):
`.p12` y CSC por pantalla (mandato: secretos fiscales no van por chat); el
email de facturación si no figura en la constancia (alguien tiene que leer esa
casilla); y `regimeId`, que el bot PROPONE con su fundamento pero pide
confirmar — la constancia lista obligaciones y no siempre las nombra con las
palabras de SIFEN.

## Worktrees — todos descartables

Los tres worktrees de agentes quedaron consumidos y se pueden borrar:
`geo-sifen-y-bot-fe` (mergeado), `agent-aa0fb08127953c581` (su extractor y
JSON viven ahora en `scripts/` y `api/database/seeds/`) y
`agent-aa6a0986b93c2240e` (el catálogo contra Factomate, mergeado y después
corregido).

## Archivos y cambios (commits `87ac7f33..8accf37b`, 14)

- `ad5741cf` — **fix crítico**: nadie podía registrarse, el endpoint de
  signup tiraba `termsAccepted` antes de llegar al servicio.
- `571b7412` — eliminado bypass `?debug` de seguridad + handler JSON que
  devolvía la excepción cruda al cliente.
- `c1ac80e3`/`b0add9ce` — adjuntos del agente: PDF/imagen llegan al modelo,
  visibles en el hilo, drag&drop en `/chat`, tope total de archivos.
- `c2890fb6` — fix `/admin/ai` Probar decía `OPENROUTER_API_KEY no
  configurada` con la clave presente.
- `87f7feba` — bienvenida del panel centrada, copy dice qué es Punto.
- `ac7ab2b2`/`e7b2af5e`/`c77fb8ae`/`a6ce4b7f` — `context/74`: saldo a favor
  elevado a módulo Wallet multi-nivel, D1 (dos modos de facturación) y D2
  (jerarquía titular/sub-cuenta) cerradas por el owner. Sin implementar.
- `348bbcf7`/`740c2766`/`8accf37b` — catálogo geográfico (ver arriba, fuente
  equivocada, en corrección).

## Callejones sin salida

- **Sincronizar el catálogo geográfico desde Factomate** — Punto ya no usa
  Factomate como motor de FE (es FE-PY propio). Se construyeron ~20 min de
  sync/cron/arnés antes de que el owner corrigiera. Causa raíz: no se leyó
  este mismo hand-off (versión anterior) antes de lanzar el agente — ya
  documentaba que existía trabajo con la fuente correcta parado en un
  worktree. **Lección: leer `_handoff.md` ANTES de lanzar un agente sobre un
  tema que quedó a medias**, no confiar en el resumen que uno recuerda.
- **Reset completo de FE de Balloon Party** — se mapeó el estado y se
  entregó el script (borrar `einvoice_account` destruye la credencial
  cifrada de Factomate, irrecuperable). El owner decidió NO hacerlo. El
  estado de FE de ese tenant queda como lo dejó la sesión anterior (ver
  "Trampas conocidas" de la entrada previa, heredadas abajo).
- Heredados de cierres previos (siguen vetados, no repetir): alta de tenant
  por CLI en FE-PY — VETADA, el tenant se purgó; `baseUrl` con `/v1` en
  `platform_config.integration.fepy` → 404 (doble prefijo).

## Próximo paso

1. **Probar el alta de FE conversando con el bot** — es el objetivo vivo.
   Mandarle la constancia de RUC de Balloon Party en PDF y ver hasta dónde
   llega solo: RUC, actividades, tipo de contribuyente, domicilios y códigos
   geográficos deberían salir sin que el operador tipee un número. Anotar
   TODO lo que siga preguntando de más.
2. Con eso medido, seguir podando `provision_einvoice` con el mismo criterio:
   lo que ya se puede leer, que se deje de preguntar.
3. Borrar los tres worktrees de agentes (todos consumidos, ver arriba).

## Trampas conocidas (heredadas, siguen vigentes)

- **Cambios A MANO en prod, no están en git**:
  - `platform_config` key `integration.fepy`: `{keyEnc: <API key de FE-PY
    cifrada>, baseUrl: "https://fepy.punto.la"}` (SIN `/v1`).
  - Balloon Party (`companyid 01a067cb-8fff-72cd-bb12-0b483dcb7dbf`)
    `einvoice_account` reseteado a cero desde la sesión anterior
    (`provider='fepy'`, `provider_tenant_ref=NULL`, `status='provisioning'`)
    — columnas de Factomate INTACTAS como fallback. Custodia local de
    cert/CSC VACÍA, se recarga en el alta por UI.
  - FE-PY prod: tenant CLI `01a080c1-a13f-75fa-82f4-7ce2b20a606a` PURGADO.
    Company de Punto: `01a08081-413a-7025-b64e-9f3bd112e2c2`. RUC
    `3595193-1` libre. Numeración objetivo FE=614/NC=2 (próximos: 615/3).
  - Códigos geográficos de los dos establecimientos de Asunción de Balloon
    Party: departamento=1 (CAPITAL), distrito=1, ciudad=1.
- `context/73-kude-propio.md` y `context/74-wallet-multinivel.md` ya están en
  la tabla de `CLAUDE.md`.
- Deploy del Front puede ir unos commits atrás de `main` si lo último fue
  API-only — verificar con `git log --stat` antes de asumir que sirve.
- Más antiguas: sin backfill del histórico de fecha, Cloudflare "Block AI
  bots" desactivada a mano, `psql`/SSH a BD bloqueados por el classifier,
  `npx vitest` correr desde `frontend/`.
