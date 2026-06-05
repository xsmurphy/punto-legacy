<?php
declare(strict_types=1);

namespace Punto\App\Helpers;

/**
 * Smoke test del autoload PSR-4. Sirve solo como prueba de que
 * `composer dump-autoload` resuelve `Punto\App\Helpers\SmokeTest`
 * desde `app/Helpers/SmokeTest.php`.
 *
 * Slice 0 — Punto\App\* PSR-4 setup. Sin breaking changes.
 *
 * Esta clase se ELIMINA cuando se haga el primer slice real (1: dead code).
 */
final class SmokeTest
{
    public static function ping(): string
    {
        return 'pong from Punto\\App\\Helpers\\SmokeTest';
    }
}
