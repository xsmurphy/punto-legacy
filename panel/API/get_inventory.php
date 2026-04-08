<?php

die();
include_once("../libraries/adodb/adodb.inc.php");

$db = ADONewConnection('mysqli');
$db->NConnect('localhost', 'incomepo_905user', 'a0Hr(Rl~H6]r', 'incomepo_905');
$db->cacheSecs 		= 3600*24;//cache 24 hs
$ADODB_CACHE_DIR 	= '../../../cache/adodb';//desde root panel hasta el cache
$ADODB_COUNTRECS 	= true;

include_once('../includes/simple.config.php');
//include_once("../includes/config.php");
include_once("../includes/functions.php");

function enc($str): string { return (string)$str; }

function dec($str): string { return (string)$str; }

if(!validateAPIAccess($_GET['company_id'],$_GET['api_key'])){
	dai(jsonError('Acceso denegado'));
}

$limitQ 		= '2000';
$companyId 		= dec($_GET['company_id']);//2158

define('COMPANY_ID', $companyId);
define('OUTLET_ID', 0);
define('TODAY', date('Y-m-d H:i:s'));

$startDate 		= iftn($_GET['from'],'2018-02-01 00:00:00');
$endDate 		= iftn($_GET['to'],TODAY);


///Looop de items 
	//loop de outlets - crear ya el array antes del loop de articulos
		//loop de lotes - crear ya el array antes del loop de articulos



//GET ALL OUTLETS ARRAY
$allOutletsArray = array();
$result = $db->cacheExecute("SELECT outletName, outletId FROM outlet WHERE companyId = ?",array(COMPANY_ID));
while (!$result->EOF) {
    $allOutletsArray[$result->fields['outletId']] = array(
														"name"	=>$result->fields['outletName'],
														"id" 	=>$result->fields['outletId']
														);
    $result->MoveNext(); 
}
$result->Close();
//

$result = $db->Execute('SELECT brandId, 
								categoryId, 
								itemName, 
								itemId, 
								itemSKU, 
								itemDiscount, 
								itemPrice, 
								itemDate, 
								itemType,
								inventoryTrigger,
								itemTrackInventory,
								itemIsParent,
								itemImage,
								itemProduction,
								itemCanSale,
								compoundId
						FROM item
						WHERE itemParentId < 1
						AND companyId = '.COMPANY_ID.'
						ORDER BY itemDate ASC'.$limit);

$count 			= $result->RecordCount();
$arrays 		= [];
if($count>0){
	$inventoryArray 	= getAllIndividualInventory();
	while (!$result->EOF) {
		$array 						= [];
		$itemId 					= enc($result->fields['itemId']);
		$inven 						= $inventoryArray[enc($itemId)];
		$imageUrl 					= '';

		$array["id"] 				= $itemId;
		$array["name"] 				= $result->fields['itemName'];
		$array["sku"] 				= $result->fields['itemSKU'];
		$array["price"] 			= $result->fields['itemPrice'];
		$array["discount"] 			= iftn($result->fields['itemDiscount'],0);
		$array["created"] 			= $result->fields['itemDate'];

		if($result->fields['itemImage'] === 'true'){
			$img 					= SYSIMGS_FOLDER.'/'.enc(COMPANY_ID).'_'.$itemId.'.jpg';
			$img 					= ASSETS_URL.'/src.php?src='.$img.'&w=150&h=150';
			$imageUrl 				= $img;
		}

		$array["img_url"] 			= $imageUrl;

		foreach ($allOutletsArray as $key) {
			$array["inventory"][] = array("outlet_id"=>enc($key['id']),"outlet_name"=>$key['name'],"inventory_count"=>sumInventoryInOutlet($inven));
		}

		array_push($arrays, $array);

		$result->MoveNext();
	}
}else{
	$array = array('error'=>'No se encontraron registros');
}

echo json_encode($arrays, JSON_PRETTY_PRINT);

dai();
?>