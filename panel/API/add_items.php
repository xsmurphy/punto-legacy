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

function createItem($data,$companyId){
	global $db;

	setTimeZone($companyId);
	define('TODAY', date('Y-m-d H:i:s'));
	define('COMPANY_ID', $companyId);

	$record 	= [];
	$maxLoop 	= 520;
	$i 			= 1;
	$success 	= 0;
	$fail 		= 0;
	$failArray 	= [];
	foreach($data as $key => $value){
		if($maxLoop<$i){
			dai(jsonError($success.' datos almacenados. Puede enviar hasta '.$maxLoop.' por vez','success'));
			exit();
		}

		$record['itemName'] 		= $value['name'];
		$record['itemDate'] 		= TODAY;
		$record['itemSKU'] 			= $value['sku'];
		$record['itemStatus'] 		= 1;
		$record['itemImage'] 		= 'false';
		$record['itemType']			= 'product';

		$record['itemTrackInventory'] 	= 1;
		$record['inventoryMethod'] 		= 1;

		$record['itemPrice'] 		= formatNumberToInsertDB(preg_replace('/[^\d-]+/', '', $value['price']));
		$record['itemDescription'] 	= $value['description'];

		$record['itemDiscount']		= iftn(formatNumberToInsertDB($value['discount'],true,3),NULL);

		$record['brandId'] 			= getTaxonomyIdOrInsert($value['brand'],'brand',true,$companyId);
		$record['categoryId'] 		= getTaxonomyIdOrInsert($value['category'],'category',true,$companyId);
		$record['taxId']			= getTaxonomyIdOrInsert($value['tax'],'tax',true,$companyId);

		$record['companyId'] 		= $companyId;
		$record['updated_at'] 		= TODAY;

		$insert = $db->AutoExecute('item', $record, 'INSERT');

		if($insert === false){
			$fail++;
			$failArray[] = $value['sku'];
		}else{
			$success++;
		}

		$i++;
	}
	return jsonError($success.' datos almacenados y '.$fail.' fallidos','success',$failArray);
}

$data = $_POST['data'];

if(validateBool('data',true,'post')){
	if(validateAPIAccess($data['company_id'],$data['api_key'])){
		dai(createItem($data['content'],dec($data['company_id'])));
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
			$.post('add_items.php',{
									data:{ type: "insert", content: [{
																	"name"		: "Plastilina Azul",
																	"sku"		: "13241234123",
																	"price"		: "20000",
																	"description" : "Producto coleccionable",
																	"brand"		: "Play-doh",
																	"category"	: "masas"
																	},
																	{
																	"name"		: "Spiderman",
																	"sku"		: "456456423",
																	"price"		: "89000",
																	"description" : "Producto coleccionable 2",
																	"brand"		: "Marvel",
																	"category"	: "muñecos"
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