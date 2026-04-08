<?php

require_once(__DIR__ . '/../includes/cors.php');

//error_log('/n' . json_encode([$_POST, $_GET]) . '/n', 3, 'error_log');

if(!isset($_GET['s']) && !isset($_POST['acquirerId']) && !isset($_GET['shop_process_id'])){
	include_once('../includes/404.inc.php');
	die();
}else if(isset($_POST['acquirerId'])){
	$ese = $_POST['acquirerId'];
}else if(isset($_GET['s'])){
	$ese = $_GET['s'];
}else{
	$ese = $_GET['shop_process_id'];
}

include_once("../libraries/rateLimiter.php");

$rateLimiter  = new RateLimiter($ese);
$limit        = 60;//240       //  number of connections to limit a user per $minutes
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

$memUse['after rate limit'] = memory_get_usage();

include_once('../includes/compression_start.php');
include_once('../includes/db.php');
include_once('../includes/simple.config.php');
include_once('../includes/functions.php');

function enc($str): string { return (string)$str; }

function dec($str): string { return (string)$str; }

function ncmEncode($str){
	$based 		= base64_encode($str);
	$encoded 	= openssl_encrypt($based,"AES-128-ECB",SALT);
	return $encoded;
}

function ncmDecode($str){
	$based 		= openssl_decrypt($str,"AES-128-ECB",SALT);
	$decoded 	= base64_decode($based);
	return $decoded;
}

$baseUrl = '/' . str_replace('.php', '', basename($_SERVER['PHP_SELF']));

$ignore   = array('GLOBALS', '_FILES', '_COOKIE', '_POST', '_GET', '_SERVER', '_ENV', 'argv', 'argc', 'ignore', 'array');
