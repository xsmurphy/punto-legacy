<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

use DB; // wrapper PDO en namespace global, en `app/includes/lib/DB.php`
use Punto\Api\Context\TenantContext;
use Punto\Api\Documents\DocumentNumber;
use Punto\Api\Items\AddonService;
use Punto\Api\Items\Exceptions\InvalidAddonSelectionException;
use Punto\Api\Sales\Exceptions\DuplicateInvoiceNumberException;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;
use Punto\Api\Tax\TaxEngine;

/**
 * SaleService — guardado de ventas del POS (slice 35 del desacople de /app).
 *
 * Strangler-fig del handler monolítico `processData` en `app/action.php`.
 * Cubre los paths de venta migrados incrementalmente; los no migrados siguen
 * en el legacy.
 *
 * Sub-slices:
 *   35a — venta simple (cashsale type=0, creditsale type=3): sin gift card,
 *         sin schedule, sin EI, sin parent, sin recurring. ~80% del tráfico.
 *   35b — Electronic Invoice (factura electrónica PY/PE/AR).
 *   35c — Gift card sale + redemption.
 *   35d — Schedule auto-create (items con duration > 0).
 *   35e — Devolución (type=6) + pago de créditos (type=5).
 *   35f — Recurring sales (type=8) + cleanup del processData legacy.
 *
 * Estado actual: **35a.2** — B1 (parse + dupli check + StartTrans) + B3
 * (construcción del record + INSERT transaction). Los bloques B4-B16 entran
 * en sub-slices 35a.3-35a.6 (toTaxObj, toAddress, toTag, itemSold loop,
 * loyalty, emails/auditoría).
 *
 * Convención §22.9: final + readonly + DI por constructor + DTOs tipados +
 * excepciones custom. Helpers del legacy (saleArraySanitizer, countUnitSold,
 * flipOnReturn) se llaman como funciones globales — deuda registrada.
 */
final class SaleService
{
    private \Punto\Api\Services\TransactionLinkService $links;

    /** F3 add-ons (context/41): revalidador server-side de las selecciones. */
    private AddonService $addons;

    public function __construct(
        private readonly TenantContext $ctx,
        private readonly DB $db,
    ) {
        $this->links  = new \Punto\Api\Services\TransactionLinkService();
        $this->addons = new AddonService();
    }

    /**
     * Guarda una venta. Idempotente por `transactionUID`.
     *
     * @throws DuplicateSaleException Si el UID ya existe (el endpoint devuelve 200 con duplicated=true).
     * @throws SaleAbortedException   Si la transacción de PG falla (el endpoint devuelve 500).
     */
    public function save(SaleInput $input): SaleResult
    {
        // ── B1: idempotencia — la cola offline puede reenviar el mismo UID ────
        // (action.php:1944) Si el UID ya existe en `transaction`, devolvemos
        // duplicate (200); el front marca el UID como sincronizado y no reintenta.
        // El INSERT también tiene safety-net contra race condition vía UNIQUE
        // constraint en transactionUID (capturado en doInsertTransaction).
        $dupRow = $this->db->Execute(
            'SELECT transactionId FROM transaction WHERE transactionUID = ? LIMIT 1',
            [$input->uid]
        );
        if ($dupRow && !$dupRow->EOF) {
            throw new DuplicateSaleException(uid: $input->uid);
        }

        // ── B1: validar clientId pertenece al tenant (anti-IDOR) ────────────
        // El front puede mandar cualquier UUID en `client`. Sin validar, la venta
        // quedaría vinculada a un cliente de OTRA company → leak cross-tenant.
        // El legacy no validaba (deuda original); acá lo cerramos.
        if ($input->clientId !== null) {
            $client = $this->db->Execute(
                'SELECT contactId FROM contact WHERE contactId = ? AND companyId = ? AND type = 1 LIMIT 1',
                [$input->clientId, $this->ctx->companyId]
            );
            if (!$client || $client->EOF) {
                throw new InvalidSaleInputException(
                    "client {$input->clientId} no existe o no pertenece al tenant"
                );
            }
            // NO validar acá `contactCreditable` (regla base de arquitectura
            // del POS, owner 2026-08-16, context/08 §53): la venta ya se
            // EMITIÓ — se validó y se imprimió en el dispositivo, offline o
            // no. El backend recibe una venta ya emitida y la GUARDA; no
            // puede rechazarla, porque la mercadería ya salió y el cliente ya
            // se fue. Si esto vuelve a fallar, el bug no es "falta el gate
            // acá" — es que `isCreditable` no llegó bien al cache local del
            // POS (`reshapeCustomer`, `frontend/lib/pos-bff/reshape.ts`) o el
            // gate de `pay-dialog.tsx` no lo está leyendo. Ese es el único
            // lugar correcto: valida contra el cache local, así que funciona
            // también sin conexión.
            //
            // Este bloque SÍ se queda: es integridad/anti-IDOR (el cliente
            // existe y pertenece al tenant), no una regla de negocio sobre
            // una venta ya emitida.
        }

        // ── B1: normalizar items del sale + computar totales ────────────────
        // saleArraySanitizer aplica markupt2HTML por campo y castea floats —
        // shape canónico que después leen toTaxObj/itemSold/manageStock.
        // F2a (context/38): enrichWithTaxes ya NO solo resuelve taxRate — corre
        // el TaxEngine server-side y congela taxId/taxRate/taxKind/taxIncluded/
        // taxAmount/taxNet por línea. $decimals sale de la config del tenant y
        // se reusa para las sumas de transactionTax/toTaxObj más abajo, así
        // no hay redondeos divergentes entre el detalle y los agregados.
        // F3 (context/41): expandAddonSelections corre ENTRE el sanitizer y el
        // motor de impuestos — revalida las selecciones contra la BD y agrega
        // las líneas hijas. Va acá, ANTES de StartTrans, para que un rechazo
        // (422) no deje una transacción abierta a medias (mismo criterio que
        // el dupli check y la validación del cliente, arriba).
        //
        // F6 (context/41 → reportes, 2026-08-19): expandCompoundSelections
        // corre DESPUÉS — mismo motivo y mismo lugar en la cadena — para que
        // la receta de un combo FIJO (`item_compound`) también deje líneas
        // hijas en `itemSold` con su propio impuesto congelado. Antes de esto
        // el combo se persistía como UNA sola línea y ProductsService no
        // tenía de dónde sacar el desglose ("Componentes de combos", reporte
        // del tester). Ver el método para el porqué de precio=0 y por qué NO
        // duplica el descuento de stock del combo.
        $decimals   = $this->currencyDecimals();
        $saleDetail = $this->enrichWithTaxes(
            $this->expandCompoundSelections(
                $this->expandAddonSelections(saleArraySanitizer($input->sale), $decimals),
                $decimals
            ),
            $input->ivaRemoved,
            $decimals
        );
        $totalUnits = countUnitSold($saleDetail);

        // ── B1: resolver userId + responsibleId ──────────────────────────────
        // (action.php:1935) Si el user del request ≠ el del JWT, registramos
        // el JWT como responsable (quien realmente operó la caja).
        $userId        = $input->userId ?? $this->ctx->userId;
        $responsibleId = ($userId !== $this->ctx->userId) ? $this->ctx->userId : null;

        // ── B1: congelar el timbrado de la caja (mig 145) ────────────────────
        // Mismo criterio que enrichWithTaxes: se resuelve ANTES de StartTrans
        // (es una lectura pura, no necesita estar dentro de la tx) y se
        // persiste el valor resuelto en el record — nunca se vuelve a leer
        // `register.data->>'registerInvoiceAuth'` para esta venta. Si el
        // timbrado de la caja cambia mañana, este documento sigue mostrando
        // (y siendo comparado contra) el timbrado con el que se emitió.
        [$invoiceAuth, $invoiceAuthStart, $invoiceAuthExpiration] = $this->resolveFrozenInvoiceAuth();

        // ── B1: abrir transacción ──────────────────────────────────────────
        $this->db->StartTrans();

        // ── B3: construir record de `transaction` ──────────────────────────
        $record = $this->buildTransactionRecord(
            input:                  $input,
            saleDetail:             $saleDetail,
            totalUnits:             $totalUnits,
            userId:                 $userId,
            responsibleId:          $responsibleId,
            decimals:               $decimals,
            invoiceAuth:            $invoiceAuth,
            invoiceAuthStart:       $invoiceAuthStart,
            invoiceAuthExpiration:  $invoiceAuthExpiration,
        );

        // ── B3: INSERT principal de la venta ────────────────────────────────
        $insertOk = $this->db->AutoExecute('transaction', $record, 'INSERT');
        $transId  = $this->db->Insert_ID();

        // ── B4 + B6 + B7: relaciones (toTaxObj / toAddress / toTag) ─────────
        // Solo si el INSERT principal funcionó (evita FK violations en cascada
        // que ensuciarían el error real). Corren dentro de la misma transacción.
        if ($insertOk !== false && !empty($transId)) {
            $this->persistRelations($input, (string) $transId, $saleDetail, $decimals);

            // ── B8: itemSold + COGS + comisiones + manageStock (inventario) ──
            $this->persistItemsAndStock($input, (string) $transId, $saleDetail);

            // ── B10 (35c.1): redención de gift card — debita el saldo usado ────
            $this->persistGiftCardRedemptions($input);

            // ── F2 vouchers (context/36-vouchers-plan.md): consumir los vales
            //    canjeados en el carrito — DENTRO de esta misma transacción, no
            //    fire-and-forget post-commit (lección de T1, 1f9c8f97, y del
            //    consume de gift cards: si corriera después y fallara, la venta
            //    quedaría cobrada con un vale que sigue disponible para reusar).
            $this->persistVoucherRedemptions($saleDetail, (string) $transId);

            // ── B10 (35e): débito de points y storeCredit del cliente ─────────
            // (manageCustomerLoyalty/StoreCredit 'used' vía helpers legacy dentro
            //  de la tx; se saltan si no hay cliente).
            $this->persistBalanceRedemptions($input);

            // ── B10: loyalty EARNED (cash/card; points/storeCredit/giftcard NO
            //         ganan puntos — mismo guard que el legacy) ────────────────
            $this->persistLoyaltyEarning($input);

            // ── B12 (35f): venta recurrente — crea fila en `recurring` ────────
            // Solo para creditsale (type=3); cashsale no tiene recurrente (el
            // legacy lo gatea con in_array(['creditsale','schedule'])). Dentro
            // de la tx: si falla, rollbackea con la venta.
            $this->persistRecurring($input, (string) $transId);

            // ── B9 (35g): packs de servicios — crear sold_pack por cada pack vendido ──
            // POST-COMMIT best-effort: si no hay cliente (clientId null), no podemos
            // crear el sold_pack (contactId NOT NULL). Se loguea y omite sin fallar
            // la venta. El sold_pack se crea DENTRO de la tx para garantizar
            // atomicidad (pack creado ↔ venta persistida).
            if ($input->clientId !== null) {
                $this->persistPackSales($input, (string) $transId, $saleDetail);
            }

            // ── F1 facturación electrónica: enqueue transaccional ──────────────
            // Dentro de la MISMA transacción de la venta: si la venta rollbackea,
            // el documento encolado rollbackea con ella (nunca queda un outbox
            // huérfano apuntando a una venta que no existe). best-effort: un
            // fallo acá (cuenta mal configurada, etc.) NUNCA aborta la venta —
            // reemplaza al hook legacy dispatchElectronicInvoice, que vivía
            // post-commit y dependía de sendFE/FACTURACION_ELECTRONICA_TOKEN
            // (proveedor de FE anterior, retirado entero en F4).
            $this->enqueueElectronicInvoice($input, (string) $transId);
        }

        // ── B11: cerrar la transacción ──────────────────────────────────────
        // (Los bloques B10 loyalty/storeCredit entran en sub-slice 35a.5, antes
        // de este cierre.)
        $dbError = $this->db->ErrorMsg();
        $failed  = $this->db->HasFailedTrans();
        $this->db->CompleteTrans();

        // ── Verificación post-commit (CRÍTICA, §22.8.1) ─────────────────────
        // El wrapper hace pdo->commit() siempre que transOk; pero un COMMIT sobre
        // una tx PG ABORTADA (cualquier statement falló sin FailTrans) hace ROLLBACK
        // silencioso y devuelve true. HasFailedTrans() no lo refleja. Sin esta
        // verificación, una venta que rolleó reportaría success → plata/inventario
        // fantasma. Confirmamos con un SELECT que la fila realmente persistió.
        $persisted = false;
        if ($insertOk !== false && !empty($transId)) {
            $check = $this->db->Execute(
                'SELECT transactionId FROM transaction WHERE transactionId = ? LIMIT 1',
                [(string) $transId]
            );
            $persisted = $check && !$check->EOF;
        }

        if ($failed || $insertOk === false || empty($transId) || !$persisted) {
            // mig 145 — choque REAL de numeración: (companyId, registerId,
            // timbrado, invoiceNo) ya existe bajo OTRO transactionUID. Chequear
            // ANTES del 23505 genérico de abajo: ambos violan una UNIQUE y
            // devuelven el mismo SQLSTATE, pero significan cosas opuestas — este
            // es un comprobante duplicado real (nunca "éxito silencioso"), no un
            // reintento del mismo evento.
            if ($dbError !== '' && str_contains($dbError, 'uq_transaction_expedition_invoiceno')) {
                throw new DuplicateInvoiceNumberException(
                    registerId: (string) $this->ctx->registerId,
                    invoiceNo:  $input->invoiceNo,
                );
            }
            // Safety-net contra race condition: si dos requests concurrentes pasan
            // el dupli check (UNIQUE en `transactionUID` previene el doble INSERT),
            // el segundo cae acá con SQLSTATE 23505. Lo convertimos a duplicate
            // para que la cola offline reciba 200 y marque el UID, no 500.
            if ($dbError !== '' && str_contains($dbError, '23505')) {
                throw new DuplicateSaleException(uid: $input->uid);
            }
            throw new SaleAbortedException(
                dbError: $dbError !== '' ? $dbError : null,
                message: 'Sale transaction aborted (no persistió tras commit)',
            );
        }

        // ── B14 + B15: notificaciones (email/SMS al cliente + auditoría + e-gift) ──
        // POST-COMMIT, BEST-EFFORT: la venta YA está confirmada en BD. Nada acá
        // puede afectarla — todo wrapeado, los fallos se loguean y se ignoran.
        $this->dispatchNotifications($input, (string) $transId, $saleDetail);

        // Rollup: marcar el día de la transacción sucio (best-effort).
        try {
            $rollupDomains = match ($input->type) {
                \Punto\Api\Sales\SaleType::Return => ['returns', 'item_returns'],
                \Punto\Api\Sales\SaleType::CashPurchase, \Punto\Api\Sales\SaleType::CreditPurchase => ['expenses'],
                \Punto\Api\Sales\SaleType::CreditPayment => ['payments'],
                default => ['sales', 'item_sales', 'payments'],
            };
            \rollupMarkDirty((string) $this->ctx->companyId, $rollupDomains, $input->date);
        } catch (\Throwable $e) {
            error_log('[SaleService] rollupMarkDirty: ' . $e->getMessage());
        }

        // Finanzas Fase 3: auto-poblado del ledger, best-effort — nunca rompe la
        // venta ni el sync offline. Único punto de verdad: antes vivía solo en
        // api/v1/sales.php, lo que dejaba afuera api/v1/offline-sync.php (la ruta
        // real del POS) → 0 ingresos en Finanzas. Centralizado acá cubre ambas.
        try {
            (new \Punto\Api\Finance\FinanceLedger())->recordSale((string) $this->ctx->companyId, (string) $transId);
        } catch (\Throwable $e) {
            error_log('[SaleService] FinanceLedger::recordSale falló para ' . $transId . ': ' . $e->getMessage());
        }

        // Realtime best-effort, scope 'dashboard' — mismo motivo que
        // FinanceLedger arriba: api/v1/sales.php y api/v1/offline-sync.php
        // corren con apiAuthPosContext(), que NO pasa por
        // apiAuthTenant()/realtimeAfterMutation() (bootstrap.php), así que sin
        // este publish explícito el dashboard del panel nunca se enteraba de
        // una venta hecha desde el POS (caso de uso 2 de context/15). scope
        // 'dashboard' porque el POS mismo debe seguir ignorando sus propias
        // ventas — no es ruido que el cajero necesite (context/15, hallazgo B).
        try {
            realtimePublish('transaction', 'create', (string) $transId, 'dashboard');
        } catch (\Throwable $e) {
            error_log('[SaleService] realtimePublish falló para ' . $transId . ': ' . $e->getMessage());
        }

        // F6 — link del portal de consulta del comprador para imprimir en el
        // comprobante. null cuando la venta no encoló documento (comercio sin
        // facturación electrónica, autoIssue apagado): el bloque `fe_py` de la
        // plantilla queda en blanco, como hasta ahora.
        $portalUrl = null;
        try {
            $portalUrl = (new \Punto\Api\EInvoice\EInvoiceService())
                ->portalUrl((string) $this->ctx->companyId, (string) $transId);
        } catch (\Throwable $e) {
            error_log('[SaleService] portalUrl: ' . $e->getMessage());
        }

        return SaleResult::created(
            transactionId:     (string) $transId,
            uid:               $input->uid,
            einvoicePortalUrl: $portalUrl,
        );
    }

