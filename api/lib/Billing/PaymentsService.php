<?php
declare(strict_types=1);

namespace Punto\Api\Billing;

use Punto\Api\Billing\Payments\DlocalGoProvider;
use Punto\Api\Billing\Payments\PaymentProvider;

// F5 (context/34-admin-saas-plan.md): SaasBillingService vive en namespace
// GLOBAL (api/lib/Admin, mismo patrón que el resto de los servicios admin) —
// no lo resuelve el autoloader `Punto\Api\*`, hace falta require explícito.
require_once __DIR__ . '/../Admin/SaasBillingService.php';
require_once __DIR__ . '/../Admin/PlatformConfig.php';

/**
 * Orquesta los pagos SaaS del tenant (compra de packs de créditos) vía dLocal Go.
 *
 *   listPacks()           → catálogo de packs activos
 *   listInvoices($cid)    → facturas del tenant (billing_invoice)
 *   createPackCheckout()  → crea invoice pending + payment en dLocal → redirectUrl
 *   handleWebhook()       → verifica firma, re-consulta el pago, acredita (idempotente)
 *
 * Usa el `$db` global (wrapper PDO, lo provee head.php en el contexto /api) para las
 * partes transaccionales — mismo patrón que CompanyAdminService::grantAiCredits.
 * Para lecturas simples usa ncmExecute (helper global).
 *
 * Acreditación idempotente (dLocal reintenta webhooks):
 *   1. SELECT ... FOR UPDATE sobre la invoice dentro de una transacción →
 *      sólo un webhook gana la transición pending → paid.
 *   2. Índice único parcial uq_ai_credit_ledger_invoice_grant (migración 30) →
 *      backstop: imposible insertar dos ledgers 'pack_purchase' por invoice.
 */
final class PaymentsService
{
    /**
     * Índice único parcial (migración 30) que hace imposible acreditar dos
     * veces el mismo pack por invoice. Es el backstop de idempotencia del
     * webhook: si el INSERT en `ai_credit_ledger` choca contra ÉL, otro
     * webhook ya acreditó. Ver el catch de `creditInvoice()`.
     */
    private const LEDGER_IDEMPOTENCY_INDEX = 'uq_ai_credit_ledger_invoice_grant';

    private PaymentProvider $provider;

    /**
     * El proveedor entra por constructor (default: dLocal Go) para que el
     * camino del webhook —el que mueve PLATA— se pueda ejercitar de punta a
     * punta en un arnés sin llamar a la API real. Producción no cambia: sin
     * argumento sigue instanciando `DlocalGoProvider`.
     */
    public function __construct(?PaymentProvider $provider = null)
    {
        $this->provider = $provider ?? new DlocalGoProvider();
    }

    public function isEnabled(): bool
    {
        return $this->provider->isConfigured();
    }

    // ── lecturas ───────────────────────────────────────────────────────────

    /** Catálogo de packs activos. */
    public function listPacks(): array
    {
        $rs = ncmExecute(
            "SELECT id, slug, name, credits, priceUsd, validityDays, sortOrder
               FROM credit_pack WHERE active = TRUE
              ORDER BY sortOrder ASC, priceUsd ASC",
            [],
            false,
            true
        );
        $out = [];
        foreach ($this->rows($rs) as $r) {
            $out[] = [
                'id'           => (string) ($r['id'] ?? ''),
                'slug'         => (string) ($r['slug'] ?? ''),
                'name'         => (string) ($r['name'] ?? ''),
                'credits'      => (int)    ($r['credits'] ?? 0),
                'priceUsd'     => (float)  ($r['priceusd'] ?? $r['priceUsd'] ?? 0),
                'validityDays' => (int)    ($r['validitydays'] ?? $r['validityDays'] ?? 0),
            ];
        }
        return $out;
    }

    /** Facturas del tenant (últimas 50). */
    public function listInvoices(string $companyId): array
    {
        $rs = ncmExecute(
            "SELECT id, type, amountUsd, currency, status, providerInvoiceId, paidAt, createdAt
               FROM billing_invoice WHERE companyId = ?
              ORDER BY createdAt DESC LIMIT 50",
            [$companyId],
            false,
            true
        );
        $out = [];
        foreach ($this->rows($rs) as $r) {
            $out[] = [
                'id'       => (string) ($r['id'] ?? ''),
                'type'     => (string) ($r['type'] ?? 'pack'),
                'amount'   => (float)  ($r['amountusd'] ?? $r['amountUsd'] ?? 0),
                'currency' => (string) ($r['currency'] ?? 'USD'),
                'status'   => (string) ($r['status'] ?? 'pending'),
                'invoice'  => (string) ($r['providerinvoiceid'] ?? $r['providerInvoiceId'] ?? ''),
                'paidAt'   => $r['paidat']   ?? $r['paidAt']   ?? null,
                'date'     => $r['createdat'] ?? $r['createdAt'] ?? null,
            ];
        }
        return $out;
    }

