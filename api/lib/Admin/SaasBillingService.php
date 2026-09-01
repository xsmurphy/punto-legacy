<?php

require_once __DIR__ . '/../Auth/RoleService.php';

require_once __DIR__ . '/PlatformConfig.php';

/**
 * SaasBillingService.php — F5 "dogfooding" (context/34-admin-saas-plan.md):
 * la facturación del SaaS se emite como una VENTA REAL dentro de un tenant
 * Punto propio (el "emisor"), configurado en admin/platform →
 * `platform_config['saasBilling']` = {tenantId, outletId, registerId, enabled}.
 *
 * Por qué acá y no en /api/v1: vive en `api/lib/Admin` (namespace global,
 * igual que el resto de los servicios admin) porque lo dispara tanto el
 * realm admin (botón manual "Emitir factura Punto") como el webhook público
 * de billing (`api/v1/billing-webhook.php`, que YA carga bootstrap.php
 * completo). El realm admin NO carga bootstrap.php/functions.php a propósito
 * (aislamiento del realm) — por eso `emitForInvoice()` se auto-bootstrapea
 * (ncm*, autoloader `Punto\Api\*`) la primera vez que corre, guardado por
 * `function_exists`/`class_exists` para no repetir trabajo cuando SÍ está
 * disponible (caso webhook).
 *
 * Idempotencia — DOS capas independientes, cualquiera alcanza:
 *   1. `saas_invoice_sale.invoiceid` es PK: `emitForInvoice()` chequea la fila
 *      ANTES de hacer nada y la vuelve a chequear (ON CONFLICT DO NOTHING +
 *      releer, patrón CheckService) al final.
 *   2. El `uid` de la venta es DETERMINÍSTICO (`saas-invoice-{invoiceId}`), no
 *      random. Si el proceso muere DESPUÉS de que SaleService::save() confirmó
 *      la venta pero ANTES del INSERT en saas_invoice_sale (crash entre ambos
 *      pasos), un reintento no duplica la venta: SaleService la detecta por
 *      transactionUID y tira DuplicateSaleException, que acá se traduce en
 *      "recuperar el transactionId existente y completar el INSERT pendiente"
 *      en vez de fallar.
 *
 * Todo el flujo está envuelto: cualquier paso que falle (invoice no pagada,
 * tenant emisor sin configurar, outlet/register faltante, venta rechazada)
 * lanza \RuntimeException con mensaje claro. No hay medio-estado: la fila de
 * saas_invoice_sale solo se escribe si la venta ya existe confirmada.
 */
final class SaasBillingService
{
    private const PLATFORM_CONFIG_KEY = 'saasBilling';

