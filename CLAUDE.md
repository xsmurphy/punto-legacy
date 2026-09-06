# Punto POS — Instrucciones para Claude

## Flujo de contexto — proporcional a la tarea

NO hay protocolo obligatorio "antes de cualquier trabajo". Buscá contexto
proporcional al riesgo de la tarea:

- **Trivial** (fix puntual en un archivo conocido, copy, comentario,
  rename): directo al código. Cero lectura previa.
- **Mediana** (cambio en un módulo): leé **UN solo doc** de `context/`
  según la tabla de abajo. Si pasa de 500 líneas, usá `Grep` para ubicar
  la sección + `Read` con `offset`/`limit`. NUNCA el doc entero.
- **Grande** (arquitectura, refactor multi-módulo, decisión de diseño):
  ahí sí, leé el doc completo + `_session-log.md` para continuidad.

**Si vas a retomar lo que quedó abierto**, leé `context/_handoff.md` PRIMERO:
es el estado de la última sesión (objetivo, qué quedó a medias, qué se intentó
y no funcionó, próximo paso). Se reescribe entero en cada cierre, así que
siempre describe el ahora — el `_session-log.md` es el índice histórico.

### Tabla de docs (en `context/`)

| Tema | Archivo |
|---|---|
| Producto / negocio | `01-producto.md` |
| Arquitectura, flujos | `02-arquitectura.md` (614 L) |
| Stack, versiones | `03-stack.md` |
| Schema, dominio | `04-modelo-de-dominio.md` |
| Módulos | `05-modulos-clave.md` |
| Infra, deploy, env vars | `06-infraestructura.md` |
| Glosario | `07-glosario.md` |
| **Convenciones críticas** | `08-convenciones-criticas.md` (241 L — invariantes nada más) |
| **Convenciones UI / Design system** | `14-ui-conventions.md` (lectura OBLIGATORIA antes de tocar JSX/TSX) |
| **Design system canonico (vivo)** | `20-design-system.md` (patrones de componentes, anti-patterns, changelog de decisiones) |
| Costos / créditos IA | `09-costos-y-creditos.md` |
| **Roadmap (vivo)** | `10-roadmap.md` (699 L) |
| Manual de marca | `11-design-system.md` |
| **Panel rewrite** | `12-panel-rewrite.md` (crítico desde 2026-06-10) |
| Plan refactor Items | `13-items-refactor-plan.md` |
| Plan espacios | `15-espacios-module-plan.md` |
| Rewrite POS (app-next) | `16-app-next-rewrite.md` |
| **POS roadmap (sprint 2026-06-21+)** | `19-pos-roadmap.md` |
| **Auth rewrite (JWT → tokens opacos)** | `21-auth-rewrite.md` (plan cerrado 2026-06-29) |
| **Sucursales, outlet scope, view-scope** | `25-sucursales-y-scopes.md` (consolidado = suma de sucursales asignadas al usuario en `contact_outlet`, cero filas = global; implementado en realm `api` y en `panel` 2026-09-02/03) |
| **Facturación electrónica (Factomate/SIFEN)** | `28-facturacion-electronica-plan.md`. **§F7 agregada 2026-09-06 — qué pasa cuando SIFEN RECHAZA**: R1 CERRADA por el owner (al cliente final NO se le avisa del rechazo — no tiene acción posible y expone el compliance del comercio a su propio cliente; la garantía va del lado del comercio). El rechazo hoy es VISIBLE pero no AVISADO. OJO: no existe 'reenviar' — `retry()` solo va desde `status='error'` y un rechazado es `issued`; reintentarlo emitiría el documento fiscal DOS VECES (Factomate no reemite, cada /Bulk es nuevo y el número lo pone la SET con `number => -1`). Se corrige METADATA FISCAL, nunca montos/ítems de una venta ya cobrada. Hallazgo: el portal del cliente ofrece descargar el KuDE de un documento RECHAZADO (`kudeAvailable` mira `status`, no `sifen_status`). Plazo legal de reemisión SIN confirmar. N0-N3 pendientes) |
| **KuDE y portal del cliente** | `49-kude-y-portal-cliente.md` (investigación normativa + plan; el ticket por defecto es comprobante interno NO fiscal, el KuDE se pone a disposición en el portal y se imprime a pedido — Decreto 872/2023 art. 25) |
| **uPay (ueno bank) — cobro desde el POS** | `50-upay.md` (**F1a implementada 2026-08-23** — pasarelas genéricas: `PspCatalog` + `ensurePspMethod` + `<PspQrDialog>` con adapter por PSP, ver §2.4 para sumar una. uPay en sí sigue bloqueado por F0: la doc de la API está detrás de login y faltan credenciales) |
| **Numeración fiscal + exclusividad de caja (MODELO CANÓNICO)** | `29-numeracion-y-exclusividad-de-caja.md` (punto de expedición sucursal+caja único por timbrado; el arriendo de bloques fue RECHAZADO — ver §6 arquitecturas rechazadas antes de proponer nada) |
| **`/admin` SaaS (dashboard, salud, planes, billing)** | `34-admin-saas-plan.md` (F1-F6 implementadas. **F7 agregada 2026-09-05 — el plan gobierna al tenant**, sin implementar: D1 CERRADA por el owner (el plan MANDA, se elimina el override de módulos por tenant — implica planes por RUBRO, costo asumido). Dos huecos verificados: `plans.features` es WRITE-ONLY (se edita y versiona, nadie lo lee — el gate real de módulos es el toggle por tenant) y `company.expiresAt` solo alimenta reportes: el login chequea SOLO `status='active'`, no hay job de vencimiento y `planExpired` se lee pero nadie lo escribe — **el trial de 14 días del signup NO vence nunca**. D2: las columnas `company.<key>`/`moduleData` NO se borran, quedan como PROYECCIÓN derivada porque el bootstrap del POS lee de ahí. P0 = MEDIR antes de flipear, o se apagan módulos en producción. **D5 CERRADA 2026-09-05**: al vencer, 5 días de gracia en SOLO LECTURA (consultar/exportar sí, vender no) y después bloqueo — o sea que la VENTA se corta el día del vencimiento, no al final de la gracia, lo que mueve todo el peso a los avisos previos (D7). **D7 y D8 CERRADAS**: avisos a los 7 y 3 días y al entrar en gracia; y —invariante que el corte NO puede romper— una venta encolada offline NUNCA se rechaza por cuenta impaga. OJO al implementar P3: `syncPendingOps()` (`lib/pos/pending-ops-sync.ts`) trata TODO fallo no clasificado como TERMINAL y lo `transient` muere igual a los 6 `OPS_MAX_ATTEMPTS` — un 403 'cuenta vencida' dejaría la venta en `failed`, trabaría el canal entero y le ofrecería al cajero el botón de descartar. La semántica correcta YA existe: `canSendPendingOp()` → `waiting` (no cuenta intentos, no escribe error, espera indefinidamente). Cuenta vencida ⇒ `waiting`, nunca `failed` ni `retry`. D6 propuesta sin OK. Ver §F7 arquitecturas rechazadas antes de proponer nada) |
| **Vínculos entre transacciones/órdenes (`transaction_link`)** | `35-transaction-link.md` (mig 115, implementado) |
| **Vouchers (vales por productos)** | `36-vouchers-plan.md` (F1/F2 implementadas 2026-08-07 — canje atómico dentro de la venta; F3 emisión desde caja pendiente) |
| **Numeración correlativa de documentos** | `37-numeracion-documentos.md` (F1-F3 implementadas, D3/D5/D6 pendientes) |
| **Impuestos multi-tasa / multi-país** | `38-impuestos-multi-pais.md` (F0-F3, F5 implementadas — F3 factura+ticket lee IVA congelado, F5 RG90/Libro Ventas; F4 rollup pendiente) |
| **Detalle de transacción (resolver canónico)** | `39-detalle-transaccion.md` (F1-F3 implementadas — resolver + página `/transactions/{id}` + cotizaciones/pagos recibidos; F4 migrar el POS, abierta) |
| **Anulación y nota de crédito** | `40-anulacion-y-nota-credito.md` (F1/F2/F5 implementadas 2026-08-21, D2/D3 implementadas en la devolución; F3/F4/F6 —numeración de NC como doctype propio + UI en `/pos`— pendientes) |
| **Reportes fiscales PY (RG90 / Marangatu)** | `46-reportes-fiscales-plan.md` (F5 de `context/38`; plan sin implementar, D1-D4 cerradas por el owner) |
| **Add-ons y combos** | `41-addons-y-combos.md` (F1-F5 implementadas, D1-D3 cerradas; F6 reportes y 2 gaps de F5 pendientes) |
| **Multi-moneda (ventas, compras, arqueo)** | `42-multi-moneda.md` (feature request, sin planificar) |
| **Remisión (traslado de mercadería)** | `42-remision.md` (implementada 2026-08-15 como documento interno. **Plan SIFEN agregado 2026-09-04**, D1-D5 PROPUESTAS sin OK del owner. **R0 EJECUTADO el mismo día contra DEV real**: tipo 7 existe en el catálogo de Factomate pero ningún tenant lo tiene provisionado; el timbrado se COMPARTE entre doctypes (lo que se crea por doctype es la fila BranchDocumentType, y eso ya lo hace nuestro EInvoiceProvisioningService — se extiende, no se pide a Automate); D6 CERRADA: la numeración fiscal la asigna la SET (number=-1) y la interna no se toca; catálogos geográficos se sincronizan de Factomate, no se seedean de la SET. Pendiente: shape del payload /Bulk tipo 7 (la guía solo documenta factura) y decidir contra qué punto de expedición sale una remisión emitida desde el PANEL. Lo estructural: el outbox `einvoice_document` se generaliza a `(source, sourceid)` —patrón `fin_movement`— porque hoy exige `transactionid` y una remisión no tiene transacción; NUNCA meter el remisionid disfrazado ahí. Datos de transporte en satélite 1:1 `document_remision_transporte`, mapper propio doctype NR, timbrado/serie por doctype, emisión como acción explícita. Ver §Arquitecturas rechazadas antes de proponer nada) |
| **Sync incremental del POS (reconexión/arranque, lápidas de borrado)** | `43-sync-incremental.md` (implementado 2026-08-16; arranque en frío usa bootstrap completo por decisión explícita, no es un pendiente) |
| **Listas de precio offline (motor espejo + bajada al bootstrap)** | `44-listas-de-precio-offline.md` (plan sin implementar, D0-D6) |
| **Ítem/contacto como raíces de sync (trigger genérico de satélites)** | `45-satelites-item-contact-sync.md` (implementado 2026-08-17, mig 139; generalizó el D1 de 44) |
| **Reportes personalizados, export y dashboards** | `47-reportes-personalizados-y-export.md` (plan sin implementar, D1-D10 cerradas por el owner — D10: Metabase solo interno; F0 catálogo+ejecutor es el primer trabajo) |
| **Escalamiento de datos (particionado, réplica, cierre de período)** | `48-escalamiento-de-datos.md` (plan, D1-D7 cerradas por el owner; E1 particionado, E1b cierre de período y D8 grano del rollup implementados 2026-08-22, migs 156/157/160) |
| **Configuración offline de la caja (cola de operaciones)** | `51-configuracion-offline-de-la-caja.md` (implementado 2026-08-23 — ajustes/hotkeys/impresoras/apertura y cierre sin red; regla de conflicto caja-vs-panel en §5, cierre a ciegas en §4) |
| **Stock: ledger única fuente de verdad** | `52-stock-ledger-unica-fuente.md` (plan cerrado 2026-08-24, en ejecución — D1-D7; el costo va CON IVA incluido a propósito; crecimiento = apertura por período + particionado patrón mig 156) |
| **Orden y stock (cuándo sale la mercadería del inventario)** | `53-orden-y-stock-reserva.md` (plan sin implementar, D1-D4 cerradas por el owner 2026-08-25 — hoy NINGUNA orden toca stock; F1 = "comprometido" derivado de órdenes abiertas + descuento al facturar, el descuento al despachar es interruptor por tenant. Ojo: `reserved` ya significa reserva de MESA. Ver §arquitecturas RECHAZADAS antes de proponer nada) |
| **Franquicias (franquiciador supervisa a sus franquiciados)** | `55-franquicias.md` (plan cerrado 2026-08-28, sin implementar — `franchiser_to_tenant` YA existe en prod, mig 08 + ADR-001: acceso N→N, NO propiedad ni billing. El franquiciador NO entra al panel del franquiciado: solo agregados desde los rollups. **D8 + F6 agregados 2026-09-03**: el acceso por MCP del franquiciador vive ACÁ, no en `context/58` — la key sigue siendo mono-tenant de realm `api` y lo que se suma son tools `punto_franchise_*` sobre el servicio de la F2; las `punto_get_*` del catálogo común NO se le exponen porque son lectura completa del tenant y contradicen el D3. Ver §6 arquitecturas rechazadas antes de proponer nada. **§8 es OTRO caso, agregado 2026-09-03: MULTI-EMPRESA** — un mismo dueño con varias empresas que necesita acceso COMPLETO, no supervisión. No comparte NADA con §1-7: se resuelve re-emitiendo la sesión hacia UNA empresa por vez, patrón que YA existe en `api/v1/active-outlet.php` y en el "entrar como empresa" de `/admin` (`PanelAuth::issuePanelSession()` ya toma el companyId del argumento). Dos bugs vivos que documenta: `findPhoneLogin()` cierra con `LIMIT 1` sin `ORDER BY` (login no determinístico si un teléfono de dueño se repite) y `SignupService` bloquea el alta de la segunda empresa. M1-M6 PROPUESTAS sin OK del owner) |
| **MCP server de Punto (conectar Claude u otro a los datos del tenant)** | `58-mcp-server.md` (plan sin implementar 2026-08-29 — D1-D3 cerradas por el owner, D4-D10 propuestas SIN su OK. El agente propio y el MCP NO se reemplazan: uno es la superficie de operación, el otro es Punto como fuente de datos en el flujo del cliente. Va contra la MISMA API con realm propio `api` (renombrado de `mcp` en la mig 182: la key sirve como API key común, MCP es solo su primer consumidor). Las 20 tools de lectura YA se extrajeron a `frontend/lib/agent/read-tools.ts` (catálogo compartido con el agente), así que la F0 de `context/47` dejó de ser prerequisito. Lo único que lo bloquea de verdad es cerrar los P2 de auth: M0 crea una credencial nueva sobre la misma `auth_session`. FE no lo bloquea — es prioridad, no dependencia. Tools con prefijo `punto_*` desde 2026-09-02 (pedido de Fock, solo en el transporte `route.ts`); el alcance por sucursal de la key sale del usuario dueño de la key, no de un outlet fijo — ver `context/25`) |
| **Asistente IA DENTRO del POS (POS-nativo)** | `59-asistente-en-la-caja.md` (**implementado 2026-08-31**, F1-F6: BFF propio con Bearer del device, catálogo de lectura recortado, y escritura vía `api/lib/Ai/AgentActor.php` sobre los permisos del OPERADOR del PIN — no del device — con `confirmToken` atado al actor. `frontend/lib/agent/read-tools.ts` sigue compartido con el MCP y no se tocó. Pendiente: D9 —`drawers` sin gate de operador, `get_drawers` excluido del catálogo como mitigación—. El P1 de atribución de `tenant_audit` quedó RESUELTO 2026-09-01 en el embudo: `api/lib/Auth/AuditActor.php`) |
| **Entrega digital del KuDE (email; WhatsApp diferido)** | `57-entrega-digital-del-kude.md` (plan cerrado 2026-08-29, sin implementar — D1: la entrega es obligación del COMERCIO, todo mecanismo customer-initiated queda descartado como canal principal. Solo email en esta iteración; el disparo es `sifen_status='Aprobado'`, NUNCA el cierre de la venta. WhatsApp evaluado y diferido en §6 — leerlo antes de reabrirlo) |
| **Cotización en PDF (documento para el cliente)** | `56-cotizacion-pdf.md` (plan cerrado 2026-08-28, sin implementar — NO sale del document builder: el motor de hoja no pagina. Documento propio con `@react-pdf/renderer`, generado bajo demanda y cacheado en S3) |
| **Sitio de marketing (punto.la)** | `61-sitio-marketing.md` (implementado 2026-08-31 — mismo container que el panel, ruteo por host en `middleware.ts`; capa de mercado en `lib/site/markets.ts` aísla todo lo que cambia por país; exportador `content/sitio/*.md` alimenta al agente de atención, corre en cada build) |
| **Finanzas: gasto devengado en el reporte por categoría** | `22-finanzas-module-plan.md` §10 (plan abierto 2026-09-03, D1 cerrada por el owner: el gasto se reconoce al COMPRAR, no al pagar. Tensión EXPLÍCITA con la §0 del mismo doc —caja simple— resuelta así: `fin_movement` sigue siendo caja, lo que cambia es de dónde lee el REPORTE. NO insertar un movimiento al comprar a crédito: debita una cuenta que no pagó y rompe saldos, flujo de efectivo y conciliación. Y OJO: hacer que `recordCreditPayment()` herede la categoría de la compra es el arreglo correcto bajo caja y un DOBLE CONTEO bajo devengado — está en §10.6 porque es la propuesta que sale sola al mirar el bug. Hoy la categoría y el centro de costo de una compra a crédito se PIERDEN al pagarse. D2-D5 propuestas sin OK) |
| **Balance y Flujo de efectivo (reportes gerenciales)** | `60-balance-y-flujo-de-efectivo.md` (implementado 2026-08-31 — foto de balance a HOY, no rango; flujo de efectivo excluye transferencias de ingresos/egresos pero sí mueve saldo por cuenta; NO es contabilidad de partida doble, es lo que un dueño mira para decidir; activos fijos no registrados, declarado explícito en la respuesta) |
| **Dashboard de operaciones (órdenes, cocina, salón)** | `62-dashboard-operaciones.md` (plan sin implementar 2026-08-31, D1-D9 PROPUESTAS sin OK del owner — el "mapa de calor de espacios" NO es geográfico: es el PLANO REAL del salón (`space.posx/posy/width/height/rotation`) con cada mesa pintada por su uso. La F0 es MEDIR LA CALIDAD DEL DATO, no dibujar: los tiempos de cocina existen solo si el personal marca los estados, y `sent → delivered` es una transición LEGAL que no cascadea a los ítems (`OrderCoreService.php:82`) — mismo problema que las coordenadas del mapa de clientes, se declara la cobertura en pantalla. Cuatro hallazgos que corrigen supuestos: `pos_order` NO tiene `ready_at`/`delivered_at`; no hay timestamp de `preparing` (la cola sale de `pos_order_event`); `pos_order.saletransactionid` fue dropeada (mig 115); `rollup_sales_day` es grano DÍA, no sirve para "por hora". Ver §arquitecturas rechazadas antes de proponer nada) |
| **Conteo de stock en la caja (relevo de turno)** | `63-conteo-de-stock-en-la-caja.md` (F0+F1 implementadas 2026-09-02 — conteo ciego en `/pos/conteo`, offline-nativo, permiso `pos.stock.count` contra el operador del PIN, migs 186/187. F2 conteo no ciego pendiente, depende de resolver el TODO `stock: null` en `reshape.ts`. Ver §arquitecturas rechazadas antes de proponer nada) |
| **Filtro de franja horaria en reportes** | `67-filtro-de-franja-horaria.md` (plan sin implementar 2026-09-01, D1 cerrada por el owner — son dos casos: un rango con horas dentro de un día YA FUNCIONA hoy vía `Date::reportRange()`, la franja horaria repetida a lo largo de varios días es la feature nueva, sin construir. Hallazgo: `EXTRACT(HOUR FROM transactionDate)` en `SalesService`/`DashboardService` ya sale en hora del tenant sin `AT TIME ZONE` explícito porque `TenantClock::apply()` fija la zona de la sesión de Postgres antes de la query. F0-F3 PROPUESTAS sin su OK) |
| **MCP de admin (salud de clientes SaaS)** | `64-mcp-admin-saas.md` (plan sin implementar 2026-09-01, D1 cerrada por el owner — expone estado/agregados del negocio SaaS (semáforo, MRR, churn, planes), NUNCA datos de negocio de los tenants; bajar al detalle de un tenant puntual queda fuera de alcance, es soporte con motivo declarado, no una API key. D2-D5 PROPUESTAS sin su OK. El dato YA ESTÁ CALCULADO (`TenantHealthService`, `AdminReportsService`) — falta el transporte. Distinto del MCP de TENANTS en `context/58`, que ya está en producción. Ver §Arquitecturas rechazadas antes de proponer nada) |
| **Seeder de datos demo (`/admin`)** | `65-seeder-de-datos-demo.md` (plan sin implementar 2026-09-01, D1 cerrada por el owner — el seeder solo puede apuntar a cuentas `company.isinternal=1`, verificado server-side, sin flag de override. D2-D4 PROPUESTAS sin su OK: sembrar por los servicios reales (`SaleService::save()`, `FinanceLedger`, `DrawerService`), nunca INSERT directo; mecanismo de reversión sin resolver (marca JSONB vs. acotar por rango+tenant); ejecución vía tabla-cola `demo_seed_job` + proceso CLI separado disparado desde `maintenance.php`, porque `run_sale_chain.php` documenta que COMPANY_ID/OUTLET_ID son constantes PHP de un solo proceso y no puede correr inline en la request del botón. El timbrado NO se fabrica — es una acción fiscal real, el seeder solo verifica y avisa. Ver §Arquitecturas rechazadas antes de proponer nada) |
| **Onboarding conducido por el agente** | `66-onboarding-conducido-por-el-agente.md` (plan sin implementar 2026-09-01, D1-D2 cerradas por el owner — ventas NO, el resto (usuarios/roles/cajas/sucursales/catálogo) SÍ; auditoría debe registrar el PEDIDO en palabras del cliente + el plan + quién confirmó, no solo el efecto. BLOQUEANTE LEVANTADO 2026-09-01: la atribución de `tenant_audit` se arregló en `apiAuthTenant()` vía `api/lib/Auth/AuditActor.php` — F0 implementada. La confirmación en bloque y el fallo-parcial-sin-rollback YA existen (`register_action` con `actions[]`, `execute.php` reporta ok/error por acción) — no son mecanismo nuevo. Ver §Arquitecturas rechazadas antes de proponer nada) |
| **Brand kit para redes sociales** | `68-brand-kit-social.md` (kit para Claude Design y herramientas externas de diseño, NO es doc de UI del producto — colores en HEX derivados de los tokens OKLCH de `globals.css` porque las herramientas de diseño no comen oklch; en social el verde Punto `#01D7A1` es PROTAGONISTA, al revés que en el producto donde está prohibido como `--primary` y queda para acentos. Incluye escala tipográfica por formato (1080×1080 / 1080×1350 / 1080×1920), uso del logo, voz y tono extraídos de `content/sitio/*.md`, y 5 plantillas de posteo. Precio/moneda/`{docFiscal}` van como placeholders de `markets.ts`, nunca literales. Si cambian los tokens del design system hay que regenerar los hex) |
| **Contexto del negocio para el asistente** | `69-contexto-del-negocio.md` (plan sin implementar 2026-09-02, D1 cerrada por el owner — el comercio carga TEXTO LIBRE, no campos estructurados: la alternativa con enums/taxonomía de rubros se le presentó y la descartó a sabiendas de que contradice la regla vigente "nunca texto libre llega al system prompt". Mitigación que NO recorta la decisión: el bloque va DESPUÉS de los guardrails, delimitado y marcado como DATO no-instrucción. El prompt está DUPLICADO en `app/api/agent/chat/route.ts` y `app/api/pos/agent/chat/route.ts` — se extrae `lib/agent/business-context.ts` compartido, no se copia. NO se le pide al comercio nada que la BD ya tenga (categorías/sucursales/moneda/país: el agente los lee con tools). Sin migración: clave `agentBusinessContext` en `company.config` JSONB. D2-D7 PROPUESTAS sin su OK. Ver §6 arquitecturas rechazadas antes de proponer nada) |
| **Viandas: pedidos, producción por lote, reposición y cobro a cuenta** | `70-viandas.md` (plan sin implementar 2026-09-03, D1-D6 cerradas por el owner, P1-P5 propuestas SIN su OK — CORRIGE el roadmap: el comprobante ES la venta (boleta a crédito: reconoce ingreso del día, crea cuenta por cobrar, sin valor fiscal, no toca caja); la factura mensual lo fiscaliza y aporta CERO ingreso nuevo. Reposición ≠ compra: la necesidad es la entidad, compra/transferencia/producción la cubren. El motor de explosión de recetas YA agrega — falta el lote multi-plato. Orden de compra + recepción es la etapa B.5 y sirve al negocio entero. Ver §Arquitecturas rechazadas antes de proponer nada) |
| **Captura masiva de facturas de compra por foto** | `71-captura-masiva-facturas-compra.md` (plan sin implementar 2026-09-06 — el pipeline de OCR YA EXISTE en producción (`context/32`, `purchase_draft` mig 105): lo que falta es la CAPTURA, no el procesamiento. **D1 CERRADA y corrige la premisa original: el canal es la PWA de Punto, NO un bot de Telegram** — razones del owner, las tres de producto: adopción de la app propia, control de la calidad de la foto y control de los límites de cantidad/tamaño. Telegram quedó **evaluado y descartado en §6** con todo lo que arrastraba (webhook entrante que no existe en el repo, tabla `chat_id`→usuario que tampoco, códigos de pareo, revocación, secreto del bot, y refactor de `PurchaseDraftService` que hoy exige `$_FILES`+sesión de panel) y con lo único que daba de más: reenviar el PDF que el proveedor mandó por chat. Si se reabre es como SEGUNDO canal sobre el mismo `purchase_draft`, nunca pipeline propio. D2-D4: vínculo por USUARIO no por comercio (evita el borrador sin autor, mismo bug que `recordEvent` 2026-09-05), revocable como una sesión, y bot sin IA si alguna vez lo hay. **F0 = prerequisito de seguridad**: `purchases.php:21`/`purchase-drafts.php:30` no tienen permission key dedicada (`context/modules/08-compras.md:45`) y este plan multiplica quién crea borradores — se cierra ANTES. Cómo se cobra el procesamiento queda ABIERTO para el owner (§5). Ver §7 arquitecturas rechazadas antes de proponer nada) |
| **Cómo funciona cada módulo (y qué asume de los otros)** | `modules/_index.md` + un doc por módulo — LEER el del módulo que vas a tocar ANTES de integrarte con él |
| **Hand-off de la última sesión** | `_handoff.md` (se reescribe cada cierre) |
| Bitácora de sesiones | `_session-log.md` (índice histórico, append) |

