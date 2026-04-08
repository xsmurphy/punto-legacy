<?php
include_once('libraries/rateLimiter.php');

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

include_once('includes/compression_start.php');
include_once('includes/secure.php');
include_once('includes/db.php');
include_once('includes/simple.config.php');
include_once('includes/config.php');
include_once('languages/' . LANGUAGE . '.php');
include_once('includes/functions.php');
include_once('libraries/countries.php');
include_once('libraries/timezone.php');
?>