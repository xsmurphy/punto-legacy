<?php

require_once(__DIR__ . '/../includes/cors.php');


include_once("../libraries/adodb/adodb.inc.php");
$db = ADONewConnection('mysqli');
$db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_905');
include_once('../includes/simple.config.php');
include_once("../includes/functions.php");


function enc($str): string { return (string)$str; }

function dec($str): string { return (string)$str; }

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
	<script type="text/javascript" src="/assets/vendor/js/jquery-3.6.3.min.js"></script>
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