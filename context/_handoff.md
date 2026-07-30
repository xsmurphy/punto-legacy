# Hand-off — 2026-07-30

> Este archivo se **reescribe entero** en cada `/end-session`. Describe el estado de la
> última sesión, no un historial. Para historial ver `_session-log.md`.

## Objetivo
Construir el módulo de facturación electrónica para Paraguay (SIFEN): que toda venta del POS emita automáticamente un documento fiscal electrónico válido, sin depender de la impresora local. Plan completo en `context/28-facturacion-electronica-plan.md` (no hace falta reescribirlo, está vivo y actualizado).

## Estado al cerrar
F0 (conexión de cuenta), F1 (emisión automática) y F2 (operación: listado, KuDE, cancelación, reconciliación) están **implementadas y verificadas contra la API real de DEV de Factomate** — no solo contra la guía. Se emitieron 2 facturas reales: correlativo 54 (rechazada por SIFEN, 1002 duplicado — esperado, era la de prueba) y 55 (**aprobada**, `FinalizadoOK`). Todo commiteado en `main` (`d7feed6d..774bd0d8`, 15 commits). **Nada de esto está deployado ni corrido en prod** — falta configurar env y correr migraciones.

## Archivos y cambios
- `context/28-facturacion-electronica-plan.md` — plan vivo, fuente de verdad del alcance/fases/decisiones.
- Migraciones 92, 93, 95 (pivot de proveedor Automate→Factomate, sin tocar la 92 original) — **no corridas en prod**.
- `CredentialVault` (cifrado AES-256-GCM) + `FactomateProvider`/`FactomateSession` — capa de integración.
- `SaleService` — outbox transaccional de emisión (reemplazó y eliminó `dispatchElectronicInvoice`).
- Drainer con CAS + endpoint `?action=drain` (secreto compartido) — necesita cron.
- `frontend/.../settings/facturacion-electronica` — página de conexión de cuenta, permiso `einvoice.manage`.
- Módulo `einvoicePy` en catálogo — campo nuevo `configHref` (botón "Configurar" navega en vez de abrir dialog).
- F2: listado `<DataTable>`, KuDE PDF, cancelación, reintento manual, reconciliación SIFEN.
- Mig 23 (taxId→tax.name) — fix del cálculo de IVA que corregía un bug que hubiera emitido todo exento.

## Callejones sin salida
- **La API real difiere de la guía de Factomate en casi todo.** No confiar en la guía para nombres de campos del payload de emisión — usar como fuente de verdad la implementación real de Automate en disco: `/Users/xstian/Dropbox/Automate/Agent/src/integrations/efatech/efatech.types.ts` y `.../src/services/billing/document-builder.ts`. Adivinar los nombres de campo desde los mensajes de error de la API falló 3 veces seguidas antes de encontrar esos archivos.
- **F0 se implementó primero contra Automate** creyendo que era el motor de FE — no lo es, es otro cliente de Factomate (el motor real). El pivot fue mig correctiva 95, no editar la 92 (no se pudo verificar `schema_migrations` en prod porque el classifier bloqueó `psql`).
- **Un CDC con `Success: true` no prueba que SIFEN aceptó**, ni que el KuDE se haya podido descargar (se descargó igual con la factura rechazada). Solo `sifen_status` es confiable.
- `GetAll` de Factomate devuelve `Items: []` incluso después de emitir — la reconciliación va por `getBulk/{id}`, no por el listado.
- Dos sub-agentes narraron sin tocar el disco (~256k tokens perdidos). Si un agente devuelve resumen sin archivos modificados, no insistir con esa instancia — lanzar una nueva con brief de un solo archivo y prioridad explícita.
- Se creó y se borró una migración 100 (índice ya cubierto por el prefijo del UNIQUE existente) — no reintentarla.
- `cp` bloqueado por el classifier: el manual de Factomate sigue en `~/Downloads/manual-tenant-abm (1).md`, no en el repo.

## Próximo paso
Configurar env y correr migraciones antes de cualquier otra cosa: `APP_ENCRYPTION_KEY` (`openssl rand -base64 32`), `FACTOMATE_BASE_URL_TEST`/`_PROD`, `EINVOICE_DRAIN_SECRET` + entrada de cron para el drainer, luego correr migs 92/93/95 en prod. **Sin `APP_ENCRYPTION_KEY` el módulo no arranca.**

## Trampas conocidas
- Las credenciales de DEV (RUC 80156424-7, "Bloom") **no están en el repo** — las tiene el owner, no commitearlas.
- Falta verificar una **cancelación exitosa** sobre un documento aprobado (solo se probó cancelar uno ya rechazado por SIFEN, que dio el error correcto 4002 CDC no existente).
- F3 (medios de pago, lookup RUC, notas de crédito), F4 (rip-out del FE legacy), F5 (offline diferido) y F7 (onboarding white-label) están bloqueadas o sin empezar — F5 espera respuesta de Factomate, F7 espera credencial admin de Punto + de dónde sale el `phonenumber`.
- 10 preguntas abiertas para Factomate, listadas en `context/28-facturacion-electronica-plan.md`.
- Dos agentes editaron `EInvoiceService.php` en paralelo durante esta sesión y chocaron — quedó resuelto y verificado a mano, pero si algo en ese archivo se ve raro, es de ahí.
