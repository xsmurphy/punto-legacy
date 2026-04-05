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
	$failArray 	= [];
	foreach($data as $key => $label){
		if($maxLoop<$i){
			dai(jsonError($success.' datos almacenados. Puede enviar hasta '.$maxLoop.' por vez','success'));
			exit();
		}

		$units  = formatNumberToInsertDB($label['count'],true,3);

		if($units>0){
			if($label['uId']){
				$idQuery = 'inventoryUID = "'.$db->Prepare($label['uId']).'"';
				$idIt = $label['uId'];
			}else{
				$idQuery = 'inventoryId = "'.$db->Prepare(dec($label['id'])).'"';
				$idIt = $label['id'];
			}

	        $addInv['inventoryCount']   = $units;
	        $addInv['inventorySource']  = 'api';
	        $addInv['inventoryUID']  	= $label['uId'];
	        $addInv['inventoryCOGS']  	= formatNumberToInsertDB(preg_replace('/[^\d-]+/', '', $label['cogs']));
	        
	        $update = $db->AutoExecute('inventory', $addInv, 'UPDATE', $idQuery.' AND companyId = '.$db->Prepare(dec($companyId)));

	        if($update === false){
				$fail++;
				$failArray[] = $idIt;
			}else{
				$success++;
			}

	        $i++;
	    }
	}
	return jsonError($success.' datos almacenados y '.$fail.' fallidos','success',$failArray);
}

$data = $_POST['data'];

if(validateBool('data',true,'post')){
	if(validateAPIAccess($data['company_id'],$data['api_key'])){
		dai(editInventoryAPI($data['content'],$data['company_id']));
	}else{
		dai(jsonError('Acceso denegado'));
	}
}else{
	dai(jsonError('No se recibieron datos'));
}
//2158

?>
<!DOCTYPE html>
<html>
<head>
	<title>Customers</title>
	<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
	<script type="text/javascript">
		$(document).ready(function(){
			console.log('passing data');
			$.post('https://panel.incomepos.com/API/edit_inventory',{
									data:{ content: [{
														"uId"		: "854304-0999",
														"count"		: "33",
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