    // ── checkout ─────────────────────────────────────────────────────────────

    /**
     * Inicia la compra de un pack. Crea invoice pending y un payment REDIRECT en
     * dLocal. Devuelve {redirectUrl, invoiceId}.
     *
     * @throws \InvalidArgumentException pack inexistente
     * @throws \RuntimeException pagos no configurados / error de dLocal / BD
     */
    public function createPackCheckout(string $companyId, string $packId): array
    {
        global $db;

        if (!$this->provider->isConfigured()) {
            throw new \RuntimeException('PAYMENTS_NOT_CONFIGURED');
        }

        $pack = ncmExecute(
            "SELECT id, name, credits, priceUsd FROM credit_pack WHERE id = ? AND active = TRUE LIMIT 1",
            [$packId]
        );
        if (!$pack || empty($pack['id'])) {
            throw new \InvalidArgumentException('PACK_NOT_FOUND');
        }
        $price  = (float) ($pack['priceusd'] ?? $pack['priceUsd'] ?? 0);
        $name   = (string) ($pack['name'] ?? 'Créditos');
        $credits = (int) ($pack['credits'] ?? 0);

        // País del tenant (ISO-2) para dLocal — default PY.
        $co = ncmExecute(
            "SELECT config->>'settingCountry' AS country FROM company WHERE companyId = ? LIMIT 1",
            [$companyId]
        );
        $country = strtoupper(trim((string) ($co['country'] ?? '')));
        if (strlen($country) !== 2) {
            $country = 'PY';
        }

        // Invoice pending (RETURNING id).
        $ins = $db->Execute(
            "INSERT INTO billing_invoice (id, companyId, type, amountUsd, currency, status, packId, provider)
             VALUES (gen_random_uuid(), ?, 'pack', ?, 'USD', 'pending', ?, 'dlocal_go')
             RETURNING id",
            [$companyId, $price, $packId]
        );
        if ($ins === false || $ins->EOF) {
            throw new \RuntimeException('No se pudo crear la factura');
        }
        $invoiceId = (string) ($ins->fields['id'] ?? '');

        // Crear el payment en dLocal con order_id = invoiceId (para matchear el webhook).
        try {
            $payment = $this->provider->createPayment([
                'amount'          => $price,
                'currency'        => 'USD',
                'country'         => $country,
                'orderId'         => $invoiceId,
                'description'     => 'Punto — ' . $name . ' (' . $credits . ' créditos)',
                'successUrl'      => $this->successUrl(),
                'backUrl'         => $this->backUrl(),
                'notificationUrl' => $this->notificationUrl(),
            ]);
        } catch (\Throwable $e) {
            // Marcar la invoice como failed para no dejar pendientes huérfanas.
            $db->Execute(
                "UPDATE billing_invoice SET status = 'failed', updatedAt = now() WHERE id = ?",
                [$invoiceId]
            );
            throw new \RuntimeException('No se pudo iniciar el pago: ' . $e->getMessage());
        }

        // Guardar el provider payment id.
        $db->Execute(
            "UPDATE billing_invoice SET providerInvoiceId = ?, updatedAt = now() WHERE id = ?",
            [$payment['providerPaymentId'], $invoiceId]
        );

        return [
            'redirectUrl' => $payment['redirectUrl'],
            'invoiceId'   => $invoiceId,
        ];
    }

    // ── webhook ──────────────────────────────────────────────────────────────

