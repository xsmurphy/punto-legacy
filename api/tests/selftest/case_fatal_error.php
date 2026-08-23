<?php
declare(strict_types=1);

/**
 * Vía 2: error fatal de PHP (E_ERROR). No pasa por set_exception_handler.
 * Debe salir ≠ 0 y no imprimir la línea canónica.
 */

require_once __DIR__ . '/../_harness.php';
require_once dirname(__DIR__, 2) . '/includes/error_handlers.php';
puntoRegisterErrorHandlers();

$failures = 0;

echo "[selftest] arrancó; ahora provoca un fatal antes de cualquier aserción\n";

/** @phpstan-ignore-next-line — el fatal es el objetivo del caso */
funcionQueNoExisteEnNingunLado();

harnessFinish($failures);
