<?php

require_once(__DIR__ . '/../includes/cors.php');

require_once('../libraries/whoops/autoload.php');
include_once("../libraries/adodb/adodb.inc.php");
$db = ADONewConnection('mysqli');
$db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_905');
include_once('../includes/simple.config.php');
include_once("../libraries/hashid.php");
include_once("../includes/functions.php");

function enc($str){
	$hashids = new Hashids\Hashids(SALT);
	return $hashids->encode($str);
}

//decode ID
function dec($str){
	$hashids = new Hashids\Hashids(SALT);
	$decoded = $hashids->decode($str)[0];
	return (int)$decoded;
}

function editItem($data,$companyId){
	global $db;
	$record 	= [];
	$maxLoop 	= 520;
	$i 			= 1;
	$success 	= 0;
	$fail 		= 0;
	$qUpdate 	= '';
	foreach($data as $key => $value){

		if($maxLoop<$i){
			dai(jsonError($success.' datos eliminados. Puede enviar hasta '.$maxLoop.' por vez','success'));
			exit();
		}

		if($value['sku']){
			$qUpdate = 'itemSKU = "'.$db->Prepare($value['sku']).'"';
		}else{
			$qUpdate = 'itemId = "'.$db->Prepare(dec($value['id'])).'"';
		}

		$delete = $db->Execute('DELETE FROM item WHERE '.$qUpdate.' AND companyId = ?', array(dec($companyId)));

		if($delete === false){
			$fail++;
		}else{
			$success++;
		}

		$i++;
	}

	return jsonError($success.' datos modificados y '.$fail.' fallidos','success');
}

$data = $_POST['data'];

if(validateBool('data',true,'post')){
	if(validateAPIAccess($data['company_id'],$data['api_key'])){
		dai(editItem($data['content'],$data['company_id']));
	}else{
		dai(jsonError('Acceso denegado'));
	}
}else{
	dai(jsonError('No se recibieron datos'));
}

/*
<!DOCTYPE html>
<html>
<head>
	<title>Customers</title>
	<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
	<script type="text/javascript">
		$(document).ready(function(){
			console.log('passing data');
			$.post('delete_items',{
									data:{ content: [{
													"sku"		: "holawMPM" //or id
													}],
										"company_id":"NLgQ","api_key":"3ba3c41f7a070d20be1782493e94755a562e4cad"
									}
								},function(result){
				$('body').html(result);
			});
		});
	</script>
</head>
<body>

</body>
</html>*/
 

?>