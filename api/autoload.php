<?php
declare(strict_types=1);

/**
 * Autoloader PSR-4 de `Punto\Api\…` — COMPARTIDO por todos los realms.
 *
 * ── Por qué salió de `bootstrap.php` (2026-09-11) ───────────────────────
 * Vivía inline en `api/bootstrap.php`, que es el embudo del realm de TENANT.
 * El realm `admin` no pasa por ahí ("realm aislado y limpio", ver el docblock
 * de `api/lib/Admin/CompanyAdminService.php`), así que cualquier clase
 * `Punto\Api\*` que un endpoint de /admin necesitara había que `require_once`
 * a mano — y no solo la clase que se usa: también TODA su cadena transitiva.
 *
 * Esa lista es frágil por construcción y ya mordió tres veces:
 *
 *   - `includes/lib/DB.php:38-46` requiere a mano sus propias excepciones
 *     "porque el autoloader lo registra `bootstrap.php`".
 *   - `CompanyAdminService` reimplementa consultas para no depender de
 *     `functions.php`.
 *   - Al aprobar una solicitud de sucursal desde /admin (mig 219), la cadena
 *     `OutletRequestService → OutletsService::create() → update() →
 *     Punto\Api\Support\TenantLocale` explotaba con "Class not found" — un
 *     archivo que nadie podía adivinar leyendo el call-site.
 *
 * El arreglo es el wrapper, no el call-site (CLAUDE.md §5): el autoloader se
 * registra desde `includes/db.php`, por donde pasan los DOS realms. Registrar
 * un autoloader no tiene efectos: solo resuelve clases que, sin él, serían un
 * fatal. No abre superficie, no ejecuta nada, no cambia ninguna respuesta.
 *
 * El aislamiento del realm admin sigue intacto en lo que importa: no carga el
 * embudo de auth del tenant, ni sus constantes, ni su rate limiter.
 *
 * `__DIR__` es `api/`, así que el mapeo es
 * `Punto\Api\Sales\SaleService` → `api/lib/Sales/SaleService.php`.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Punto\\Api\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = __DIR__ . '/lib/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
        return;
    }
    // Fallback de case: el dir físico de algunos módulos es lowercase (ej.
    // `lib/services/` con namespace `Punto\Api\Services`). En macOS (FS
    // case-insensitive) el path de arriba matchea igual, pero en Linux prod
    // (case-sensitive) falla → "Class not found". Reintentamos con el primer
    // segmento del path en minúscula para resolver ese mismatch sin renombrar
    // el directorio (que rompería los require_once existentes en lowercase).
    $lower = preg_replace_callback('#^[^/]+#', static fn ($m) => strtolower($m[0]), $relative);
    if ($lower !== $relative) {
        $pathLower = __DIR__ . '/lib/' . $lower . '.php';
        if (is_file($pathLower)) {
            require_once $pathLower;
        }
    }
});
