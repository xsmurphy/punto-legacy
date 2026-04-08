<?php
include_once("libraries/rateLimiter.php");

$rateLimiter  = new RateLimiter($rateLimiterId);
$limit        = 80;//240       //  number of connections to limit a user per $minutes
$minutes      = 1;        //  number of $minutes to check for.
$seconds      = floor($minutes * 60); //  retry after $minutes in seconds.

try {
	$rateLimiter->limitRequestsInMinutes($limit, $minutes);
} catch (RateExceededException $e) {
	//header(sprintf("Retry-After: %d", $seconds));
	http_response_code(429);
	header('Content-Type: application/json');
	die(json_encode(['error'=>'Rate Limit Exceeded']));
}

include_once("app_version.php");
include_once("includes/db.php");
include_once("includes/simple.config.php");
include_once("libraries/countries.php");
include_once("includes/functions.php");

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