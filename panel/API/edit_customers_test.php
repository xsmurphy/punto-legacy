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

function editCustomer($data,$companyId){
	global $db;

	setTimeZone($companyId);
	define('TODAY', date('Y-m-d H:i:s'));

	$record 	= [];
	$maxLoop 	= 520;
	$i 			= 1;
	$success 	= 0;
	$fail 		= 0;
	$failArray 	= [];
	foreach($data as $key => $value){
		if($maxLoop<$i){
			dai(jsonError($success.' Registros almacenados. Puede enviar hasta '.$maxLoop.' por vez','success'));
			exit();
		}

		if($value['tin']){
			$idQuery = 'contactTIN = "'.$db->Prepare(($value['tin'])).'"';
			$idIt 	= $value['tin'];
		}else{
			$idQuery = 'contactId = "'.$db->Prepare(dec($value['contactId'])).'"';
			$idIt 	= $value['contactId'];
		}

		$record['contactTIN'] 		= $value['tin'];
		$record['contactName'] 		= $value['name'];
		$record['contactNote'] 		= $value['note'];
		$record['contactAddress'] 	= $value['address'];
		$record['contactPhone'] 	= $value['phone'];
		$record['contactEmail'] 	= $value['email'];
		$record['contactStatus'] 	= $value['status'];
		$record['updated_at']      	= TODAY;

		$update = $db->AutoExecute('contact', $record, 'UPDATE', $idQuery.' AND companyId = '.$db->Prepare(dec($companyId)));
		if($update === false){
			$fail++;
			$failArray[] = $idIt;
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
		dai(editCustomer($data['content'],$data['company_id']));
	}else{
		dai(jsonError('Acceso denegado'));
	}
}else{
	//dai(jsonError('No se recibieron datos'));

	/*$data["data"] = array(
				"type"=>"insert", 
				"content"=>array(
								array(
									"UID"		=> "02928364",
									"name"		=> "Roberto Valencia",
									"date"		=> "2018-02-01",
									"note"		=> "Cliente fiel desde hace 2 años",
									"address"		=> "Mca. López 24000",
									"phone"		=> "123456",
									"email" 		=> "client@gmail.com",
									"status" 		=> 1
								),
								array(
									"tin"			=> "567789",
									"name"		=> "Penelope Bogado",
									"date"		=> "2018-02-02",
									"note"		=> "Cliente fiel desde hace 6 año",
									"address"		=> "Brasilia 23456",
									"phone"		=> "56775",
									"email" 		=> "brrbbr@gmail.com",
									"status" 		=> 0		
								)
		
							)
				);

	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL,"https://panel.incomepos.com/lasca/add_customer");
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

	$server_output = curl_exec ($ch);

	curl_close ($ch);*/
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
			$.post('edit_customers_test.php',{
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