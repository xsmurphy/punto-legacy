<?php
/**
 * /api/v1/print.php — print de red TCP (ESC/POS via socket)
 *
 * POST { host: string, port: int, bytes: string (base64) }
 * Realms: panel + pos-app
 */

require_once dirname(__DIR__) . '/bootstrap.php';

$ctx = apiAuthTenant(['panel', 'pos-app']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Método no permitido', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) apiError('Body JSON inválido', 400);

$host = trim((string)($body['host'] ?? ''));
$port = (int)($body['port'] ?? 0);
$b64  = (string)($body['bytes'] ?? '');

if ($host === '') apiError('host requerido', 422);
if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-zA-Z0-9._-]+$/', $host))
    apiError('host inválido', 422);

if (filter_var($host, FILTER_VALIDATE_IP)) {
    // Bloquear loopback, link-local y RFC-1918 (anti-SSRF)
    $ip4 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    if ($ip4) {
        $long = ip2long($host);
        $private = [
            ['start' => ip2long('127.0.0.0'),   'end' => ip2long('127.255.255.255')], // loopback
            ['start' => ip2long('10.0.0.0'),    'end' => ip2long('10.255.255.255')],  // RFC-1918
            ['start' => ip2long('172.16.0.0'),  'end' => ip2long('172.31.255.255')],  // RFC-1918
            ['start' => ip2long('192.168.0.0'), 'end' => ip2long('192.168.255.255')], // RFC-1918
            ['start' => ip2long('169.254.0.0'), 'end' => ip2long('169.254.255.255')], // link-local
        ];
        foreach ($private as $range) {
            if ($long >= $range['start'] && $long <= $range['end'])
                apiError('host no permitido', 403);
        }
    } else {
        // IPv6 loopback
        if ($host === '::1' || $host === '0:0:0:0:0:0:0:1')
            apiError('host no permitido', 403);
    }
}

if ($port < 1 || $port > 65535) apiError('port inválido (1-65535)', 422);
if ($b64 === '') apiError('bytes requerido', 422);

$bytes = base64_decode($b64, true);
if ($bytes === false) apiError('bytes no es base64 válido', 422);

$sock = @fsockopen($host, $port, $errno, $errstr, 5);
if (!$sock) {
    apiError("No se pudo conectar a {$host}:{$port} — {$errstr} ({$errno})", 502);
}

fwrite($sock, $bytes);
fclose($sock);

apiOk(['sent' => strlen($bytes)]);
