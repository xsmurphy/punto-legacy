<?php
if(!$_GET['a'] || $_GET['a'] != NCM_SECRET){
	//die('I died');
}

include_once('/home/encom/public_html/panel/includes/compression_start.php');
include_once('/home/encom/public_html/panel/libraries/whoops/autoload.php');
include_once('/home/encom/public_html/panel/includes/db.php');
include_once('/home/encom/public_html/panel/includes/simple.config.php');
include_once('/home/encom/public_html/panel/libraries/hashid.php');
include_once('/home/encom/public_html/panel/includes/functions.php');

date_default_timezone_set('America/Asuncion');


function theErrorHandler($type=false){
  $whoops = new \Whoops\Run;

  if($_GET['debug']){
    if($type == 'plain'){
      $whoops->pushHandler(new \Whoops\Handler\PlainTextHandler);
      $whoops->register();
    }else if($type == 'json'){
      $whoops->pushHandler(new \Whoops\Handler\JsonResponseHandler);
      $whoops->register();
    }else{
      $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler);
      $whoops->register();
    }
  }else{
  	if($type == 'json'){
      $whoops->pushHandler(new \Whoops\Handler\JsonResponseHandler);
      $whoops->register();
    }else{
	    $whoops->pushHandler(function($exception, $inspector, $run) {
	        include_once('/home/encom/public_html/panel/includes/errorPage.inc.php');
	        return true;
	    });
	    $whoops->register();
	}
  }
}

//encode ID
function enc($str){
  $hashids = new Hashids\Hashids(SALT);
  return $hashids->encode((int)$str);
}
 
//decode ID
function dec($str){
  $hashids = new Hashids\Hashids(SALT);
  return $hashids->decode($str)[0];
}

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

$get = $db->Prepare($_GET);

define('TODAY', date('Y-m-d H:i:s'));
define('TODAY_START', date('Y-m-d 00:00:00'));
define('TODAY_END', date('Y-m-d 23:59:59'));
define('SMS_CREDIT', 0);
define('COUNTRY_CODE', false);