    /**
     * Emite (o reutiliza) la venta correspondiente a una billing_invoice pagada.
     *
     * @return array{transactionId:string, reused:bool}
     * @throws \RuntimeException con mensaje claro ante cualquier fallo.
     */
    public function emitForInvoice(string $invoiceId): array
    {
        $this->bootstrapLegacyRuntime();

        global $db;

        // ── 0. idempotencia — ¿ya existe? ────────────────────────────────
        $already = ncmExecute(
            'SELECT transactionid FROM saas_invoice_sale WHERE invoiceid = ? LIMIT 1',
            [$invoiceId]
        );
        if ($already && !empty($already['transactionid'])) {
            return ['transactionId' => (string) $already['transactionid'], 'reused' => true];
        }

        // ── 1. invoice + tenant cliente ──────────────────────────────────
        $invoice = ncmExecute(
            'SELECT id, companyId, type, amountUsd, currency, status, packId
               FROM billing_invoice WHERE id = ? LIMIT 1',
            [$invoiceId]
        );
        if (!$invoice) {
            throw new \RuntimeException("SaasBillingService: billing_invoice {$invoiceId} no existe");
        }
        $status = (string) ($invoice['status'] ?? '');
        if ($status !== 'paid') {
            throw new \RuntimeException("SaasBillingService: invoice {$invoiceId} no está paga (status={$status})");
        }
        $clientCompanyId = (string) ($invoice['companyid'] ?? $invoice['companyId'] ?? '');
        if ($clientCompanyId === '') {
            throw new \RuntimeException("SaasBillingService: invoice {$invoiceId} sin companyId");
        }
        $amount   = (float) ($invoice['amountusd'] ?? $invoice['amountUsd'] ?? 0);
        $currency = (string) ($invoice['currency'] ?? 'USD');
        if ($amount <= 0) {
            throw new \RuntimeException("SaasBillingService: invoice {$invoiceId} con monto inválido ({$amount})");
        }

        // ── 2. config del tenant emisor ──────────────────────────────────
        $cfg = \PlatformConfig::get(self::PLATFORM_CONFIG_KEY, null);
        if (!is_array($cfg) || empty($cfg['enabled'])) {
            throw new \RuntimeException(
                'SaasBillingService: facturación SaaS no está habilitada (admin/platform → Facturación del SaaS)'
            );
        }
        $issuerCompanyId  = trim((string) ($cfg['tenantId']   ?? ''));
        $issuerOutletId   = trim((string) ($cfg['outletId']   ?? ''));
        $issuerRegisterId = trim((string) ($cfg['registerId'] ?? ''));
        if ($issuerCompanyId === '' || $issuerOutletId === '' || $issuerRegisterId === '') {
            throw new \RuntimeException(
                'SaasBillingService: falta tenant/sucursal/caja emisor en la config (admin/platform → Facturación del SaaS)'
            );
        }
        if ($issuerCompanyId === $clientCompanyId) {
            throw new \RuntimeException('SaasBillingService: el tenant cliente es el mismo que el tenant emisor');
        }

        // ── 3. cliente = contact tipo cliente en el tenant emisor ────────
        $clientCompany = ncmExecute(
            "SELECT config->>'settingName' AS name, config->>'settingRUC' AS ruc
                 FROM company WHERE companyId = ? LIMIT 1",
            [$clientCompanyId]
        );
        if (!$clientCompany) {
            throw new \RuntimeException("SaasBillingService: tenant cliente {$clientCompanyId} no existe");
        }
        $clientName = trim((string) ($clientCompany['name'] ?? ''));
        if ($clientName === '') {
            $clientName = 'Tenant ' . substr($clientCompanyId, 0, 8);
        }
        $clientRuc = trim((string) ($clientCompany['ruc'] ?? ''));

        $contactId = $this->findOrCreateCustomerContact($issuerCompanyId, $clientCompanyId, $clientName, $clientRuc);

        // ── 4. item = plan/concepto de la invoice ────────────────────────
        [$planCode, $planName] = $this->resolvePlan($invoice);
        $itemId = $this->findOrCreateSubscriptionItem($issuerCompanyId, $planCode, $planName, $amount);

        // ── 5. usuario "vendedor" = owner del tenant emisor ──────────────
        $issuerOwner = ncmExecute(
            "SELECT contactId, role FROM contact
                WHERE companyId = ? AND type = 0 AND " . RoleService::ownerContactSql() . " LIMIT 1",
            [$issuerCompanyId]
        );
        if (!$issuerOwner || empty($issuerOwner['contactid'])) {
            throw new \RuntimeException("SaasBillingService: el tenant emisor {$issuerCompanyId} no tiene owner");
        }
        $issuerUserId = (string) $issuerOwner['contactid'];
        $issuerRoleId = (string) ($issuerOwner['role'] ?? '1');

        // ── 6. venta vía SaleService (rail real, sin HTTP interno) ───────
        $uid = 'saas-invoice-' . $invoiceId;
        $paymentType = $this->resolvePaymentType($issuerCompanyId);
        $tenantDate  = \Punto\Api\Support\TenantClock::now($issuerCompanyId);

        $raw = [
            'transaction' => [
                'uid'        => $uid,
                'type'       => \Punto\Api\Sales\SaleType::Cashsale->value,
                'sale'       => [[
                    'itemId'    => $itemId,
                    'name'      => $planName,
                    'type'      => 'article',
                    'count'     => 1,
                    'uniPrice'  => $amount,
                    'price'     => $amount,
                    'total'     => $amount,
                    'tax'       => 0,
                    'discount'  => 0,
                    'currency'  => $currency,
                    'uId'       => substr(md5($invoiceId . '-line1'), 0, 12),
                ]],
                'subtotal'   => $amount,
                'tax'        => 0,
                'discount'   => 0,
                'payment'    => [[
                    'type'  => $paymentType,
                    'price' => $amount,
                ]],
                'date'       => $tenantDate,
                'timestamp'  => time(),
                'client'     => $contactId,
                'user'       => $issuerUserId,
                'note'       => "Suscripción SaaS Punto — invoice {$invoiceId}",
                'currency'   => $currency,
                'dontNotify' => true,
            ],
        ];

        try {
            $input = \Punto\Api\Sales\SaleInput::fromPayload($raw, $issuerCompanyId);
        } catch (\Punto\Api\Sales\Exceptions\InvalidSaleInputException $e) {
            throw new \RuntimeException('SaasBillingService: payload de venta inválido — ' . $e->getMessage(), 0, $e);
        }

        // Legacy: SaleService (y todo lo que toca por debajo) lee estas
        // constantes globales en vez del ctx tipado — mismo patrón que
        // apiAuthPosContext.php. El realm admin nunca las define, así que
        // esto es seguro (no pisa un contexto de OTRO tenant en la misma request).
        if (!defined('COMPANY_ID'))  define('COMPANY_ID', $issuerCompanyId);
        if (!defined('OUTLET_ID'))   define('OUTLET_ID', $issuerOutletId);
        if (!defined('USER_ID'))     define('USER_ID', $issuerUserId);
        if (!defined('REGISTER_ID')) define('REGISTER_ID', $issuerRegisterId);

        $ctx = new \Punto\Api\Context\TenantContext(
            companyId:  $issuerCompanyId,
            outletId:   $issuerOutletId,
            userId:     $issuerUserId,
            registerId: $issuerRegisterId,
            roleId:     $issuerRoleId !== '' ? $issuerRoleId : '1',
        );
        $saleService = new \Punto\Api\Sales\SaleService(ctx: $ctx, db: $db);

        try {
            $result = $saleService->save($input);
            $transactionId = $result->transactionId;
        } catch (\Punto\Api\Sales\Exceptions\DuplicateSaleException $e) {
            // uid determinístico: ya existe (reintento tras crash post-venta,
            // pre-INSERT saas_invoice_sale). Recuperamos el transactionId real.
            $existingTx = ncmExecute(
                'SELECT transactionId FROM transaction WHERE transactionUID = ? LIMIT 1',
                [$uid]
            );
            if (!$existingTx || empty($existingTx['transactionid'])) {
                throw new \RuntimeException(
                    "SaasBillingService: venta duplicada (uid={$uid}) pero no se pudo recuperar el transactionId",
                    0,
                    $e
                );
            }
            $transactionId = (string) $existingTx['transactionid'];
        } catch (\Throwable $e) {
            throw new \RuntimeException('SaasBillingService: falló la venta — ' . $e->getMessage(), 0, $e);
        }

        // ── 7. registrar idempotencia ─────────────────────────────────────
        $ins = ncmExecute(
            'INSERT INTO saas_invoice_sale (invoiceid, companyid, transactionid, created_at)
             VALUES (?, ?, ?, now())
             ON CONFLICT (invoiceid) DO NOTHING
             RETURNING invoiceid',
            [$invoiceId, $clientCompanyId, $transactionId]
        );
        if (!$ins || empty($ins['invoiceid'])) {
            // Otro request ya ganó la carrera — releer para devolver el mismo shape.
            $existing = ncmExecute(
                'SELECT transactionid FROM saas_invoice_sale WHERE invoiceid = ? LIMIT 1',
                [$invoiceId]
            );
            $finalTxId = $existing ? (string) ($existing['transactionid'] ?? $transactionId) : $transactionId;
            return ['transactionId' => $finalTxId, 'reused' => true];
        }

        return ['transactionId' => $transactionId, 'reused' => false];
    }

