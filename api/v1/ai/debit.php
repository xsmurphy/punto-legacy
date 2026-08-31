<?php
/**
 * POST /v1/ai/debit
 *
 * Debita créditos del balance de la company por uso del agente IA.
 * Body JSON: { tokensIn, tokensOut, capability, model, requestId? }
 *
 * Lógica atómica: SELECT FOR UPDATE sobre company → UPDATE aiCreditsBalance →
 * INSERT ai_credit_ledger (delta negativo). Mismo patrón que
 * PaymentsService::creditInvoice.
 *
 * Auth: realms `panel` y `pos-app`. POST only.
 */

require_once __DIR__ . '/../../bootstrap.php';

// `pos-app` (context/59 F2/D5): el asistente de la caja consume créditos del
// MISMO pozo del tenant, así que tiene que poder debitarlos. Es el único
// endpoint de ESCRITURA que la caja abre para el asistente, y va sin gate por
// método porque el endpoint entero es la escritura — no hay un GET del que
// separarlo.
//
// Lo que esto habilita, dicho sin maquillar: quien tenga el Bearer eterno de una
// tablet puede llamar acá con `tokensIn`/`tokensOut` inventados y quemar
// créditos del comercio. NO es una clase de riesgo nueva —los tokens ya vienen
// del cliente y cualquier sesión de panel puede hacer lo mismo desde 2026-07-31—
// pero sí la extiende a una credencial que no expira y vive en el localStorage
// de un dispositivo compartido. Mitigarlo de verdad es mover el cálculo al
// server (que el BFF reporte el uso y el backend lo valide contra la respuesta
// del proveedor); queda anotado en context/59 D5 junto con el actor del ledger.
$ctx = apiAuthTenant(['panel', 'pos-app']);

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    apiError('Method not allowed', 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$tokensIn   = isset($body['tokensIn'])  ? (int) $body['tokensIn']  : -1;
$tokensOut  = isset($body['tokensOut']) ? (int) $body['tokensOut'] : -1;
$capability = trim((string) ($body['capability'] ?? ''));
$model      = trim((string) ($body['model'] ?? ''));
$requestId  = trim((string) ($body['requestId'] ?? ''));

if ($tokensIn < 0 || $tokensOut < 0 || $capability === '' || $model === '') {
    apiError('Parámetros inválidos', 422);
}

// Leer pricing desde ai_model_config
$cfgRow = ncmExecute(
    'SELECT creditsPerKToken FROM ai_model_config WHERE capability = ? AND enabled = true LIMIT 1',
    [$capability],
    false,
    true
);
if (!$cfgRow || $cfgRow->EOF) {
    apiError('Capability sin config activa', 422);
}
$creditsPerKToken = (float) ($cfgRow->fields['creditsperktoken'] ?? $cfgRow->fields['creditsPerKToken'] ?? 1);

// ceil((in+out)/1000 * creditsPerKToken), mínimo 1 si hubo uso
$totalTokens = $tokensIn + $tokensOut;
if ($totalTokens <= 0) {
    apiOk(['credits' => 0, 'balance' => 0]);
    exit;
}
$credits = (int) ceil($totalTokens / 1000 * $creditsPerKToken);
if ($credits < 1) {
    $credits = 1;
}

$companyId = $ctx['companyId'];
$metaJson  = json_encode(['capability' => $capability, 'model' => $model, 'requestId' => $requestId]);
if ($metaJson === false) {
    $metaJson = '{}';
}

global $db;
$db->BeginTrans();
try {
    $coRow = $db->Execute(
        "SELECT aiCreditsBalance FROM company WHERE companyId = ? LIMIT 1 FOR UPDATE",
        [$companyId]
    );
    if (!$coRow || $coRow->EOF) {
        $db->RollbackTrans();
        apiError('Empresa no encontrada', 500);
    }
    $current    = (int) ($coRow->fields['aicreditsbalance'] ?? $coRow->fields['aiCreditsBalance'] ?? 0);
    $newBalance = $current - $credits;

    $updCo = $db->Execute(
        "UPDATE company SET aiCreditsBalance = ?, updatedAt = now() WHERE companyId = ?",
        [$newBalance, $companyId]
    );
    if ($updCo === false) {
        throw new \RuntimeException('BD update balance: ' . $db->ErrorMsg());
    }

    $ins = $db->Execute(
        "INSERT INTO ai_credit_ledger
           (id, companyId, delta, balanceAfter, reason, tokensIn, tokensOut, meta)
         VALUES (gen_random_uuid(), ?, ?, ?, 'agent_chat', ?, ?, ?::jsonb)",
        [$companyId, -$credits, $newBalance, $tokensIn, $tokensOut, $metaJson]
    );
    if ($ins === false) {
        throw new \RuntimeException('BD insert ledger: ' . $db->ErrorMsg());
    }

    $db->CommitTrans();
    apiOk(['credits' => $credits, 'balance' => $newBalance]);

} catch (\Throwable $e) {
    $db->RollbackTrans();
    error_log('[ai/debit] ' . $e->getMessage());
    apiError('No se pudo registrar el uso', 500);
}
