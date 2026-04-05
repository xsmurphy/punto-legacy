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

function createCustomer($data,$companyId){
	global $db;

	setTimeZone($companyId);
	define('TODAY', date('Y-m-d H:i:s'));

	$record 	= [];
	$maxLoop 	= 1000;
	$i 			= 1;
	$success 	= 0;
	$fail 		= 0;
	$failArray 	= [];
	foreach($data as $key => $value){
		if($maxLoop<$i){
			dai(jsonError($success.' Registros almacenados. Puede enviar hasta '.$maxLoop.' por vez','success'));
			exit();
		}

		$updateTable 				= 'customer';
		$record['contactTIN'] 		= $value['tin'];
		$record['contactUID'] 		= generateUID($i);
		$record['contactName'] 		= $value['name'];
		$record['contactDate'] 		= TODAY;
		$record['contactNote'] 		= $value['note'];
		$record['contactAddress'] 	= $value['address'];
		$record['contactPhone'] 	= $value['phone'];
		$record['contactEmail'] 	= $value['email'];
		$record['contactStatus'] 	= ($value['status']>0)?'1':'0';
		$record['type'] 			= 1;//cleinte
		$record['companyId'] 		= dec($companyId);
		$record['updated_at']      	= TODAY;

		//if(!checkIfExists($email, 'contactEmail', 'contact', false, false)){
			$insert = $db->AutoExecute('contact', $record, 'INSERT');
			if($insert === false){
				$fail++;
				$failArray[] = $value['tin'];
			}else{
				$success++;
			}
		//}

		$i++;
	}
	return jsonError($success.' datos almacenados y '.$fail.' fallidos','success',$failArray);
}

$data = $_POST['data'];

if(validateBool('data',true,'post')){
	if(validateAPIAccess($data['company_id'],$data['api_key'])){
		dai(createCustomer($data['content'],$data['company_id']));
	}else{
		dai(jsonError('Acceso denegado'));
	}
}else{
	dai(jsonError('No se recibieron datos'));
}

?>
<!DOCTYPE html>
<html>
<head>
	<title>Customers</title>
	<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
	<script type="text/javascript">
		$(document).ready(function(){
			console.log('passing data');
			$.post('add_customers_test.php',{
									data:{ "content": [{
														"tin"		: "123456",
														"name"		: "Jose Arreola",
														"date"		: "2018-02-01",
														"note"		: "Cliente fiel desde hace 5 años",
														"address"	: "Mca. López 24000",
														"phone"		: "123456",
														"email" 	: "client@gmail.com",
														"status" 	: 1
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
</html>
 
*/
?>