    // ── internos ─────────────────────────────────────────────────────────

    /**
     * Carga las funciones legacy globales (ncmInsert/ncmExecute/TODAY/SALT…)
     * y el autoloader de `Punto\Api\*` SIN pasar por `api/bootstrap.php`
     * completo — el realm admin no debe heredar CORS/session/Sentry/rate
     * limiter del bootstrap HTTP a mitad de un request ya en curso. Ambos
     * guards (`function_exists`/`class_exists`) hacen esto un no-op cuando
     * el caller YA cargó bootstrap.php (caso `billing-webhook.php`).
     */
    private function bootstrapLegacyRuntime(): void
    {
        if (!function_exists('ncmInsert')) {
            require_once __DIR__ . '/../../head.php';
        }
        if (!class_exists(\Punto\Api\Sales\SaleService::class, false)) {
            require_once __DIR__ . '/../../vendor/autoload.php';
            spl_autoload_register(static function (string $class): void {
                $prefix = 'Punto\\Api\\';
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                $path = __DIR__ . '/../' . $relative . '.php';
                if (is_file($path)) {
                    require_once $path;
                    return;
                }
                // Mismo fallback de case que bootstrap.php (macOS case-insensitive
                // vs Linux prod case-sensitive, ej. lib/services/ lowercase).
                $lower = preg_replace_callback('#^[^/]+#', static fn ($m) => strtolower($m[0]), $relative);
                if ($lower !== $relative) {
                    $pathLower = __DIR__ . '/../' . $lower . '.php';
                    if (is_file($pathLower)) {
                        require_once $pathLower;
                    }
                }
            });
        }
    }

