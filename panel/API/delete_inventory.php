<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type');


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

function editInventoryAPI($data,$companyId){
	global $db;
	$addInv = [];
	$maxLoop 	= 520;
	$i 			= 1;
	$success 	= 0;
	$fail 		= 0;
	foreach($data as $key => $label){

		if($maxLoop<$i){
			dai(jsonError($success.' datos eliminados. Puede enviar hasta '.$maxLoop.' por vez','success'));
			exit();
		}

		$deleteAll = false;

		if($label['itemId'] || $label['sku']){

			if($label['sku']){
				$itemId = getItemId($label['sku'],'itemSKU',$companyId);
			}else{
				$itemId = dec($label['itemId']);
			}

			$idQuery = 'itemId = "'.$db->Prepare($itemId).'"';
		}else{

			if($label['uid']){
				$idQuery = 'inventoryUID = "'.$db->Prepare($label['uid']).'"';
			}else{
				$idQuery = 'inventoryId = "'.$db->Prepare(dec($label['id'])).'"';
			}

		}

		$delete = $db->Execute('DELETE FROM inventory WHERE '.$idQuery.' AND companyId = ?', array(dec($companyId)));

        if($delete === false){
			$fail++;
		}else{
			$success++;
		}

        $i++;
	}
	return jsonError($success.' datos eliminados y '.$fail.' fallidos','success');
}

$data = $_POST['data'];

if(validateBool('data',true,'post')){
	if(validateAPIAccess($data['company_id'],$data['api_key'])){
		dai(createInventory($data['content'],$data['company_id']));
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
		/*$(document).ready(function(){
			console.log('passing data');
			$.post('https://panel.incomepos.com/API/add_inventory',{
									data:{ type: "insert", content: [{
																		"id"		: "inventoryId",
																		"count"		: "5",
																		"cogs"		: "15000"
																	}],
										"company_id":"NLgQ","api_key":"3ba3c41f7a070d20be1782493e94755a562e4cad"
									}
								},function(result){
				$('body div').html(result);
			});
		});
	</script>
</head>
<body>
	<div></div>
</body>
</html>
 */

?>