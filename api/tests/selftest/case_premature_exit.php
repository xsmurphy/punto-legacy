<?php
declare(strict_types=1);

/**
 * Vía 3: terminación prematura LIMPIA — `exit(0)` en medio del arnés, sin
 * excepción ni fatal. Es el caso más traicionero: PHP no tiene nada que
 * reportar y el exit code es literalmente 0. Sólo la ausencia de la línea
 * canónica lo delata.
 */

require_once __DIR__ . '/../_harness.php';

$failures = 0;

echo "[selftest] arrancó; ahora hace exit(0) antes de evaluar nada\n";

exit(0);

harnessFinish($failures);
