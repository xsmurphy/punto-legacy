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

        // ── Pre-flight: items con sesiones → no es path simple ──────────────
        // Se chequea ANTES de StartTrans: si algún item tiene sesiones (y hay
        // cliente), el legacy crearía citas (35d). Rechazar acá evita dejar una
        // transacción colgada (el throw mid-tx no haría rollback limpio).
        $this->assertNoScheduledItems($input, $saleDetail);

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
            // (points/storeCredit siguen rechazados en eligibility; giftcard migró).
            $this->persistGiftCardRedemptions($input);

            // ── B10: loyalty EARNED (cash/card; points/storeCredit/giftcard NO
            //         ganan puntos — mismo guard que el legacy) ────────────────
            $this->persistLoyaltyEarning($input);
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

        // ── B14 + B15: notificaciones (email/SMS al cliente + auditoría) ────
        // POST-COMMIT, BEST-EFFORT: la venta YA está confirmada en BD. Nada acá
        // puede afectarla — todo wrapeado, los fallos se loguean y se ignoran.
        $this->dispatchNotifications($input, (string) $transId);

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
    private function dispatchNotifications(SaleInput $input, string $transId): void
    {
        // B14: recibo/factura al cliente (email + SMS) — solo cashsale/creditsale.
        try {
            $this->notifyCustomer($input, $transId);
        } catch (\Throwable $e) {
            error_log('[SaleService] notifyCustomer: ' . $e->getMessage() . "\n", 3, './error_log');
        }

        // B15: registro de auditoría (FACTURACION).
        try {
            $this->sendAudit($input);
        } catch (\Throwable $e) {
            error_log('[SaleService] sendAudit: ' . $e->getMessage() . "\n", 3, './error_log');
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

        // URL del recibo. Si el módulo digitalInvoice está activo, usa esa vista.
        // El payload codifica enc(transId),enc(companyId) (mismo orden que el legacy).
        $hasDigitalInvoice = $this->moduleEnabled('digitalInvoice');
        $surl    = $hasDigitalInvoice
            ? '/screens/digitalInvoice?s=' . base64_encode(enc($transId) . ',' . enc($this->ctx->companyId)) . '&pdf=1'
            : '/screens/receipt?s=' . base64_encode(enc($transId) . ',' . enc($this->ctx->companyId));
        $url = getShortURL($surl);

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
     * Pre-flight: rechaza la venta si algún item tiene sesiones configuradas
     * (`itemSessions > 0`) y hay cliente — eso dispara la creación de citas en
     * el legacy (B8 sesiones), que es 35d. Corre ANTES de StartTrans para no
     * dejar transacciones colgadas. `itemSessions` vive en la BD (no en el
     * payload), por eso no lo cubre SaleInput::assertSimplePathEligible.
     *
     * @param array<int,array<string,mixed>> $saleDetail
     */
    private function assertNoScheduledItems(SaleInput $input, array $saleDetail): void
    {
        if ($input->clientId === null) {
            return; // sin cliente, el legacy no crea sesiones aunque el item las tenga
        }
        foreach ($saleDetail as $sD) {
            if (($sD['type'] ?? '') === 'discount' || empty($sD['itemId'])) {
                continue;
            }
            // itemSessions está DEMOTED a la columna `data` JSONB → DEBE leerse con
            // ncmExecute (aplica _flattenJsonb y expone la key); con $this->db->Execute
            // crudo la key no existe y $sessions sería siempre 0 (guard muerto). §22.8.
            $row = ncmExecute(
                'SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',
                [(string) $sD['itemId'], $this->ctx->companyId]
            );
            $sessions = (is_array($row) || $row instanceof \ArrayAccess) ? (int) ($row['itemSessions'] ?? 0) : 0;
            if ($sessions > 0) {
                throw new InvalidSaleInputException(
                    "Item {$sD['itemId']} tiene sesiones agendadas — no soportado en este path (usar legacy)"
                );
            }
        }
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

        $typeStr   = (string) $input->type->value;
        $companyId = $this->ctx->companyId;

        foreach ($saleDetail as $sD) {
            if (($sD['type'] ?? '') === 'discount') {
                continue; // las líneas de descuento no generan itemSold ni mueven stock
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
    }
}
