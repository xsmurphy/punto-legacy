<?php
require_once(__DIR__ . '/../includes/cors.php');

if(empty($_POST)){
	$_POST = json_decode(file_get_contents('php://input'),true);
}

include_once("../includes/db.php");
include_once('../includes/simple.config.php');
include_once("../libraries/hashid.php");
include_once("../includes/config.php");
include_once("../includes/functions.php");
include_once("../libraries/countries.php");

$login_ok 	= false;
$email 		= validateHttp('email','post');
$phone 		= validateHttp('phone','post');
$pass 		= validateHttp('password','post');

if($phone){
	$email = $phone;
}

$result 	= findEmailOrPhoneLogin($email);

if(!$email || !$pass){
	jsonDieResult(['error' => true, 'message' => 'Invalid data'], 403);
}

if($result){
	$check 	= checkForPassword($pass, $result['salt']);

	if($check === $result['contactPassword']){
		$login_ok 	= true;
		$apiKey 	= getAPICreds($result['companyId']);
		$data 		= ['api_key' => $apiKey, 'company_id' => enc($result['companyId']), 'user_id' => enc($result['contactId']) ];
	}
}

if($login_ok){
	jsonDieResult(['error' => false, 'message' => 'Success', 'data' => $data], 200);
}else{
	jsonDieResult(['error' => true, 'message' => 'User not found'], 404);
}


?>