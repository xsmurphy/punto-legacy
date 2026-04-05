<?php
/**
 * CORS allowlist centralizado.
 * Incluido por panel/includes/config.php y panel/API/api_head.php.
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowed_domains = [
    'https://encom.app',
    'https://panel.encom.app',
    'https://app.encom.app',
    'https://assets.encom.app',
    'https://api.encom.app',
    'https://ecom.encom.app',
    'https://encom.site',
    'https://encom.com.py',
    'https://panel.encom.com.py',
    'https://app.encom.com.py',
    'https://assets.encom.com.py',
    'https://api.encom.com.py',
    'http://localhost:8000',
    'http://localhost:8001',
    'http://localhost:8002',
];

if (in_array($origin, $allowed_domains)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
} else {
    // Origen no permitido: no emitir header (el navegador bloqueará la request)
}

header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
