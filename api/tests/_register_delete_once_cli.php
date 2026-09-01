<?php
declare(strict_types=1);

/**
 * Helper de `outlet_chain_invariant_test.php` — hace UNA operación de baja de
 * caja (`delete()` o `update(['status' => false])`) en un subproceso propio.
 *
 * Nació por la misma razón que `_void_once_cli.php`: el guard de "no se puede
 * eliminar/desactivar la última caja de la sucursal" respondía con `apiError()`,
 * que hace `exit` directo, y un arnés que muere no imprime la línea
 * `HARNESS RESULT` — falso rojo indistinguible de un crash.
 *
 * Desde 2026-09-01 el servicio LANZA (`RegisterAdminException`) en vez de salir,
 * así que el subproceso ya no es estrictamente necesario. Se conserva igual: es
 * el aislamiento que garantiza que un `exit` futuro —el guard de devices todavía
 * responde así— no se lleve puesto al arnés entero.
 *
 * Los DOS caminos se ejercitan porque los dos pueden romper la cadena: borrar
 * la caja y desactivarla dejan igual de muerta a la sucursal.
 *
 * El padre solo lee la salida y su contrato no cambió: envelope
 * `{"ok":false,"error":{message,code}}` si el guard bloqueó —el mismo que
 * emitía `apiError()`, ahora armado desde la excepción— o `{"ok":true,...}` si
 * la baja pasó.
 *
 * Uso: php _register_delete_once_cli.php <companyId> <registerId> [delete|deactivate]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\RegisterAdminService;

$companyId  = (string) ($argv[1] ?? '');
$registerId = (string) ($argv[2] ?? '');
$accion     = (string) ($argv[3] ?? 'delete');

$svc = new RegisterAdminService($companyId);

try {
    $result = $accion === 'deactivate'
        ? $svc->update($registerId, ['status' => false])
        : $svc->delete($registerId);
} catch (\Punto\Api\Services\RegisterAdminException $e) {
    echo json_encode([
        'ok'    => false,
        'error' => ['message' => $e->getMessage(), 'code' => $e->httpCode()],
    ]);
    exit;
}

echo json_encode(['ok' => true, 'data' => $result]);
