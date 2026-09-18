<?php
/**
 * REST canónico — Wallet multi-nivel, F1 (context/74).
 *
 *   GET  /v1/wallet?resource=pockets[&active=1]            → { pockets: [...] }
 *   POST /v1/wallet?resource=pockets                       → crea un bolsillo (201)
 *        body: { name, active? }
 *   PUT  /v1/wallet?resource=pockets&id=<uuid>             → renombra y/o activa-desactiva
 *        body: { name?, active? }
 *   GET  /v1/wallet?resource=balances&contactId=<uuid>     → { balances: [...] }
 *   GET  /v1/wallet?resource=movements&contactId=<uuid>[&pocketId=&limit=&beforeSeq=]
 *                                                          → { movements: [...], nextBeforeSeq }
 *   POST /v1/wallet?resource=adjust                        → ajuste manual
 *        body: { contactId, pocketId, amount (con signo), reason }
 *
 * No hay DELETE de bolsillos: tienen historia (se desactivan). No hay DELETE
 * ni PUT de movimientos: son append-only (lo garantiza la BD, mig 232).
 *
 * ── Qué NO está acá, a propósito ───────────────────────────────────────────
 *
 * Cargar, pagar con saldo, revertir y transferir no se exponen en F1. Cargar
 * y pagar son de la CAJA (F2) y van dentro de la venta; transferir es de la
 * F3. `WalletService` ya tiene las cuatro operaciones — lo que falta es la
 * superficie, y una superficie genérica "mové saldo" en el panel sería un
 * camino de dinero sin venta detrás.
 *
 * ── Realm: `panel` ─────────────────────────────────────────────────────────
 *
 * El POS consumirá la wallet por sus propios endpoints (F2), con el operador
 * del PIN como autor. Este archivo es la superficie de gestión.
 *
 * ── Permisos ───────────────────────────────────────────────────────────────
 *
 * `wallet.view` para leer; `wallet.manage` para el catálogo y los ajustes
 * (un ajuste crea o quita saldo sin venta de por medio). Quien tiene manage
 * también puede leer.
 *
 * Alcance por sucursal: NO aplica. El saldo es del COMERCIO (context/74 §3.1:
 * "todo vive dentro de un comercio") — el mismo bolsillo se consume en
 * cualquier sucursal, así que filtrar por las sucursales del usuario le
 * mostraría un saldo que no es el real.
 *
 * ── Módulo ─────────────────────────────────────────────────────────────────
 *
 * Se gatea server-side además de en el panel: un comercio con el módulo
 * apagado no opera la wallet aunque arme la request a mano.
 *
 * Auditoría: automática vía `apiAuthTenant()`. Realtime: lo publica el
 * servicio DESPUÉS del write (el publish automático del bootstrap corre antes
 * de que el handler escriba).
 */

require_once __DIR__ . '/../bootstrap.php';

use Punto\Api\Wallet\WalletException;
use Punto\Api\Wallet\WalletService;

$ctx       = apiAuthTenant(['panel']);
$companyId = (string) $ctx['companyId'];
$userId    = (string) ($ctx['userId'] ?? '');

$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$resource = (string) ($_GET['resource'] ?? '');
$id       = isset($_GET['id']) ? (string) $_GET['id'] : null;

if (!(new \Punto\Api\Modules\ModulesService())->isEnabled($companyId, 'wallet')) {
    apiError('El módulo de saldo de clientes no está activo', 403);
}

$canManage = hasPermission('wallet.manage');

$requireView = static function () use ($canManage): void {
    if (!$canManage && !hasPermission('wallet.view')) {
        apiError('No tenés permiso para esta acción (requiere: wallet.view)', 403);
    }
};
$requireManage = static function () use ($canManage): void {
    if (!$canManage) {
        apiError('No tenés permiso para esta acción (requiere: wallet.manage)', 403);
    }
};

$svc = new WalletService();

try {
    switch ($resource) {
        // ── Catálogo de bolsillos ───────────────────────────────────────────
        case 'pockets':
            if ($method === 'GET') {
                $requireView();
                apiOk(['pockets' => $svc->listPockets($companyId, ($_GET['active'] ?? '') === '1')]);
            }
            if ($method === 'POST') {
                $requireManage();
                $pocket = $svc->createPocket($companyId, (string) ($_POST['name'] ?? ''));
                // El form del catálogo manda `active` también en el alta.
                if (array_key_exists('active', $_POST)
                    && filter_var($_POST['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
                    $pocket = $svc->setPocketActive($companyId, $pocket['id'], false);
                }
                apiOk($pocket, 201);
            }
            if ($method === 'PUT') {
                $requireManage();
                if ($id === null || $id === '') {
                    apiError('id es requerido', 422);
                }
                $pocket = null;
                if (array_key_exists('name', $_POST)) {
                    $pocket = $svc->renamePocket($companyId, $id, (string) $_POST['name']);
                }
                if (array_key_exists('active', $_POST)) {
                    $active = filter_var($_POST['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($active === null) {
                        apiError('active inválido', 422);
                    }
                    $pocket = $svc->setPocketActive($companyId, $id, $active);
                }
                if ($pocket === null) {
                    apiError('Patch vacío', 422);
                }
                apiOk($pocket);
            }
            apiError('Method not allowed', 405);

        // ── Saldo de un cliente por bolsillo ────────────────────────────────
        case 'balances':
            if ($method !== 'GET') {
                apiError('Method not allowed', 405);
            }
            $requireView();
            $contactId = (string) ($_GET['contactId'] ?? '');
            if ($contactId === '') {
                apiError('contactId es requerido', 422);
            }
            apiOk(['balances' => $svc->balances($companyId, $contactId)]);

        // ── Movimientos de un cliente ───────────────────────────────────────
        case 'movements':
            if ($method !== 'GET') {
                apiError('Method not allowed', 405);
            }
            $requireView();
            $contactId = (string) ($_GET['contactId'] ?? '');
            if ($contactId === '') {
                apiError('contactId es requerido', 422);
            }
            $beforeSeq = isset($_GET['beforeSeq']) && ctype_digit((string) $_GET['beforeSeq'])
                ? (int) $_GET['beforeSeq'] : null;
            apiOk($svc->movements(
                $companyId,
                $contactId,
                isset($_GET['pocketId']) && $_GET['pocketId'] !== '' ? (string) $_GET['pocketId'] : null,
                (int) ($_GET['limit'] ?? 50),
                $beforeSeq
            ));

        // ── Ajuste manual ───────────────────────────────────────────────────
        case 'adjust':
            if ($method !== 'POST') {
                apiError('Method not allowed', 405);
            }
            $requireManage();
            $amount = $_POST['amount'] ?? null;
            if (!is_numeric($amount)) {
                apiError('Indicá el monto del ajuste', 422);
            }
            apiOk($svc->adjust(
                $companyId,
                (string) ($_POST['contactId'] ?? ''),
                (string) ($_POST['pocketId'] ?? ''),
                (float) $amount,
                (string) ($_POST['reason'] ?? ''),
                // El autor sale de la sesión, nunca del cuerpo de la request.
                $userId
            ), 201);

        default:
            apiError('Recurso no encontrado', 404);
    }
} catch (WalletException $e) {
    apiError($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
}