    /**
     * Procesa la notificación de dLocal. Verifica firma → re-consulta el pago →
     * si está pagado acredita de forma transaccional e idempotente.
     *
     * @throws \RuntimeException 'invalid_signature' si la firma no valida (→ 401).
     * @return array{handled:bool, status?:string, reason?:string}
     */
    public function handleWebhook(string $rawBody, array $headers): array
    {
        global $db;

        if (!$this->provider->isConfigured()) {
            return ['handled' => false, 'reason' => 'disabled'];
        }

        if (!$this->provider->verifyWebhookSignature($headers, $rawBody)) {
            throw new \RuntimeException('invalid_signature');
        }

        $body = json_decode($rawBody, true);
        if (!is_array($body)) {
            $body = [];
        }

        $paymentId = $this->provider->extractPaymentId($body);
        if ($paymentId === null) {
            return ['handled' => false, 'reason' => 'no_payment_id'];
        }

        // Ubicar la invoice ANTES de re-consultar a dLocal: así no hacemos una
        // llamada autenticada a dLocal por un payment_id ajeno/desconocido
        // (P1 code-review). Match por providerInvoiceId (= el id de dLocal que
        // guardamos) o por id::text (= order_id que mandamos, por si el INSERT
        // de providerInvoiceId no alcanzó a persistir).
        $bodyOrderId = (string) ($body['order_id'] ?? '');
        $invoice = ncmExecute(
            "SELECT id, companyId, packId, status FROM billing_invoice
              WHERE provider = 'dlocal_go' AND (providerInvoiceId = ? OR id::text = ?)
              ORDER BY (providerInvoiceId = ?) DESC LIMIT 1",
            [$paymentId, $bodyOrderId, $paymentId]
        );
        if (!$invoice || empty($invoice['id'])) {
            return ['handled' => false, 'reason' => 'invoice_not_found'];
        }
        $invoiceId = (string) ($invoice['id'] ?? '');

        // NO confiar en el body → re-consultar el estado real a dLocal.
        $payment = $this->provider->getPayment($paymentId);

        // Pago no exitoso → marcar failed si seguía pendiente.
        if ($payment['status'] !== 'paid') {
            if ((string) ($invoice['status'] ?? '') === 'pending') {
                $db->Execute(
                    "UPDATE billing_invoice SET status = 'failed', updatedAt = now() WHERE id = ? AND status = 'pending'",
                    [$invoiceId]
                );
            }
            return ['handled' => true, 'status' => $payment['status']];
        }

        // Pago exitoso → acreditar transaccional e idempotente.
        return $this->creditInvoice($invoiceId, $payment['raw']);
    }