    /**
     * B14 + B15 — side effects post-commit (BEST-EFFORT, nunca lanzan).
     *
     * La venta ya está commiteada y verificada cuando se llama esto. Cada
     * sub-acción va en su propio try/catch para que un fallo (Mailgun/SMS/audit
     * sin configurar, constante undefined, etc.) no bloquee las demás ni rompa
     * la respuesta de la venta. En dev típicamente no hay infra de email/SMS →
     * fallan en silencio, que es el comportamiento correcto.
     */
    private function dispatchNotifications(SaleInput $input, string $transId, array $saleDetail): void
    {
        // B14: recibo/factura al cliente (email + SMS) — solo cashsale/creditsale.
        try {
            $this->notifyCustomer($input, $transId);
        } catch (\Throwable $e) {
            error_log('[SaleService] notifyCustomer: ' . $e->getMessage() . "\n", 3, './error_log');
        }

        // B14 (35c.2): e-gift card — email/SMS al BENEFICIARIO de las gift cards
        // con fecha de envío = hoy. Movido a post-commit (el legacy lo hacía inline
        // en la tx, action.php:2331 — un curl lento bloqueando la transacción).
        try {
            $this->notifyGiftCardBeneficiaries($saleDetail, $input);
        } catch (\Throwable $e) {
            error_log('[SaleService] notifyGiftCard: ' . $e->getMessage() . "\n", 3, './error_log');
        }

        // F1 facturación electrónica: intento de emisión inline. El documento ya
        // fue encolado DENTRO de la transacción (ver save() — enqueueElectronicInvoice);
        // acá solo se intenta emitirlo YA para no depender del cron del drainer en el
        // caso feliz. Reemplazó al hook legacy dispatchElectronicInvoice/sendFE,
        // retirado entero en F4 — no convive con la solución nueva (regla de
        // arquitectura del repo: nunca parche + solución final compitiendo).
        try {
            $this->tryIssueElectronicInvoiceInline($input, $transId);
        } catch (\Throwable $e) {
            error_log('[SaleService] tryIssueElectronicInvoiceInline: ' . $e->getMessage() . "\n", 3, './error_log');
        }

        // B15: registro de auditoría (FACTURACION).
        try {
            $this->sendAudit($input);
        } catch (\Throwable $e) {
            error_log('[SaleService] sendAudit: ' . $e->getMessage() . "\n", 3, './error_log');
        }
    }

    /**
     * F1 — encola el documento de facturación electrónica DENTRO de la
     * transacción de la venta (ver save(), antes de CompleteTrans). Solo
     * cashsale/creditsale (FC/FCR) — devoluciones (NC) quedan para F2.
     * `EInvoiceService::enqueueForSale` es best-effort puertas adentro (silencioso
     * si no hay cuenta 'ok', autoIssue off, o onlyWithTaxId sin RUC/CI) pero
     * cualquier excepción inesperada acá SÍ podría abortar la venta si no se
     * atrapa — se envuelve en try/catch para que un bug en el módulo de FE
     * nunca tumbe una venta.
     */
    private function enqueueElectronicInvoice(SaleInput $input, string $transId): void
    {
        $doctype = match ($input->type) {
            SaleType::Cashsale   => 'FC',
            SaleType::Creditsale => 'FCR',
            default              => null,
        };
        if ($doctype === null) {
            return;
        }
        try {
            (new \Punto\Api\EInvoice\EInvoiceService())->enqueueForSale(
                $this->ctx->companyId,
                $transId,
                $doctype,
                $input->clientId,
            );
        } catch (\Throwable $e) {
            error_log('[SaleService] enqueueElectronicInvoice: ' . $e->getMessage() . "\n", 3, './error_log');
        }
    }

    /** F1 — intento de emisión inline post-commit (ver dispatchNotifications). */
    private function tryIssueElectronicInvoiceInline(SaleInput $input, string $transId): void
    {
        $doctype = match ($input->type) {
            SaleType::Cashsale   => 'FC',
            SaleType::Creditsale => 'FCR',
            default              => null,
        };
        if ($doctype === null) {
            return;
        }
        (new \Punto\Api\EInvoice\EInvoiceService())->tryIssueInline($this->ctx->companyId, $transId, $doctype);
    }

    /**
     * B14 (35c.2) — e-gift card: notifica al beneficiario (email + SMS) cuando la
     * gift card tiene fecha de envío = HOY. Port de action.php:2331-2375. BEST-EFFORT
     * post-commit: la venta ya está confirmada; un fallo de email/SMS no la afecta.
     *
     * @param array<int,array<string,mixed>> $saleDetail
     */
    private function notifyGiftCardBeneficiaries(array $saleDetail, SaleInput $input): void
    {
        $compName = defined('COMPANY_NAME') ? COMPANY_NAME : '';
        foreach ($saleDetail as $sD) {
            if (empty($sD['giftcardId']) || empty($sD['giftDate']) || empty($sD['beneficiaryId'])) {
                continue;
            }
            // Solo si la fecha de envío es HOY (legacy: date('Y-m-d') == parte fecha de giftDate).
            $sendDay = explode(' ', (string) $sD['giftDate'])[0];
            if ($sendDay !== date('Y-m-d')) {
                continue;
            }

            $benefRaw  = (string) $sD['beneficiaryId'];
            $benefId   = is_numeric($benefRaw) ? $benefRaw : dec($benefRaw);
            $benefData = getCustomerData($benefId, 'uid');
            if (!$benefData) {
                continue;
            }
            $benefEmail = $benefData['email'] ?? '';
            $benefPhone = $benefData['phone'] ?? ($benefData['phone2'] ?? '');
            if ($benefEmail === '' && $benefPhone === '') {
                continue;
            }

            $senderName = $compName;
            if ($input->clientId !== null) {
                $senderData = getCustomerData($input->clientId, 'uid');
                if ($senderData) {
                    $senderName = getCustomerName($senderData);
                }
            }
            $benefName = getCustomerName($benefData, 'first');
            $gifUrl    = getShortURL('/screens/giftCardRedeem?s=' . base64_encode($sD['uId'] . ',' . enc($this->ctx->companyId)));

            if ($benefEmail !== '') {
                $body = '<p>Hola ' . $benefName . ', <br>' . $senderName . ' le ha enviado una Gift Card</p>'
                      . makeEmailActionBtn($gifUrl, 'Ver Gift Card');
                sendEmails([
                    'subject'  => '[' . $compName . '] Gift Card',
                    'to'       => $benefEmail,
                    'fromName' => $compName,
                    'data'     => ['message' => $body, 'companyname' => $compName, 'companylogo' => $this->globalStr('compLogo')],
                ]);
            }
            if ($benefPhone !== '') {
                sendSMS($benefPhone, '[' . $compName . '] Hola ' . $benefName . ', ' . $senderName . ' le ha enviado una Gift Card. ' . $gifUrl);
            }
        }
    }

    /**
     * B14 — email + SMS de recibo/factura al cliente (cashsale/creditsale).
     * Port del legacy action.php:2550-2639. Gates: cliente + venta de hoy +
     * tiene email/teléfono + no `dontNotify`. URL receipt o digitalInvoice según
     * el módulo. sendEmails/sendSMS son helpers legacy (Mailgun + gateway SMS).
     */
    private function notifyCustomer(SaleInput $input, string $transId): void
    {
        if ($input->clientId === null || $input->dontNotify) {
            return;
        }
        // Solo si la venta es de HOY (el legacy no notifica ventas con fecha pasada).
        if (date('Y-m-d', strtotime($input->date)) !== date('Y-m-d')) {
            return;
        }

        $contact = getCustomerData($input->clientId, 'uid');
        if (!$contact) {
            return;
        }
        $email = $contact['email'] ?? '';
        $phone = $contact['phone'] ?? ($contact['phone2'] ?? '');
        if ($email === '' && $phone === '') {
            return;
        }

        $compName = defined('COMPANY_NAME') ? COMPANY_NAME : '';
        $compLogo = $this->globalStr('compLogo');
        $contactName = getCustomerName($contact, 'first');

        // URL del recibo — misma lógica de prioridad que el legacy (action.php:2594-2601):
        //   1. digitalInvoice activo → pantalla de factura digital
        //   2. Default               → pantalla de recibo
        //
        // El legacy tenía un caso intermedio: con `electronicInvoicePY` en el
        // payload mandaba la URL raíz del proveedor de FE anterior. Se retiró
        // en F4 junto con ese proveedor — el documento electrónico de hoy vive
        // en el panel (KuDE) y no reemplaza al recibo de la venta.
        $hasDigitalInvoice = $this->moduleEnabled('digitalInvoice');
        if ($hasDigitalInvoice) {
            $surl = '/screens/digitalInvoice?s=' . base64_encode(enc($transId) . ',' . enc($this->ctx->companyId)) . '&pdf=1';
            $url  = getShortURL($surl);
        } else {
            $surl = '/screens/receipt?s=' . base64_encode(enc($transId) . ',' . enc($this->ctx->companyId));
            $url  = getShortURL($surl);
        }

        $hello   = defined('L_HELLO') ? L_HELLO : 'Hola';
        $subject = '[' . $compName . '] ' . (defined('L_EMAIL_DETAILS_TITLE') ? L_EMAIL_DETAILS_TITLE : 'Detalle de su compra');
        $bodyTxt = defined('L_EMAIL_DETAILS_BODY') ? L_EMAIL_DETAILS_BODY : 'Puede ver el detalle de su compra en el siguiente enlace.';
        $btnTxt  = defined('L_EMAIL_VIEW_DETAILS') ? L_EMAIL_VIEW_DETAILS : 'Ver detalle';

        $body = $hello . ' ' . $contactName . ',<p>' . $bodyTxt . '</p>' . makeEmailActionBtn($url, $btnTxt);

        if ($email !== '') {
            sendEmails([
                'subject'  => $subject,
                'to'       => $email,
                'fromName' => $compName,
                'data'     => ['message' => $body, 'companyname' => $compName, 'companylogo' => $compLogo],
            ]);
        }
        if ($phone !== '') {
            $smsBody = '[' . $compName . '] ' . $hello . ' ' . $contactName . ', ' . $bodyTxt . ' ' . $url;
            sendSMS($phone, $smsBody);
        }
    }

    /**
     * B15 — registro de auditoría del documento (módulo FACTURACION).
     * Port del legacy action.php:2753-2790. sendAuditoria es curl best-effort.
     * getValue('setting',...) del legacy se reemplaza por COMPANY_NAME (la tabla
     * `setting` no existe en PG; settings en company.config).
     */
    private function sendAudit(SaleInput $input): void
    {
        // AUDITORIA_URL/TOKEN SIEMPRE están definidas (simple.config.php las setea a
        // '' cuando el env no existe) → gatear por string vacío, no por defined().
        // Sin esto dispararíamos un curl a /api/auditoria con Bearer vacío en cada venta.
        if (!defined('AUDITORIA_URL') || !defined('AUDITORIA_TOKEN')
            || AUDITORIA_URL === '' || AUDITORIA_TOKEN === '') {
            return; // auditoría no configurada (dev) → no-op
        }
        // Solo cashsale/creditsale en este path (35a). Las devoluciones (type 6,
        // docType='Nota de Crédito') llegan en 35e — gate explícito para no
        // mis-etiquetar cuando eso ocurra.
        if (!$input->type->isSimplePathEligible()) {
            return;
        }
        $userName     = getValue('contact', 'contactName', "WHERE contactId = '" . USER_ID . "'");
        $registerName = getValue('register', 'registerName', "WHERE registerId = '" . REGISTER_ID . "'");
        $companyName  = defined('COMPANY_NAME') ? COMPANY_NAME : '';
        $outletName   = getCurrentOutletName(OUTLET_ID);
        $docType      = 'Factura';

        sendAuditoria([
            'date'       => $input->date,
            'user'       => $userName,
            'module'     => 'FACTURACION',
            'origin'     => 'CAJA',
            'company_id' => $this->ctx->companyId,
            'data'       => [
                'action'       => "El usuario {$userName} agregó una {$docType} desde la caja {$registerName}",
                'userId'       => $this->ctx->userId,
                'userName'     => $userName,
                'registerId'   => $this->ctx->registerId,
                'registerName' => $registerName,
                'companyID'    => $this->ctx->companyId,
                'companyName'  => $companyName,
                'outletId'     => $this->ctx->outletId,
                'outletName'   => $outletName,
                'timestamp'    => $input->timestamp,
            ],
        ], AUDITORIA_TOKEN);
    }

    /** Lee un global $-var del bootstrap (compLogo, etc.) de forma segura. */
    private function globalStr(string $name): string
    {
        return isset($GLOBALS[$name]) ? (string) $GLOBALS[$name] : '';
    }

