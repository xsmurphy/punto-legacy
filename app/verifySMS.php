<?php
require_once(__DIR__ . '/includes/cors.php');

include_once('head.php');

$debugMode = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';

// En debug mode no enviar SMS
if ($debugMode) {
    http_response_code(200);
    die();
}

list($number, $code) = explode(',', base64_decode($_GET['p']));
$number = str_replace('+', '', $number);
$msg    = '[' . (defined('APP_NAME') ? APP_NAME : 'Punto') . '] Su código de verificación: ' . $code;
sendSMS($number, $msg, false);
?>
