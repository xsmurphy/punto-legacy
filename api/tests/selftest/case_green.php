<?php
declare(strict_types=1);

/**
 * Control positivo: un arnés que SÍ termina bien. Sirve para probar que el
 * mecanismo no volvió todo rojo (un guard que nunca deja pasar nada es tan
 * inútil como uno que deja pasar todo).
 */

require_once __DIR__ . '/../_harness.php';

echo "[selftest] arnés sano: 3 aserciones, todas OK\n";

harnessFinish(0, 3);