> Items completados / docs superseded archivados en `_archive-*.md` (no se leen en uso normal).

### Archivos prohibidos para Read entero

NO leer estos archivos con Read sin `offset`/`limit` — los chunks
explotan el contexto:

- `context/_archive-convenciones-detalladas.md` (1697 L) y `context/_archive-roadmap-completado.md` (1058 L) — son archives, no contexto vivo; Grep solo si necesitás referencia histórica

---

## Reglas del proyecto (críticas)

1. **Templating: Alpine.js, NO Mustache.js.** Todo template/UI nuevo se hace con
   Alpine (`x-data`/`x-for`/`x-if`/`x-text`/`x-html`). Prohibido crear nuevos
   templates Mustache. Patrón Alpine: `context/08-convenciones-criticas.md` §24.

2. **Marca: "Punto", NO "ENCOM".** No introducir "ENCOM" en código/UI/datos
   nuevos. El rename del nombre BD (`encomdb`), claves de permisos
   (`permissions.encom.*`) y archivos de imagen requieren coordinación infra
   (no es find-replace ciego).

3. **No hardcodear dominios/URLs.** Deben venir de `simple.config.php` →
   `$_ENV[...]` (`APP_URL`, `API_URL`, etc.). CORS es security-sensitive:
   cualquier cambio debe preservar la allowlist actual como fallback.