    /**
     * Acredita los créditos del pack de una invoice pagada, de forma atómica.
     * Idempotente: el lock + el chequeo de status + el índice único garantizan
     * que dos webhooks no acrediten dos veces.
     */
    private function creditInvoice(string $invoiceId, array $rawPayment): array
    {
        global $db;

        $db->BeginTrans();
        try {
            // Lock de la invoice + estado.
            $inv = $db->Execute(
                "SELECT id, companyId, packId, status FROM billing_invoice WHERE id = ? LIMIT 1 FOR UPDATE",
                [$invoiceId]
            );
            if (!$inv || $inv->EOF) {
                $db->RollbackTrans();
                return ['handled' => false, 'reason' => 'invoice_not_found'];
            }
            $status    = (string) ($inv->fields['status'] ?? '');
            $companyId = (string) ($inv->fields['companyid'] ?? $inv->fields['companyId'] ?? '');
            $packId    = (string) ($inv->fields['packid'] ?? $inv->fields['packId'] ?? '');

            // Ya procesada → idempotente.
            if ($status === 'paid') {
                $db->RollbackTrans();
                return ['handled' => true, 'status' => 'already_paid'];
            }
            if ($status !== 'pending') {
                $db->RollbackTrans();
                return ['handled' => true, 'status' => $status];
            }

            // Créditos del pack.
            $packRow = $db->Execute(
                "SELECT credits FROM credit_pack WHERE id = ? LIMIT 1",
                [$packId]
            );
            $credits = ($packRow && !$packRow->EOF)
                ? (int) ($packRow->fields['credits'] ?? 0)
                : 0;
            if ($credits <= 0) {
                $db->RollbackTrans();
                return ['handled' => false, 'reason' => 'invalid_pack'];
            }

            // Marcar pagada.
            $meta = json_encode($rawPayment);
            $upd = $db->Execute(
                "UPDATE billing_invoice SET status = 'paid', paidAt = now(), providerMetadata = ?::jsonb, updatedAt = now()
                  WHERE id = ? AND status = 'pending'",
                [$meta !== false ? $meta : '{}', $invoiceId]
            );
            if ($upd === false) {
                throw new \RuntimeException('BD update invoice: ' . $db->ErrorMsg());
            }
            // Guard primario de idempotencia: si 0 filas cambiaron, otra tx ya
            // hizo pending→paid → no acreditamos de nuevo (P1 code-review).
            if ((int) $db->Affected_Rows() === 0) {
                $db->RollbackTrans();
                return ['handled' => true, 'status' => 'already_paid'];
            }

            // Subir saldo de la company (lock pesimista).
            $coRow = $db->Execute(
                "SELECT aiCreditsBalance FROM company WHERE companyId = ? LIMIT 1 FOR UPDATE",
                [$companyId]
            );
            if (!$coRow || $coRow->EOF) {
                throw new \RuntimeException('Empresa no encontrada');
            }
            $current    = (int) ($coRow->fields['aicreditsbalance'] ?? $coRow->fields['aiCreditsBalance'] ?? 0);
            $newBalance = $current + $credits;

            $updCo = $db->Execute(
                "UPDATE company SET aiCreditsBalance = ?, updatedAt = now() WHERE companyId = ?",
                [$newBalance, $companyId]
            );
            if ($updCo === false) {
                throw new \RuntimeException('BD update balance: ' . $db->ErrorMsg());
            }

            // Ledger con relatedInvoiceId → backstop de idempotencia (índice único).
            $ins = $db->Execute(
                "INSERT INTO ai_credit_ledger
                   (id, companyId, delta, balanceAfter, reason, tokensIn, tokensOut, meta, relatedInvoiceId)
                 VALUES (gen_random_uuid(), ?, ?, ?, 'pack_purchase', 0, 0, '{}'::jsonb, ?)",
                [$companyId, $credits, $newBalance, $invoiceId]
            );
            if ($ins === false) {
                // Kill-switch DB_THROW_ON_ERROR=false: el wrapper vuelve a
                // devolver `false` en vez de lanzar. Probable violación del
                // índice único (otro webhook ya acreditó) → idempotente.
                throw new \RuntimeException('ledger_duplicate');
            }

            $db->CommitTrans();

            // F5 — dogfooding: emitir la factura del SaaS como venta real en el
            // tenant emisor. Best-effort A PROPÓSITO: un fallo acá (tenant emisor
            // mal configurado, item/contacto rechazado, etc.) NUNCA debe romper
            // la respuesta del webhook — dLocal reintendría el pago entero. La
            // idempotencia de SaasBillingService (PK de saas_invoice_sale + uid
            // determinístico) hace seguro reintentarlo después (botón manual en
            // la ficha del tenant, o el próximo webhook si dLocal reintenta).
            try {
                $saasCfg = \PlatformConfig::get('saasBilling', null);
                if (is_array($saasCfg) && !empty($saasCfg['enabled'])) {
                    (new \SaasBillingService())->emitForInvoice($invoiceId);
                }
            } catch (\Throwable $e) {
                error_log('[PaymentsService] SaasBillingService::emitForInvoice falló para invoice '
                    . $invoiceId . ': ' . $e->getMessage());
            }

            return ['handled' => true, 'status' => 'paid', 'credited' => $credits];

        } catch (\Punto\Api\Support\DbQueryException $e) {
            // VA ANTES del \Throwable a propósito. Desde que el wrapper lanza,
            // el INSERT en ai_credit_ledger ya NO devuelve `false`: la violación
            // del índice único (relatedInvoiceId) llega como DbQueryException
            // con SQLSTATE 23505 y el `throw new RuntimeException('ledger_duplicate')`
            // de arriba nunca se arma. Sin esta rama, un webhook duplicado de
            // dLocal dejaba de detectarse y salía 500 → dLocal reintenta el pago.
            // Es plata: la idempotencia se resuelve por SQLSTATE, no por texto.
            $db->RollbackTrans();
            // Se exige el NOMBRE del índice, no un 23505 a secas: 'already_paid'
            // significa "otro webhook ya acreditó ESTA invoice", y eso lo prueba
            // únicamente `uq_ai_credit_ledger_invoice_grant`. Cualquier otra
            // violación de unicidad que aparezca en esta transacción es un bug,
            // no una repetición — tragarla devolvería 'already_paid' sobre un
            // pago que NUNCA se acreditó. Hoy el ledger es la única escritura
            // con unique acá, pero eso es una propiedad frágil: el día que
            // alguien agregue otro INSERT, este catch tiene que dejarlo pasar.
            if ($e->sqlState() === '23505'
                && str_contains($e->getMessage(), self::LEDGER_IDEMPOTENCY_INDEX)) {
                error_log('[PaymentsService] ledger duplicado (' . self::LEDGER_IDEMPOTENCY_INDEX
                    . ') para invoice ' . $invoiceId . ' — webhook idempotente, no se acredita de nuevo');
                return ['handled' => true, 'status' => 'already_paid'];
            }
            throw $e;
        } catch (\Throwable $e) {
            $db->RollbackTrans();
            if ($e->getMessage() === 'ledger_duplicate') {
                // Ya acreditado por otro webhook concurrente — éxito idempotente.
                return ['handled' => true, 'status' => 'already_paid'];
            }
            throw $e;
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function successUrl(): string      { return $this->cfg('DLOCAL_GO_SUCCESS_URL'); }
    private function backUrl(): string         { return $this->cfg('DLOCAL_GO_BACK_URL'); }
    private function notificationUrl(): string { return $this->cfg('DLOCAL_GO_NOTIFICATION_URL'); }

    private function cfg(string $key): string
    {
        if (defined($key)) {
            return (string) constant($key);
        }
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return (string) $v;
        }
        return (string) ($_ENV[$key] ?? '');
    }

    /** Normaliza el resultado de ncmExecute(forceObj) a array de filas assoc. */
    private function rows($rs): array
    {
        if (is_object($rs) && method_exists($rs, 'GetRows')) {
            return $rs->GetRows();
        }
        return is_array($rs) ? [$rs] : [];
    }
}
