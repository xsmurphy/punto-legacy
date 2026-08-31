# Hand-off — 2026-08-31 (4)

## Objetivo
Tres hilos que se enredaron en una sesión larga: (1) el owner pidió que el
asistente de la caja pueda escribir cambios simples (precios, stock, ítems)
sin que el cajero tenga que entrar al panel; (2) el teclado virtual del POS
seguía tapando inputs en iOS pese a dos rondas previas de fix; (3) limpieza
de reportes que fue apareciendo en el camino — Equipo vacío, Auditoría sin
fecha, secciones muertas, identificadores fiscales hardcodeados a Paraguay.

## Estado al cerrar
`main` en `ae34f580`, todo pusheado. Deploys verificados con el MCP de
Coolify (sin `in_progress` pendientes): Front en `5630c3d1` (deployment
`1oag5axpukdg1cnq2wdheqvv`, finished), Backend en `7e29907d` (deployment
`s3h28hg9pjem49yjfvptkmsv`, finished) — ambos cubren mis commits de código.
`79f6f2b8` y `ae34f580` son solo markdown, no requieren deploy.

## Archivos y cambios
- `api/lib/Ai/AgentActor.php` — nuevo. Identidad + permiso del operador del
  PIN para las DOS mitades (`confirm`/`execute`) de una escritura del agente.
- `frontend/app/(pos)/pos/**` — BFF propio del asistente (`/api/pos/agent/
  chat`), catálogo de tools recortado (`POS_TOOL_IDS`), UI del chat.
- `frontend/components/pos/keyboard-inset.tsx` + `viewport-probe.tsx` — miden
  contra `document.documentElement.clientHeight`, no `window.innerHeight`,
  en iOS PWA standalone. Test en `frontend/lib/pos/__tests__/keyboard-inset.
  test.ts` prohíbe restar de `innerHeight`.
- `frontend/app/(panel)/reports/audit/**` — fix de los 2 bugs de
  serialización (jsonb `meta`, claves lowercase de `AuditRow`).
- `frontend/app/(panel)/reports/customers/**` — reescrito en 3 tabs.
- `api/lib/Support/CountryDefaults.php` (nuevo) + `frontend/lib/contact-id-
  types.ts` — catálogo de identificadores fiscales por país (`c9dfc3cd`),
  reemplazó 3 `?? "RUC"` hardcodeados.
- `context/59-asistente-en-la-caja.md` — status actualizado a implementado
  (F1-F6), D9 sigue abierta.
- `CLAUDE.md` — fila de `context/59` actualizada a implementado.
- `context/62-dashboard-operaciones.md` — doc nuevo, plan sin OK del owner.

## Callejones sin salida
- **El teclado se arregló mal dos veces antes de acertar.** Rondas previas
  culparon el CONSUMO de `--kb-inset` (cada superficie que no la
  descontaba) y después la fórmula (`vv.offsetTop`). Ninguna era la causa:
  en iOS PWA `innerHeight` sigue al viewport visual, no al de layout. El
  síntoma es IDÉNTICO a "no hay teclado" — sin la sonda `?debug=viewport`
  capturando 441 vs 797 no se resolvía.
- **Se culpó al deploy sin verificar la hora, otra vez.** Capturas del owner
  de las 12:08 comparadas contra un deploy de las 00:20 — 12 horas antes, no
  después. Ya está anotado como trampa recurrente en hand-offs previos.
- **Un `git commit` sin `-o` arrastró un rename staged de otra sesión**
  (`McpKeyService`→`ApiKeyService`) al índice. En repo con sesiones
  paralelas, `git status` antes de commitear no es opcional.
- **`main` tuvo el typecheck roto** por un commit ajeno (`e49cccd5`) hasta
  `359f30dc` — nada se podía deployar mientras tanto.
- **`npx vitest` desde la raíz del repo** (no `frontend/`) tira 35 archivos
  fallando con "Cannot find package '@/...'" — no es bug, es directorio
  equivocado.
- **El MCP devolvía 401** durante parte de la sesión, probablemente por el
  rename de realm `mcp`→`api` de la sesión paralela — no se pudo verificar
  ningún reporte contra datos reales por esa vía.
- **`psql` contra prod y SSH a la BD siguen bloqueados por el classifier.**

## Próximo paso
Nada abierto que arrancar de una: lo que sigue depende del owner (ver
Trampas). Si retoma código, el punto natural es D9 de `context/59` —gate de
`OperatorAssertion` en `drawers.php`— para poder sumar `get_drawers` al
catálogo del asistente.

## Trampas conocidas
- **El asistente de la caja NO responde hasta que el tenant tenga créditos
  IA cargados** en `/admin` → Empresas → Créditos IA. El "Sin créditos" es
  literal (saldo 0), no un bug — la cadena de auth funciona.
- **`tenant_audit` atribuye las escrituras del asistente del POS al contacto
  que pareó la tablet, no al operador del PIN** — P1 de un code-review,
  pendiente en commit propio.
- **D9 de `context/59` sin implementar**: `/v1/reports/drawers` no scopea
  por caja y su GET no chequea permisos; `get_drawers` quedó fuera del
  catálogo del asistente como mitigación, no como decisión final.
- Pendiente de verificación del owner (sin acceso a datos reales): si
  `/reports/audit` trae filas tras el deploy, y si `/reports/users` (Equipo)
  ya muestra datos.
- Plan de compras en el POS (alcance ya cerrado por el owner: solo cargar,
  mismo impacto en stock que el panel) sigue sin escribirse.
- `context/62-dashboard-operaciones.md` es plan con D1-D9 propuestas SIN OK
  del owner — no asumir ninguna cerrada.
