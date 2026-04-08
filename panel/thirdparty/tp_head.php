<?php
require_once(__DIR__ . '/../includes/cors.php');

include_once('/home/encom/public_html/panel/includes/db.php');
include_once('/home/encom/public_html/panel/includes/simple.config.php');
include_once('/home/encom/public_html/panel/includes/functions.php');

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

if(!validateHttp('s') && !validateHttp('code')){
  dai();
}

define("OUTLETS_COUNT", 0);//para el JS que checks