    /** True si el módulo (company.config / moduleData) está habilitado. */
    private function moduleEnabled(string $module): bool
    {
        $company = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$this->ctx->companyId]);
        if (!(is_array($company) || $company instanceof \ArrayAccess)) {
            return false;
        }
        return !empty($company[$module]);
    }

    /**
     * Resuelve el timbrado VIGENTE de la caja (número/inicio/vencimiento) en
     * el momento de emitir — mig 145. `registerInvoiceAuth*` vive en
     * `register.data` (JSONB, mig 26), config mutable de la caja; esta
     * llamada es la ÚNICA lectura de esa config para efectos de la venta —
     * el valor devuelto se congela en `transaction` (ver buildTransactionRecord)
     * y nunca se vuelve a resolver desde `register` para ESTA venta.
     *
     * `ncmExecute('SELECT data ...')` aplana el JSONB (Query::flattenJsonb):
     * las claves de `data` llegan directo en la fila, `$row['data']` no
     * existe. Con count=1 el resultado es un CaseInsensitiveArray, NO un
     * array plano — is_array() sobre eso da false (bug ya pisado antes en
     * este repo, ver lease.php); el chequeo real es is_array() || ArrayAccess
     * (mismo criterio que moduleEnabled() arriba y enrichWithTaxes()).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [invoiceAuth, invoiceAuthStart, invoiceAuthExpiration]
     */
    private function resolveFrozenInvoiceAuth(): array
    {
        $row = ncmExecute(
            'SELECT data FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$this->ctx->registerId, $this->ctx->companyId]
        );
        if (!(is_array($row) || $row instanceof \ArrayAccess)) {
            return [null, null, null];
        }

        $auth  = trim((string) ($row['registerInvoiceAuth']           ?? ''));
        $start = trim((string) ($row['registerInvoiceAuthStart']      ?? ''));
        $exp   = trim((string) ($row['registerInvoiceAuthExpiration'] ?? ''));

        return [
            $auth  === '' ? null : $auth,
            $start === '' ? null : $start,
            $exp   === '' ? null : $exp,
        ];
    }

    /**
     * Arma el array de campos para INSERT en `transaction` (B3 del legacy).
     *
     * Notas críticas portadas del legacy (action.php:1989-2034):
     * - **`meta` JSONB doble-encode** (§22.6): `transactionDetails` y `tags`
     *   se guardan como JSON-strings dentro del JSONB `meta`, NO como columnas
     *   (fueron demoted en la migración PG). Las lecturas usan
     *   `json_decode($row['transactionDetails'])` tras `_flattenJsonb`.
     * - **UUID NULL-coalesce**: customerId / userId / transactionParentId
     *   vacíos van como NULL, no como 0 o "" (PG rechaza con "invalid input
     *   syntax for type uuid"). Ver bugs históricos del commit b45684f.
     * - **timestamp NULL-coalesce**: transactionDueDate / fromDate / toDate
     *   vacíos van como NULL (PG rechaza string "" para timestamp).
     * - **transactionComplete**: false para tipos a pagar (creditsale=3,
     *   creditpurchase=4, schedule=13) — true para el resto.
     * - **flipOnReturn**: no-op para type 0/3, solo invierte signo en type=6.
     *   Lo mantenemos por consistencia con el helper que aplica a todos los
     *   tipos en sub-slices futuros (35e devolución).
     *
     * @param array<int,array<string,mixed>> $saleDetail
     * @return array<string,mixed>
     */
    private function buildTransactionRecord(
        SaleInput $input,
        array $saleDetail,
        float $totalUnits,
        string $userId,
        ?string $responsibleId,
        int $decimals,
        ?string $invoiceAuth = null,
        ?string $invoiceAuthStart = null,
        ?string $invoiceAuthExpiration = null,
    ): array {
        $typeStr = (string) $input->type->value;
        $isIncomplete = in_array($input->type, [
            SaleType::Creditsale,
            SaleType::CreditPurchase,
            SaleType::Schedule,
        ], true);

        return [
            'transactionDiscount'    => flipOnReturn($typeStr, $input->discount),
            // F2a (context/38): ya NO se confía en $input->tax del payload — es
            // la suma de taxAmount por línea, calculado por TaxEngine dentro de
            // enrichWithTaxes() y congelado en $saleDetail. Cierra la
            // vulnerabilidad de transactionTax sin validar (auditoría §diagnóstico #4).
            'transactionTax'         => flipOnReturn($typeStr, $this->sumLineTax($saleDetail, $decimals)),
            'transactionTotal'       => flipOnReturn($typeStr, $input->subtotal),
            // Venta sin IVA (toggle del POS, mig 101): los importes de arriba ya
            // vienen netos desde el front. Sin esta bandera no habia forma de
            // distinguir una venta sin IVA de una con IVA mal cargada.
            'ivaRemoved'             => $input->ivaRemoved,
            // Consumo interno (botón "Interno" del POS, mig 118). El carrito ya
            // mandaba el flag; sin esta línea se perdía en el borde de la API y
            // la venta interna quedaba indistinguible de una venta a cliente.
            'interno'                => $input->interno,
            'transactionUnitsSold'   => flipOnReturn($typeStr, $totalUnits),

            // meta JSONB: shape EXACTO del legacy — ambas keys son JSON-STRINGS adentro
            // del JSON exterior (doble-encode). Los readers (OrderService:229,
            // TransactionService:41) hacen `json_decode($meta['tags'])` → DEBE ser string,
            // no array nativo. El legacy guarda `$data['tags']` (el JSON-string del front);
            // nosotros normalizamos a list<uuid> y re-encodeamos al mismo shape.
            'meta' => json_encode([
                'transactionDetails' => json_encode($saleDetail),
                'tags'               => $input->tags !== null ? json_encode($input->tags) : null,
            ]),
            'transactionPaymentType' => json_encode($input->payment),

            // path simple: sin parentId (B2 omitido). Sub-slices futuros lo agregarán
            // como transaction_link (mig 115, kind='quote_to_sale') — columna dropeada.
            'transactionType'        => $typeStr,
            'transactionComplete'    => $isIncomplete ? 0 : 1,

            'transactionDate'        => $input->date,
            'transactionDueDate'     => $input->dueDate, // null si vacío (DTO ya normaliza)
            'fromDate'               => null,            // path simple
            'toDate'                 => null,            // path simple
            'transactionName'        => $input->ident !== null ? strip_tags($input->ident) : null,
            'transactionNote'        => $input->note  !== null ? strip_tags($input->note)  : null,
            'invoiceNo'              => $input->invoiceNo,
            // mig 145 — timbrado CONGELADO al emitir (resuelto en save(), ANTES
            // de este builder — ver resolveFrozenInvoiceAuth()). null para
            // saveQuote() (no pasa estos parámetros): una cotización no es un
            // documento bajo timbrado, no participa de uq_transaction_
            // expedition_invoiceno (WHERE transactiontype IN (0,3)).
            'invoiceAuth'            => $invoiceAuth,
            'invoiceAuthStart'       => $invoiceAuthStart,
            'invoiceAuthExpiration'  => $invoiceAuthExpiration,
            'timestamp'              => $input->timestamp,
            'transactionUID'         => $input->uid,
            'transactionCurrency'    => $input->currency,
            'transactionStatus'      => $input->status ?? 1,

            'customerId'             => $input->clientId,           // null si vacío
            'registerId'             => $this->ctx->registerId,
            'userId'                 => $userId,
            'responsibleId'          => $responsibleId,             // null si user del request == JWT
            'outletId'               => $this->ctx->outletId,
            'companyId'              => $this->ctx->companyId,

            // Sesión de caja: drawerId del turno de ESTE register cuyo rango
            // contiene la fecha de la venta ($input->date, la misma que va a
            // transactionDate arriba) — NO "la caja abierta ahora", que para
            // una venta offline sincronizada tarde puede ser un turno distinto
            // al que la cobró (bug verificado 2026-08-17, context/modules/
            // 14-caja.md regla 5). null si ningún turno contiene esa fecha
            // (controlCaja off, o sync sin candidato) → la venta NO falla; el
            // resumen la recupera por el fallback de fecha (mig 70).
            'drawerId'               => \Punto\Api\Services\DrawerService::resolveDrawerIdForDate(
                (string) $this->ctx->registerId,
                (string) $this->ctx->companyId,
                $input->date,
            ),
        ];
    }

    /**
     * Persiste las relaciones de la venta (B4 + B6 + B7 del legacy):
     *   - toTaxObj: snapshot de la matriz de impuestos aplicada (B4, action.php:2040)
     *   - toAddress: link a la dirección de entrega del cliente (B6, action.php:2060)
     *   - toTag: links a los tags de la venta (B7, action.php:2066)
     *
     * Todo dentro de la transacción abierta en save(). Un fallo (FK violation, etc.)
     * marca la transacción como fallida y save() lanza SaleAbortedException.
     */
    private function persistRelations(SaleInput $input, string $transId, array $saleDetail, int $decimals): void
    {
        // ── B4: snapshot de impuestos (toTaxObj) ────────────────────────────
        // F2a (context/38): antes venía tal cual del taxObj del payload (sin
        // validar — auditoría §diagnóstico #4). Grep de lectores de toTaxObj/
        // toTaxObjText en api/ y frontend/ (2026-08-08): el ÚNICO otro caller es
        // CompanyAdminService::delete() (`DELETE FROM toTaxObj WHERE companyId=?`,
        // borrado en cascada al eliminar el tenant) — nadie hace SELECT del
        // contenido. Sin lector del shape legacy {name,val} que preservar, se
        // reemplaza directo por el desglose byRate del motor: lista de
        // {taxId, rate, kind, base, amount} agrupada por (taxRate,taxKind) sobre
        // las líneas YA congeladas en $saleDetail (misma fuente que itemSold/
        // transactionTax — un solo cálculo, sin re-invocar el motor).
        //
        // El techo de VARCHAR(255) que tenía `toTaxObjText` se levantó en la
        // mig 124 (TEXT): con ~6+ tasas el json_encode lo excedía, PG abortaba
        // la tx por truncación (22001) y la venta ENTERA fallaba con
        // SaleAbortedException. No pasaba en PY (3 tasas), pero el plan
        // multi-país (context/38) lo volvía cuestión de tiempo.
        $taxByRate = $this->groupTaxByRate($saleDetail, $decimals);
        if ($taxByRate !== []) {
            $this->db->AutoExecute('toTaxObj', [
                'toTaxObjText'  => json_encode($taxByRate),
                'transactionId' => $transId,
                'companyId'     => $this->ctx->companyId,
            ], 'INSERT');
        }

        // ── B6: dirección de entrega (toAddress) ────────────────────────────
        // El legacy gatea por saleType ∈ {cashsale, creditsale, order, schedule};
        // type 0/3 (path simple) siempre califica. Requiere cliente + addressId.
        // Anti-IDOR: validamos que la dirección pertenezca a ESE cliente y tenant
        // (el legacy insertaba el addressId crudo del front sin validar).
        if ($input->clientId !== null && $input->addressId !== null) {
            // status = 1: una dirección borrada (soft-delete, mig 87) no se
            // puede elegir para una venta NUEVA — a diferencia de
            // getTransactionAddress() (Customer.php), que SÍ debe seguir
            // resolviendo direcciones borradas para ventas YA hechas.
            $addr = $this->db->Execute(
                'SELECT customerAddressId FROM customerAddress
                 WHERE customerAddressId = ? AND customerId = ? AND companyId = ? AND status = 1 LIMIT 1',
                [$input->addressId, $input->clientId, $this->ctx->companyId]
            );
            if ($addr && !$addr->EOF) {
                $this->db->AutoExecute('toAddress', [
                    'customerAddressId' => $input->addressId,
                    'transactionId'     => $transId,
                ], 'INSERT');
            }
            // Si la dirección no matchea, la omitimos en silencio (no abortamos la
            // venta por un addressId stale — es decorativo, no afecta el cobro).
        }

        // ── B7: tags de la venta (toTag) ────────────────────────────────────
        // FIX PG: el legacy hacía `intval($ttag)` → roto, porque `totag.tagid` es
        // UUID (taxonomyId), no int. Mantenemos los UUIDs. Validamos cada tag contra
        // taxonomy (FK + tenant scope) antes de insertar — un tag inexistente
        // dispararía FK violation y abortaría la venta.
        if ($input->tags) {
            foreach ($input->tags as $tagId) {
                $tag = $this->db->Execute(
                    "SELECT taxonomyId FROM taxonomy
                     WHERE taxonomyId = ? AND taxonomyType = 'tag' AND companyId = ? LIMIT 1",
                    [$tagId, $this->ctx->companyId]
                );
                if ($tag && !$tag->EOF) {
                    $this->db->AutoExecute('toTag', [
                        'toTagType' => 0,
                        'parentId'  => $transId,
                        'tagId'     => $tagId,
                    ], 'INSERT');
                }
                // Tag inexistente o de otro tenant → omitido (no aborta la venta).
            }
        }
    }

    /**
     * B10 (parcial) — loyalty EARNED. Por cada pago (todos cash/card en este path:
     * los pagos que gastan balance ya fueron rechazados en eligibility), el cliente
     * gana puntos si el módulo loyalty está activo. Solo cashsale (type 0) con cliente
     * — creditsale (3) NO gana (guard del legacy: `type == '0' || type == '5'`).
     *
     * Corre dentro de la transacción de save(). manageCustomerLoyalty es helper legacy
     * (deuda §22.9); su path 'earned' fue arreglado para PG (loyaltyMin/loyaltyValue
     * desde company.config JSONB).
     */
    private function persistLoyaltyEarning(SaleInput $input): void
    {
        if ($input->type !== SaleType::Cashsale || $input->clientId === null) {
            return;
        }
        // compLoyalty = módulo loyalty habilitado (company.config->loyalty).
        // ncmExecute aplana el config JSONB → expone la key `loyalty`.
        // Asunción: config.loyalty es numérico (1/0), igual que el legacy
        // `$compLoyalty = $_modules['loyalty']` (data.php:35) que compara `> 0`.
        $company = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$this->ctx->companyId]);
        $compLoyalty = (is_array($company) || $company instanceof \ArrayAccess)
            ? (float) ($company['loyalty'] ?? 0)
            : 0;
        if ($compLoyalty <= 0) {
            return; // módulo loyalty deshabilitado → no se ganan puntos
        }
        foreach ($input->payment as $pay) {
            // El legacy SOLO premia los pagos del branch `else` (action.php:2453-2456):
            // points/storeCredit/giftcard GASTAN balance y NO ganan puntos. Sin este
            // skip, al habilitarse giftcard (35c.1) se premiaría doble (gastar + ganar).
            $type = (string) ($pay['type'] ?? '');
            if (in_array($type, ['points', 'storeCredit', 'giftcard'], true)) {
                continue;
            }
            $price = (float) ($pay['price'] ?? 0);
            if ($price > 0) {
                manageCustomerLoyalty('earned', $price, $input->clientId, $this->ctx->companyId);
            }
        }
    }

    /**
     * B12 (35f) — venta recurrente: crea la suscripción en `recurring`.
     * Port del bloque creditsale del legacy (action.php:2393-2411).
     *
     * Solo se ejecuta para creditsale (type=3) con repeat=true — el legacy gatea
     * con in_array(['creditsale','schedule']); cashsale (type=0) no tiene recurrente.
     * Corre DENTRO de la transacción de save(); si falla, rollbackea con la venta.
     *
     * `data` JSONB almacena recurringSaleData (el payload completo de la venta) —
     * el cron de recurrente lo usa para re-someter la venta. El campo
     * `recurringTransactionData` del legacy contenía el `?l=` base64 raw (params de
     * sesión del front); en SaleService almacenamos el contexto JWT como JSON (mismos
     * datos que el cron necesita para reautenticar).
     */
    private function persistRecurring(SaleInput $input, string $transId): void
    {
        if (!$input->repeat || $input->type !== SaleType::Creditsale) {
            return; // solo creditsale con repeat=true (legacy: in_array(['creditsale','schedule']))
        }
        if ($input->repeatF === null || $input->repeatT === null || $input->repeatT <= 0) {
            return; // sin frecuencia o sin repeticiones → no-op
        }

        $nextDate = getNextDatePeriod($input->repeatF, '1',           $input->date);
        $endDate  = getNextDatePeriod($input->repeatF, $input->repeatT, $input->date);

        // recurringSaleData: el payload completo de la venta, serializado.
        // El cron lo deserializa y lo re-somete al SaleService (o legacy aún).
        // Usamos json_encode del array de propiedades públicas del DTO.
        $saleData = [
            'uid'       => $input->uid,
            'type'      => $input->type->value,
            'sale'      => $input->sale,
            'subtotal'  => $input->subtotal,
            'tax'       => $input->tax,
            'discount'  => $input->discount,
            'payment'   => $input->payment,
            'date'      => $input->date,
            'timestamp' => $input->timestamp,
            'client'    => $input->clientId,
            'user'      => $input->userId,
            'note'      => $input->note,
            'dueDate'   => $input->dueDate,
            'currency'  => $input->currency,
            'repeat'    => false, // la re-submisión NO repite (evita loop)
        ];

        // recurringTransactionData: el cron (cronCreateRecurringInvoice.php) hace:
        // LEGACY (cron + action.php ELIMINADOS 2026-06-29): se persistía este shape de
        // recurringTransactionData para que el cron lo re-posteara a action.php?l=processData.
        // Ese path ya no existe. TODO: rehacer la automatización de facturas recurrentes sobre
        // el path moderno (/v1/sales → SaleService). El dato se sigue guardando por compat.
        $txData = json_encode([
            'action'     => 'processData',
            'companyId'  => $this->ctx->companyId,
            'outletId'   => $this->ctx->outletId,
            'userId'     => $this->ctx->userId,
            'roleId'     => 1,
            'registerId' => $this->ctx->registerId,
        ]);

        $this->db->AutoExecute('recurring', [
            'recurringNextDate'        => $nextDate,
            'recurringEndDate'         => $endDate,
            'recurringFrecuency'       => $input->repeatF,
            'recurringStatus'          => 1,
            'recurringTransactionData' => $txData,
            'companyId'                => $this->ctx->companyId,
            // data JSONB: recurringSaleData como key dentro del objeto (mismo patrón
            // que meta/transactionDetails — json dentro de jsonb, §22.6).
            'data'                     => json_encode(['recurringSaleData' => json_encode($saleData)]),
        ], 'INSERT');
    }

    /**
     * B10 (35e) — débito de puntos y crédito de tienda usados como pago.
     * Port de la rama points/storeCredit del payment loop del legacy (action.php:2446-2449).
     * Reutiliza los helpers existentes (ya corregidos para PG: parametrizados,
     * sin $db->Prepare) dentro de la misma transacción de save().
     *
     * Gate: requiere clientId (sin cliente no hay balance que debitar). El legacy
     * usaba `$client` que nunca es null en este contexto; los requerimos igualmente.
     */
    private function persistBalanceRedemptions(SaleInput $input): void
    {
        if ($input->clientId === null) {
            return; // sin cliente no hay puntos ni crédito que debitar
        }
        foreach ($input->payment as $pay) {
            $type   = (string) ($pay['type'] ?? '');
            $amount = (float)  ($pay['price'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            if ($type === 'points') {
                // Debita contactLoyaltyAmount del cliente.
                manageCustomerLoyalty('used', $amount, $input->clientId, $this->ctx->companyId);
            } elseif ($type === 'storeCredit') {
                // Debita contactStoreCredit del cliente.
                manageCustomerStoreCredit('used', $amount, $input->clientId, $this->ctx->companyId);
            }
        }
    }

    /**
     * B8 (35e) — item inCredit: acredita crédito interno (contactStoreCredit) al cliente.
     * Port del bloque inCredit del items loop del legacy (action.php:2379-2382).
     *
     * Fix vs legacy: el legacy concatenaba $sD['total'] crudo en el SQL → SQLi.
     * Acá usamos UPDATE parametrizado.
     *
     * El item `inCredit` no tiene itemId → no genera itemSold ni mueve stock;
     * solo actualiza el saldo de crédito del cliente.
     *
     * @param array<string,mixed> $sD item sanitizado (saleArraySanitizer)
     */
    private function persistInCreditItem(array $sD, string $clientId, string $companyId): void
    {
        $amount = (float) ($sD['total'] ?? 0);
        if ($amount <= 0) {
            return;
        }
        $this->db->Execute(
            "UPDATE contact SET contactStoreCredit = contactStoreCredit + ?, updated_at = ? WHERE contactId = ?",
            [$amount, TODAY, $clientId]
        );
        updateLastTimeEdit($companyId, 'customer');
    }

    /**
     * B10 (35c.1) — redención de gift cards usadas como pago. Port de la rama
     * `giftcard` del payment loop del legacy (action.php:2450-2452 → manageGiftCard).
     * Corre DENTRO de la transacción de save(): si la venta aborta, el débito del
     * saldo se rollbackea junto con todo.
     *
     * Diferencias vs el legacy manageGiftCard (functions.php:313):
     *  - Tenant-scoped: el SELECT y el UPDATE filtran por companyId (anti-IDOR —
     *    el legacy no scopeaba el UPDATE → un código de otra company podía debitarse).
     *  - UPDATE PARAMETRIZADO por giftCardSoldId (PK): el legacy concatenaba `$id`
     *    crudo en el WHERE (SQL injection — mitigado solo por el (int) cast del front).
     *  - Card no encontrada → log + skip (NO throw): un throw 422 haría que el front
     *    rebote la venta al legacy, que ahora también la rechaza (giftcard migró) →
     *    loop infinito. Matchea el no-op silencioso del legacy (el front ya validó
     *    la card con chkGiftCard antes de cobrar).
     */
    private function persistGiftCardRedemptions(SaleInput $input): void
    {
        foreach ($input->payment as $pay) {
            if (($pay['type'] ?? '') !== 'giftcard') {
                continue;
            }
            $amount  = (float) ($pay['price'] ?? 0);
            $cardRef = $pay['extra'] ?? null; // código (giftCardSoldCode) o timestamp
            if ($amount <= 0 || $cardRef === null || $cardRef === '') {
                continue;
            }
            $this->redeemGiftCard($cardRef, $amount);
        }
    }

    /** Debita `$amount` del saldo de la gift card `$cardRef` (capeado al saldo). */
    private function redeemGiftCard(int|string $cardRef, float $amount): void
    {
        // giftCardSoldCode/timestamp son numéricas (int/bigint). Un `extra` no-numérico
        // haría `giftCardSoldCode = 'abc'` → PG aborta la tx (invalid input syntax) →
        // la venta entera fallaría. El front siempre manda un código numérico; si no,
        // log + skip (no-op, como card inexistente) en vez de tumbar la venta.
        if (!is_numeric($cardRef)) {
            error_log("[SaleService] redeemGiftCard: ref '{$cardRef}' no numérica — skip\n", 3, './error_log');
            return;
        }

        // Lookup por código O timestamp, scopeado al tenant. giftCardSold no tiene
        // columnas demoted a JSONB → $this->db->Execute crudo es seguro acá.
        $row = $this->db->Execute(
            'SELECT giftCardSoldId, giftCardSoldValue FROM giftCardSold
             WHERE (giftCardSoldCode = ? OR timestamp = ?) AND companyId = ? LIMIT 1',
            [$cardRef, $cardRef, $this->ctx->companyId]
        );
        if (!$row || $row->EOF) {
            // Card inexistente / de otro tenant → matchea el no-op legacy, con log.
            error_log("[SaleService] redeemGiftCard: card '{$cardRef}' no encontrada para tenant {$this->ctx->companyId}\n", 3, './error_log');
            return;
        }

        // Decremento ATÓMICO en SQL (mejora vs legacy): GREATEST(...-?,0) capea al
        // saldo y re-lee el valor dentro del mismo UPDATE → sin ventana read-then-write
        // (dos ventas concurrentes con la misma card ya no pierden un débito). El SELECT
        // de arriba queda solo para existencia + scope de tenant + el skip de no-encontrada.
        $this->db->Execute(
            'UPDATE giftCardSold
             SET giftCardSoldValue = GREATEST(giftCardSoldValue - ?, 0), giftCardSoldLastUsed = ?
             WHERE giftCardSoldId = ?',
            [$amount, TODAY, $row->fields['giftcardsoldid']]
        );
    }

    /**
     * F2 vouchers (context/36-vouchers-plan.md) — consume cada vale canjeado
     * en el carrito. Corre DENTRO de la transacción de save() (mismo `$db`
     * ambiente que VoucherService::consume() usa vía `global $db` — ambos
     * services comparten conexión, así que su UPDATE participa de este
     * StartTrans/CompleteTrans sin nada especial de por medio).
     *
     * Un vale puede traer VARIAS líneas (una por ítem) — se dedupea por
     * voucherId y se consume UNA vez por vale, no una vez por línea.
     *
     * Fallo del consume (vale ya usado por otra transacción / vencido /
     * anulado — típicamente una carrera: dos cajas canjeando el mismo código
     * casi al mismo tiempo) aborta TODA la venta: sin esto, las líneas del
     * vale (que no suman al `transactionTotal`, ver buildSalePayload en el
     * front) quedarían registradas como entregadas gratis sin que el vale se
     * haya consumido de verdad — plata que se va sin respaldo.
     *
     * @param array<int,array<string,mixed>> $saleDetail sanitizado (saleArraySanitizer)
     */
    private function persistVoucherRedemptions(array $saleDetail, string $transId): void
    {
        $svc  = new \Punto\Api\Services\VoucherService();
        $seen = [];

        foreach ($saleDetail as $sD) {
            $voucher = $sD['voucher'] ?? null;
            if (!is_array($voucher)) {
                continue;
            }
            $code = trim((string) ($voucher['code'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;

            $result = $svc->consume($this->ctx->companyId, $code, $transId);
            if (!$result['ok']) {
                throw new InvalidSaleInputException(
                    "No se pudo canjear el vale '{$code}': " . ($result['reason'] ?? 'error desconocido')
                );
            }
        }
    }

    /**
     * Emisión de gift card (F2 giftcard-issue-flow) — crea la fila en la tabla
     * NUEVA `giftcard` (mig 44 + 78) cuando se vende un item de catálogo con
     * itemType='giftcard' y el front mandó metadata (`sD['giftcard']`: code,
     * beneficiaryContactId?, expiresAt?, note?). Corre DENTRO de la transacción
     * de save() (rollback con la venta si algo falla) — llamada desde
     * persistItemsAndStock ANTES del itemSold/manageStock normal del item (que
     * sigue corriendo igual: la gift card es un producto vendible más).
     *
     * Idempotencia: la dedup real es a nivel TRANSACCIÓN (UNIQUE transactionUID,
     * chequeado al inicio de save() — ver DuplicateSaleException). Si esta línea
     * corre, es una venta nueva: no hace falta dedup propio acá.
     *
     * Código único: pre-check + el INSERT vuelve a chocar con la UNIQUE
     * (companyid, code) de la mig 78 si hay carrera entre dos devices — en
     * ambos casos se lanza InvalidSaleInputException (422, mensaje claro para
     * que el cajero regenere el código), NUNCA un 500 silencioso.
     *
     * beneficiaryContactId: validado contra contact del tenant → null si no
     * existe/no es UUID (decorativo, no aborta la venta — mismo criterio que
     * sellGiftCard() de abajo).
     */
    private function issueGiftCard(array $sD, string $itemId, string $transId, string $companyId): void
    {
        $gc   = is_array($sD['giftcard'] ?? null) ? $sD['giftcard'] : [];
        // Normalizado a MAYÚSCULAS acá (no solo confiar en el front): el
        // canje (api/v1/giftcards.php validate/consume) matchea con
        // UPPER(code) = UPPER(?), y el índice único es sobre UPPER(code)
        // (mig 126) — si acá se guardara el case tal cual lo tipeó el
        // cajero, "GC-ABC" y "gc-abc" serían DOS filas distintas para el
        // índice pero la MISMA gift card para el canje (que resuelve con
        // LIMIT 1 y se queda con una sola, arbitrariamente) — plata fantasma.
        $code = strtoupper(trim((string) ($gc['code'] ?? '')));
        if ($code === '') {
            throw new InvalidSaleInputException('Falta el código de la gift card');
        }

        // Backstop server-side: UNA línea de gift card = UNA card, código y
        // saldo únicos. El front bloquea el stepper de qty en estas líneas
        // (cart-panel.tsx CartRowExpanded::qtyLocked), pero acá lo re-chequeamos
        // — sin esto, count=2 emitiría UNA fila con el doble de saldo bajo un
        // único código (itemSold/stock sí reflejarían count=2 → plata inconsistente).
        $count = (float) ($sD['count'] ?? 1);
        if (abs($count - 1.0) > 0.0001) {
            throw new InvalidSaleInputException('Una gift card se emite de a una — cantidad debe ser 1');
        }

        $amount = (float) ($sD['total'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidSaleInputException('La gift card debe tener un monto mayor a 0');
        }

        // Pre-check de unicidad (companyId, UPPER(code)) — mensaje claro y
        // rápido. El índice único case-insensitive (mig 126,
        // uq_giftcard_company_code_ci sobre UPPER(code)) es la garantía real
        // ante carrera concurrente entre dos devices — este SELECT es solo
        // UX (mensaje legible en vez del 23505 crudo de más abajo).
        $dup = $this->db->Execute(
            'SELECT id FROM giftcard WHERE companyid = ? AND UPPER(code) = UPPER(?) LIMIT 1',
            [$companyId, $code]
        );
        if ($dup && !$dup->EOF) {
            throw new InvalidSaleInputException("El código de gift card '{$code}' ya existe — generá uno nuevo");
        }

        // beneficiaryContactId: solo si tiene forma de UUID y pertenece al tenant.
        $beneficiaryId   = null;
        $beneficiaryName = null;
        $benef = trim((string) ($gc['beneficiaryContactId'] ?? ''));
        if ($benef !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $benef)) {
            $bRow = $this->db->Execute(
                'SELECT contactId, contactName FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',
                [$benef, $companyId]
            );
            if ($bRow && !$bRow->EOF) {
                $beneficiaryId   = $benef;
                $beneficiaryName = trim((string) ($bRow->fields['contactname'] ?? '')) ?: null;
            }
        }

        // expiresAt: '' / fecha inválida → null (PG rechaza '' en TIMESTAMPTZ).
        $expiresAt = null;
        $expRaw    = trim((string) ($gc['expiresAt'] ?? ''));
        if ($expRaw !== '') {
            $ts = strtotime($expRaw);
            if ($ts !== false) {
                $expiresAt = date('Y-m-d 23:59:59', $ts);
            }
        }

        $note = trim((string) ($gc['note'] ?? ''));

        // INSERT RAW con columnas QUOTED camelCase. NO AutoExecute: éste arma
        // `INSERT INTO giftcard (companyId, initialBalance, ...)` con implode
        // SIN comillas (DB.php) → PG pliega a lowercase → `companyid` no existe
        // en `giftcard` (mig 44, columnas quoted) → el INSERT falla SIEMPRE.
        // `id` se omite: la columna tiene DEFAULT gen_random_uuid() (mig 44).
        // Mismo patrón quoted que api/v1/giftcards.php (validate/consume).
        $ok = $this->db->Execute(
            'INSERT INTO giftcard
                (id, companyid, code, initialbalance, currentbalance, expiresat,
                 beneficiarycontactid, beneficiaryname, note, issuedbytransactionid,
                 outletid, status)
             VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $companyId,
                $code,
                $amount,
                $amount,
                $expiresAt,
                $beneficiaryId,
                $beneficiaryName,
                $note !== '' ? $note : null,
                $transId,
                $this->ctx->outletId,
                1,
            ]
        );
        if ($ok === false) {
            // Carrera concurrente contra la UNIQUE (companyid, code): PG
            // devuelve SQLSTATE 23505 (unique_violation) en el ErrorMsg.
            $err = $this->db->ErrorMsg();
            if (stripos($err, '23505') !== false
                || stripos($err, 'unique') !== false
                || stripos($err, 'duplicate') !== false) {
                throw new InvalidSaleInputException("El código de gift card '{$code}' ya existe — generá uno nuevo");
            }
            throw new InvalidSaleInputException('No se pudo emitir la gift card: ' . $err);
        }
    }

    /**
     * @deprecated F2 giftcard-issue-flow (2026-07-18) — el POS nuevo nunca dispara
     *             este path (nunca manda `sD['type']==='giftcard'` sin itemId).
     *             Reemplazado por issueGiftCard() sobre la tabla `giftcard`
     *             (mig 44+78). Se mantiene sin borrar por compat de código legacy
     *             que aún pudiera invocar processData con el shape viejo — NO usar
     *             en código nuevo. `giftCardSold` NO se borra (histórico).
     *
     * B8 (35c.2) — venta de gift card: crea el registro giftCardSold con el saldo
     * inicial. Port de insertNewGiftCard (functions.php:336). Corre DENTRO de la
     * transacción de save() (rollback con la venta si algo falla).
     *
     * Mejoras vs el legacy:
     *  - Dedup PARAMETRIZADO + tenant-scoped (el legacy concatenaba timestamp/COMPANY_ID).
     *  - beneficiaryId VALIDADO contra contact del tenant → null si no existe (evita la
     *    FK violation que abortaría la venta; el legacy insertaba el id crudo del front).
     *  - NULL-coalesce de expires/sendDate/beneficiary ('' → null; PG rechaza '' en
     *    columnas timestamp/uuid).
     *
     * @param array<string,mixed> $sD item giftcard sanitizado (saleArraySanitizer rama giftcard)
     */
    private function sellGiftCard(array $sD, string $transId, SaleInput $input): void
    {
        $timestamp = (int) ($sD['uId'] ?? 0);
        if ($timestamp <= 0) {
            return; // el legacy requiere timestamp para dedup/lookup
        }

        // Dedup por timestamp (la cola offline puede reenviar la misma venta).
        $dup = $this->db->Execute(
            'SELECT giftCardSoldId FROM giftCardSold WHERE timestamp = ? AND companyId = ? LIMIT 1',
            [$timestamp, $this->ctx->companyId]
        );
        if ($dup && !$dup->EOF) {
            return; // ya existe → no duplicar
        }

        // Saldo inicial: totalGift si vino, si no total (legacy action.php:2317).
        $value = (float) ($sD['totalGift'] ?? $sD['total'] ?? 0);

        // beneficiaryId: el front manda enc(contactId) (o numérico legacy). dec() lo
        // descifra. Validamos que sea un contact del tenant; si no, null (decorativo,
        // no abortamos la venta por un beneficiario stale — FK a contact).
        $beneficiaryId = null;
        $benef = (string) ($sD['beneficiaryId'] ?? '');
        if ($benef !== '') {
            $candidate = is_numeric($benef) ? $benef : dec($benef);
            // contact.contactId es UUID en PG. Solo consultamos si `$candidate` tiene
            // forma de UUID — un valor no-uuid (id numérico legacy, basura de dec())
            // haría que el SELECT aborte la tx por "invalid input syntax for uuid".
            // En ese caso → beneficiaryId null (decorativo, no rompe la venta).
            if (is_string($candidate)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate)) {
                $bRow = $this->db->Execute(
                    'SELECT contactId FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',
                    [$candidate, $this->ctx->companyId]
                );
                if ($bRow && !$bRow->EOF) {
                    $beneficiaryId = $candidate;
                }
            }
        }

        $expires  = !empty($sD['giftcardExp']) ? date('Y-m-d 01:00:00', strtotime((string) $sD['giftcardExp'])) : null;
        $sendDate = !empty($sD['giftDate']) ? (string) $sD['giftDate'] : null;

        $record = [
            'giftCardSoldValue'         => $value > 0 ? $value : 0,
            'giftCardSoldExpires'       => $expires,
            'giftCardSoldNote'          => !empty($sD['note']) ? (string) $sD['note'] : null,
            'giftCardSoldSendDate'      => $sendDate,
            'giftCardSoldBeneficiaryId' => $beneficiaryId,
            'giftCardSoldColor'         => !empty($sD['giftcardColor']) ? (string) $sD['giftcardColor'] : null,
            'timestamp'                 => $timestamp,
            'transactionId'             => $transId,
            'outletId'                  => $this->ctx->outletId,
            'companyId'                 => $this->ctx->companyId,
        ];

        // giftCardSoldCode (int, opcional): solo si es un código real, no placeholder.
        $code = (string) ($sD['giftcardId'] ?? '');
        if ($code !== '' && is_numeric($code) && !in_array($code, ['none', 'no', 'giftcard'], true)) {
            $record['giftCardSoldCode'] = (int) $code;
        }

        $this->db->AutoExecute('giftCardSold', $record, 'INSERT');
    }

    /**
     * Pre-flight: rechaza la venta si algún item tiene sesiones configuradas
     * (`itemSessions > 0`) y hay cliente — eso dispara la creación de citas en
     * el legacy (B8 sesiones), que es 35d. Corre ANTES de StartTrans para no
     * dejar transacciones colgadas. `itemSessions` vive en la BD (no en el
     * payload), por eso no lo cubre SaleInput::assertSimplePathEligible.
     * Nota: este método fue el guard pre-35d. Con 35d implementado ya no lanza;
     * la lógica real vive en persistScheduledSessions (más abajo).
     */

    /**
     * B8 (35d) — sesiones agendadas: crea N filas type=13 en `transaction`, una por
     * sesión del item. Port de la rama de sesiones en processData (action.php:2267-2306
     * → insertEmptySchedule). Corre DENTRO de la transacción de save().
     *
     * Diferencias vs el legacy insertEmptySchedule (functions.php:3382):
     *  - transactionDetails va en `meta` JSONB (como el resto de SaleService). El
     *    legacy intentaba setearlo como columna directa, que en PG no existe → el
     *    campo se perdía silenciosamente.
     *  - packageId (link itemSold → sesión) es UUID ya resuelto, no $db->Insert_ID()
     *    legacy que devolvía int.
     *  - Todos los INSERTs son parametrizados (el legacy usaba AutoExecute directamente
     *    sobre el global $db).
     *  - updateLastTimeEdit se llama dentro de la tx (como el legacy).
     *
     * Gate: solo con cliente (el legacy no crea sesiones si `$client` es falsy).
     * `itemSessions` está DEMOTED a la columna `data` JSONB → ncmExecute (§22.8).
     *
     * @param array<string,mixed> $sD item sanitizado
     */
    /** @return int número de sesiones creadas (0 si el item no tiene sesiones) */
    private function persistScheduledSessions(
        array $sD,
        string $itemSoldId,
        string $transId,
        SaleInput $input,
        string $itemId,
        string $companyId,
    ): int {
        // itemSessions DEMOTED a data JSONB → DEBE leerse con ncmExecute.
        $row      = ncmExecute(
            'SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
            [$itemId, $companyId]
        );
        $perItem  = (is_array($row) || $row instanceof \ArrayAccess) ? (int) ($row['itemSessions'] ?? 0) : 0;
        $total    = $perItem * (int) ($sD['count'] ?? 1);

        if ($total <= 0) {
            return 0;
        }

        // El precio de la venta se divide equitativamente entre las N sesiones,
        // con redondeo 'up' (mismo criterio que el legacy: divider($price,$N,'up')).
        $price    = (float) ($sD['price'] ?? 0);
        $perPrice = ($total > 0 && $price > 0) ? (int) ceil($price / $total) : 0;

        // transactionDetails (el contenido de cada sesión) — mismo shape que
        // buildTransactionRecord::meta['transactionDetails']: JSON array de items.
        $detailsJson = json_encode([[
            'itemId' => $sD['itemId'] ?? $itemId,
            'count'  => $sD['count']  ?? 1,
            'price'  => $sD['price']  ?? 0,
            'user'   => $sD['user']   ?? '',
        ]]);

        $invoicePrefix = (string) ($input->invoiceNo ?? 0) . '/';

        for ($i = 0; $i < $total; $i++) {
            // UUID generado acá (no default del schema) para poder vincularlo
            // vía transaction_link (mig 115, kind='package_session') sin
            // depender de RETURNING/Insert_ID del wrapper AutoExecute.
            $sessionId = (string) $this->db->GetOne('SELECT gen_random_uuid()');
            $this->db->AutoExecute('transaction', [
                // meta JSONB: transactionDetails va aquí (no es columna directa en PG).
                'transactionId'         => $sessionId,
                'meta'                  => json_encode(['transactionDetails' => $detailsJson]),
                'transactionDate'       => $input->date,
                'transactionTotal'      => $perPrice,
                'transactionStatus'     => 0,        // pendiente / sin confirmar
                'transactionType'       => 13,       // schedule
                'invoiceNo'             => $i + 1,
                'invoicePrefix'         => $invoicePrefix,
                'customerId'            => $input->clientId,
                'packageId'             => $itemSoldId !== '' ? $itemSoldId : null,
                'registerId'            => $this->ctx->registerId,
                'userId'                => $input->userId ?? $this->ctx->userId,
                'outletId'              => $this->ctx->outletId,
                'companyId'             => $companyId,
            ], 'INSERT');
            $this->links->link($companyId, $transId, $sessionId, 'package_session');
        }
        // updateLastTimeEdit se llama una vez al final del loop de items, NO acá —
        // con varios items con sesiones se dispararía N veces (P2 redundante).
        // Ver persistItemsAndStock donde se llama cuando $hadSessions=true.
        return $total;
    }

    /**
     * Loop de items de la venta: itemSold + COGS + comisiones + descuento de
     * inventario (B8 del legacy, action.php:2105-2253). Corre dentro de la
     * transacción abierta en save().
     *
     * Solo el path SIMPLE: la elegibilidad (SaleInput::assertSimplePathEligible)
     * ya garantizó que NO hay gift cards, sesiones, líneas de crédito ni items
     * sin itemId. Los combos/compounds SÍ se procesan (son productos normales).
     *
     * Excluido vs el legacy (entra en sub-slices futuros):
     *   - sesiones agendadas (itemSessions > 0) → 35d  [guard defensivo abajo]
     *   - gift cards → 35c
     *   - inCredit storeCredit → 35a.5 (payment loop; además dead en legacy)
     *   - devolución type=6 (manageStock source='return') → 35e
     *
     * Reusa helpers globales del legacy (manageStock, getItemStock, etc.) — leen
     * COMPANY_ID/OUTLET_ID/USER_ID de constantes y escriben con el `global $db`
     * (mismo objeto inyectado), corriendo en la misma transacción. Deuda §22.9.
     *
     * @param array<int,array<string,mixed>> $saleDetail
     */
    /**
     * Resuelve `itemSoldDescription` para una línea vendida.
     *
     * Antes esto vivía inline en `persistItemsAndStock` y `persistQuoteItems`,
     * cada uno con la misma condición `$sD['type'] === 'dynamic'` — un item
     * `dynamic` (legacy, freeform sin catalog item) usa `note` como su
     * DESCRIPCIÓN, cargada desde un editor rico, por eso pasa por
     * `markupt2HTML` (HtM: HTML → markup liviano) antes de guardarse.
     *
     * El bug: para CUALQUIER OTRA línea (el caso normal — comentario de línea
     * que el cajero escribe en un textarea plano del POS) la condición nunca
     * matcheaba, así que `itemSoldDescription` quedaba vacío. El comentario
     * sí viajaba y se guardaba en `meta.transactionDetails`, pero cualquier
     * reporte que lee `itemSold` directamente jamás lo veía.
     *
     * `note` es una propiedad de la LÍNEA, no una peculiaridad de `dynamic` —
     * se persiste siempre que venga. Para el caso normal se guarda tal cual:
     * es texto plano de un textarea, no HTML, así que correrlo por HtM no
     * corresponde (esa conversión asume marcado HTML de verdad, que no existe
     * acá — en el peor caso es un no-op, pero afirma una garantía falsa).
     *
     * @param array<string,mixed> $sD
     */
    private function resolveItemSoldDescription(array $sD): ?string
    {
        $note = trim((string) ($sD['note'] ?? ''));
        if ($note === '') {
            return null;
        }

        return ($sD['type'] ?? '') === 'dynamic'
            ? markupt2HTML(['text' => $note, 'type' => 'HtM'])
            : $note;
    }

    /**
     * Resuelve el JSONB `itemSold.meta` para una línea vendida — hoy solo
     * transporta `tags` (etiquetas de línea, uso interno, pedido owner
     * 2026-08-14: "salen en comandas... pero no se imprimen en facturas, se
     * usan internamente"). `itemSold.meta` ya existía para esto exactamente
     * ("future per-line metadata", ver db-schema-postgres.sql) — no hace
     * falta migración.
     *
     * A diferencia de las etiquetas de VENTA (persistRelations B7: `toTag` +
     * `taxonomy` tipo 'tag', requieren que cada tag exista en ese catálogo)
     * acá NO se valida contra ningún catálogo — son texto libre, mismo
     * criterio que `itemSoldDescription`/`note` arriba. `Money::
     * sanitizeSaleArray` ya sanitiza cada entrada (markupt2HTML) antes de
     * que `$sD['tags']` llegue acá.
     *
     * Guardar esto en una columna de `itemSold` (en vez de solo en
     * `transaction.meta.transactionDetails`, que ya las trae vía el mismo
     * sanitizer) es lo que habilita reportar "qué se vendió con la etiqueta
     * X" con una query directa sobre `itemSold`, sin decodificar JSON de
     * cada transacción — el pedido del owner de que sean "uso interno" pide
     * justamente eso a futuro. `null` si la línea no trae tags: no persiste
     * un objeto vacío de más.
     *
     * F3 (context/41): `meta.addon` es además el ÚNICO lugar donde queda el
     * vínculo con la LÍNEA padre exacta. `itemSold.itemSoldParent` NO sirve
     * para eso: su FK es a `item(itemId)` (no a `itemSold`), así que guarda el
     * ítem padre — con dos líneas del mismo producto en el carrito (una con
     * queso, otra sin) sería ambiguo a qué línea pertenece cada add-on. El
     * comentario del schema reserva `itemSold.meta` justamente para esto
     * ("future per-line metadata (modifiers, prep notes…)").
     *
     * F6 (context/41 → reportes): mismo mecanismo para `meta.compound` —
     * componentes de un combo FIJO (`expandCompoundSelections`). Una línea es
     * hija de add-on XOR de compound, nunca las dos, así que un solo
     * parámetro `$parentItemSoldId` alcanza para ambos casos.
     *
     * @param array<string,mixed> $sD
     * @param ?string $parentItemSoldId itemSoldId de la línea padre, ya
     *   insertada (null en cotizaciones y en líneas que no son hija de
     *   add-on ni de compound).
     */
    private function resolveItemSoldMeta(array $sD, ?string $parentItemSoldId = null): ?string
    {
        $meta = [];

        $raw = $sD['tags'] ?? null;
        if (is_array($raw)) {
            $tags = [];
            foreach ($raw as $t) {
                $t = trim((string) $t);
                if ($t !== '') {
                    $tags[] = $t;
                }
            }
            if ($tags !== []) {
                $meta['tags'] = $tags;
            }
        }

        if (!empty($sD['addonOptionId'])) {
            $meta['addon'] = [
                'optionId'         => (string) $sD['addonOptionId'],
                'parentItemSoldId' => $parentItemSoldId,
            ];
        }

        if (!empty($sD['compoundChildItemId'])) {
            $meta['compound'] = [
                'parentItemSoldId' => $parentItemSoldId,
            ];
        }

        return $meta !== [] ? json_encode($meta) : null;
    }

    /**
     * F3 (context/41) — expande las selecciones de add-ons de cada línea en
     * LÍNEAS HIJAS de `$saleDetail`. Corre entre `saleArraySanitizer` y
     * `enrichWithTaxes`, ANTES de abrir la transacción.
     *
     * Por qué acá y no adentro del loop de `itemSold`: así las hijas entran al
     * pipeline como CUALQUIER otra línea de venta y no hay una sola línea de
     * lógica duplicada — se les calcula el IVA con su propio `taxId`
     * (enrichWithTaxes), suman a `transactionTax`/`toTaxObj`, viajan en
     * `meta.transactionDetails` para el ticket y la comanda, generan su
     * `itemSold` y descuentan stock con el MISMO código que las líneas
     * top-level, incluida la explosión recursiva de recetas. Un add-on con
     * receta propia (queso porcionado) descuenta sus insumos igual que si se
     * hubiera vendido suelto.
     *
     * Reparto de la plata (así el DETALLE no cuenta dos veces el recargo): la
     * línea PADRE conserva su importe base tal cual vino del carrito; cada
     * hija lleva ÚNICAMENTE su `priceDelta` (0 si la opción no suma, D2).
     *
     * OJO — `transaction.transactionTotal` NO se deriva de estas líneas: sigue
     * siendo `$input->subtotal`, o sea lo que informa el cliente, EXACTAMENTE
     * con el mismo nivel de confianza que hoy tiene el precio base de
     * cualquier línea (el server recalcula el IVA, no el precio). F3 no
     * cambia ese contrato: hacerlo solo para los add-ons dejaría el precio
     * base sin tocar, y derivar el total de la suma de líneas ROMPERÍA casos
     * vivos donde esa suma no es el total a propósito — el canje de voucher
     * lleva total bruto en la línea y su plata NO está en `transactionTotal`
     * (context/36, ver enrichWithTaxes), y los descuentos viajan aparte en
     * `transactionDiscount`. Consecuencia para F4: el POS DEBE sumar los
     * deltas al `subtotal` y al cobro, igual que ya hace con el precio de
     * cada línea. Cerrar el hueco de verdad = validar el total contra el
     * detalle para TODAS las ventas, no solo las que traen add-ons: es un
     * cambio de contrato propio, no un detalle de esta fase.
     *
     * Cantidades: la qty de la opción se multiplica por las unidades del padre
     * — 2 hamburguesas con queso extra son 2 quesos de stock.
     *
     * El precio NUNCA viaja del cliente: `priceDelta` sale de
     * `addon_group_option`. El payload solo aporta `optionId` + `qty`, y
     * `validateSelections` además agrega solo los `isLocked` que el cliente no
     * mandó (un add-on fijo se cobra y se descuenta aunque el POS lo omita).
     *
     * Línea SIN la key `selections` → se devuelve intacta y no se consulta
     * NADA: la venta del POS actual no cambia en un solo byte.
     *
     * @param array<int,array<string,mixed>> $saleDetail Ya pasado por saleArraySanitizer.
     * @return array<int,array<string,mixed>> Lista reindexada (enrichWithTaxes
     *   asume claves 0..n-1 alineadas con su array interno de líneas).
     * @throws InvalidSaleInputException Selección inválida → 422 (el endpoint
     *   de venta y el de sync offline ya mapean esta excepción).
     */
    private function expandAddonSelections(array $saleDetail, int $decimals): array
    {
        $expanded = [];

        foreach ($saleDetail as $idx => $sD) {
            $selections = $sD['selections'] ?? null;
            $itemId     = (string) ($sD['itemId'] ?? '');

            // Sin key `selections`, sin itemId (descuento / inCredit / gift card
            // legacy) o línea de descuento → nada que expandir.
            if (!is_array($selections) || $itemId === '' || ($sD['type'] ?? '') === 'discount') {
                $expanded[] = $sD;
                continue;
            }

            try {
                $validated = $this->addons->validateSelections($itemId, $this->ctx->companyId, $selections);
            } catch (InvalidAddonSelectionException $e) {
                // Se traduce al mecanismo de errores de validación que la venta
                // YA usa (422 en sales.php y por-venta en offline-sync.php), en
                // vez de inventar uno nuevo.
                throw new InvalidSaleInputException($e->getMessage());
            }

            // Ancla para que las hijas puedan referenciar el `itemSoldId` del
            // padre en su `meta` (ver resolveItemSoldMeta). Es el índice de la
            // línea en el carrito: único y estable dentro de esta venta.
            $parentUid          = 'ln' . $idx;
            $sD['addonLineUid'] = $parentUid;
            $parentUnits = (float) ($sD['count'] ?? 0);

            // ── El recargo se DESCUENTA del padre antes de repartirlo ──────
            // El cliente manda el precio de la línea CON los add-ons adentro
            // (`CartLine.unitPrice = base + Σ deltas`, F4): tiene que ser así
            // para que el subtotal y el cobro del POS ya incluyan el recargo.
            // Si el padre conservaba ese precio Y además cada hija traía su
            // delta, el recargo quedaba DOS VECES en el detalle — y como
            // `enrichWithTaxes` corre sobre todas las líneas, el IVA se
            // calculaba dos veces sobre esa plata (`transactionTax` y
            // `toTaxObj` inflados, y el ticket sumando más que el total
            // cobrado).
            //
            // Invariante que se preserva acá: padre + hijas = exactamente lo
            // que el cliente cobró. Se le resta al padre la suma de los deltas
            // unitarios, que es justo lo que se le reparte a las hijas.
            $unitDeltaSum = 0.0;
            foreach ($validated['lines'] as $line) {
                $optQty = (float) ($line['qty'] ?? 0);
                if ($optQty > 0) {
                    $unitDeltaSum += (float) $line['priceDelta'] / $optQty;
                }
            }

            if ($unitDeltaSum > 0) {
                $parentUnitPrice = (float) ($sD['price'] ?? 0) - $unitDeltaSum;
                // Guard: un precio base negativo significa que el cliente NO
                // mandó el recargo adentro (integración vieja o payload a
                // mano). Ahí no se toca el padre — mejor cobrar de menos en el
                // detalle que emitir una línea en negativo.
                if ($parentUnitPrice >= 0) {
                    $sD['price']    = $parentUnitPrice;
                    $sD['uniPrice'] = $parentUnitPrice;
                    $sD['total']    = round($parentUnitPrice * $parentUnits, $decimals);
                }
            }

            $expanded[]         = $sD;

            foreach ($validated['lines'] as $line) {
                // `priceDelta` de la línea ya viene multiplicado por su qty
                // (unitario × qty). Lo bajamos a unitario para que el motor de
                // impuestos calcule sobre qty × precio como con cualquier otra
                // línea, y el total se redondea con los decimales del tenant.
                $optQty     = (float) $line['qty'];
                $unitDelta  = $optQty > 0 ? ((float) $line['priceDelta'] / $optQty) : 0.0;
                $childUnits = $optQty * $parentUnits;
                $childTotal = round($unitDelta * $childUnits, $decimals);

                $expanded[] = [
                    'itemId'        => (string) $line['itemId'],
                    'count'         => $childUnits,
                    'oQty'          => $childUnits,
                    'name'          => (string) ($line['itemName'] ?? ''),
                    'uniPrice'      => $unitDelta,
                    'price'         => $unitDelta,
                    'total'         => $childTotal,
                    'tax'           => 0.0,   // lo congela enrichWithTaxes con el taxId del add-on
                    'discount'      => 0.0,
                    'totalDiscount' => 0.0,
                    'tags'          => [],
                    // Hereda el vendedor del padre: la comisión del add-on va a
                    // quien vendió el producto.
                    'user'          => $sD['user'] ?? '',
                    'type'          => 'addon',
                    'date'          => $sD['date'] ?? '',
                    'note'          => '',
                    'currency'      => $sD['currency'] ?? '',
                    'uId'           => 0,
                    // `parent` alimenta `itemSold.itemSoldParent`, cuya FK es a
                    // item(itemId) → va el ÍTEM padre, no el itemSoldId. Es lo
                    // que ya esperan sus lectores (ItemRepository::hardDelete
                    // bloquea borrar un ítem que fue padre de una venta;
                    // ProductsService lo indenta con "↳"). El link a la LÍNEA
                    // exacta va en `meta.addon.parentItemSoldId`.
                    'parent'        => $itemId,
                    'isParent'      => null,
                    'giftcard'      => null,
                    'voucher'       => null,
                    'addonOptionId'  => (string) $line['optionId'],
                    'addonParentUid' => $parentUid,
                ];
            }
        }

        return $expanded;
    }

    /**
     * F6 (context/41 → reportes, hallazgo 2026-08-19) — expande la receta de
     * un combo FIJO (`item_compound`) en LÍNEAS HIJAS de `$saleDetail`, mismo
     * mecanismo y mismo lugar de la cadena que `expandAddonSelections` (corre
     * DESPUÉS de esa, ANTES de `enrichWithTaxes`): así cada hija recibe su
     * propio IVA congelado con el `taxId` del componente, entra al ticket/
     * comanda vía `meta.transactionDetails` como cualquier línea, y genera su
     * propio `itemSold` con `itemSoldParent` + `meta.compound`.
     *
     * Por qué hacía falta: hasta ahora un combo fijo se persistía como UNA
     * sola fila en `itemSold` (la del combo) — `item_compound` solo se leía
     * para explotar stock (`persistItemsAndStock`, más abajo), nunca para
     * dejar rastro de qué lo componía. `ProductsService` (reportes) agrega
     * sobre `itemSold`, así que sin estas líneas hijas el reporte no puede
     * desglosar "Combo Sandy" en sus componentes — no es que el reporte
     * filtre mal, es que el dato nunca existió (reporte del tester,
     * "Componentes de combos"). Esto es SOLO hacia adelante: las ventas de
     * combos ya persistidas antes de este cambio NO tienen estas líneas y no
     * se retroactúan.
     *
     * Discriminante `itemType` (DB) ∈ {combo, precombo} — el MISMO criterio
     * que ya usa el cálculo de COGS unas líneas más abajo en
     * `persistItemsAndStock` para reconocer "esto es un combo". No alcanza
     * con "tiene filas en item_compound": esa tabla es compartida con
     * recetas de producción directa (ej. una hamburguesa con ingredientes
     * propios) — generarle líneas hijas a CADA producto con receta inflaría
     * los reportes de venta con cada insumo de cocina, que nunca fue lo que
     * pidió el tester. Un ítem con receta que NO es combo sigue vendiéndose
     * como una sola línea, igual que siempre.
     *
     * Precio SIEMPRE 0 (mismo criterio que un add-on gratis, D2): la plata
     * del combo vive entera en la línea padre — `item_compound` no tiene
     * columna de precio propia (a diferencia de `addon_group_option.
     * priceDelta`), así que no hay nada que restarle al padre. Cantidad =
     * qty del componente (`toCompoundQty`) × unidades del padre.
     *
     * Stock — esto es SOLO trazabilidad, no toca inventario: estas líneas
     * llevan `type: 'compound'`, y `persistItemsAndStock` usa ese marcador
     * para SALTAR tanto la explosión de receta (`explodeRecipe`) como el
     * descuento de stock del ítem propio en esas líneas puntuales — el combo
     * sigue descontando exactamente como antes, con la MISMA `explodeRecipe`
     * recursiva corriendo sobre la línea PADRE. Si estas hijas también
     * decrementaran, el insumo se restaría dos veces.
     *
     * Línea sin `itemId`, de descuento, o cuyo `itemType` no es combo/
     * precombo, o sin filas en `item_compound` → se devuelve intacta, sin
     * queries de más que las estrictamente necesarias para descartar el caso.
     *
     * NO corre en `saveQuote()` — mismo criterio que `expandAddonSelections`:
     * F3 dejó las cotizaciones sin expandir add-ons a propósito (no cobran,
     * no mueven stock), y este método sigue esa misma línea.
     *
     * @param array<int,array<string,mixed>> $saleDetail Ya pasado por expandAddonSelections.
     * @return array<int,array<string,mixed>> Lista reindexada.
     */
    private function expandCompoundSelections(array $saleDetail, int $decimals): array
    {
        $expanded  = [];
        $companyId = $this->ctx->companyId;

        foreach ($saleDetail as $idx => $sD) {
            $itemId = (string) ($sD['itemId'] ?? '');
            if ($itemId === '' || ($sD['type'] ?? '') === 'discount') {
                $expanded[] = $sD;
                continue;
            }

            $itmRow = $this->db->Execute(
                'SELECT itemType FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                [$itemId, $companyId]
            );
            $itemType = ($itmRow && !$itmRow->EOF) ? (string) ($itmRow->fields['itemtype'] ?? '') : '';

            if (!in_array($itemType, ['combo', 'precombo'], true)) {
                $expanded[] = $sD;
                continue;
            }

            $compound = getCompoundsArray($itemId);
            if (!is_array($compound) || $compound === []) {
                $expanded[] = $sD;
                continue;
            }

            // Ancla para que las hijas referencien el `itemSoldId` del padre
            // en su `meta` — mismo patrón que `addonLineUid` en
            // expandAddonSelections, namespace distinto (`cp` vs `ln`) para
            // que nunca colisionen si algún día conviven en la misma venta.
            $parentUid              = 'cp' . $idx;
            $sD['compoundLineUid']  = $parentUid;
            $parentUnits            = (float) ($sD['count'] ?? 0);

            $expanded[] = $sD;

            foreach ($compound as $comp) {
                $childId = $comp['compoundId'] ?? null;
                if (!$childId) {
                    continue;
                }
                $childUnits = (float) ($comp['toCompoundQty'] ?? 0) * $parentUnits;
                if ($childUnits <= 0) {
                    continue;
                }

                $expanded[] = [
                    'itemId'              => (string) $childId,
                    'count'               => $childUnits,
                    'oQty'                => $childUnits,
                    'name'                => '',
                    'uniPrice'            => 0.0,
                    'price'               => 0.0,
                    'total'               => 0.0,
                    'tax'                 => 0.0, // lo congela enrichWithTaxes con el taxId del componente
                    'discount'            => 0.0,
                    'totalDiscount'       => 0.0,
                    'tags'                => [],
                    // Hereda el vendedor del padre, igual que un add-on.
                    'user'                => $sD['user'] ?? '',
                    'type'                => 'compound',
                    'date'                => $sD['date'] ?? '',
                    'note'                => '',
                    'currency'            => $sD['currency'] ?? '',
                    'uId'                 => 0,
                    // `parent` alimenta `itemSold.itemSoldParent` (FK a
                    // item(itemId), no a itemSoldId) — mismo criterio que
                    // addon children, ver expandAddonSelections.
                    'parent'              => $itemId,
                    'isParent'            => null,
                    'giftcard'            => null,
                    'voucher'             => null,
                    'compoundChildItemId' => (string) $childId,
                    'compoundParentUid'   => $parentUid,
                ];
            }
        }

        return $expanded;
    }

    private function persistItemsAndStock(SaleInput $input, string $transId, array $saleDetail): void
    {
        // El loop hardcodea source='sale'/type='-' (descuento de inventario). Las
        // DEVOLUCIONES (type=6) necesitan source='return'/type='+' y flipOnReturn
        // invirtiendo signos — eso es 35e. SaleInput::fromPayload ya garantiza
        // type ∈ {0,3} (Cashsale/Creditsale) vía isSimplePathEligible, pero lo
        // afirmamos acá para que el invariante sea explícito en el money path.
        if (!$input->type->isSimplePathEligible()) {
            throw new InvalidSaleInputException(
                'persistItemsAndStock solo soporta cashsale/creditsale; type=' . $input->type->value
            );
        }

        $typeStr     = (string) $input->type->value;
        $companyId   = $this->ctx->companyId;
        $hadSessions = false; // flag para updateLastTimeEdit una sola vez al final

        // F3 add-ons (context/41) + F6 compound (reportes, 2026-08-19):
        // addonLineUid/compoundLineUid del padre → su itemSoldId ya
        // insertado. `expandAddonSelections`/`expandCompoundSelections` dejan
        // cada hija INMEDIATAMENTE después de su padre, así que para cuando
        // se procesa una hija el padre ya está en el mapa. Un solo mapa
        // alcanza para las dos mecánicas: una línea es hija de UNA sola
        // (nunca las dos a la vez).
        $lineParents = [];

        foreach ($saleDetail as $sD) {
            if (($sD['type'] ?? '') === 'discount') {
                continue; // las líneas de descuento no generan itemSold ni mueven stock
            }

            // ── VENTA de gift card (35c.2) ──────────────────────────────────
            // Crea el giftCardSold. En el legacy esto va FUERA del `if itemId`
            // (action.php:2313): un item giftcard puede no tener itemId. Si lo tiene,
            // genera además itemSold/stock (el body de abajo) — igual que el legacy.
            if (!empty($sD['giftcardId'])) {
                $this->sellGiftCard($sD, $transId, $input);
            }
            if (empty($sD['itemId'])) {
                // inCredit (35e): item sin itemId que acredita crédito interno al cliente.
                // Fix vs legacy (action.php:2380): el legacy concatenaba $sD['total'] crudo
                // (SQLi); acá parametrizamos. Requiere cliente — sin él, skip (igual legacy).
                if (($sD['type'] ?? '') === 'inCredit' && $input->clientId !== null) {
                    $this->persistInCreditItem($sD, $input->clientId, $companyId);
                }
                continue; // sin itemId → no hay itemSold ni stock
            }

            $itemId  = (string) $sD['itemId'];
            $itmData = $this->db->Execute(
                'SELECT itemType, itemPrice FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                [$itemId, $companyId]
            );
            $itemType  = ($itmData && !$itmData->EOF) ? (string) ($itmData->fields['itemtype'] ?? '') : '';
            $itemPrice = ($itmData && !$itmData->EOF) ? (float) ($itmData->fields['itemprice'] ?? 0) : 0.0;

            // Discriminante real de "explota receta al vender" — mismo predicado
            // que usa la explosión de compuestos más abajo (`saleExplodesRecipe`,
            // evaluado en SQL sobre itemProduction/itemTrackInventory para evitar
            // el bug de boolean-coercion de PDO pgsql, ver Inventory.php:234-239).
            // Se calcula UNA vez y se reusa para COGS, explosión y `source` del
            // movimiento de stock — antes cada uso comparaba contra strings que
            // nunca están persistidos (`itemType === 'direct_production'`,
            // `$sD['type'] === 'direct_production'`), dejando esas ramas muertas
            // (hallazgo context/modules/06-produccion.md §7 y context/modules/05-stock.md
            // regla 4: itemSoldCOGS de producción directa quedaba null y el
            // stockSource de esos movimientos nunca era 'production').
            $isCombo            = in_array($itemType, ['precombo', 'combo'], true);
            $explodesRecipe     = \Punto\App\Domain\Inventory::saleExplodesRecipe($itemId, $companyId);
            $isDirectProduction = $explodesRecipe && !$isCombo;

            // ── EMISIÓN de gift card (item de catálogo kind=giftcard) ───────
            // Distinta de sellGiftCard() (rama legacy `sD['type']==='giftcard'`,
            // sin itemId, @deprecated) — acá el item SÍ es un item de catálogo
            // real (itemType='giftcard'), genera itemSold/stock normal COMO
            // CUALQUIER OTRO ítem (ver abajo) y ADEMÁS crea la fila en
            // `giftcard` si el front mandó metadata (sD['giftcard']). Sin
            // metadata (front viejo / venta sin dialog) el item se vende
            // igual mas no emite gift card — evita romper ventas existentes.
            if ($itemType === 'giftcard' && !empty($sD['giftcard']) && is_array($sD['giftcard'])) {
                $this->issueGiftCard($sD, $itemId, $transId, $companyId);
            }

            // F6 compound (reportes, 2026-08-19): estas líneas son PURA
            // trazabilidad — cero comisión, cero COGS propio. El costo de
            // estos componentes YA está en el COGS del PADRE (`getComboCOGS`,
            // más abajo, bundlea toda la receta) — si esta hija sumara TAMBIÉN
            // su propio COGS (`getItemStock`), el costo del combo se contaría
            // DOS VECES en cualquier reporte que sume COGS por producto (una
            // vez en la fila del combo, otra en la fila de este componente).
            // Comisión fija (`contactFixedComission`, no depende del importe
            // de la línea) tiene el mismo riesgo — cero acá, cero excepciones.
            $isCompoundChild = ($sD['type'] ?? '') === 'compound';

            $comission = 0.0;
            $cogsVal   = null;

            if (!$isCompoundChild) {
                // ── comisión del usuario asignado a la línea (si tiene fija > 0) ──
                // contactFixedComission está DEMOTED a `data` JSONB → ncmExecute (flatten),
                // NO $this->db->Execute crudo (la key no existiría → comisión fija nunca
                // se aplicaría = divergencia financiera vs legacy). §22.8.
                $userComission = false;
                if (!empty($sD['user'])) {
                    $contactRow = ncmExecute(
                        'SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',
                        [(string) $sD['user'], $companyId]
                    );
                    if (is_array($contactRow) || $contactRow instanceof \ArrayAccess) {
                        $fixed = (float) ($contactRow['contactFixedComission'] ?? 0);
                        if ($fixed > 0) {
                            $userComission = $fixed;
                        }
                    }
                }

                $comissionTotal = ($sD['type'] ?? '') === 'inCombo'
                    ? $itemPrice * (float) $sD['count']
                    : (float) $sD['total'];

                $comission = $userComission !== false
                    ? getUserComissionTotal($comissionTotal, $userComission)
                    : getItemComsissionTotal($itemId, $sD['count'], $comissionTotal);

                // ── COGS según tipo de item ─────────────────────────────────
                //
                // CONTRATO: `itemSold.itemSoldCOGS` es el costo UNITARIO (por
                // una unidad vendida), NO el costo de la línea. Todo consumidor
                // que quiera el costo total multiplica por `itemSoldUnits`
                // (`Reports\ProductsService`, `Reports\ProductionService`,
                // `StockReversalPolicy`). Ver el docblock de `RecipeCosting`.
                //
                $itemSoldCOGS = [];
                // Predicados reales (fix 2026-08-19): `$itemType` es un string
                // sintetico de UI que NUNCA se persiste, asi que comparar contra
                // 'direct_production' dejaba el COGS en null siempre.
                //
                // La sucursal es la de la VENTA (`$this->ctx->outletId`), no la
                // de la sesión: el costo promedio de un insumo es POR sucursal,
                // y los wrappers legacy (`getProductionCOGS`/`getComboCOGS`)
                // caían en `OUTLET_ID` — una venta de la sucursal B se costeaba
                // con el promedio de la A (reporte del tester "Actualización 21"
                // #1). Se llama a `RecipeCosting` directo, la única fórmula de
                // costo de receta: misma explosión recursiva que mueve el stock,
                // con merma por nivel y fallback a `item.itemCost`.
                //
                // El try/catch NO es defensivo por si acaso: `RecipeCosting`
                // exige la sucursal y tira si falta, y esta venta YA fue
                // emitida en la caja (ticket impreso, plata cobrada). El back
                // nunca rechaza una venta emitida — el COGS es dato de
                // reporte, no el hecho económico. Sin sucursal se guarda la
                // venta con COGS null y queda el rastro en el log, en vez de
                // devolver un 500 que el POS reintenta para siempre.
                if ($isDirectProduction || $isCombo) {
                    try {
                        $itemSoldCOGS['stockOnHandCOGS'] = \Punto\App\Domain\RecipeCosting::total(
                            $itemId,
                            $companyId,
                            $this->ctx->outletId
                        );
                    } catch (\InvalidArgumentException $e) {
                        error_log('SaleService: no se pudo costear la receta de ' . $itemId . ' — ' . $e->getMessage());
                    }
                } else {
                    // Con la sucursal de la VENTA: sin ella cae en OUTLET_ID (la de la
                    // sesión) y el costo del item vendido sale del stock de otra
                    // sucursal.
                    $itemSoldCOGS = getItemStock($itemId, $this->ctx->outletId);
                }
                $cogsVal = (is_array($itemSoldCOGS) || $itemSoldCOGS instanceof \ArrayAccess)
                    ? ($itemSoldCOGS['stockOnHandCOGS'] ?? null)
                    : null;
            }

            // ── INSERT itemSold ─────────────────────────────────────────────
            $records = [
                'itemSoldTotal'     => flipOnReturn($typeStr, $sD['total']),
                // F2a (context/38): antes addTax($sD['tax'], $sD['total']) — el
                // payload mandaba tax=0 siempre, así que esto daba $0 de IVA en
                // todos los reportes. taxAmount ya viene calculado por TaxEngine
                // dentro de enrichWithTaxes(), congelado por línea.
                'itemSoldTax'       => flipOnReturn($typeStr, (float) ($sD['taxAmount'] ?? 0)),
                'itemSoldDiscount'  => flipOnReturn($typeStr, $sD['totalDiscount']),
                'itemSoldUnits'     => flipOnReturn($typeStr, $sD['count']),
                'itemSoldComission' => flipOnReturn($typeStr, $comission),
                'itemSoldCOGS'      => flipOnReturn($typeStr, $cogsVal),
                'itemSoldParent'    => !empty($sD['parent']) ? $sD['parent'] : null,
                'itemId'            => $itemId,
                'itemSoldDate'      => $input->date,
                'transactionId'     => $transId,
                'userId'            => !empty($sD['user']) ? (string) $sD['user'] : null,
                // D4 de context/48-escalamiento-de-datos.md (mig 156):
                // itemSold gana estas 3 columnas denormalizadas para poder
                // filtrar reportes por sucursal/caja sin JOIN a transaction
                // (que tras el particionado es más caro). Mismos valores que
                // buildTransactionRecord() ya usó para el INSERT de arriba —
                // no hace falta releer nada.
                'companyId'         => $this->ctx->companyId,
                'outletId'          => $this->ctx->outletId,
                'registerId'        => $this->ctx->registerId,
            ];
            $itemSoldDescription = $this->resolveItemSoldDescription($sD);
            if ($itemSoldDescription !== null) {
                $records['itemSoldDescription'] = $itemSoldDescription;
            }
            // F3 add-ons + F6 compound: si esta línea es una hija (de add-on O
            // de compound, nunca las dos), su `meta` guarda el itemSoldId de
            // la línea padre (ya insertada en una vuelta previa).
            $parentLineUid    = $sD['addonParentUid'] ?? $sD['compoundParentUid'] ?? null;
            $parentItemSoldId = !empty($parentLineUid) ? ($lineParents[(string) $parentLineUid] ?? null) : null;
            $itemSoldMeta     = $this->resolveItemSoldMeta($sD, $parentItemSoldId);
            if ($itemSoldMeta !== null) {
                $records['meta'] = $itemSoldMeta;
            }
            $this->db->AutoExecute('itemSold', $records, 'INSERT');
            $itemSoldId = (string) $this->db->Insert_ID();

            // F3 add-ons + F6 compound: el padre publica su itemSoldId para
            // las hijas que vienen atrás. Best-effort a propósito: si el mapa
            // no tuviera la entrada, la hija se guarda igual con
            // parentItemSoldId=null — nunca debe hacer fallar la venta por un
            // dato de trazabilidad.
            //
            // Publica bajo LAS DOS keys si están presentes (no `??`): un
            // combo fijo puede tener SUS PROPIOS add-ons además de su receta
            // (AddonService no restringe por itemType), así que la MISMA
            // línea padre puede ser ancla de hijas de add-on Y de hijas de
            // compound a la vez. Con `??` solo se publicaba una de las dos —
            // las hijas del otro mecanismo quedaban con parentItemSoldId=null
            // pese a que el padre SÍ estaba insertado. El lado hijo (arriba,
            // `$parentLineUid`) sigue siendo XOR de verdad: una hija es de
            // add-on O de compound, nunca las dos.
            foreach (['addonLineUid', 'compoundLineUid'] as $uidKey) {
                if (!empty($sD[$uidKey])) {
                    $lineParents[(string) $sD[$uidKey]] = $itemSoldId;
                }
            }

            // ── B8 (35d): sesiones agendadas ────────────────────────────────
            // Si el item tiene itemSessions > 0 en BD (campo demoted a JSONB) y
            // la venta tiene cliente, crea N filas type=13 en transaction, una
            // por sesión (packageId = itemSoldId del item vendido). Dentro de la
            // transacción principal: si algo falla, se rollbackea con la venta.
            if ($input->clientId !== null) {
                $sessionsCreated = $this->persistScheduledSessions($sD, $itemSoldId, $transId, $input, $itemId, $companyId);
                if ($sessionsCreated > 0) {
                    $hadSessions = true;
                }
            }

            $units = (float) $sD['count'];

            // F6 compound (reportes, 2026-08-19): estas líneas son PURA
            // trazabilidad (expandCompoundSelections) — el combo padre ya
            // descontó estos mismos insumos, recursivamente, con la
            // explosión de más abajo. Si esta línea también moviera stock, el
            // insumo se restaría dos veces. `continue` acá: nada de lo que
            // sigue en esta vuelta del loop (compound propio, descuento del
            // item, sesiones ya corrieron arriba) aplica a una hija de reporte.
            if (($sD['type'] ?? '') === 'compound') {
                continue;
            }

            // ── compounds: descuenta el stock de los ingredientes ───────────
            // El guard de "producción previa" se resuelve contra la BD
            // (Inventory::saleExplodesRecipe), NO contra `$sD['type']`: ese
            // campo es opcional y el POS nunca lo manda, así que el chequeo
            // `!== 'production'` de acá jamás cortaba y un terminado ya
            // producido volvía a consumir sus insumos en cada venta.
            // Se van los chequeos contra `$sD['type']` ('combo' / 'production'):
            // el propio comentario de arriba dice que el POS no manda ese campo,
            // así que no cortaban nada — pero si algún cliente empezaba a
            // mandarlo, `type = 'combo'` habría apagado la explosión justo en el
            // caso que hay que explotar. El predicado real es
            // `saleExplodesRecipe()` (ya calculado arriba en `$explodesRecipe`),
            // que se resuelve contra la BD.
            $compound = getCompoundsArray($itemId);
            if (is_array($compound) && $compound !== [] && $explodesRecipe) {
                // Explosión RECURSIVA: la receta puede tener varios niveles y
                // recorrer solo el primero deja sin descontar todo lo que
                // cuelga de un hijo sin stock propio. En "Combo 30 Piezas"
                // (combo → 3 rolls → insumos) se descontaba únicamente el roll
                // que trackea inventario; los insumos de los otros dos no se
                // tocaban nunca. `explodeRecipe` baja hasta lo que realmente
                // mueve stock y acumula la merma de cada nivel.
                //
                // `source`: 'production' SOLO si el item VENDIDO es en sí un
                // producción directa (no un combo que a su vez contiene un
                // producción directa) — mismo criterio que `$isDirectProduction`
                // de arriba. Antes comparaba contra `$sD['type']`, un campo que
                // el POS nunca manda (mismo bug que el COGS, ver comentario
                // arriba) — el `stockSource` de la explosión de receta en venta
                // quedaba siempre 'sale', nunca 'production', y el tab
                // "Compuestos" de Reportes\Producción (filtra stockSource=
                // 'production') salía vacío para toda venta de producción
                // directa.
                $source = $isDirectProduction ? 'production' : 'sale';
                $leaves = \Punto\App\Domain\Inventory::explodeRecipe($itemId, $companyId, (float) $units);

                foreach ($leaves as $comid => $comunits) {
                    $locRow   = $this->db->Execute(
                        'SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                        [$comid, $companyId]
                    );
                    $comLoc   = ($locRow && !$locRow->EOF) ? ($locRow->fields['locationid'] ?? null) : null;

                    manageStock([
                        'itemId'        => $comid,
                        'outletId'      => $this->ctx->outletId,
                        'date'          => TODAY,
                        'locationId'    => $comLoc,
                        'count'         => $comunits,
                        'type'          => '-',
                        'source'        => $source,
                        'transactionId' => $transId,
                        'timestamp'     => $input->timestamp,
                    ]);
                }
            }

            // ── descuento de inventario del item principal ──────────────────
            // manageStock retorna false (no-op) si el item no es stockeable
            // (itemTrackInventory < 1) — servicios/items sin stock venden OK.
            $locRow = $this->db->Execute(
                'SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                [$itemId, $companyId]
            );
            $itemLoc = ($locRow && !$locRow->EOF) ? ($locRow->fields['locationid'] ?? null) : null;

            manageStock([
                'itemId'        => $itemId,
                'outletId'      => $this->ctx->outletId,
                'date'          => TODAY,
                'locationId'    => $itemLoc,
                'count'         => $units,
                'type'          => '-',
                'source'        => 'sale',
                'transactionId' => $transId,
                'timestamp'     => $input->timestamp,
            ]);
        }

        // updateLastTimeEdit UNA SOLA VEZ al final del loop (el legacy lo llamaba
        // una vez por item con sesiones — redundante; lo hoistamos aquí).
        if ($hadSessions) {
            updateLastTimeEdit($companyId, 'calendar');
        }
    }

    /**
     * Guarda el carrito como cotización (type=9).
     *
     * Diferencias vs save():
     *   - NO requiere payment ni caja abierta
     *   - NO mueve stock
     *   - NO genera movimiento de caja
     *   - NO factura electrónica
     *   - SÍ guarda transaction + itemSold con type=9
     *   - SÍ genera quoteNo desde register.registerQuoteNumber
     *   - NO expande add-ons (F3 es solo la VENTA, context/41): si una línea
     *     trae `selections`, se IGNORAN en silencio — quedan en el JSON del
     *     detalle, sin líneas hijas ni recargo. Se eligió ignorar y no
     *     rechazar por coherencia con el resto de saveQuote, que ya acepta
     *     payloads que la venta rechaza (no corre assertSimplePathEligible) y
     *     nunca falla por algo que no afecta plata: una cotización no cobra ni
     *     mueve stock. Cotizar con add-ons entra con la UI (F4/F5), junto con
     *     la conversión quote→venta.
     *
     * // TODO: quote-to-sale conversion — context/12 sprint futuro
     */
    public function saveQuote(SaleInput $input): array
    {
        if ($input->type !== SaleType::Quote) {
            throw new InvalidSaleInputException('saveQuote requiere type=9');
        }

        $dupRow = $this->db->Execute(
            'SELECT transactionId FROM transaction WHERE transactionUID = ? LIMIT 1',
            [$input->uid]
        );
        if ($dupRow && !$dupRow->EOF) {
            return [
                'transactionId'  => (string) $dupRow->fields['transactionid'],
                'transactionNo'  => 0,
                'transactionDoc' => '',
                'duplicated'     => true,
            ];
        }

        if ($input->clientId !== null) {
            $client = $this->db->Execute(
                'SELECT contactId FROM contact WHERE contactId = ? AND companyId = ? AND type = 1 LIMIT 1',
                [$input->clientId, $this->ctx->companyId]
            );
            if (!$client || $client->EOF) {
                throw new InvalidSaleInputException(
                    "client {$input->clientId} no existe o no pertenece al tenant"
                );
            }
        }

        // F2a (context/38): la cotización congela el desglose de impuestos
        // igual que la venta — si se convierte en venta más tarde, reimprimir
        // o auditar la cotización tiene que mostrar la misma tasa con la que
        // se cotizó, no la del catálogo al momento de mirarla.
        $decimals      = $this->currencyDecimals();
        $saleDetail    = $this->enrichWithTaxes(saleArraySanitizer($input->sale), $input->ivaRemoved, $decimals);
        $totalUnits    = countUnitSold($saleDetail);
        $userId        = $input->userId ?? $this->ctx->userId;
        $responsibleId = ($userId !== $this->ctx->userId) ? $this->ctx->userId : null;

        $this->db->StartTrans();

        // DENTRO de la transacción a propósito (D1, context/37): si la
        // cotización no persiste, el rollback devuelve también el número y no
        // queda hueco en el correlativo. Antes se calculaba antes del
        // StartTrans y una cotización abortada quemaba el número.
        $quoteNo = $this->getNextQuoteNumber();

        $record = $this->buildTransactionRecord(
            input:         $input,
            saleDetail:    $saleDetail,
            totalUnits:    $totalUnits,
            userId:        $userId,
            responsibleId: $responsibleId,
            decimals:      $decimals,
        );
        $record['invoiceNo']           = $quoteNo;
        $record['transactionComplete'] = 1;

        $insertOk = $this->db->AutoExecute('transaction', $record, 'INSERT');
        $transId  = $this->db->Insert_ID();

        if ($insertOk !== false && !empty($transId)) {
            $this->persistRelations($input, (string) $transId, $saleDetail, $decimals);
            $this->persistQuoteItems($input, (string) $transId, $saleDetail);
        }

        $dbError = $this->db->ErrorMsg();
        $failed  = $this->db->HasFailedTrans();
        $this->db->CompleteTrans();

        $persisted = false;
        if ($insertOk !== false && !empty($transId)) {
            $check = $this->db->Execute(
                'SELECT transactionId FROM transaction WHERE transactionId = ? LIMIT 1',
                [(string) $transId]
            );
            $persisted = $check && !$check->EOF;
        }

        if ($failed || $insertOk === false || empty($transId) || !$persisted) {
            throw new SaleAbortedException(
                dbError: $dbError !== '' ? $dbError : null,
                message: 'Quote transaction aborted',
            );
        }

        // El contador legacy `registerQuoteNumber` ya no se escribe: la
        // secuencia se incrementó sola al reservar el número. Mantener ambos
        // dejaría dos fuentes de verdad divergiendo en silencio.

        return [
            'transactionId'  => enc((string) $transId),
            'transactionNo'  => $quoteNo,
            'transactionDoc' => '',
            'duplicated'     => false,
        ];
    }

    /**
     * Loop de items para cotización: solo itemSold, sin manageStock.
     *
     * @param array<int,array<string,mixed>> $saleDetail
     */
    private function persistQuoteItems(SaleInput $input, string $transId, array $saleDetail): void
    {
        $companyId = $this->ctx->companyId;

        foreach ($saleDetail as $sD) {
            if (($sD['type'] ?? '') === 'discount') {
                continue;
            }
            if (empty($sD['itemId'])) {
                continue;
            }

            $itemId  = (string) $sD['itemId'];
            $itmData = $this->db->Execute(
                'SELECT itemType, itemPrice FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                [$itemId, $companyId]
            );
            $itemPrice = ($itmData && !$itmData->EOF) ? (float) ($itmData->fields['itemprice'] ?? 0) : 0.0;

            $userComission = false;
            if (!empty($sD['user'])) {
                $contactRow = ncmExecute(
                    'SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',
                    [(string) $sD['user'], $companyId]
                );
                if (is_array($contactRow) || $contactRow instanceof \ArrayAccess) {
                    $fixed = (float) ($contactRow['contactFixedComission'] ?? 0);
                    if ($fixed > 0) {
                        $userComission = $fixed;
                    }
                }
            }

            $comissionTotal = ($sD['type'] ?? '') === 'inCombo'
                ? $itemPrice * (float) $sD['count']
                : (float) $sD['total'];

            $comission = $userComission !== false
                ? getUserComissionTotal($comissionTotal, $userComission)
                : getItemComsissionTotal($itemId, $sD['count'], $comissionTotal);

            $itemSoldCOGS = getItemStock($itemId, $this->ctx->outletId);
            $cogsVal = (is_array($itemSoldCOGS) || $itemSoldCOGS instanceof \ArrayAccess)
                ? ($itemSoldCOGS['stockOnHandCOGS'] ?? null)
                : null;

            $records = [
                'itemSoldTotal'     => (float) $sD['total'],
                // F2a (context/38): idem persistItemsAndStock — taxAmount ya
                // sale del motor, congelado por enrichWithTaxes().
                'itemSoldTax'       => (float) ($sD['taxAmount'] ?? 0),
                'itemSoldDiscount'  => (float) $sD['totalDiscount'],
                'itemSoldUnits'     => (float) $sD['count'],
                'itemSoldComission' => $comission,
                'itemSoldCOGS'      => $cogsVal,
                'itemSoldParent'    => !empty($sD['parent']) ? $sD['parent'] : null,
                'itemId'            => $itemId,
                'itemSoldDate'      => $input->date,
                'transactionId'     => $transId,
                'userId'            => !empty($sD['user']) ? (string) $sD['user'] : null,
                // D4 de context/48-escalamiento-de-datos.md (mig 156) — ver
                // el comentario gemelo en persistItemsAndStock() más arriba.
                'companyId'         => $this->ctx->companyId,
                'outletId'          => $this->ctx->outletId,
                'registerId'        => $this->ctx->registerId,
            ];
            $itemSoldDescription = $this->resolveItemSoldDescription($sD);
            if ($itemSoldDescription !== null) {
                $records['itemSoldDescription'] = $itemSoldDescription;
            }
            $itemSoldMeta = $this->resolveItemSoldMeta($sD);
            if ($itemSoldMeta !== null) {
                $records['meta'] = $itemSoldMeta;
            }
            $this->db->AutoExecute('itemSold', $records, 'INSERT');
        }
    }

    /**
     * F2a del plan multi-país (context/38-impuestos-multi-pais.md, sección
     * "Arquitectura propuesta → B y C"). Evolución de `withTaxRates`
     * (commits 81d5d66d + b20bd721): antes solo congelaba `taxRate` por
     * línea; ahora corre el TaxEngine server-side completo y congela TODO
     * el desglose — el POS sigue mandando `tax:0` (su cableado al motor TS
     * es F2b), pero la venta ya persiste IVA real porque el backend lo
     * recalcula solo, sin confiar en nada del payload salvo qty/precio/
     * descuento por línea.
     *
     * Por línea agrega: `taxId`, `taxRate`, `taxKind`, `taxIncluded`
     * (resueltos del catálogo — nunca del payload), y `taxAmount`/`taxNet`
     * (salida de TaxEngine::computeTaxes, la fuente que leen itemSold/
     * transactionTax/toTaxObj más abajo).
     *
     * La factura paraguaya separa el detalle en columnas por tasa (exentas /
     * 5% / 10%) y la reimpresión de un documento fiscal tiene que salir IGUAL
     * a como se emitió. Resolver la tasa contra el catálogo al reimprimir daría
     * la tasa ACTUAL del ítem: si alguien la cambió después, la copia saldría
     * distinta del original. Por eso se persiste acá, al momento de vender.
     *
     * @param array<int,array<string,mixed>> $saleDetail
     * @param bool $ivaRemoved Flag de venta (mig 101, toggle "quitar IVA" del
     *   POS). El front, cuando está activo, ya divide el precio por línea
     *   ANTES de mandarlo (`price` llega neto — ver
     *   frontend/lib/commands/create-sale.ts:299) — si el backend encima le
     *   aplicara una tasa, se restaría IVA dos veces. Por eso acá NO se toca
     *   el precio: se fuerza taxRate=0/taxKind='exempt' efectivos en TODAS
     *   las líneas para que el motor pase el precio ya-neto sin tocarlo
     *   (rama `exempt` de TaxEngine no aplica ninguna fórmula de inclusión).
     *   `taxId` de catálogo se preserva igual (dato informativo, no se borra).
     * @param int $decimals Decimales del tenant (ver `currencyDecimals()`) —
     *   mismo valor que usan `sumLineTax`/`groupTaxByRate` después, así el
     *   detalle y los agregados redondean idéntico.
     * @return array<int,array<string,mixed>>
     */
    private function enrichWithTaxes(array $saleDetail, bool $ivaRemoved, int $decimals): array
    {
        $itemIds = [];
        foreach ($saleDetail as $sD) {
            $id = (string) ($sD['itemId'] ?? '');
            if ($id !== '') {
                $itemIds[$id] = true;
            }
        }

        $itemMeta = [];
        if ($itemIds !== []) {
            $ids = array_keys($itemIds);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            // Los impuestos viven HOY en dos tablas y hay que mirar las dos:
            // mig 23 sacó `tax` de `taxonomy` reusando el MISMO UUID, pero no
            // eliminó la vieja — algún taxId viejo puede no tener fila en `tax`
            // todavía (mig 120 hizo backfill masivo, pero no hay garantía de
            // que TODO taxId de TODO item haya quedado cubierto). Mirar una
            // sola tabla dejaría a esos ítems como exentos en silencio.
            // `tax.rate`/`tax.kind` (mig 120) son la fuente numérica/tipada
            // cuando hay fila en `tax`; si no, se cae al parseo legacy de
            // `name` (mismo criterio que TaxService::deriveRateKindFromName).
            //
            // `i.data->>'itemTaxIncluded'` extrae el override por ítem del
            // JSONB SIN pasar por el auto-demote de flattenJsonb (que solo
            // aplica si seleccionás la columna `data` cruda) — bug histórico
            // repetido 5 veces (api/v1/register.php:110), acá se evita
            // extrayendo el campo puntual directo en SQL.
            $rows = ncmExecute(
                "SELECT i.itemId AS itemid,
                        i.taxId AS itemtaxid,
                        tx.rate AS taxrate,
                        tx.kind AS taxkind,
                        COALESCE(tx.name, tn.taxonomyName) AS taxname,
                        i.data->>'itemTaxIncluded' AS itemtaxincluded
                   FROM item i
                   LEFT JOIN tax tx
                     ON tx.taxId = i.taxId AND tx.companyId = i.companyId
                   LEFT JOIN taxonomy tn
                     ON tn.taxonomyId = i.taxId AND tn.taxonomyType = 'tax'
                  WHERE i.itemId IN ($ph) AND i.companyId = ?",
                array_merge($ids, [$this->ctx->companyId]),
                false,
                false,
                true
            );
            foreach ((is_array($rows) ? $rows : []) as $r) {
                $id = (string) ($r['itemid'] ?? '');
                if ($id === '') {
                    continue;
                }
                if ($r['taxrate'] !== null && $r['taxkind'] !== null) {
                    $rate = (float) $r['taxrate'];
                    $kind = (string) $r['taxkind'];
                } else {
                    [$rate, $kind] = self::deriveTaxRateKindFromName((string) ($r['taxname'] ?? ''));
                }
                $itemMeta[$id] = [
                    'taxId'          => $r['itemtaxid'] ? (string) $r['itemtaxid'] : null,
                    'rate'           => $rate,
                    'kind'           => $kind,
                    'taxIncludedRaw' => $r['itemtaxincluded'] ?? null,
                ];
            }
        }

        // Default de taxIncluded cuando el ítem no lo define: el del outlet.
        // `outlet.itemsTaxIncluded` vive DEMOTED a `data` JSONB (OutletsService)
        // — `SELECT *` vía ncmExecute lo auto-aplana (Query::flattenJsonb ve la
        // columna `data` literal y la demota). Default true si NULL/sin fila:
        // mismo default fiscal que usa OutletsService::create().
        $outletTaxIncludedDefault = true;
        if ($saleDetail !== []) {
            $outletRow = ncmExecute(
                'SELECT * FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',
                [$this->ctx->outletId, $this->ctx->companyId]
            );
            if (is_array($outletRow) || $outletRow instanceof \ArrayAccess) {
                // self::toBoolOrNull y no (bool): el valor llega del JSONB como
                // STRING ("false", "0", "true", "1") según quién lo haya
                // escrito, y (bool) "false" === true en PHP — el modo "IVA no
                // incluido" del outlet se ignoraría en silencio.
                $outletTaxIncludedDefault = self::toBoolOrNull($outletRow['itemsTaxIncluded'] ?? null) ?? true;
            }
        }

        $lines = [];
        foreach ($saleDetail as $i => $sD) {
            $id   = (string) ($sD['itemId'] ?? '');
            $meta = $itemMeta[$id] ?? null;

            $rate  = $meta['rate'] ?? 0.0;
            $kind  = $meta['kind'] ?? 'exempt';
            $taxId = $meta['taxId'] ?? null;

            if ($ivaRemoved) {
                $rate = 0.0;
                $kind = 'exempt';
            }

            // Canje de voucher (context/36, decisión 5): la línea lleva total
            // bruto pero su plata NO está en transactionTotal — el vale ya se
            // cobró (y devengó SU IVA) en la venta que lo emitió. Computarle
            // IVA acá lo duplicaría en transactionTax sin respaldo en el
            // total. Se fuerza exenta; taxId de catálogo se preserva igual
            // (informativo, mismo criterio que ivaRemoved).
            if (is_array($sD['voucher'] ?? null)) {
                $rate = 0.0;
                $kind = 'exempt';
            }

            // toBoolOrNull y no (bool): `data->>'itemTaxIncluded'` devuelve el
            // booleano del JSONB como STRING — "false" casteado con (bool) da
            // true, y el override "IVA no incluido" del ítem se perdería.
            $taxIncluded = self::toBoolOrNull($meta['taxIncludedRaw'] ?? null) ?? $outletTaxIncludedDefault;

            $saleDetail[$i]['taxId']       = $taxId;
            $saleDetail[$i]['taxRate']     = $rate;
            $saleDetail[$i]['taxKind']     = $kind;
            $saleDetail[$i]['taxIncluded'] = $taxIncluded;

            // qty/unitPrice/discount: mismos campos que persistScheduledSessions
            // y el loop de itemSold ya usan (`count`/`price`/`totalDiscount`,
            // ver frontend/lib/commands/create-sale.ts:296-307). `total` NO se
            // usa acá — es el bruto sin descontar calculado client-side con la
            // fórmula vieja hardcodeada a 10%; el motor recalcula desde cero.
            $lines[] = [
                'qty'         => (float) ($sD['count'] ?? 0),
                'unitPrice'   => (float) ($sD['price'] ?? 0),
                'discount'    => (float) ($sD['totalDiscount'] ?? 0),
                'taxRate'     => $rate,
                'taxKind'     => $kind,
                'taxIncluded' => $taxIncluded,
            ];
        }

        if ($lines !== []) {
            $result = TaxEngine::computeTaxes($lines, ['decimals' => $decimals]);
            foreach ($saleDetail as $i => $sD) {
                $saleDetail[$i]['taxAmount'] = $result['lines'][$i]['tax'];
                $saleDetail[$i]['taxNet']    = $result['lines'][$i]['net'];
            }
        }

        return $saleDetail;
    }

    /**
     * Decimales del tenant para redondeo fiscal (D1, context/38): PY 0
     * decimales, MX/otros LATAM con centavos 2. Mismo campo que expone
     * /v1/bootstrap y el mismo criterio que
     * SpaceSettlementService::currencyDecimals() — duplicado a propósito acá
     * (5 líneas, contextos de dominio distintos) en vez de forzar un import
     * cross-módulo por un helper tan chico.
     */
    private function currencyDecimals(): int
    {
        $row  = ncmExecute(
            "SELECT config->>'settingDecimal' AS decimalflag FROM company WHERE companyId = ? LIMIT 1",
            [$this->ctx->companyId]
        );
        $flag = $row ? (string) ($row['decimalflag'] ?? '') : '';
        return $flag === 'yes' ? 2 : 0;
    }

    /**
     * rate/kind derivados de `name` cuando el taxId de la línea no tiene fila
     * en `tax` (solo taxonomy, o taxId huérfano). Misma regla que
     * TaxService::deriveRateKindFromName y que el backfill de la mig 120:
     * primer número del texto (coma o punto decimal) → kind='rate'; sin
     * número, o número > 100 (no es una tasa real, desborda DECIMAL(5,2)) →
     * kind='exempt', rate=0 — el default fiscal seguro, nunca se inventa una
     * tasa. Duplicado a propósito (8 líneas, TaxService es un dominio
     * distinto — catálogo vs venta).
     *
     * @return array{0: float, 1: string}
     */
    private static function deriveTaxRateKindFromName(string $name): array
    {
        if (preg_match('/\d+(?:[.,]\d+)?/', $name, $m)) {
            $rate = (float) str_replace(',', '.', $m[0]);
            if ($rate <= 100) {
                return [$rate, 'rate'];
            }
        }
        return [0.0, 'exempt'];
    }

    /**
     * Normaliza a bool un valor que puede venir de JSONB aplanado: PHP bool,
     * int, o STRING "true"/"false"/"1"/"0" (data->> siempre devuelve texto).
     * (bool) "false" === true — ese cast ingenuo ya se prohibió acá dos veces.
     * null/desconocido → null, para que el caller aplique su default.
     */
    private static function toBoolOrNull(mixed $v): ?bool
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }
        return filter_var((string) $v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Suma taxAmount por línea ya congelado por enrichWithTaxes() — es lo que
     * persiste `transactionTax`. Redondea con la misma regla del motor
     * (TaxEngine::roundHalfUp) para no divergir del detalle por ruido de
     * punto flotante al sumar floats ya redondeados.
     *
     * @param array<int,array<string,mixed>> $saleDetail
     */
    private function sumLineTax(array $saleDetail, int $decimals): float
    {
        $sum = 0.0;
        foreach ($saleDetail as $sD) {
            $sum += (float) ($sD['taxAmount'] ?? 0);
        }
        return TaxEngine::roundHalfUp($sum, $decimals);
    }

    /**
     * Agrupa las líneas ya congeladas por (taxRate, taxKind) — el desglose
     * que persiste en `toTaxObj` (ver persistRelations). Shape:
     * `[{taxId, rate, kind, base, amount}]` (arquitectura propuesta → C,
     * context/38). `taxId` es informativo (primera línea del bucket); el
     * agrupamiento fiscal real es por tasa, no por fila de catálogo — dos
     * taxId distintos con la misma tasa/kind caen en el mismo bucket, que es
     * como Paraguay separa el detalle fiscal (por columna de tasa).
     *
     * @param array<int,array<string,mixed>> $saleDetail
     * @return list<array{taxId:?string,rate:float,kind:string,base:float,amount:float}>
     */
    private function groupTaxByRate(array $saleDetail, int $decimals): array
    {
        $buckets = [];
        $order   = [];
        foreach ($saleDetail as $sD) {
            if (!array_key_exists('taxRate', $sD)) {
                continue; // línea que no pasó por enrichWithTaxes (no debería pasar)
            }
            // Las líneas sintéticas de descuento (type='discount', legacy) no
            // son ítems: su neto negativo se colaría como base NEGATIVA del
            // bucket exento y ensuciaría el desglose fiscal que después
            // consume el Libro Ventas (F5). El descuento real ya viene
            // asignado por línea en `discount` y el motor lo descuenta de la
            // base del bucket correcto.
            if (($sD['type'] ?? '') === 'discount') {
                continue;
            }
            // Canje de voucher: exenta por enrichWithTaxes (amount=0), pero su
            // neto tampoco puede entrar como BASE del bucket exento — esa
            // plata no está en transactionTotal (el vale se cobró al
            // emitirse) y engordaría la base fiscal del Libro Ventas (F5) por
            // encima del total del documento.
            if (is_array($sD['voucher'] ?? null)) {
                continue;
            }
            $rate = (float) $sD['taxRate'];
            $kind = (string) ($sD['taxKind'] ?? 'exempt');
            $key  = $rate . '|' . $kind;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'taxId'  => $sD['taxId'] ?? null,
                    'rate'   => $rate,
                    'kind'   => $kind,
                    'base'   => 0.0,
                    'amount' => 0.0,
                ];
                $order[] = $key;
            }
            $buckets[$key]['base']   += (float) ($sD['taxNet'] ?? 0);
            $buckets[$key]['amount'] += (float) ($sD['taxAmount'] ?? 0);
        }

        $out = [];
        foreach ($order as $key) {
            $b = $buckets[$key];
            $b['base']   = TaxEngine::roundHalfUp($b['base'], $decimals);
            $b['amount'] = TaxEngine::roundHalfUp($b['amount'], $decimals);
            $out[] = $b;
        }
        return $out;
    }

    /**
     * Próximo número de cotización, reservado atómicamente (F2, context/37).
     *
     * Antes esto era `max(registerQuoteNumber + 1, último emitido + 1, piso)`
     * seguido de un UPDATE aparte al cerrar: lectura pura, así que dos cajeros
     * cotizando a la vez obtenían el MISMO número. Ahora la reserva y el
     * incremento son un solo statement en `document_sequence`.
     *
     * Llamar SIEMPRE dentro de la transacción de la cotización.
     */
    private function getNextQuoteNumber(): int
    {
        return DocumentNumber::allocate(
            'cotizacion',
            DocumentNumber::SCOPE_REGISTER,
            $this->ctx->registerId,
            $this->ctx->companyId,
        );
    }

    /**
     * B9 (35g) — packs de servicios: crea sold_pack por cada línea de tipo 'pack' vendida.
     *
     * Corre DENTRO de la transacción principal: si falla (PG error), la tx entera
     * hace rollback — la venta no persiste sin su pack. El clientId ya fue validado
     * antes de entrar al bloque de la tx.
     *
     * Si el item tiene qty > 1, crea qty instancias independientes de sold_pack.
     *
     * @param array<int,array<string,mixed>> $saleDetail
     */
    private function persistPackSales(SaleInput $input, string $transId, array $saleDetail): void
    {
        $companyId = $this->ctx->companyId;
        $contactId = $input->clientId; // ya validado como perteneciente al tenant

        foreach ($saleDetail as $sD) {
            $itemId = trim((string) ($sD['itemId'] ?? ''));
            if ($itemId === '') {
                continue;
            }

            // Verificar que el item es de tipo 'pack' (query directa — ncmExecute
            // no está disponible sin COMPANY_ID constante definida, pero sí lo está
            // porque apiAuthTenant ya corrió antes de instanciar SaleService).
            $itmRow = $this->db->Execute(
                'SELECT itemType FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                [$itemId, $companyId]
            );
            if (!$itmRow || $itmRow->EOF) {
                continue;
            }
            $itemType = strtolower((string) ($itmRow->fields['itemtype'] ?? ''));
            if ($itemType !== 'pack') {
                continue;
            }

            // packDurationDays desde item.data JSONB (flatteado por ncmExecute).
            $fullRow      = ncmExecute(
                'SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                [$itemId, $companyId]
            );
            $durationDays = (int) (($fullRow['packDurationDays'] ?? null) ?: 30);

            // Qty vendida del pack (puede ser > 1 → múltiples instancias).
            $qty     = max(1, (int) ($sD['count'] ?? 1));
            $outletId = $this->ctx->outletId ?: null;

            for ($i = 0; $i < $qty; $i++) {
                $this->db->Execute(
                    "INSERT INTO sold_pack
                       (packItemId, contactId, transactionId, outletId, companyId, expiresAt, status)
                     VALUES (?, ?, ?, ?, ?, now() + INTERVAL '{$durationDays} days', 1)",
                    [$itemId, $contactId, $transId, $outletId, $companyId]
                );
            }
        }
    }
}
