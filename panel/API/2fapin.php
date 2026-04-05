<?php
//2FA AUTH PARA REGISTRO Y VALIDACION DE NUMEROS DE CELULAR POR SMS

include_once('/home/encom/public_html/panel/includes/db.php');
include_once('/home/encom/public_html/panel/includes/simple.config.php');
include_once("/home/encom/public_html/panel/libraries/hashid.php");

$db->selectDb('phone');

$timeout 	= 240;
$get 		= $db->Prepare($_GET);
$QRMODE 	= ($get['qr'] == 1) ? true : false;
$randNo 	= $QRMODE ? mt_rand(100000000000,999999999999) : mt_rand(1000,9999);

define('CODE', $randNo); //4 or 9 digits code
define('TIME', time()); //actual time in seconds

function jsonDieResult($array,$code=200){
	http_response_code($code);
	header('Content-Type: application/json');
    die(json_encode($array));
}

function createSession(){
	global $db;
	$db->Execute('DELETE FROM codes WHERE phone = ? LIMIT 1',[PHONE]);
	$insert = $db->AutoExecute('codes', ['phone' => PHONE, 'code' => CODE, 'time' => TIME], 'INSERT');
	return $insert;
}

function enc($str){
	$hashids = new Hashids\Hashids(SALT);
	return $hashids->encode($str);
}

//decode ID
function dec($str){
	$str 		= $str . '';
	$hashids 	= new Hashids\Hashids(SALT);
	$decoded 	= $hashids->decode($str)[0];
	return (int)$decoded;
}

if(!$get['phone'] && !$get['qr']){
	jsonDieResult(['error'=>'Not found'],404);
}

define('PHONE', $get['phone']);

$db->Execute('DELETE FROM codes WHERE time < ?',[(TIME - $timeout)]);

if($get['new']){//si solicito un nuevo codigo lo genero
	
	if(PHONE){
		createSession();
		jsonDieResult( [ 'code' => CODE ] );
	}else{
		jsonDieResult(['error'=>'No phone'],404);
	}

}else if($get['checkCompany']){

	$code 			= $db->Prepare($get['code']);
	$result 		= $db->Execute('SELECT company, outlet FROM codes WHERE code = ? AND company IS NOT NULL LIMIT 1',[$code]);

	if($result && $result->RecordCount() > 0){
		$company 	= $result->fields['company'];
		$outlet 	= $result->fields['outlet'];
	}else{
		$company 	= false;
		$outlet 	= false;
	}

	jsonDieResult(['company' => $company, 'outlet' => $outlet]);

}else if($get['scan']){
//'https://api.encom.app/2fapin.php?scan=1&company=' . $company . '&outlet=' . $outlet . '&code=' . $code . '&qr=1'

	$code 		= $db->Prepare($get['code']);
	$company 	= $db->Prepare($get['company']);
	$outlet 	= $db->Prepare($get['outlet']);

	if(!$company || !$outlet){
		jsonDieResult(['success' => false]);
	}

	$update 	= $db->AutoExecute('codes', ['company' => $company, 'outlet' => $outlet], 'UPDATE', 'code = ' . $code . ' LIMIT 1');
	$updateId 	= $db->Insert_ID();

	jsonDieResult(['success' => $update, 'data' => 'code:' . $code . ' comp:' . $company . ' ou:' . $outlet ]);

}else{//si no solicito nuevo verifico el que existe
	//chequeo si la session sigue activa
	$result = $db->Execute('SELECT code FROM codes WHERE phone = ? LIMIT 1',[PHONE]);

	if($result && $result->RecordCount() > 0){
		$code = (int)$result->fields['code'];
	}else{
		createSession();
		$code = CODE;
	}
	jsonDieResult(['code'=>$code]);
}
?>