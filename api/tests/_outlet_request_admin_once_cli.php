<?php
declare(strict_types=1);

/**
 * Helper de `outlet_request_test.php` — resuelve UNA solicitud de sucursal
 * cargando SOLO lo que carga un endpoint del realm `admin`.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────
 * El resto del arnés hace `require bootstrap.php`, que es el embudo del realm
 * de TENANT: registra el autoloader PSR-4, carga `functions.php`, define
 * constantes. En `/admin` nada de eso corre ("realm aislado y limpio", ver
 * `api/lib/Admin/CompanyAdminService.php`), así que un arnés que prueba la
 * aprobación con el bootstrap puesto prueba un entorno que NO es donde el
 * código corre — y da verde sobre un "Class not found" garantizado en
 * producción. Pasó exactamente eso con `Punto\Api\Support\TenantLocale`, que
 * `OutletsService::update()` referencia y que en /admin no resolvía.
 *
 * Por eso este subproceso replica los requires del endpoint real
 * (`api/v1/admin/companies.php`): `includes/db.php` y nada más. Si mañana la
 * cadena de la aprobación suma una clase que el realm admin no puede resolver,
 * este archivo se pone rojo.
 *
 * `AdminAuth` NO se carga: la autenticación del admin no es lo que se prueba y
 * arrastraría sesiones. Lo que se prueba es la CARGA DE CLASES y el efecto.
 *
 * Uso: php _outlet_request_admin_once_cli.php <requestId> <approve|reject> [motivo]
 * Salida: envelope JSON en la última línea.
 */

require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/lib/Outlets/OutletRequestService.php';

$requestId = (string) ($argv[1] ?? '');
$accion    = (string) ($argv[2] ?? 'approve');
$motivo    = isset($argv[3]) ? (string) $argv[3] : null;

try {
    $res = (new \Punto\Api\Outlets\OutletRequestService())
        ->resolve($requestId, $accion === 'approve', $motivo, 'arnes-admin@punto.la');
} catch (\Throwable $e) {
    // Un fatal de carga NO es un resultado del servicio: se marca distinto para
    // que el padre pueda decir "la cadena del realm admin está rota".
    echo "\n" . json_encode([
        'ok'    => false,
        'fatal' => get_class($e) . ': ' . $e->getMessage(),
    ]);
    exit(1);
}

echo "\n" . json_encode($res);
