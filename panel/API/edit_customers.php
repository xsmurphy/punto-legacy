<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$modules 		= ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

$record 	= [];
$maxLoop 	= 520;
$i 			= 0;
$success 	= 0;
$fail 		= 0;
$failArray 	= [];
$data 		= json_decode(validateHttp('users','post'),true);

print_r($data);
die();

if(validity($data,'array')){

	foreach($data as $id => $value){
		print_r( $value);
		if($i > $maxLoop){
			apiOk(['success' => $success . ' Registros almacenados. Puede enviar hasta ' . $maxLoop . ' por vez']);
		}

		if($value['contactId']){
			$idQuery = 'contactId = ' . $db->Prepare(dec($value['contactId']));
			$idIt 	= $value['contactId'];
		}else if($value['uid']){
			$idQuery = 'contactId = ' . $db->Prepare(dec($value['uid']));
			$idIt 	= $value['uid'];
		}else if($value['ci']){
			$idQuery = 'contactCI = ' . $db->Prepare($value['ci']);
			$idIt 	= $value['ci'];
		}

		if($value['tin']){
			$record['contactTIN'] 		= $value['tin'];
		}

		if($value['ci']){
			$record['contactTIN'] 		= $value['ci'];
		}

		if($value['fiscalName']){
			$record['contactName'] 		= $value['fiscalName'];
		}

		if($value['name']){
			$record['contactSecondName'] = $value['name'];
		}

		if($value['bday']){
			$record['contactBirthDay'] = $value['bday'];
		}

		if($value['note']){
			$record['contactNote'] 		= $value['note'];
		}

		if($value['city']){
			$record['contactCity'] 		= $value['city'];
		}

		if($value['location']){
			$record['contactLocation'] 		= $value['location'];
		}

		if($value['address']){
			$record['contactAddress'] 	= $value['address'];
		}

		if($value['address2']){
			$record['contactAddress2'] 	= $value['address2'];
		}

		if($value['phone']){
			$record['contactPhone'] 	= $value['phone'];
		}

		if($value['phone2']){
			$record['contactPhone2'] 	= $value['phone2'];
		}

		if($value['email']){
			$record['contactEmail'] 	= $value['email'];
		}

		if($value['status']){
			$record['contactStatus'] 		= $value['status'];
		}

		if($value['storeCredit']){
			$record['contactStoreCredit'] 	= $value['storeCredit'];
		}

		if($value['loyalty']){
			$record['contactLoyalty'] 		= $value['loyalty'];
		}

		if($value['loyaltyAmount']){
			$record['contactLoyaltyAmount'] = $value['loyaltyAmount'];
		}

		if($value['lat'] && $value['lng']){
			$record['contactLatLng'] 		= $value['lat'] . ',' . $value['lng'];
		}

		$record['updated_at']      	= TODAY;

		$update = $db->AutoExecute('contact', $record, 'UPDATE', $idQuery.' AND companyId = ' . $db->Prepare(COMPANY_ID) );
		if($update === false){
			$fail++;
			$failArray[] = $idIt;
		}else{
			$success++;
		}

		$i++;
	}
	apiOk(['success'=>$success . ' Registros almacenados y ' . $fail . ' fallidos.','failed'=>$failArray]);
}else{
	apiOk(['error'=>'No se encontraron datos','failed'=>$data], 404);
}



/*
<!DOCTYPE html>
<html>
<head>
<title>Customers</title>
<script type="text/javascript" src="/assets/vendor/js/jquery-3.6.3.min.js"></script>
<script type="text/javascript">
	$(document).ready(function(){
		console.log('passing data');
		$.post('add_customer.php',{
								data:{ type: "insert", content: [{
																"tin"		: "123456",
																"name"		: "Jose Arreola",
																"date"		: "2018-02-01",
																"note"		: "Cliente fiel desde hace 5 años",
																"address"	: "Mca. López 24000",
																"phone"		: "123456",
																"email" 	: "client@gmail.com"
																},
																{
																"tin"		: "567789",
																"name"		: "Arturo Amarilla",
																"date"		: "2018-02-02",
																"note"		: "Cliente fiel desde hace 1 año",
																"address"	: "Brasilia 23456",
																"phone"		: "56775",
																"email" 	: "brrbbr@gmail.com"
																}]
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