# Estación de Impresión (pool) — plan (P0-P3)

Plan cerrado con el owner 2026-07-19. Decisiones abajo NO se relitigan.

## Problema

Hoy TODA la impresión es browser-side: el device que opera (POS) imprime por
sus propias conexiones (WebUSB/BT/red/window.print, `lib/hardware/printers/`).
Eso obliga a que la impresora esté físicamente alcanzable desde la tablet del
cajero. El caso "comanda sale en la impresora de Cocina, que está cableada a
una PC vieja en la cocina" no tiene solución.

## Decisiones del owner (2026-07-19)

1. **La estación es un ROUTER TONTO.** Una pantalla device-paired (mismo
   patrón KDS/display) corre en la PC que tiene las impresoras físicas
   (térmicas, matriciales, inkjet — USB, red, Bluetooth, lo que sea). En la
   estación NO se configura nada de negocio: ni templates, ni bindings, ni
   ruteo. Solo se vinculan las conexiones físicas. Toda la inteligencia
   (template, render, a qué impresora va cada doc) viaja EN el job o vive en
   panel/POS.
2. **Convive con browser-side, opt-in por impresora.** `printer_binding` gana
   `via` (`'local'` default | `'pool'`): local imprime como hoy; pool encola
   hacia una impresora de estación. Cajas chicas siguen igual; gastronomía
   con cocina remota usa el pool. Cero breaking change.
3. **Cola durable en BD** (decisión arquitectónica, no relitigar): un comando
   de cocina no puede perderse porque la estación estaba offline. `print_job`
   es la fuente de verdad; el WS solo notifica. La estación re-sincroniza
   pendientes al reconectar (mismo patrón REST inicial + WS del KDS).

## Modelo

```
POS/panel (origina)                 backend                    Estación (PC con impresoras)
─────────────────────               ───────────────            ─────────────────────────────
pipeline actual por                 INSERT print_job           WS {companyId}:print:{outletId}
categoryId/docType                  + wsPublish  ──────────►   claim CAS (queued→printing)
  ├─ via=local → dispatchBytes                                 dispatch a conexión física local
  └─ via=pool  → render (template                              (WebUSB/BT/red/window.print)
     del tenant, como hoy) →                                   → done | failed(+error, retry)
     POST enqueue con payload listo
```

- **El que origina renderiza.** El POS ya sabe renderizar ESC/POS y HTML con
  el template del tenant (`render-template.ts`, `html-renderer.ts`). El job
  lleva el payload terminado + formato; la estación jamás renderiza ni conoce
  templates. Envelope abierto: `format: 'escpos' | 'html' | 'raw'` (P3 agrega
  raster/ESC-P para inkjet/matriciales sin re-migrar).
- **La estación = el device.** No hay tabla `print_station`: la identidad de
  la estación es el `device` pareado con `module='print'` (extiende
  `DISPLAY_MODULES` de `api/v1/screens.php` y `DeviceModule` del front). Las
  impresoras físicas cuelgan del device.

## Schema (P0, mig 83)

- `station_printer` — impresora física registrada por una estación:
  `id, companyId, outletId, deviceId` (la estación), `name`,
  `kind` (`thermal|inkjet|matrix|generic`), `transport` (`usb|bluetooth|
  network|native`), `transportConfig jsonb` (vendorId/productId/host/port/…),
  `status`, timestamps. El registro nace DESDE la estación (ella descubre el
  hardware); el nombre se puede editar desde el panel.
- `print_job` — cola durable: `id, companyId, outletId, stationPrinterId,
  docType, format, payload text` (base64 para escpos/raw, HTML plano para
  html), `copies, openDrawer, status` (`queued|printing|done|failed|
  cancelled`), `attempts, lastError, sourceKind/sourceId` (transaction/order,
  opcional, para reimprimir/auditar), `createdByDeviceId`, timestamps.
  Transiciones con CAS (UPDATE … WHERE status='queued') — mismo criterio que
  `ORDER_TRANSITIONS`/KDS para no imprimir dos veces con dos estaciones.
- `printer_binding` + cols: `via VARCHAR NOT NULL DEFAULT 'local'`,
  `stationPrinterId UUID NULL`.

## Realtime

Canal `{companyId}:print:{outletId}` (patrón exacto de `kds`): evento
`job:new` al encolar. La estación además hace GET de pendientes al conectar y
en cada reconexión. `realtimePublish('printJob', …)` para invalidación
TanStack en panel (listado de jobs).

## Endpoints (P0)

- `api/v1/station-printers.php` — GET list (panel + pos-app + device print),
  POST register/update desde la estación (realm device, module print), PUT
  rename / DELETE desde panel.
- `api/v1/print-jobs.php` — POST enqueue (`apiAuthTenant(['panel','pos-app'])`),
  GET pending por estación + POST claim/done/failed (realm device module
  print, whitelist de transiciones — patrón `assertModuleCanSetStatus` de
  `orders-core.php`), GET list para el panel (auditoría/reimpresión).

Servicio: `api/lib/Printing/PrintPoolService.php` (namespace
`Punto\Api\Printing`), patrón `OrderCoreService.php`: `$companyId` explícito,
`StartTrans`/`CompleteTrans` en claim, `ncmExecute` para lecturas.

## Fases

- **P0 — backend core** (mig 83 + service + endpoints + WS + module 'print'
  en screens.php). Sin UI. Ejecuta Sonnet.
- **P1 — pantalla estación** `app/(screen)/print/page.tsx`: pairing (reusa
  `use-paired-screen`), wire de conexiones físicas (reusa transports de
  `lib/hardware/printers/`), registro de `station_printer`, consumo WS +
  claim + dispatch + done/failed, log local con reimprimir.
- **P2 — panel + POS**: `/settings/printers` gana `via` + selector de
  impresora de estación en el binding; el pipeline (`printSale`/
  `print-with-fallback`) rama pool → render + enqueue. Selector de módulo de
  invitación (`device-invite-create-dialog`) suma "Estación de impresión".
- **P3 — formatos extra** (fast-follow): raster/ESC-P para inkjet y
  matriciales. P0-P2 shippean `escpos` + `html` + `raw`.

## Reglas para la ejecución

- jsonb: PROHIBIDO el operador `?` en queries PDO — `jsonb_exists()`
  (memoria: migs 74/77 tiraron todos los deploys).
- `ncmExecute` DML devuelve filas afectadas reales; `forceObj=true` devuelve
  recordset (iterar `while(!$rs->EOF)`), no array.
- Commits de schema/migraciones → code-reviewer antes del commit final
  (workflow §3). Sub-agentes commitean `wip:` sin push; el main context
  revisa y cierra.
