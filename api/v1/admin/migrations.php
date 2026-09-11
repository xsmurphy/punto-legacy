<?php

/**
 * /v1/admin/migrations.php — migrador ENCOM → Punto (realm /admin, context/77).
 *
 * Gateado por adminMiddleware() (sesión opaca `_jwt_admin`). NO apiMiddleware.
 *
 *   GET                          → lista de jobs (opcional ?companyId=&limit=)
 *   GET  ?id=<uuid>              → detalle de un job, con bitácora
 *   POST  body JSON              → crea un job (hace el login al legacy y
 *                                  guarda SOLO las cookies — D2)
 *
 * Rol: `support`. Es la tarea del equipo de soporte según el pedido del owner,
 * y el alta queda atribuida por `adminAudit()`.
 *
 * Lo que este endpoint NO hace: importar. Encola y contesta. El trabajo lo
 * levanta `api/scripts/migration_worker.php` desde el drain de
 * `/v1/maintenance?job=migration-drain` (D3) — el export son minutos de
 * requests paceadas contra el legacy, muy por encima de cualquier timeout de
 * PHP-FPM.
 */

// simple.config.php ANTES que nada: el realm admin no pasa por bootstrap.php
// (que es quien lo carga en el realm tenant), así que sin este require la
// constante ENCOM_MIGRATION_URL no existe en la request real y `ready` daba
// false con la variable perfectamente cargada en el entorno (2026-09-11).
// El worker CLI no lo necesita: entra por bootstrap.php.
require_once __DIR__ . '/../../includes/simple.config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../lib/Auth/AdminAuth.php';
require_once __DIR__ . '/../../lib/Admin/EncomMigrationService.php';
require_once __DIR__ . '/../../lib/Admin/EncomMigrationException.php';

adminMiddleware();          // define ADMIN_AUTHED_ID o mata con 401
adminRequireRole('support');

use Punto\Api\Admin\EncomMigrationService;
use Punto\Api\Admin\EncomMigrationException;

$svc    = new EncomMigrationService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$uuidRe = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

try {
    if ($method === 'GET') {
        $id = trim((string) ($_GET['id'] ?? ''));

        if ($id !== '') {
            if (!preg_match($uuidRe, $id)) {
                apiError('id inválido', 422);
            }
            apiOk($svc->detail($id));
        }

        $companyId = trim((string) ($_GET['companyId'] ?? ''));
        if ($companyId !== '' && !preg_match($uuidRe, $companyId)) {
            apiError('companyId inválido', 422);
        }

        apiOk([
            'jobs'    => $svc->listJobs($companyId !== '' ? $companyId : null, (int) ($_GET['limit'] ?? 50)),
            'domains' => EncomMigrationService::DOMAINS,
            // El front usa esto para avisar "falta configurar el entorno"
            // ANTES de que el operador tipee la contraseña de un cliente.
            'ready'   => defined('ENCOM_MIGRATION_URL') && trim((string) ENCOM_MIGRATION_URL) !== '',
        ]);
    }

    if ($method === 'POST') {
        // adminMiddleware() ya vuelca el body JSON a $_POST cuando viene vacío.
        $companyId = trim((string) ($_POST['companyId'] ?? ''));
        if (!preg_match($uuidRe, $companyId)) {
            apiError('Elegí la empresa destino.', 422);
        }

        $registerOutletId = trim((string) ($_POST['registerOutletId'] ?? ''));
        if ($registerOutletId !== '' && !preg_match($uuidRe, $registerOutletId)) {
            apiError('registerOutletId inválido', 422);
        }

        $domains = $_POST['domains'] ?? [];
        if (is_string($domains)) {
            $domains = array_filter(array_map('trim', explode(',', $domains)));
        }
        if (!is_array($domains)) {
            $domains = [];
        }

        // El usuario del legacy es UN campo de texto libre: el cliente entra
        // con su email o con su celular, y el backend legacy resuelve cuál
        // es. No se normaliza nada acá (ver `EncomClient::login()`).
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        if ($identifier === '') {
            apiError('Falta el usuario del cliente en el sistema legacy (email o celular).', 422);
        }

        $password = (string) ($_POST['password'] ?? '');
        if (trim($password) === '') {
            apiError('Falta la contraseña del cliente en el sistema legacy.', 422);
        }

        // Rango del HISTÓRICO. Se valida acá —con el operador mirando— y no
        // adentro del worker: una fecha mal tipeada media hora después es un
        // job fallado que hay que volver a crear con la contraseña del cliente.
        $historyFrom = trim((string) ($_POST['historyFrom'] ?? ''));
        $historyTo   = trim((string) ($_POST['historyTo'] ?? ''));

        foreach (['desde' => $historyFrom, 'hasta' => $historyTo] as $cual => $valor) {
            if ($valor !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
                apiError('La fecha "' . $cual . '" del histórico tiene que ser AAAA-MM-DD.', 422);
            }
        }

        if ($historyFrom !== '' && $historyTo !== '' && $historyFrom > $historyTo) {
            apiError('El "desde" del histórico es posterior al "hasta".', 422);
        }

        $res = $svc->create(
            $companyId,
            [
                'identifier' => $identifier,
                'password'   => $password,
            ],
            $domains,
            $registerOutletId !== '' ? $registerOutletId : null,
            defined('ADMIN_AUTHED_ID') ? (string) ADMIN_AUTHED_ID : null,
            ['historyFrom' => $historyFrom, 'historyTo' => $historyTo]
        );

        // La password NO entra en el meta de auditoría. Queda quién lanzó la
        // migración, contra qué empresa y qué dominios pidió.
        adminAudit(
            'migration.create',
            'company',
            $companyId,
            '',
            ['jobId' => $res['jobId'], 'domains' => array_values($domains), 'source' => 'encom']
        );

        apiOk($res, 201);
    }

    apiError('Método no soportado', 405);
} catch (EncomMigrationException $e) {
    apiError($e->getMessage(), $e->status());
}
