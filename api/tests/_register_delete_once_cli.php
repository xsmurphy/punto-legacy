<?php
declare(strict_types=1);

/**
 * Helper de `outlet_chain_invariant_test.php` — hace UNA operación de baja de
 * caja (`delete()` o `update(['status' => false])`) en un subproceso propio.
 *
 * Existe por la misma razón que `_void_once_cli.php`: el guard de "no se puede
 * eliminar/desactivar la última caja de la sucursal" responde con `apiError()`
 * (mismo error-path que el endpoint real), que hace `exit` directo. No se puede
 * `try/catch` en el MISMO proceso del arnés sin matarlo entero, y un arnés que
 * muere no imprime la línea `HARNESS RESULT` — falso rojo indistinguible de un
 * crash.
 *
 * Los DOS caminos se ejercitan porque los dos pueden romper la cadena: borrar
 * la caja y desactivarla dejan igual de muerta a la sucursal.
 *
 * El padre solo lee la salida: envelope `{"ok":false,"error":{...}}` de
 * `apiError()` si el guard bloqueó, o `{"ok":true,...}` si la baja pasó.
 *
 * Uso: php _register_delete_once_cli.php <companyId> <registerId> [delete|deactivate]
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Services\RegisterAdminService;

$companyId  = (string) ($argv[1] ?? '');
$registerId = (string) ($argv[2] ?? '');
$accion     = (string) ($argv[3] ?? 'delete');

$svc = new RegisterAdminService($companyId);

$result = $accion === 'deactivate'
    ? $svc->update($registerId, ['status' => false])
    : $svc->delete($registerId);

echo json_encode(['ok' => true, 'data' => $result]);
