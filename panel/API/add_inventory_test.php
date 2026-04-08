<?php

require_once(__DIR__ . '/../includes/cors.php');


include_once("../libraries/adodb/adodb.inc.php");
$db = ADONewConnection('mysqli');
$db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_905');
include_once('../includes/simple.config.php');
include_once("../includes/functions.php");

function enc($str): string { return (string)$str; }

function dec($str): string { return (string)$str; }

function createInventoryAPI($data,$companyId){
	global $db;

	setTimeZone($companyId);
	define('TODAY', date('Y-m-d H:i:s'));
	define('COMPANY_ID', $companyId);
	
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
	    	$insert 	= false;

			$outlet = 2446;//dec($label['outletId']);

	    	if(validity($label['itemId'])){
		    	$itemId = getItemId(dec($label['itemId']),'itemId',$companyId,true);
		    	$idIt = $label['itemId'];
		    }else{
		    	$itemId = getItemId($label['sku'],'itemSKU',$companyId,true);
		    	$idIt = $label['sku'];
		    }

	        $addInv['inventoryCount']   = $units;
	        $addInv['inventorySource']  = 'api';
	        $addInv['inventoryCOGS']  	= formatNumberToInsertDB(preg_replace('/[^\d-]+/', '', $label['cogs']));
	        $addInv['inventoryDate']    = TODAY;
	        $addInv['companyId']        = $companyId;
	        $addInv['outletId']         = $outlet;
	        $addInv['inventoryUID']     = $label['uId'];
	        $addInv['itemId'] 			= $itemId;

	        if(validity($itemId)){
		        $insert = $db->AutoExecute('inventory', $addInv, 'INSERT'); 
		    }

	        if($insert === false){
				$fail++;
				$failArray[] = $idIt;
			}else{
				$success++;
				updateRowLastUpdate('item','itemId = '.$itemId);
			}

	        $i++;
	    }
	}

	return jsonError($success.' datos almacenados y '.$fail.' fallidos','success',$failArray);
}

$data = $_POST['data'];

if(validateBool('data',true,'post')){
	if(validateAPIAccess($data['company_id'],$data['api_key'])){
		dai(createInventoryAPI($data['content'],dec($data['company_id'])));
	}else{
		dai(jsonError('Acceso denegado'));
	}
}else{
	dai(jsonError('No se recibieron datos'));
}

?>