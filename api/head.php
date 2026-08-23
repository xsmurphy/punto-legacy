<?php
use Punto\Api\RateLimit\RateExceededException;
use Punto\Api\RateLimit\RateLimiter;

require_once(__DIR__ . "/lib/RateLimit/RateLimiter.php");

// Rate limit global por IP del cliente. $rateLimiterId lo resuelve bootstrap.php
// vía Punto\Api\Http\ClientIp — NO con REMOTE_ADDR pelado: detrás de Traefik eso
// es la IP del proxy, y meteria a toda la plataforma en un unico contador.
//
// FAIL-OPEN a proposito: si Redis no responde, el request PASA. Este limite es
// anti-abuso, no un control de seguridad; hacerlo fail-closed convertiria una
// caida del cache en una caida total de la API. El fallo queda logeado y
// reportado a GlitchTip desde RedisClient.
$rateLimiter  = new RateLimiter($rateLimiterId, 'http');
$limit        = 80;       //  requests permitidos por ventana
$minutes      = 1;        //  largo de la ventana, en minutos
$seconds      = (int) floor($minutes * 60);

try {
	$rateLimiter->limit($limit, $seconds, RateLimiter::FAIL_OPEN);
} catch (RateExceededException $e) {
	header(sprintf("Retry-After: %d", $seconds));
	http_response_code(429);
	header('Content-Type: application/json');
	die(json_encode(['error'=>'Rate Limit Exceeded']));
}

include_once(__DIR__ . "/app_version.php");
include_once(__DIR__ . "/includes/db.php");
include_once(__DIR__ . "/includes/simple.config.php");
include_once(__DIR__ . "/libraries/countries.php");
include_once(__DIR__ . "/includes/functions.php");

theErrorHandler('json');

$dias   = ["Domingo", "Lunes", "Martes", "Miercoles", "Jueves", "Viernes", "Sábado"];
$meses  = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];

function enc($str): string { return (string)$str; }

function dec($str): string { return (string)$str; }

function ncmEncode($str){
	$based 		= base64_encode($str);
	$encoded 	= openssl_encrypt($based,"AES-128-ECB",SALT);
	$encoded 	= base64_encode($encoded);//vuelvo a base64 para poder usar en urls
	return $encoded;
}

function ncmDecode($str){
	$str 		= base64_decode($str);
	$based 		= openssl_decrypt($str,"AES-128-ECB",SALT);
	$decoded 	= base64_decode($based);
	return $decoded;
}
?>