4. **Design system — shadcn default, NO copiar legacy visual.** Cualquier JSX/TSX
   nuevo en `frontend/` respeta `context/14-ui-conventions.md`: tipografía
   canónica (`h1=text-2xl font-semibold`, etc.), componentes shadcn sin
   sobreescribir tamaños sin razón documentada, `<DataTable>` para listados
   largos, `<EmptyState>` para vacíos, sin hex colors (excepto pedidos
   explícitos), formatos vía helpers, sin emojis. **Screenshots del legacy son
   referencia funcional, NO visual.** El brief de un sub-agente debe leer §14
   antes de tocar JSX y FLAGEAR en el reporte si el brief contradice la regla.

5. **Soluciones arquitectónicas, NUNCA parches** (regla global en `~/.claude/CLAUDE.md`).
   Casos típicos en este codebase donde la respuesta correcta es el wrapper, no
   el call-site: `CaseInsensitiveArray` del DB layer (RecordsetIterator en
   `app/Database/Query.php`), doble prefix `/api/api` (api-client baseUrl), Bearer
   faltante en `/api/pos/*` (lib/api/pos-fetch.ts), `registerId=''` en realm
   panel (guard en bootstrap.php). Si aparece un bug similar a alguno de estos,
   atacar la raíz, no agregar un parche más.

