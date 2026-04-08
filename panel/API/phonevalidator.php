<?php
require_once __DIR__ . '/../vendor/autoload.php';

if(isset($_GET['phone']) && isset($_GET['country'])){

	$phone 		= preg_replace("/[^0-9]/", "", $_GET['phone']);
	$country 	= trim($_GET['country']);
	$format 	= trim($_GET['format']);
	$out 		= [];
	$phoneUtil 	= \libphonenumber\PhoneNumberUtil::getInstance();
	$parsed 	= $phoneUtil->parse($phone, $country);
	
	$isValid 	= $phoneUtil->isValidNumber($parsed);
	$isMobile 	= $phoneUtil->getNumberType($parsed);

	if($isValid){
		if($format == 'national'){//formato nacional
			$out['phone'] = $phoneUtil->format($parsed, \libphonenumber\PhoneNumberFormat::NATIONAL);
		}else{//imprimo formato internacional
			$out['phone'] = $phoneUtil->format($parsed, \libphonenumber\PhoneNumberFormat::E164);
		}
		$out['type'] 	  = $isMobile; //si es movile envia 1 si es landline envia 0
		$code = 200;
	}else{
		$out['error'] = "Invalid phone";
		$code = 500;
	}

	http_response_code($code);
	header('Content-Type: application/json');
    die(json_encode($out));
}
?>