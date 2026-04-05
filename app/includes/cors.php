<?php
/**
 * CORS allowlist para el módulo /app (POS).
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowed_domains = [
    'https://encom.app',
    'https://app.encom.app',
    'https://api.encom.app',
    'https://encom.com.py',
    'https://app.encom.com.py',
    'http://localhost:8000',
    'http://localhost:8001',
    'http://localhost:8002',
];

if (in_array($origin, $allowed_domains)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