---

## Workflow de Git

**Branch por subproyecto cuando hay sesiones paralelas; `main` para todo lo demás.**

Cuando una sesión va a tocar exclusivamente `frontend/` (o `api/`) y hay
riesgo de que otra sesión esté tocando algo en paralelo, trabajá en una
branch dedicada del subproyecto. Esto evita stomp entre sesiones y simplifica
el merge.

> **El POS vive dentro de `frontend/` en `app/(pos)/pos`** (fusión 2026-06-16).
> El subproyecto `app-next/` fue eliminado — su contenido se movió al panel.
> Ya NO existen branches `app-next/*`.

Convención de nombres:
- `frontend/<slice>` — ej. `frontend/pos-fusion`, `frontend/team-crud`
- `api/<feature>` — solo para refactors grandes de `/api` PHP compartida
- Cualquier otra cosa (fixes triviales, docs, hooks, settings) → directo en `main`

Reglas:
1. **Una branch toca UN subproyecto.** Si necesitás un cambio cross-cutting
   (ej. `frontend/` Y `api/` a la vez de forma acoplada), hacelo en `main`.
2. **`api/`** y **`context/`** se pueden tocar desde la branch del subproyecto
   que los necesita (ej. una branch `frontend/*` puede modificar
   `api/v1/bootstrap.php` si su feature lo requiere — declaralo en el commit).
