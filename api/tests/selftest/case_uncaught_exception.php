<?php
declare(strict_types=1);

/**
 * Vía 1 del falso verde: excepción NO atrapada con los handlers de la API
 * registrados. ANTES del fix esto salía con código 0 y el runner cantaba
 * "TODO OK". Ahora debe salir ≠ 0 y no imprimir la línea canónica.
 *
 * Carga `error_handlers.php` directo (no `bootstrap.php`) para reproducir la
 * condición exacta sin necesitar Postgres.
 */

require_once __DIR__ . '/../_harness.php';
require_once dirname(__DIR__, 2) . '/includes/error_handlers.php';
puntoRegisterErrorHandlers();

$failures = 0;

echo "[selftest] arrancó; ahora lanza una excepción antes de cualquier aserción\n";

throw new \RuntimeException('boom deliberado del selftest');

// Inalcanzable — si el guard fallara, esto nunca corre y el arnés igual
// terminaría en 0 sin el mecanismo.
harnessFinish($failures);
