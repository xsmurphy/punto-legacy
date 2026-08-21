# 47 — Reportes personalizados, export y dashboards

> Estado (2026-08-21): **plan, sin implementar.** Pedido del owner el mismo
> día: unificar exportación puntual, reportes personalizados guardados y
> dashboards sobre un catálogo semántico único, con generación asistida por
> el agente IA existente (`context/17`). D1-D9 cerradas por el owner, NO
> relitigar. Falta: F0 (catálogo + ejecutor) es el primer trabajo real —
> nada de esto tiene sentido picar sin eso primero.

## Por qué

En un POS uno termina construyendo reportes al infinito y siempre falta el
que ese cliente puntual quiere — cada tenant quiere ver algo distinto de sus
datos. La prueba está en el código: `api/lib/Reports/` ya tiene **31
archivos**, de los cuales ~27 son servicios de reporte hechos a mano uno por
uno (el resto son helpers compartidos: `Roc.php`, `RollupReader.php`,
`SaleFilters.php`, `Taxonomy.php`). Ejemplos reales: `SalesService`,
`DashboardService`, `SummaryYearService`, `ProductsService`,
`CategoriesService`, `BrandsService`, `CustomersService`,
`OpenInvoicesService`, `PurchasesService`, `DrawersService`,
`CashflowService`, `ProductionService`, `StockService`, `StockDayService`,
`PaymentMethodsService`, `VPaymentsService`, `GiftcardsService`,
`FiscalService`, `OrdersService`, `UsersService`. Y la cola no se agota
nunca: cada tenant nuevo trae un corte distinto ("ventas por mesero",
"ticket promedio por franja horaria", "insumos que más se pierden por
merma") que ningún catálogo fijo de reportes hardcodeados anticipa.

## La tensión central

**Personalización vs. plug-and-play.** Cuanto más se deja configurar al
tenant, más trabajo de setup le cae encima antes de ver valor; cuanto más
cerrado el producto, menos cubre el pedido puntual que lo diferencia. El
owner la planteó así, textual, y **D3 es su resolución**: los dashboards NO
nacen en blanco, vienen con plantillas de fábrica que andan sin configurar
nada, y la IA parte de ahí para ajustar — nunca crea desde cero. Este
criterio gobierna toda decisión de UI del módulo: cualquier pantalla nueva
de este plan tiene que preguntarse primero "¿esto es plug-and-play por
default, con personalización opcional encima?" y no al revés.

## Pros y contras

**A favor:**
- Cubre la cola larga de reportes sin escribir un `XxxService.php` por
  pedido — el catálogo declarativo reemplaza el patrón "un archivo PHP por
  reporte" que hoy explica los 27 servicios de `api/lib/Reports/`.
- Diferenciador competitivo real: ningún POS de la categoría ofrece
  dashboards ajustables por chat.
- Palanca de upsell concreta (D6) sin inventar una feature nueva de cero —
  reusa la estructura de planes que ya existe en `/admin` (`plans`,
  `context/34`).
- La base de datos agregada (rollups, `context/18`) ya existe — no hay que
  construir la capa de performance desde cero, solo declarar sobre ella.

**En contra, y cómo lo mitiga el diseño:**
- *Trabajo de configuración para el tenant* → D3 lo neutraliza: el dashboard
  anda desde el minuto uno con plantillas de fábrica, la personalización es
  opcional y asistida por IA, no un requisito de onboarding.
- *Mantenimiento de dashboards guardados cuando cambia el catálogo/schema*
  → riesgo real, sin mitigación completa todavía (ver Preguntas abiertas);
  D1 al menos concentra el problema en UN solo lugar (el catálogo) en vez de
  N reportes hardcodeados divergentes.
- *Riesgo de números divergentes entre presentaciones* → es exactamente lo
  que D1 existe para prevenir: un motor, tres consumidores, mismo cálculo
  siempre. Los reportes legales/fiscales (RG90, Libro de Ventas,
  `FiscalService`) quedan **fuera** del catálogo a propósito — hardcodeados,
  exactos, auditables — así que el catálogo nunca reemplaza ni compite con
  ellos por el mismo número.
- *Costo de IA por generación* → D9 lo hace explícito y facturable: no es un
  costo oculto, se cobra igual que el resto del agente contra
  `ai_credit_ledger` (`context/09`).
- *Soporte más difícil cuando cada tenant tiene su propio dashboard* → D2
  (especificación declarativa, no SQL libre) hace que "qué está viendo este
  tenant" sea siempre un JSON legible y reproducible, no una query opaca que
  el modelo improvisó.

## Decisiones cerradas (D1-D9)

**D1 — Un solo catálogo de datasets, tres consumidores.** UNA capa
semántica (catálogo: ventas, ítems vendidos, stock, cuentas por
cobrar/pagar, compras, arqueos, producción, clientes…) con dimensiones,
métricas y filtros declarativos. Sobre el mismo catálogo se apoyan los TRES
casos: (a) exportación puntual Excel/PDF, (b) reportes personalizados
guardados, (c) dashboards. Un motor, tres presentaciones. Motivo: si el
dashboard y el reporte oficial calculan distinto, el número no coincide y se
pierde la confianza en todo el sistema.

**D2 — El LLM produce una ESPECIFICACIÓN, nunca datos ni SQL.** El agente
emite un JSON declarativo (dataset + filtros + agrupación + métricas +
formato) validado contra un schema; el backend lo ejecuta con el scope del
tenant y arma el archivo. Prohibido SQL libre generado por el modelo: fuga
multi-tenant garantizada, y un LLM redactando cifras produce reportes
contables inventados que parecen legítimos. Mismo criterio que ya cerró
`context/10` para el agente histórico ("tool calling determinista, nunca
text-to-SQL libre").

**D3 — Los dashboards NO nacen en blanco.** Vienen plantillas de fábrica
listas (Ventas, Clientes, Stock, Caja) que funcionan sin configurar nada —
plug-and-play. La IA NO crea desde cero: parte de una plantilla y la ajusta
por pedido ("agregale ticket promedio por mesero"). Menos superficie de
error, cero trabajo obligatorio para el tenant.

**D4 — Histórico desde rollup, hoy en vivo** (mismo criterio de
`context/18`). Advertencia real, no del owner sino relevada esta sesión: el
rollup HOY solo cubre los dominios `sales`, `expenses`, `returns`,
`drawer_expenses`, `item_sales` y `payments` (`api/database/migrations/postgres/41_report_rollup.sql`,
`rollupMarkDirty()` llamado desde `SaleService`, `SaleVoidService`,
`DrawerService`, `transactions.php`). Los dominios `purchases`, `orders`,
`stock_moves`, `production`, `commissions`, `vpayments` están en el plan de
`context/18` (RB-3) pero **no implementados todavía** — un dataset del
catálogo que dependa de esos dominios lee la tabla fact en vivo hasta que
RB-3 se complete (ver Riesgos).

**D5 — Generación en el BACKEND.** Excel con PhpSpreadsheet (librería nueva:
`api/composer.json` hoy NO tiene ninguna dependencia de Excel/PDF), PDF
reusando el motor de plantillas de impresión (`context/modules/18-impresion.md`)
bajo la misma regla de ese módulo — "lo que se imprime lo decide la
plantilla, no el renderer" — así que un reporte en PDF es una plantilla más,
no un renderer paralelo. El front sigue exportando XLSX del DataTable para
"bajar la tabla que estoy viendo" (`exceljs`/`xlsx` ya están en
`frontend/package.json`), pero el agente y los envíos programados necesitan
generación server-side porque no hay un browser con sesión ejecutándolos.

**D6 — Gating por plan (upsell).** Reportes básicos y plantillas de
dashboard: incluidos en todos los planes. En planes superiores: reportes
personalizados guardados, dashboards personalizados, envío programado,
export masivo. Se apoya en lo que ya existe en `/admin` (`context/34` F4):
tabla `plans` (módulos incluidos, créditos IA incluidos/mes, versionado no
retroactivo) + el mismo patrón de override por tenant que ya usan los
módulos nativos (`company.<key>` columna plana + JSONB
`config.moduleData[key].status`, double-write, `ModulesService::nativeKeys()`).

**D7 — Archivos se guardan y entregan por URL firmada con expiración**, no
inline en la respuesta (pueden ser grandes). Reusa el S3 que ya usa el
proyecto (`api/lib/Storage/S3Client.php`, ya consumido por
`PurchaseDraftService`, `ItemImageService`, `SettingsService`) — con una
salvedad relevada esta sesión: `S3Client` hoy solo sabe `put()` (con
`publicRead`) y `publicUrl()`, **no** genera URLs firmadas con expiración.
Sumar ese método (presigned GET, mismo algoritmo de firma que ya usa
`signedRequest()` para las requests salientes) es trabajo de F1, no una
pieza que ya exista.

**D8 — Todo export queda auditado**: quién, qué dataset, qué filtros,
cuándo. Datos del negocio saliendo del sistema.

**D9 — El consumo de IA de generar/ajustar un dashboard se cobra** contra
`ai_credit_ledger` (`context/09`), igual que el resto del agente. Ejecutar
un reporte ya guardado NO consume IA (es el motor, no el modelo) — solo
crearlo o modificarlo consume.

## Anatomía del catálogo de datasets

Lista inicial, anclada en lo que existe hoy en `api/lib/Reports/` y en los
dominios reales del rollup (`context/18`) — no genérica:

| Dataset | Dimensiones | Métricas | Fuente hoy |
|---|---|---|---|
| **Ventas** | fecha, `outletId`, `userId` (vendedor/mesero), `paymentType`, `categoryId`, `brandId`, `contactId` (cliente) | total, tax, discount, qty, cogs, cnt | `SalesService`, `DashboardService`, rollup `sales` |
| **Ítems vendidos** | `itemId`, `categoryId`, `brandId`, `outletId`, fecha | qty, total, tax, cogs, discount, comisión | `ProductsService`, rollup `item_sales` (`RollupReader::itemSalesRange`) |
| **Cuentas por cobrar/pagar** | `contactId`, `isCustomer`, fecha de vencimiento | saldo, total, pagado | `OpenInvoicesService` |
| **Compras** | `contactId` (proveedor), `itemId`, `outletId`, fecha | total, tax, qty | `PurchasesService` (rollup `purchases` NO implementado aún — ver D4) |
| **Arqueos / caja** | `registerId`, `userId`, turno, fecha | apertura, cierre, diferencia, movimientos | `DrawersService`, `CashflowService` |
| **Producción** | `itemId` (receta), `outletId`, fecha | cantidad producida, cogs, valor de merma | `ProductionService` (rollup `production` NO implementado aún) |
| **Stock** | `itemId`, `outletId`, fecha | qty, valorización, movimientos | `StockService`, `StockDayService`, `InventoryService` |
| **Clientes** | `contactId`, fecha | ranking, ticket promedio, frecuencia, total comprado | `CustomersService` |
| **Medios de pago** | `paymentType`, `outletId`, fecha | total, cnt | `PaymentMethodsService`, rollup `payments` |
| **Devoluciones** | fecha, `outletId`, `itemId` | total, cnt | `NonAddingSales`, rollup `returns` |

Todo dataset respeta el mismo modelo de scope que ya usa el resto del panel:
`Roc::build()` centraliza `AND companyId=… [AND outletId=…]`, y el
view-scope del selector de sucursal (`context/25-sucursales-y-scopes.md`
§1-2, header `X-Outlet-Id`, `VIEW_OUTLET_ID`) se aplica IGUAL sobre
cualquier dataset del catálogo — el catálogo no inventa un mecanismo de
aislamiento nuevo, hereda el que ya filtra los ~21 endpoints de reports
existentes.

## Shape del JSON de especificación

Ejemplo real: *"ventas por mesero del último mes, agrupado por día, en
Excel"*.

```json
{
  "specVersion": "1.0",
  "dataset": "sales",
  "dateRange": { "from": "2026-07-21", "to": "2026-08-21" },
  "scope": { "outletId": null },
  "filters": [],
  "groupBy": ["date_day", "userId"],
  "metrics": ["total", "cnt"],
  "sort": [{ "field": "date_day", "dir": "asc" }],
  "format": "xlsx"
}
```

El backend valida `dataset` contra el catálogo (enum cerrado), y
`groupBy`/`metrics`/`filters` contra las dimensiones y métricas declaradas
para ESE dataset — no acepta ningún campo que el catálogo no exponga.
`scope.outletId` se resuelve igual que view-scope: `null` usa el outlet del
JWT, `"all"` consolida, un UUID fuerza esa sucursal (validado contra
`companyId`). El LLM nunca ve ni elige `companyId` — lo fija el contexto de
auth, igual que las tools de lectura/escritura existentes del agente.

## Fases

- **F0 — Catálogo + ejecutor.** Registro declarativo de datasets (dataset →
  dimensiones/métricas/filtros válidos → mapeo a `RollupReader`/services
  existentes o query live), validador del JSON de especificación, motor que
  lo ejecuta con el scope de tenant (`Roc::build`). Sin generación de
  archivos, sin IA, sin UI. Todo lo demás se apoya acá — es el trabajo que
  hay que picar primero.
- **F1 — Generación de archivos + entrega + auditoría (D5, D7, D8).**
  PhpSpreadsheet (dependencia nueva) para Excel, motor de plantillas de
  impresión para PDF; sube a S3, genera URL firmada con expiración (sumar
  presigned GET a `S3Client`, hoy no existe); tabla de auditoría
  (quién/dataset/filtros/cuándo). Desbloquea: exportación puntual real
  (caso a).
- **F2 — Tool de exportación del agente (confirmToken).** Nueva tool de
  escritura sumada a las 8 existentes de `context/17` — recibe la
  especificación del LLM, la valida (F0), la ejecuta (F1) y devuelve la URL
  firmada. Confirmación previa porque es data del negocio saliendo del
  sistema (D8). Desbloquea: exportación conversacional ("mandame un excel
  de ventas por mesero del mes").
- **F3 — Dashboards de fábrica + UI de visualización (D3).** 4 plantillas
  plug-and-play (Ventas, Clientes, Stock, Caja); cada widget es una
  especificación F0 ya resuelta, guardada como config, renderizada en vivo
  (KPIs = definiciones, no valores cacheados). Desbloquea: dashboards sin
  requerir IA todavía — el tenant ve algo andando desde el día uno.
- **F4 — Personalización asistida por IA (D9).** El LLM parte de una
  plantilla F3 (o de un reporte guardado) y la ajusta por pedido, emitiendo
  una especificación F0 modificada — nunca crea desde cero (D3). Reportes
  personalizados guardados usan la misma mecánica sin renderizado en vivo de
  widgets. Consume `ai_credit_ledger`. Desbloquea: personalización real de
  dashboards + reportes guardados (caso b completo).
- **F5 — Envío programado.** Job que ejecuta una especificación guardada en
  un horario, genera el archivo (F1) y lo entrega (email/link). Desbloquea:
  reportes recurrentes sin acción manual.
- **F6 — Gating por plan (D6).** Feature flags sobre `plans`/
  `company.config`, mismo patrón que los módulos nativos. Desbloquea:
  monetización — básicos y plantillas en todos los planes, guardados/
  personalizados/programado/export masivo en planes superiores.

## Riesgos y preguntas abiertas

- **Dataset guardado que referencia un campo eliminado del catálogo** — si
  se quita/renombra una dimensión o métrica (ej. deja de existir una
  categoría custom, o un dataset cambia de shape), ¿el widget/reporte
  guardado falla silenciosamente, se deshabilita con aviso, o se migra
  automáticamente? Sin decidir.
- **Versionado del schema de especificación** — `specVersion` está en el
  ejemplo pero sin política: a medida que el catálogo crece (nuevos
  datasets/métricas), ¿cómo conviven specs guardadas viejas con un schema
  nuevo? ¿Migración perezosa al ejecutar, o versión congelada por spec?
- **Límites de export masivo** — PhpSpreadsheet arma el archivo en memoria
  del proceso PHP; un dataset de rango largo sin límite de filas puede
  tumbar el worker. Falta definir el techo (filas, tiempo de query) y qué
  pasa cuando se excede (¿fuerza async con notificación, rechaza con
  mensaje, trunca?).
- **Cobertura incompleta del rollup (D4)** — datasets de Compras, Producción
  y Stock dependen de dominios de rollup que `context/18` planificó pero no
  implementó (RB-3 pendiente). Hasta que se complete, esos datasets del
  catálogo leen la tabla fact en vivo — puede ser lento en rangos largos, y
  es la primera vez que ese gap le pega directo a una feature de cliente
  (antes solo afectaba reportes internos ya optimizados caso por caso).
- **Reportes legales excluidos, sin lista formal** — D1 dice que RG90/Libro
  de Ventas y afines quedan fuera del catálogo a propósito, pero no hay un
  inventario explícito de qué queda excluido y por qué, para que nadie
  intente "unificarlos" más adelante sin saber que la exclusión fue
  deliberada.