3. **`code-reviewer`** solo en commits de alto riesgo: schema/migrations,
   auth/JWT, admin realm, aislamiento multi-tenant, billing/pagos,
   hard-delete, CORS, permisos. Trivial (UI/copy/1-archivo): skip.
4. **Commit inmediato** dentro de la branch — no acumular cambios sin commitear.
5. **Push inmediato de la branch a remoto** — sirve de respaldo y permite que
   el owner mire el diff en GitHub mientras la sesión sigue.
6. **Excepción `wip:`**: commits con prefix `wip:` saltean reviewer (NO push).
7. **Merge a `main`** al cierre de sesión (o cuando el slice cierra), con
   `git merge --no-ff` desde main para preservar la historia del slice. Borrar
   la branch local + remota tras el merge.
8. **`context-updater` NO se invoca** post-commit. La bitácora se mantiene
   manualmente con `/end-session` al cierre (UNA llamada por sesión, no
   por commit). El `_session-log.md` se actualiza desde `main` post-merge,
   no desde la branch — así dos sesiones paralelas no compiten por ese archivo.

---

## Deploy — lo disparás VOS con el MCP de Coolify

El auto-deploy por push está APAGADO en Coolify. Cada commit gatillaba un build
completo (varios minutos) y se encolaban en serie: una sesión de 10 commits
dejaba el último cambio esperando más de una hora, y un build colgado bloqueaba
a todos los que venían atrás.

