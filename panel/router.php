<?php
/**
 * Router para PHP built-in server (panel)
 * Uso: php -S localhost:8001 router.php
 *
 * Replica las reglas de .htaccess:
 * 1. URLs sin extension -> .php
 * 2. API/* sin extension -> API/*.php
 */

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

// Security headers — aplican a todas las respuestas del módulo /panel
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Servir archivos estaticos que existen (css, js, images, fonts)
// Incluye /assets/ que es un symlink a ../assets/
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false; // PHP built-in server sirve el archivo directamente
}

// Fallback para URLs de resize del CDN: /assets/{w}-{h}/... → imagenotfound.jpg
if (preg_match('|^/assets/\d+-\d+/|', $path)) {
    $fallback = __DIR__ . '/../assets/images/imagenotfound.jpg';
    header('Content-Type: image/jpeg');
    readfile($fallback);
    return true;
}

// Front estático del BFF: el shell navega a /a_<hash>, pero los reportes migrados al
// modelo BFF de 3 niveles se sirven como .html estático (PHP nunca sirve HTML).
// En prod, replicar con un RewriteRule en .htaccess.
$bffStaticReports = [
    // El dashboard del panel (home). Read-only: el único write legacy (?action=tutorial = tour
    // iguider) queda fuera (seguimiento en 10-roadmap → iguider→driver.js).
    '/a_dashboard'        => '/reports/dashboard.html',
    '/a_report_summary'   => '/reports/summary.html',
    '/a_report_p_methods' => '/reports/payment-methods.html',
    '/a_report_inventory' => '/reports/inventory.html',
    '/a_report_users'     => '/reports/users.html',
    '/a_report_categories' => '/reports/categories.html',
    '/a_report_brands'     => '/reports/brands.html',
    '/a_report_stock_day'  => '/reports/stock-day.html',
    '/a_report_satisfaction' => '/reports/satisfaction.html',
    '/a_report_stock'      => '/reports/stock.html',
    '/a_report_recurring'  => '/reports/recurring.html',
    '/a_report_summary_year' => '/reports/summary-year.html',
    // by_brands es un duplicado legacy de brands (misma lógica "Ventas por Marca", ya migrada).
    // Está huérfano (nada lo linkea); se aliasa al front canónico por si queda algún bookmark.
    '/a_report_by_brands'    => '/reports/brands.html',
    '/a_report_customers'    => '/reports/customers.html',
    '/a_report_expenses'     => '/reports/expenses.html',
    '/a_report_drawers'      => '/reports/drawers.html',
    '/a_report_products'     => '/reports/products.html',
    '/a_report_cashflow'     => '/reports/cashflow.html',
    '/a_report_open_invoices' => '/reports/open_invoices.html',
    '/a_report_vpayments'     => '/reports/vpayments.html',
];
if (isset($bffStaticReports[$path])) {
    $htmlFile = __DIR__ . $bffStaticReports[$path];
    if (file_exists($htmlFile)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($htmlFile);
        return true;
    }
}

// Casos especiales — reportes con MIGRACIÓN PARCIAL: migraron sólo sus vistas de lectura al BFF.
// Sin ?action= se sirve el front estático; CON ?action= (edit/update/delete/paymentForm/rg90/
// libro-*/feTable/download-report/etc.) cae al PHP legacy por la regla genérica de abajo.
// En prod, replicar con RewriteCond %{QUERY_STRING} !(^|&)action= en .htaccess.
$bffPartialReports = [
    '/a_report_purchases'    => '/reports/purchases.html',
    '/a_report_transactions' => '/reports/transactions.html',
    '/a_report_giftcards'    => '/reports/giftcards.html',
    '/a_report_schedule'     => '/reports/schedule.html',
    '/a_report_production'   => '/reports/production.html',
];
if (isset($bffPartialReports[$path]) && empty($_GET['action'])) {
    $htmlFile = __DIR__ . $bffPartialReports[$path];
    if (file_exists($htmlFile)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($htmlFile);
        return true;
    }
}

// Regla: URLs sin extension -> .php (incluye API/)
if ($path !== '/' && !pathinfo($path, PATHINFO_EXTENSION)) {
    $phpFile = __DIR__ . $path . '.php';
    if (file_exists($phpFile)) {
        require $phpFile;
        return true;
    }
}

// Default: index.php
if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

// Archivo no encontrado
http_response_code(404);
echo "404 Not Found: $path";
