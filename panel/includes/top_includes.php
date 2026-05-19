<?php
// Path absoluto vía __DIR__: cuando top_includes.php se carga desde un script
// que no chdir() a panel/, el path relativo "libraries/rateLimiter.php" falla.
include_once(__DIR__ . '/../libraries/rateLimiter.php');

$_RATER = $_SERVER["HTTP_CF_CONNECTING_IP"] ?? $_SERVER["REMOTE_ADDR"] ?? '127.0.0.1';
if(isset($_SESSION['user']) && $_SESSION['user']['companyId']){
	$_RATER = $_SESSION['user']['companyId'];
}

$rateLimiter  = new RateLimiter( $_RATER );
$limit        = 60;       //  number of connections to limit user to per $minutes
$minutes      = 1;        //  number of $minutes to check for.
$seconds      = floor($minutes * 60); //  retry after $minutes in seconds.

try {
  $rateLimiter->limitRequestsInMinutes($limit, $minutes);
} catch (RateExceededException $e) {
  header("HTTP/1.1 429 Too Many Requests");
  header(sprintf("Retry-After: %d", $seconds));
  $data = 'Rate Limit Exceeded ';
  die(json_encode($data));
}

// __DIR__ === panel/includes/, así que ../ = panel/
include_once(__DIR__ . '/compression_start.php');
include_once(__DIR__ . '/secure.php');
include_once(__DIR__ . '/db.php');
include_once(__DIR__ . '/simple.config.php');
include_once(__DIR__ . '/config.php');
include_once(__DIR__ . '/../languages/' . LANGUAGE . '.php');
include_once(__DIR__ . '/functions.php');
include_once(__DIR__ . '/../libraries/countries.php');
include_once(__DIR__ . '/../libraries/timezone.php');
?>