    /**
     * Busca el contacto cliente por RUC (contactTIN) y, si no hay RUC, por
     * nombre exacto. Lo crea si no existe. Solo razón social + RUC — el
     * teléfono/email del owner del tenant cliente se omite a propósito (el
     * formato puede no pasar la validación E.164 de ContactService y esto es
     * un contacto administrativo, no uno que vaya a recibir SMS/WhatsApp).
     */
    private function findOrCreateCustomerContact(
        string $issuerCompanyId,
        string $clientCompanyId,
        string $clientName,
        string $clientRuc
    ): string {
        if ($clientRuc !== '') {
            $found = ncmExecute(
                'SELECT contactId FROM contact WHERE companyId = ? AND type = 1 AND contactTIN = ? LIMIT 1',
                [$issuerCompanyId, $clientRuc]
            );
            if ($found && !empty($found['contactid'])) {
                return (string) $found['contactid'];
            }
        }
        $found = ncmExecute(
            'SELECT contactId FROM contact WHERE companyId = ? AND type = 1 AND contactName = ? LIMIT 1',
            [$issuerCompanyId, $clientName]
        );
        if ($found && !empty($found['contactid'])) {
            return (string) $found['contactid'];
        }

        $repo = new \Punto\Api\Contacts\ContactRepository($GLOBALS['db']);
        $svc  = new \Punto\Api\Contacts\ContactService($repo);
        $in   = ['fiscalName' => $clientName, 'type' => \Punto\Api\Contacts\ContactService::TYPE_CUSTOMER];
        if ($clientRuc !== '') {
            $in['tin'] = $clientRuc;
        }
        try {
            return $svc->create($issuerCompanyId, $in);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "SaasBillingService: no se pudo crear el contacto cliente (tenant {$clientCompanyId}) — " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Deriva (planCode, planName) de la invoice. `billing_invoice` hoy solo
     * emite invoices type='pack' (créditos IA) — se resuelve contra
     * credit_pack. Cualquier otro `type` futuro (ej. suscripción de plan)
     * cae al fallback genérico "type:{type}".
     *
     * @return array{0:string,1:string} [planCode, planName]
     */
    private function resolvePlan(array $invoice): array
    {
        $type   = (string) ($invoice['type'] ?? 'pack');
        $packId = (string) ($invoice['packid'] ?? $invoice['packId'] ?? '');

        if ($packId !== '') {
            $pack = ncmExecute('SELECT slug, name FROM credit_pack WHERE id = ? LIMIT 1', [$packId]);
            if ($pack) {
                $slug = (string) ($pack['slug'] ?? $packId);
                $name = (string) ($pack['name'] ?? 'Pack de créditos IA');
                return ['pack:' . $slug, 'Suscripción — ' . $name];
            }
        }

        return ['type:' . $type, 'Suscripción SaaS Punto'];
    }

    /**
     * Busca el item marcado con `data->>'saasPlanCode' = $planCode` en el
     * catálogo del tenant emisor; lo crea on-demand si no existe (servicio,
     * sin stock, precio = monto de la invoice — el precio se re-setea en
     * cada emisión por si el monto cambió, ver nota abajo).
     */
    private function findOrCreateSubscriptionItem(
        string $issuerCompanyId,
        string $planCode,
        string $planName,
        float $amount
    ): string {
        $found = ncmExecute(
            "SELECT itemId FROM item WHERE companyId = ? AND data->>'saasPlanCode' = ? LIMIT 1",
            [$issuerCompanyId, $planCode]
        );
        if ($found && !empty($found['itemid'])) {
            return (string) $found['itemid'];
        }

        $repo = new \Punto\Api\Items\ItemRepository($GLOBALS['db']);
        $itemId = $repo->create([
            'itemName'           => $planName,
            'itemKind'           => 'servicio',
            'itemType'           => 'product',
            'itemPrice'          => $amount,
            'itemTrackInventory' => false,
            'itemCanSale'        => true,
            'itemProduction'     => false,
            'itemStatus'         => 1,
            'companyId'          => $issuerCompanyId,
            'itemDate'           => TODAY,
            'updated_at'         => TODAY,
            'itemTaxIncluded'    => 1,     // ruteado a data JSONB (mismo patrón que ItemService::createBlank)
            'saasPlanCode'       => $planCode, // ruteado a data JSONB — marcador de F5
        ]);
        if (!$itemId) {
            throw new \RuntimeException("SaasBillingService: no se pudo crear el ítem de suscripción ({$planCode})");
        }

        // Sucursales del ítem (`item_outlet`, mig 170). Este alta va directo al
        // repo, sin pasar por `ItemService::createBlank()`, así que el vínculo
        // hay que crearlo acá: un ítem con CERO sucursales es invisible para
        // toda caja. Se asigna a TODAS las del emisor — es un ítem de
        // facturación interna, no un producto de una sucursal en particular.
        $outletSvc = new \Punto\Api\Items\ItemOutletService($GLOBALS['db']);
        $issuerOutlets = $outletSvc->allOutletIdsOf($issuerCompanyId);
        if ($issuerOutlets !== []) {
            $outletSvc->replace((string) $itemId, $issuerCompanyId, $issuerOutlets);
        }

        return (string) $itemId;
    }

    /**
     * Medio de pago para la venta: prioriza un método cuyo nombre matchee
     * "tarjeta"/"online" (cobro dLocal), si no cae al método con
     * systemKey='cash' (Efectivo), si no al slug literal 'cash' (el resolver
     * lo matchea igual sin necesitar el taxonomyId).
     */
    private function resolvePaymentType(string $companyId): string
    {
        $resolver = new \Punto\Api\PaymentMethods\PaymentMethodResolver();
        $methods  = $resolver->methods($companyId);

        foreach ($methods as $m) {
            if (preg_match('/tarjeta|online/i', (string) $m['name'])) {
                return $m['id'];
            }
        }
        foreach ($methods as $m) {
            if ($m['systemKey'] === 'cash') {
                return $m['id'];
            }
        }
        return 'cash';
    }
}
