<?php
declare(strict_types=1);

/**
 * Control negativo "sano": el arnés corre entero y REPORTA fallas. Distinto de
 * abortar: acá las aserciones sí son concluyentes, sólo que dieron mal.
 * Debe salir 1 e imprimir la línea canónica con `-> FAIL`.
 */

require_once __DIR__ . '/../_harness.php';

echo "[selftest] arnés sano con 2 aserciones fallidas\n";

harnessFinish(2, 5);
