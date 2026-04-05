<?php
require '/home/encom/public_html/vendor/Twilio/autoload.php';
use Twilio\Rest\Client;

include_once('api_head.php');


if( validateHttp('phone','post') && validateHttp('country','post') && validateHttp('msg','post') && validateHttp('secret','post') == NCM_SECRET){

	$COUNTRY 	= validateHttp('country','post');
	$PHONE 		= validateHttp('phone','post');
	$MSG 		= validateHttp('msg','post');
	$MEDIA 		= validateHttp('media','post');
	$CREDIT 	= validateHttp('credit','post');

	$MSG 		= str_replace(['\n','\r','<br>','</br>'], ['','','',''], $MSG);
	$segments 	= iftn(SMSSegmentsCounter($MSG),1);

	$PHONE     = json_decode(getFileContent('https://api.encom.app/phonevalidator.php?phone=' . $PHONE . '&country=' . $COUNTRY . '&format=international'),true);

	if($PHONE['error']){
		jsonDieResult(['error'=>$number['error']],500);
	}

	if($CREDIT > $segments){

		$url = 'https://encom.whatzaby.com/send-message';

		// Datos que quieres enviar en formato JSON
		$data = [
			"celular" => str_replace("+","",$PHONE['phone']),
			"mensaje" => $MSG
		];

		// Convertir los datos a JSON
		$jsonData = json_encode($data);
		// error_log($jsonData);
		// Inicializar cURL
		$ch = curl_init($url);

		// Configurar cURL para una petición POST
		curl_setopt($ch, CURLOPT_POST, true);

		// Configurar los datos POST
		curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);

		// Configurar los headers para que sepa que es JSON
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen($jsonData)
		]);

		// Configurar para recibir la respuesta como string
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		// Ejecutar la petición
		$response = curl_exec($ch);
		// error_log($response);
		// Cerrar la sesión cURL
		curl_close($ch);

		$response = json_decode($response,true);
		
		if(empty($response['status']) || $response['status'] === "error"){

			// Your Account SID and Auth Token from twilio.com/console
			$client = new Client(TWILIO_SID, TWILIO_AUTH_TOKEN);
			
			if($MEDIA){
				$params = [
					'from' 		=> TWILIO_PHONE,
					'body' 		=> $MSG,
					"mediaUrl" 	=> $MEDIA
				];
			}else{
				$params = [
					'from' 		=> TWILIO_PHONE,
					'body' 		=> $MSG
				];
			}
			
			$client->messages->create(
				$PHONE['phone'],
				$params
			);
		}

		//debito la cantidad de segmentos SMS enviados
		$db->Execute('UPDATE company SET companySMSCredit = companySMSCredit - ' . $segments . ' WHERE companyId = ?',[COMPANY_ID]);

		if($CREDIT == 49 || $CREDIT == 39 || $CREDIT == 29 || $CREDIT == 19){
			//notifico al cliente que le quedan pocos SMS
			$ops = [
					"title" 	=> "Te estás quedando sin crédito SMS",
					"message" 	=> "Te quedan solo " . $CREDIT . " mensajes, contactanos y recarga crédito para evitar interrupciones en los envios.",
					"type" 		=> 1,
					"company" 	=> COMPANY_ID
					];
			insertNotifications($ops);
		}else if($CREDIT == 3){
			$ops = [
				"title" 	=> "Te quedaste sin crédito SMS",
				"message" 	=> "Contactanos y recarga saldo para continuar interactuando con tus clientes.",
				"type" 		=> 2,
				"company" 	=> COMPANY_ID
				];
			insertNotifications($ops);
		}

		jsonDieResult(['sent'=>true]);
		
	}else{
		jsonDieResult(['error'=>"No credit"],404);
	}
}else{
	jsonDieResult(['error'=>"Phone, country and message are required"],500);
}
?>