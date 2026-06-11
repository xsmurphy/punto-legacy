<?php
/**
 * CORS allowlist para el módulo /app (POS).
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Allowlist: si CORS_ALLOWED_ORIGINS está en env (comma-separated), úsala. Sino,
// fallback al allowlist legacy de encom.app + localhost para no romper local dev.
$envAllowed = $_ENV['CORS_ALLOWED_ORIGINS'] ?? getenv('CORS_ALLOWED_ORIGINS') ?: '';
if ($envAllowed !== '') {
    $allowed_domains = array_filter(array_map('trim', explode(',', $envAllowed)));
} else {
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
}

if (in_array($origin, $allowed_domains)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true'); // requerido para cookies JWT cross-origin
    header('Vary: Origin');
}

// OPTIONS preflight (CORS): el browser pega un OPTIONS antes del POST/PUT/DELETE
// real para chequear permisos. Si el endpoint en cuestión devuelve 405 (porque
// solo declara POST), el preflight falla y el browser cancela la request real.
// Atajamos acá: si es preflight, respondemos 204 con los headers CORS y cortamos
// (no llegamos al endpoint que rechazaría el método).
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 600'); // cache del preflight 10min
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
