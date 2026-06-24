<?php
declare(strict_types=1);

namespace Punto\Api\Sales;

use DB; // wrapper PDO en namespace global, en `app/includes/lib/DB.php`
use Punto\Api\Context\TenantContext;
use Punto\Api\Sales\Exceptions\DuplicateSaleException;
use Punto\Api\Sales\Exceptions\InvalidSaleInputException;
use Punto\Api\Sales\Exceptions\SaleAbortedException;

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
    public function __construct(
        private readonly TenantContext $ctx,
        private readonly DB $db,
    ) {
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
        }

        // ── B1: normalizar items del sale + computar totales ────────────────
        // saleArraySanitizer aplica markupt2HTML por campo y castea floats —
        // shape canónico que después leen toTaxObj/itemSold/manageStock.
        $saleDetail = saleArraySanitizer($input->sale);
        $totalUnits = countUnitSold($saleDetail);

        // ── B1: resolver userId + responsibleId ──────────────────────────────
        // (action.php:1935) Si el user del request ≠ el del JWT, registramos
        // el JWT como responsable (quien realmente operó la caja).
        $userId        = $input->userId ?? $this->ctx->userId;
        $responsibleId = ($userId !== $this->ctx->userId) ? $this->ctx->userId : null;

        // ── B1: abrir transacción ──────────────────────────────────────────
        $this->db->StartTrans();

        // ── B3: construir record de `transaction` ──────────────────────────
        $record = $this->buildTransactionRecord(
            input:         $input,
            saleDetail:    $saleDetail,
            totalUnits:    $totalUnits,
            userId:        $userId,
            responsibleId: $responsibleId,
        );

        // ── B3: INSERT principal de la venta ────────────────────────────────
        $insertOk = $this->db->AutoExecute('transaction', $record, 'INSERT');
        $transId  = $this->db->Insert_ID();

        // ── B4 + B6 + B7: relaciones (toTaxObj / toAddress / toTag) ─────────
        // Solo si el INSERT principal funcionó (evita FK violations en cascada
        // que ensuciarían el error real). Corren dentro de la misma transacción.
        if ($insertOk !== false && !empty($transId)) {
            $this->persistRelations($input, (string) $transId);

            // ── B8: itemSold + COGS + comisiones + manageStock (inventario) ──
            $this->persistItemsAndStock($input, (string) $transId, $saleDetail);

            // ── B10 (35c.1): redención de gift card — debita el saldo usado ────
            $this->persistGiftCardRedemptions($input);

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

        return SaleResult::created(
            transactionId: (string) $transId,
            uid:           $input->uid,
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

        // B14 (35b): factura electrónica PY — envío al proveedor FE (fire-and-forget).
        try {
            $this->dispatchElectronicInvoice($input);
        } catch (\Throwable $e) {
            error_log('[SaleService] dispatchElectronicInvoice: ' . $e->getMessage() . "\n", 3, './error_log');
        }

        // B15: registro de auditoría (FACTURACION).
        try {
            $this->sendAudit($input);
        } catch (\Throwable $e) {
            error_log('[SaleService] sendAudit: ' . $e->getMessage() . "\n", 3, './error_log');
        }
    }

    /**
     * B14 (35b) — envío de factura electrónica al proveedor FE (Paraguay).
     * Port del bloque EI del legacy (action.php:2742-2763 → sendFE).
     * POST-COMMIT, BEST-EFFORT: la venta ya está confirmada. Fire-and-forget
     * (el legacy también descarta $feresult). FACTURACION_ELECTRONICA_TOKEN
     * definido en simple.config.php; si vacío → no-op (dev sin FE configurada).
     *
     * Tipo → typeDoc: FC (cashsale), FCR (creditsale). type=6 (NC) se agrega en 35e.
     */
    private function dispatchElectronicInvoice(SaleInput $input): void
    {
        if ($input->electronicInvoicePY === null) {
            return;
        }
        // Solo FC y FCR en este sub-slice (type=6/NC es 35e — devoluciones).
        $typeDoc = match ($input->type) {
            SaleType::Cashsale   => 'FC',
            SaleType::Creditsale => 'FCR',
            default              => null,
        };
        if ($typeDoc === null) {
            return;
        }
        if (!defined('FACTURACION_ELECTRONICA_TOKEN') || FACTURACION_ELECTRONICA_TOKEN === '') {
            return; // FE no configurada (dev/staging)
        }

        $company = ncmExecute(
            "SELECT config->>'settingRUC' AS settingRUC FROM company WHERE companyId = ? LIMIT 1",
            [$this->ctx->companyId]
        );
        $ruc = ($company && (is_array($company) || $company instanceof \ArrayAccess))
            ? (string) ($company['settingRUC'] ?? '')
            : '';

        $fedata = [
            'ruc'   => $ruc,
            'email' => (string) ($input->electronicInvoicePY['email'] ?? ''),
            'type'  => $typeDoc,
            'data'  => $input->electronicInvoicePY,
        ];

        sendFE($fedata, FACTURACION_ELECTRONICA_TOKEN);
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
        //   2. EI presente (35b)    → URL del proveedor FE (no es URL local)
        //   3. Default              → pantalla de recibo
        $hasDigitalInvoice = $this->moduleEnabled('digitalInvoice');
        if ($hasDigitalInvoice) {
            $surl = '/screens/digitalInvoice?s=' . base64_encode(enc($transId) . ',' . enc($this->ctx->companyId)) . '&pdf=1';
            $url  = getShortURL($surl);
        } elseif ($input->electronicInvoicePY !== null
            && defined('FACTURACION_ELECTRONICA_URL') && FACTURACION_ELECTRONICA_URL !== '') {
            $url = FACTURACION_ELECTRONICA_URL; // el legacy usa la URL raíz del proveedor EI
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
    ): array {
        $typeStr = (string) $input->type->value;
        $isIncomplete = in_array($input->type, [
            SaleType::Creditsale,
            SaleType::CreditPurchase,
            SaleType::Schedule,
        ], true);

        return [
            'transactionDiscount'    => flipOnReturn($typeStr, $input->discount),
            'transactionTax'         => flipOnReturn($typeStr, $input->tax),
            'transactionTotal'       => flipOnReturn($typeStr, $input->subtotal),
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

            // path simple: sin parentId (B2 omitido). Sub-slices futuros lo agregarán.
            'transactionParentId'    => null,
            'transactionType'        => $typeStr,
            'transactionComplete'    => $isIncomplete ? 0 : 1,

            'transactionDate'        => $input->date,
            'transactionDueDate'     => $input->dueDate, // null si vacío (DTO ya normaliza)
            'fromDate'               => null,            // path simple
            'toDate'                 => null,            // path simple
            'transactionName'        => $input->ident !== null ? strip_tags($input->ident) : null,
            'transactionNote'        => $input->note  !== null ? strip_tags($input->note)  : null,
            'invoiceNo'              => $input->invoiceNo,
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
    private function persistRelations(SaleInput $input, string $transId): void
    {
        // ── B4: snapshot de impuestos (toTaxObj) ────────────────────────────
        // taxObjSanitizer recorta a 11 items y formatea name/val. Devuelve false
        // si está vacío → no insertamos.
        //
        // DEUDA conocida (matchea el legacy, no es regresión): `toTaxObjText` es
        // VARCHAR(255). Con ~6+ impuestos el json_encode puede exceder 255 chars
        // → PG aborta la tx por truncación (22001) y la venta entera falla con
        // SaleAbortedException. Una venta multi-impuesto legítima podría romper.
        // Fix futuro: widening de la columna a TEXT (migración) — registrado en
        // roadmap § processData. Por ahora se preserva el comportamiento legacy.
        $taxObj = taxObjSanitizer($input->taxObj ?? []);
        if (is_array($taxObj) && $taxObj !== []) {
            $this->db->AutoExecute('toTaxObj', [
                'toTaxObjText'  => json_encode($taxObj),
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
            $addr = $this->db->Execute(
                'SELECT customerAddressId FROM customerAddress
                 WHERE customerAddressId = ? AND customerId = ? AND companyId = ? LIMIT 1',
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
        //   $url = '/action?l=' . base64_encode($fields['recurringTransactionData'])
        //   → action.php: base64_decode(?l) → json_decode → $get['action']
        // El JSON DEBE tener la clave `action` para que action.php despache correctamente.
        // Además el cron lee $transData['registerId'] y $transData['companyId'] (raw UUID).
        // Guardamos el mismo shape que el legacy (base64_decode(masterUrlParams())):
        // {"action":"processData","companyId":"...","outletId":"...","userId":"...","roleId":1,"registerId":"..."}.
        // El cron mintea un JWT de servicio de corta vida (120s) usando este contexto —
        // ver panel/crons/cronCreateRecurringInvoice.php (fix del cron JWT).
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
            $this->db->AutoExecute('transaction', [
                // meta JSONB: transactionDetails va aquí (no es columna directa en PG).
                'meta'                  => json_encode(['transactionDetails' => $detailsJson]),
                'transactionDate'       => $input->date,
                'transactionTotal'      => $perPrice,
                'transactionParentId'   => $transId,
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

            // ── COGS según tipo de item ─────────────────────────────────────
            $itemSoldCOGS = [];
            if ($itemType === 'direct_production') {
                $itemSoldCOGS['stockOnHandCOGS'] = getProductionCOGS($itemId);
            } elseif (in_array($itemType, ['precombo', 'combo'], true)) {
                $itemSoldCOGS['stockOnHandCOGS'] = getComboCOGS($itemId);
            } else {
                $itemSoldCOGS = getItemStock($itemId);
            }
            $cogsVal = (is_array($itemSoldCOGS) || $itemSoldCOGS instanceof \ArrayAccess)
                ? ($itemSoldCOGS['stockOnHandCOGS'] ?? null)
                : null;

            // ── INSERT itemSold ─────────────────────────────────────────────
            $records = [
                'itemSoldTotal'     => flipOnReturn($typeStr, $sD['total']),
                'itemSoldTax'       => flipOnReturn($typeStr, addTax($sD['tax'], $sD['total'])),
                'itemSoldDiscount'  => flipOnReturn($typeStr, $sD['totalDiscount']),
                'itemSoldUnits'     => flipOnReturn($typeStr, $sD['count']),
                'itemSoldComission' => flipOnReturn($typeStr, $comission),
                'itemSoldCOGS'      => flipOnReturn($typeStr, $cogsVal),
                'itemSoldParent'    => !empty($sD['parent']) ? $sD['parent'] : null,
                'itemId'            => $itemId,
                'itemSoldDate'      => $input->date,
                'transactionId'     => $transId,
                'userId'            => !empty($sD['user']) ? (string) $sD['user'] : null,
            ];
            if (($sD['type'] ?? '') === 'dynamic') {
                $records['itemSoldDescription'] = markupt2HTML(['text' => $sD['note'] ?? '', 'type' => 'HtM']);
            }
            $this->db->AutoExecute('itemSold', $records, 'INSERT');
            $itemSoldId = (string) $this->db->Insert_ID();

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

            // ── compounds: descuenta el stock de los ingredientes ───────────
            $compound = getCompoundsArray($itemId);
            if (is_array($compound) && $compound !== []
                && ($sD['type'] ?? '') !== 'combo' && ($sD['type'] ?? '') !== 'production') {
                $allWaste = getAllWasteValue();
                foreach ($compound as $comr) {
                    $comid    = $comr['compoundId'];
                    $comunits = (float) $comr['toCompoundQty'] * $units;
                    $locRow   = $this->db->Execute(
                        'SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                        [$comid, $companyId]
                    );
                    $comLoc   = ($locRow && !$locRow->EOF) ? ($locRow->fields['locationid'] ?? null) : null;

                    $wasteP = $allWaste[$comid] ?? '';
                    if ($wasteP > 0) {
                        $comunits = getNeedWithWaste($comunits, $wasteP);
                    }

                    $source = (($sD['type'] ?? '') === 'direct_production') ? 'production' : 'sale';
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

        $saleDetail    = saleArraySanitizer($input->sale);
        $totalUnits    = countUnitSold($saleDetail);
        $userId        = $input->userId ?? $this->ctx->userId;
        $responsibleId = ($userId !== $this->ctx->userId) ? $this->ctx->userId : null;

        $quoteNo = $this->getNextQuoteNumber();

        $this->db->StartTrans();

        $record = $this->buildTransactionRecord(
            input:         $input,
            saleDetail:    $saleDetail,
            totalUnits:    $totalUnits,
            userId:        $userId,
            responsibleId: $responsibleId,
        );
        $record['invoiceNo']           = $quoteNo;
        $record['transactionComplete'] = 1;

        $insertOk = $this->db->AutoExecute('transaction', $record, 'INSERT');
        $transId  = $this->db->Insert_ID();

        if ($insertOk !== false && !empty($transId)) {
            $this->persistRelations($input, (string) $transId);
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

        $this->db->Execute(
            'UPDATE register SET registerQuoteNumber = ? WHERE registerId = ? AND companyId = ?',
            [$quoteNo, $this->ctx->registerId, $this->ctx->companyId]
        );

        return [
            'transactionId'  => (string) $transId,
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

            $itemSoldCOGS = getItemStock($itemId);
            $cogsVal = (is_array($itemSoldCOGS) || $itemSoldCOGS instanceof \ArrayAccess)
                ? ($itemSoldCOGS['stockOnHandCOGS'] ?? null)
                : null;

            $records = [
                'itemSoldTotal'     => (float) $sD['total'],
                'itemSoldTax'       => addTax($sD['tax'], $sD['total']),
                'itemSoldDiscount'  => (float) $sD['totalDiscount'],
                'itemSoldUnits'     => (float) $sD['count'],
                'itemSoldComission' => $comission,
                'itemSoldCOGS'      => $cogsVal,
                'itemSoldParent'    => !empty($sD['parent']) ? $sD['parent'] : null,
                'itemId'            => $itemId,
                'itemSoldDate'      => $input->date,
                'transactionId'     => $transId,
                'userId'            => !empty($sD['user']) ? (string) $sD['user'] : null,
            ];
            if (($sD['type'] ?? '') === 'dynamic') {
                $records['itemSoldDescription'] = markupt2HTML(['text' => $sD['note'] ?? '', 'type' => 'HtM']);
            }
            $this->db->AutoExecute('itemSold', $records, 'INSERT');
        }
    }

    private function getNextQuoteNumber(): int
    {
        $register = ncmExecute(
            'SELECT * FROM register WHERE registerId = ? AND companyId = ? LIMIT 1',
            [$this->ctx->registerId, $this->ctx->companyId],
            false
        );
        $stored  = $register ? (int) ($register['registerQuoteNumber'] ?? 0) : 0;
        $current = $stored + 1;

        $row = $this->db->Execute(
            'SELECT invoiceNo FROM transaction
              WHERE companyId = ? AND registerId = ?
                AND invoiceNo IS NOT NULL AND invoiceNo > 0
                AND transactionType = 9
              ORDER BY transactionDate DESC LIMIT 1',
            [$this->ctx->companyId, $this->ctx->registerId]
        );
        $lastUsed = ($row && !$row->EOF) ? (int) $row->fields['invoiceno'] : 0;

        return $lastUsed >= $current ? $lastUsed + 1 : $current;
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
