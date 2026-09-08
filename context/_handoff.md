# Hand-off — 2026-09-08 (continuación)

## Objetivo

Vivo, textual del owner: **"lo que quiero es que el bot pueda configurar 100%
la facturación electrónica"** — el operador carga la constancia de RUC (PDF)
y el domicilio, y el agente resuelve todo lo demás; lo único que sigue
cargándose a mano es el certificado `.p12` y el CSC (secretos fiscales, no
viajan por chat). El catálogo geográfico SIFEN es un paso de ese objetivo,
no un fin en sí mismo.

## Estado al cerrar

`main` = `8accf37b`, **sin deployar**. Trae mergeado el catálogo geográfico
fiscal (mig 207: `geo_department`/`geo_district`/`geo_city`, endpoint
`api/v1/geo.php`, UI en cascada en el alta de establecimiento) pero
**sincronizado contra la fuente EQUIVOCADA (Factomate)** — Punto ya no usa
Factomate, el motor vigente es FE-PY propio (https://fepy.punto.la). Los
catálogos no coinciden (Factomate 6419 ciudades vs SIFEN/FE-PY 6766): un
código válido en uno puede no existir en el otro, y el que valida el XML es
FE-PY. **No deployar `main` tal cual** — pondría en prod un sync contra una
API que no se usa.

Hay un agente corriendo AHORA en el worktree
`.claude/worktrees/geo-sifen-y-bot-fe` (branch `frontend/geo-sifen-y-bot-fe`)
corrigiendo esto. Dos piezas en el mismo brief:

1. **Fuente correcta.** Reemplaza Factomate por SIFEN: trae el extractor +
   `sifen-geo.json` (18 depto / 272 distrito / 6766 ciudad) del worktree
   parado `agent-aa0fb08127953c581` a ubicaciones de servidor, crea
   `SifenGeoSource`, borra `FactomateGeoSource.php` y los métodos de geo en
   `FactomateProvider.php`, migración que pasa `source` de `'factomate'` a
   `'sifen'`, revisa el cron semanal (un seed versionado no necesita
   sync). Tiene instrucción de frenar y reportar si algún tenant en prod ya
   guardó códigos con numeración Factomate.
2. **Bot configura FE solo.** Tool `resolve_geo_codes` en
   `frontend/lib/agent/read-tools.ts` (compartido panel+MCP): nombre de
   ciudad → códigos, match insensible a acentos por `searchname`, homónimos
   devuelven TODAS las candidatas (el bot pregunta, nunca elige). Reescribe
   `frontend/lib/agent/confirm-tool.ts:139`, cuyo `.describe()` hoy le
   ORDENA al bot pedirle los códigos al usuario — es lo que bloquea que
   configure solo. Revisa el resto de `provision_einvoice` con el mismo
   criterio (lo que esté en la constancia de RUC que el bot ya lee, que lo
   extraiga en vez de preguntarlo).

**No interrumpir ese agente ni tocar esa branch/worktree.**

## Worktrees vivos — cuáles sirven

- `frontend/geo-sifen-y-bot-fe` — el que importa, en vuelo (arriba).
- `agent-aa0fb08127953c581` (branch `worktree-agent-aa0fb08127953c581`,
  parado en `e6a52d64`) — **queda CONSUMIDO por la pieza 1 de arriba**
  (extractor + JSON). Una vez que `frontend/geo-sifen-y-bot-fe` mergea, este
  worktree ya no sirve y se puede borrar.
- `agent-aa6a0986b93c2240e` (branch `frontend/catalogo-geografico`, parado en
  `740c2766`) — el trabajo del agente que construyó el catálogo contra
  Factomate. Ya mergeado a `main` (`8accf37b`) antes de detectar el error.
  Descartable una vez confirmado que no queda nada suyo por rescatar.

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

1. Esperar a que termine el agente en `frontend/geo-sifen-y-bot-fe` (pieza 1
   fuente SIFEN + pieza 2 `resolve_geo_codes`/`confirm-tool.ts`).
2. Revisar el diff completo (no solo el reporte del agente) — en particular
   que la migración de `source` deje el catálogo consistente y que no haya
   tenants en prod con códigos Factomate (si el agente reportó que SÍ los
   hay, resolverlo ANTES de mergear).
3. Mergear a `main`, cerrar el worktree consumido
   (`agent-aa0fb08127953c581`) y el ya mergeado
   (`agent-aa6a0986b93c2240e`).
4. Deploy — DOS: Backend `z645wx54kwtcciczaeoldwvc` (toca `api/` +
   migraciones) y Front `nzmay2ytcdup3sgylspq39z6` (toca `frontend/`). Un
   deploy a la vez, verificar `finished` antes del siguiente.
5. Con el bot pudiendo resolver códigos geográficos y leer la constancia de
   RUC, retomar el resto de `provision_einvoice` con el mismo criterio: lo
   que ya se puede leer, que se deje de preguntar.

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
