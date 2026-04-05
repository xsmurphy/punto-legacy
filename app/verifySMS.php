<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

define(COMPANY_ID, '15');
define(SMS_CREDIT, '10');

include_once('libraries/whoops/autoload.php');
include_once('head.php');

list($number,$code)  = explode(',', base64_decode($_GET['p']));

$number = str_replace('+', '', $number);

$msg     = '[ENCOM] Su codigo de verificacion: ' . $code;
sendSMS($number,$msg,false);


?>