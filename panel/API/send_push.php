<?php
require '/home/encom/public_html/vendor/Twilio/autoload.php';
use Twilio\Rest\Client;

include_once('api_head.php');
 
$tags 			= [];
$where 			= validateHttp('where','post');
$externalId		= validateHttp('ids','post');
$title 			= iftn(validateHttp('title','post'),false);
$message 		= validateHttp('message','post');
$appLink		= iftn(validateHttp('app_url','post'),false);
$webLink		= iftn(validateHttp('web_url','post'),false);
$filters 		= json_decode( stripslashes( validateHttp('filters','post') ), true );
$edata 			= json_decode( stripslashes( validateHttp('edata','post') ), true );

if(validateHttp('secret','post') == NCM_SECRET && $externalId){

	if($filters){
		foreach ($filters as $i => $value) {
			$key 		= $value['key'];
			$relation 	= iftn($value['rel'],"=");
			$value 		= $value['value'];

			$tags[] = [
							"field" 	=> "tag", 
							"key" 		=> $key, 
							"relation" 	=> $relation, 
							"value" 	=> $value
						];
			
		}
	}

	$appId 		= 'f130b414-ff8a-4765-8f1f-97f7b2e3e1cf';//iD caja
	$appAuth 	= 'YzBmMzQ4NGUtNDdmMC00Y2IwLTgyN2ItMzExNjNjN2Q5YzNk';

	if($where == 'panel'){
		$appId 		= 'cd135ef0-2abc-4a20-a7e4-9783824e33b0';//iD panel
		$appAuth 	= 'OGFjNGRiMTQtMTVjZi00ODY2LTg5NjYtYjdkZGIyMmQ0ODdi';
	}else if($where == 'ecom'){
		$appId 		= '633e28bd-924a-4331-871c-90e297bee62e';//iD panel
		$appAuth 	= 'MGMyYjJiY2UtYmIwOS00NjRhLWE0MzYtMDczNGFkMWIwNWRh';
	}

	if(!is_array($externalId)){
		$externalId = [$externalId];
	}

	/*
	//FILTER EXAMPLE
	[
		[
			"field" 	=> "tags", 
			"key" 		=> "userId", 
			"relation" 	=> "=", 
			"value" 	=> "10"
		],
		[
			"operator" 	=> "OR"
		],
		[
			"field" 	=> "tag", 
			"key" 		=> "user_id", 
			"relation"	=> "=", 
			"value" 	=> "11"
		]
	]
	*/

	$data 	= 	[
	    			'app_id' 					=> $appId,
	    			'include_external_user_ids' => $externalId,
	    			'large_icon' 				=> "https://app.encom.app/images/iconincomesm.png",
	    			'contents' 					=> ["en" => $message],
	    			'headings' 					=> ["en" => $title],
	    			'filters' 					=> $tags,
	    			'web_url'					=> $webLink,
	    			'app_url'					=> $appLink,
	    			'data' 						=> $edata
				];

	$header = 	[
					'Content-Type: application/json; charset=utf-8',
	            	'Authorization: Basic ' . $appAuth
	          	];


	$push = curlContents('https://onesignal.com/api/v1/notifications','POST',json_encode($data),$header);
	$push = json_decode($push,true);

	if($push['errors']){
		//error_log(json_encode($push['errors']), 3, "/error_log");
		jsonDieResult(['error' => $push['errors'], 'sent' => $data], 500);
	}else{
		error_log(json_encode($push), 3, "error_log");
	}

	jsonDieResult(['sent' => $push]);
}else{
	jsonDieResult(['error' => "Missing Push Data"], 500);
}
?>