**Flujo**: pusheá las veces que haga falta; deployá UNA vez por tanda coherente.

- Deploy: `mcp__coolify__deploy` con `uuid: "<APP_UUID>"`.
- Estado: `mcp__coolify__get_deployment` con el `deployment_uuid` que devolvió el
  deploy — `status` pasa de `queued`/`in_progress` a `finished`/`failed`.
- Historial de una app: `mcp__coolify__list_deployments` con `application_uuid`.
  SIN ese parámetro devuelve solo los activos de todo el equipo; con él,
  el historial completo — así se verifica un deploy sin entrar por SSH.
- Salud tras el deploy: `mcp__coolify__list_applications` (trae `status`, ej.
  `running:healthy`), y también los UUID de las apps.

### Las tres apps de Punto salen del MISMO repo

Un push a `main` no actualiza nada por sí solo, y cada app se deploya aparte.
Mirá qué tocó la tanda:

| App | UUID | Se deploya si tocaste |
|---|---|---|
| Punto Front | `nzmay2ytcdup3sgylspq39z6` | `frontend/` |
| Punto Backend | `z645wx54kwtcciczaeoldwvc` | `api/` (incluye **migraciones**) |
| Punto WebSockets | `sji3nm6ze583d9ykm0e8gsc6` | `ws-server/` |

