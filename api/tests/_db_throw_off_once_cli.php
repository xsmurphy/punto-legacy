<?php
declare(strict_types=1);

/**
 * Helper de subproceso de `db_error_visibility_test.php`, caso (d).
 *
 * `DB_THROW_ON_ERROR` es una CONSTANTE (se define una sola vez por proceso en
 * `api/includes/simple.config.php`), así que el kill-switch no se puede
 * probar en el mismo proceso que el resto de los casos. Se invoca así:
 *
 *   DB_THROW_ON_ERROR=false php -d variables_order=EGPCS _db_throw_off_once_cli.php
 *
 * Imprime `RESULT=false_no_throw` si el wrapper volvió al comportamiento
 * histórico (devuelve `false` sin lanzar), y `ERRMSG_OK` si además siguió
 * poblando `ErrorMsg()`. Cualquier otra salida es un fallo.
 *
 * Mismo patrón de subproceso que `_sale_void_once_cli.php`.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use Punto\Api\Support\DbQueryException;

/** @var DB $db */
global $db;

// El wrapper loguea el fallo esperado; no ensuciar la salida que lee el test.
ini_set('error_log', sys_get_temp_dir() . '/db_throw_off_' . getmypid() . '.log');

if (!defined('DB_THROW_ON_ERROR')) {
    echo "RESULT=constant_missing\n";
    exit(1);
}
if (DB_THROW_ON_ERROR !== false) {
    echo "RESULT=switch_not_applied (DB_THROW_ON_ERROR=" . var_export(DB_THROW_ON_ERROR, true) . ")\n";
    exit(1);
}

try {
    $rs = $db->Execute('SELECT columna_que_no_existe FROM company');
} catch (DbQueryException $e) {
    echo "RESULT=threw_anyway\n";
    exit(1);
} catch (\Throwable $e) {
    echo 'RESULT=threw_' . get_class($e) . "\n";
    exit(1);
}

if ($rs !== false) {
    echo "RESULT=not_false\n";
    exit(1);
}
echo "RESULT=false_no_throw\n";

if (str_contains($db->ErrorMsg(), 'columna_que_no_existe')) {
    echo "ERRMSG_OK\n";
}
exit(0);