Una tanda que tocó `frontend/` Y `api/` necesita DOS deploys.

**Cuándo deployar**

- Cuando la tanda está completa y verificada (build y typecheck en verde).
- SIEMPRE antes de cerrar la sesión.
- En el momento, si el usuario necesita probar algo puntual ya.

**Un deploy a la vez.** Antes de encolar, verificá que no haya otro corriendo
para esa app: `mcp__coolify__get_deployment` sobre el último `deployment_uuid`,
o `mcp__coolify__list_deployments` con `application_uuid`. Si el status es
`queued` o `in_progress`, ESPERÁ a que termine — no encoles encima. Los builds
se ejecutan en serie y compiten por CPU del server: encolar sobre uno vivo no
adelanta nada, alarga los dos (los del Front pasan de ~7 a ~10 min) y deja al
usuario esperando un cambio que ya estaba listo. Es la misma razón por la que
el auto-deploy está apagado.

Corolario: si mientras esperás llegan más commits, mejor — un solo deploy
levanta toda la tanda. Encolá recién cuando el anterior diga `finished`.

**Nunca termines una sesión con commits pusheados sin deployar.** Sin deploy no
hay código nuevo en producción — y como las migraciones corren al arranque del
contenedor del backend, tampoco están aplicadas. La próxima sesión va a asumir
que lo que está en `main` es lo que está corriendo. Si por algo no se pudo
deployar, decilo explícito en el cierre y anotalo en `_handoff.md`.

**Verificá que subió**, no lo des por hecho: el deploy queda encolado y puede
fallar. `get_deployment` hasta `status: "finished"` y después
`list_applications` para confirmar `running:healthy`. Los builds del Front
tardan ~6-8 min; los del Backend ~1.

El SSH sigue sirviendo si hace falta ver la imagen que corre, pero no es
necesario para verificar un deploy:

```bash
ssh root@167.71.165.221 'docker ps --format "{{.Names}}\t{{.Image}}" | grep -E "nzmay|z645"'
```

---

## Subagentes (`.claude/agents/`)

Invocá solo cuando matchee claramente la descripción del agente:

| Agente | Cuándo |
|--------|--------|
| `code-reviewer` | Commits de alto riesgo (ver Workflow §1) |
| `codebase-orchestrator` | Refactors multi-archivo con riesgo de regresión |
| `postgres-pro` | Optimización queries/índices, replicación, schema design |
| `typescript-pro` | TypeScript avanzado (frontend) |
| `react-specialist` | React 18+ en frontend |
| `Explore` | Búsqueda exploratoria ≥ 3 queries |
| `Plan` | Planificación de tareas no-triviales |

## Skills

Invocá proactivamente solo si el trigger es claro. Para el día a día,
las más relevantes son:

- `engineering:debug` — stack traces, errores prod, divergencias
- `engineering:code-review`, `simplify` — review pre-commit
- `engineering:architecture`, `engineering:system-design` — decisiones grandes
- `claude-api` — código que importa `anthropic` SDK
- `security-review` — auditoría de seguridad del branch
- `shadcn` — frontend, componentes UI
- `end-session` — cierre: entry en `_session-log.md` + `_handoff.md` reescrito

El resto (operations, design, documentos, enterprise-search) solo si la
tarea lo pide explícitamente.
