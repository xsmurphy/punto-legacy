<?php
require_once __DIR__ . '/../composer/vendor/autoload.php';

use Mailgun\Mailgun as MailgunClient;
//user var 

function isHttps(){
	$isSecure = false;
	if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
	    $isSecure = true;
	}
	elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
	    $isSecure = true;
	}

	//$REQUEST_PROTOCOL = $isSecure ? 'https' : 'http';

	if(!$isSecure){
		$redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	    header('HTTP/1.1 301 Moved Permanently');
	    header('Location: ' . $redirect);
	    exit();
	}
}


function the_file_exists($filename){
	$file_headers = @get_headers($filename);

	if($file_headers[0] == 'HTTP/1.0 404 Not Found'){
	    return false;
	} else if ($file_headers[0] == 'HTTP/1.0 302 Found' && $file_headers[7] == 'HTTP/1.0 404 Not Found'){
	    return false;
	} else {
	    return true;
	}
}

function getRoleName($id){
	if($id == 1){
		$out = 'Jefe';
	}else if($id == 2){
		$out = 'Administrador';
	}else if($id == 3){
		$out = 'Cajero';
	}else if($id == 4){
		$out = 'Recurso';
	}else if($id == 5){
		$out = 'Cajero Base';
	}else if($id == 6){
		$out = 'Recurso';
	}else if($id == 7){
		$out = 'Admin. Base';
	}

	return $out;
}


function getPaymentMethodName($id,$decode=false){
	global $db;
	if($id == 'cash'){
		$out = 'Efectivo';
	}else if($id == 'pix'){
		$out = 'PIX';
	}else if($id == 'creditcard'){
		$out = 'T. Crédito';
	}else if($id == 'debitcard'){
		$out = 'T. Débito';
	}else if($id == 'check'){
		$out = 'Cheque';
	}else if($id == 'giftcard'){
		$out = 'Gift Card';
	}else if($id == 'inCredit'){
		$out = 'Crédito Interno';
	}else if($id == 'points'){
		$out = 'Loyalty';
	}else if($id == 'QRPayment' || $id == 'VPOS' || $id == 'ePOS' || $id == 'epos' || $id == 'bancardQROnline'){
		$out = 'ePOS';
	}else if($id == 'ePOSCard'){
		$out = 'ePOS Card';
	}else{
		if($decode){
			$id = dec($id);
		}
		$result = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? LIMIT 1',[$id]);

		if($result){
			$out = $result['taxonomyName'];
		}else{
			if(!$decode){
				$id = dec($id);
			}
			$result = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? LIMIT 1',[$id]);
			if($result){
				$out = $result['taxonomyName'];
			}else{
				$out = '';
			}
		}
	}

	return $out;
}

function companyLogo($small = false){
	$compId 	= enc(COMPANY_ID);

    $img 		= SYSIMGS_FOLDER.'/'.$compId.'.jpg';
    $isImg 		= the_file_exists(ASSETS_URL.$img);
    if(!$isImg){
      $img 		= ($small)?'images/transparent.png':'images/add.png';
    }else{
		$img 		= ($small)?ASSETS_URL.'/src.php?src='.$img.'&w=70':ASSETS_URL.'/src.php?src='.$img.'&w=150';
		$img = $img.'&'.rand();
    }
    return $img;
}

function getShortURL($url){
	$creator 	= 'https://public.encom.app/shorturl.php?c=';
	$short 		= file_get_contents($creator . rawurlencode($url));//@file_get_contents($creator . $url);
	if($short && $short != 'false'){
		return $short;
	}else{
		return 'https://encom.app';
	}
}

function getImage($name,$w,$h){
	$compId 	= enc(COMPANY_ID);

    $img 		= '../assets/sysimages/'.$name.'.jpg';
    $isImg 		= the_file_exists($img);
    if(!$isImg){
      $img 		= 'images/transparent.png';
    }else{
      $img 		= 'http://assets.encom.app/src.php?src='.$img.'&w='.$w.'&h='.$h.'&'.rand();
    }
    return $img;
}

function getAllPlans($planId=false){
	global $db;
	$plans = [];
	$result = ncmExecute("SELECT * FROM plans LIMIT 20",[],true,true);

	if($result){
		while (!$result->EOF) {
			$fields = $result->fields;
		    $plans[$fields['id']] = $fields;
		    $result->MoveNext(); 
		}
		$result->Close();
	}
	
	//
	if($planId){
    	return $plans[$planId];
	}else{
		return $plans;
	}
}

function getCategoriesIds($companyId){
	
	$result = ncmExecute("SELECT GROUP_CONCAT(taxonomyId) as ids FROM taxonomy WHERE (taxonomyExtra = 2 OR taxonomyExtra IS NULL) AND taxonomyType = 'category' AND companyId = ?", [$companyId], false, true);

	if($result){
		if($result->fields['ids']){
			return $result->fields['ids'];
		}else{
			return false;
		}
	    $result->Close();
	}else{
		return false;
	}
}

function getItemName($id){
	global $db;
	$obj = ncmExecute("SELECT itemName FROM item WHERE itemId = ?",[$id]);
	return $obj['itemName'];
}

function getItemComsissionTotal($itemId,$count,$total,$isSession=false,$test=false){
	$comission 	= 0;
    $item 		= ncmExecute('SELECT itemComissionPercent, itemComissionType, itemSessions FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[$itemId,COMPANY_ID]);

    if($item){
	    $comissionValue 	= $item['itemComissionPercent'];
	    $comissionType 		= $item['itemComissionType'];
	    $hasSessions 			= $item['itemSessions'];

	    if($hasSessions > 1 && !$isSession){//verifico si tiene sesiones
	    	$comission = 0;
	    }else if(validity($comissionValue)){
	    	if($hasSessions){
		    	$comissionValue = ($comissionValue / $hasSessions);
		    }

	    	if($comissionType > 0){//en GS
		      $comission                  = $comissionValue * $count;
		    }else{//en %
		      $comission                  = ($comissionValue > 0) ? divider(( $comissionValue * $total ),100) : 0;

		      if($test){
				    dai('$comissionPercent: ' . $comissionValue . ' * $total: ' . $total . ' = ' . divider(( $comissionValue * $total ),100));
				  }
		    }
	    }
	}

    return $comission;
}

function getUserComissionTotal($total,$percent){
	if($total > 0 && $percent > 0){
		return divider(( $percent * $total ),100);
	}else{
		return 0;
	}
}

function getTaxValue($id){
	$tax = ncmExecute("SELECT taxonomyName FROM taxonomy WHERE taxonomyType = 'tax' AND taxonomyId = ? LIMIT 1",[$id]);
	return $tax['taxonomyName'] ?? false;
}

function getTableObjectName($id, $table, $customQuery = "", $column = 1, $where = ''){
	global $db, $SQLcompanyId;
	if($customQuery == ""){
		if($table != 'role'){//aqui filtro las tablas en la BD que no se basan en Company IDS
			$obj = $db->Execute("SELECT * FROM ".$table." WHERE ".$table."Id = ".$id." AND ".$SQLcompanyId);
		}else{
			$obj = $db->Execute("SELECT * FROM ".$table." WHERE ".$table."Id = ".$id);
		}
	}else{
		$obj = $db->Execute($customQuery);
	}
	
	if(validity($obj->fields[$column])){
		return $obj->fields[$column];
	}else{
		return 0;
	}
	$obj->Close();
}

function getRealCustomerId($id){
	$l = strlen((string)$id);
	if($l > 11) {
	    return 'contactUID';
	} else {
	    return 'contactId';
	}
}

function manageCustomerLoyalty($type,$amount,$id,$compId=false){
	global $db;

	$compId 	= iftn($compId,COMPANY_ID);
	$amount 	= $db->Prepare($amount);

	if($type == 'used'){
		$db->Execute("UPDATE contact SET contactLoyaltyAmount = contactLoyaltyAmount - " . $amount . ", updated_at = '" . TODAY . "' WHERE contactUID = ?",[$id]);
	}else if($type == 'earned'){
		$contactAble = ncmExecute('SELECT contactLoyalty FROM contact WHERE contactUID = ? LIMIT 1',[$id]);

		if(!empty($contactAble) && ($contactAble['contactLoyalty'] > 0)){

			$loyaltyVal 	= ncmExecute('SELECT loyaltyMin,loyaltyValue FROM module WHERE companyId = ? LIMIT 1',[$compId]);
			$loyalMin 		= $loyaltyVal['loyaltyMin'];
			$loyalVal 		= $loyaltyVal['loyaltyValue'];

			if($amount >= $loyalMin){
				$mult 	= divider($amount,$loyalMin,false,'down');
				$amount = $loyalVal * $mult;
				$db->Execute("UPDATE contact SET contactLoyaltyAmount = contactLoyaltyAmount + " . $amount . ", updated_at = '" . TODAY . "' WHERE contactUID = ?",[$id]);
			}
		}
	}

	updateLastTimeEdit($compId,'customer');
}

function manageCustomerStoreCredit($type,$amount,$id,$compId=false){
	global $db;
	$compId 	= iftn($compId,COMPANY_ID);
	$amount = $db->Prepare($amount);

	if($type == 'used'){
		$db->Execute('UPDATE contact SET contactStoreCredit = contactStoreCredit-'.$amount.', updated_at = "'.TODAY.'" WHERE contactUID = ?',array($id));
	}else if($type == 'earned'){
		//
	}
	updateLastTimeEdit($compId,'customer');
}

function manageGiftCard($amount,$id){
	global $db;

	$amount = floatval($amount);

	if($amount > 0){
		$value 		= ncmExecute('SELECT giftCardSoldValue FROM giftCardSold WHERE (giftCardSoldCode = ? OR timestamp = ?) AND companyId = ? LIMIT 1', [$id, $id, COMPANY_ID] );

		if($amount > $value['giftCardSoldValue']){
			$amount = $value['giftCardSoldValue'];
		}

		$record 	= [
									'giftCardSoldValue' 		=> $value['giftCardSoldValue'] - $amount,
									'giftCardSoldLastUsed'	=> TODAY
								];

		ncmUpdate([ 'records' => $record, 'table' => 'giftCardSold', 'where' => '(giftCardSoldCode = ' . $id . ' OR timestamp = ' . $id . ') AND companyId = ' . COMPANY_ID . ' LIMIT 1' ]);

		//$db->Execute("UPDATE giftCardSold SET giftCardSoldValue = giftCardSoldValue - " . $amount . ", giftCardSoldLastUsed = '" . TODAY . "' WHERE (giftCardSoldCode = ? OR timestamp = ?) AND outletId = ? LIMIT 1", [ $id, $id, OUTLET_ID ] );
	}
}

function insertNewGiftCard($code,$price,$expires,$trsId,$note,$beneficiaryId,$timestamp,$sendDate,$color){
	global $db;
	//precio, vencimiento, status = 1, code, transId, outletId, companyId

	$repety = ncmExecute('SELECT giftCardSoldId FROM giftCardSold WHERE timestamp = ' . $timestamp . ' AND companyId = ' . COMPANY_ID . ' LIMIT 1');

	if(!$repety && $timestamp){
		$record['giftCardSoldValue'] 				= ($price) ? $price : 0;
		$record['giftCardSoldExpires'] 			= $expires;
		$record['giftCardSoldNote'] 				= $note;
		$record['giftCardSoldSendDate']			= $sendDate;
		$record['giftCardSoldBeneficiaryId']= $beneficiaryId;
		$record['giftCardSoldColor'] 				= $color;
		$record['timestamp'] 								= $timestamp;
		$record['transactionId'] 						= $trsId;
		$record['outletId'] 								= OUTLET_ID;
		$record['companyId'] 								= COMPANY_ID;

		if($code != 'none' && $code != 'no' && $code != 'giftcard'){
			$record['giftCardSoldCode'] 	=  $code;
		}

		return $db->AutoExecute('giftCardSold', $record, 'INSERT');
	}
}

function getAllTransactionPayments($id=false,$limit=false){
	global $db;

	$a 				= [];
	$parent		= ($id) ? ' AND transactionParentId IN (' . $id . ')' : '';
	$limits 	= ($limit) ? ' LIMIT ' . $limit : '';
	$result 	= ncmExecute('SELECT * FROM transaction WHERE transactionType = 5' . $parent . $limits, [], false, true);
	
	if($result){
		while (!$result->EOF) {
			$fields = $result->fields;
			
			$a[$fields['transactionParentId']][] = [
																								'id' 				=> enc($fields['transactionId']),
																								'total' 		=> abs($fields['transactionTotal']),
																								'userid' 		=> $fields['userId'],
																								'date' 			=> $fields['transactionDate'],
																								'methods' 	=> $fields['transactionPaymentType'],
																								'receiptNo' => $fields['invoiceNo']
																							];

		    $result->MoveNext(); 
		}
		$result->Close();
	}
	
	return $a;
}

function getAllToPayTransactions($where=''){
	global $db, $SQLcompanyId;
	$a 		= [];
	$sql 	= 	'SELECT SUM(ABS(transactionTotal)) as payed, transactionParentId as id FROM transaction WHERE transactionType in(5,6) AND ' . 
				$SQLcompanyId . 
				$where . 
				' GROUP BY transactionParentId';

	$result = ncmExecute($sql,[],false,true);

	if($result){
		while (!$result->EOF) {
			$fields = $result->fields;
		    $a[$fields['id']] = $fields['payed'];
		    $result->MoveNext(); 
		}
		$result->Close();
	}
	return $a;
}

function getDebtListByTransaction($id,$expireds=false){
	$expired = '';
	if($expireds){
		$expired = "AND transactionDueDate <= '" . TODAY . "'";
	}
	
	$debtList  = ncmExecute('	SELECT transactionId, transactionTotal, transactionDiscount 
								FROM transaction 
								WHERE customerId = ? 
								AND transactionType = 3 
								AND transactionComplete = 0
								' . $expired . '
								ORDER BY transactionDate ASC', [$id], false, true);

	$debtArr 	= [];
	$ids 		= [];
	$sum 		= 0;
	if($debtList){
		while (!$debtList->EOF) {
		  $deb 		= $debtList->fields;
		  $ids[] 	= $deb['transactionId'];
		  $debtList->MoveNext();
		}

		$payed = getAllToPayTransactions(' AND transactionParentId IN(' . implodes(',',$ids) . ')');

		$debtList->MoveFirst();

		while (!$debtList->EOF) {

		  $deb 		= $debtList->fields;
		  $total 	= $deb['transactionTotal'] - $deb['transactionDiscount'];
		  $remains 	= $total - ($payed[$deb['transactionId']] ?? 0);
		  $debtArr[enc($deb['transactionId'])] = $remains;

		  $sum = $sum + $remains;

		  $debtList->MoveNext();
		}
	}

	return $debtArr;
}

function getAllContacts($type=false,$where=''){
	global $db,$compId,$ADODB_CACHE_DIR;
	//GET ALL CUSTOMERS ARRAY
	$a1 = [];
	$a2 = [];

	$compId 		= iftn($compId,COMPANY_ID);
	$plan 			= getAllPlans(PLAN_ID);
	$outletsCount 	= getOutletCount($compId);

	if($type == 0){
		$limit = ' LIMIT ' . $plan['max_users'] * $outletsCount;
	}else if($type == 1){
		$limit = ' LIMIT ' . $plan['max_customers'] * $outletsCount;
	}else{
		$limit = ' LIMIT ' . $plan['max_suppliers'] * $outletsCount;
	}

	
	if($type == 0 || $type > 0){$typeand = ' AND type = ' . $type;}else{$typeand = '';}

	$result = ncmExecute("SELECT * FROM contact WHERE companyId = ?" . $where . $typeand . $limit,[COMPANY_ID],false,true);

	if($result){
		while (!$result->EOF) {
			$values = 	[
							"name"		=>$result->fields['contactName'],
							"sname"		=>$result->fields['contactSecondName'],
							"address"	=>$result->fields['contactAddress'],
							"email"		=>$result->fields['contactEmail'],
							"ruc"		=>$result->fields['contactTIN'],
							"id"		=>$result->fields['contactId'],
							"rid"		=>$result->fields['contactRealId'],
							"uid"		=>$result->fields['contactUID'],
							"phone"		=>$result->fields['contactPhone'],
							"note"		=>$result->fields['contactNote'],
							"city"		=>$result->fields['contactCity'],
							"date"		=>$result->fields['contactDate'],
							"type"		=>$result->fields['type'],
							"role"		=>$result->fields['role'],
							"main"		=>$result->fields['main'],
							"lockpass"	=>$result->fields['lockPass'],
							"outlet"	=>$result->fields['outletId']
						];

		    $a1[$result->fields['contactUID']] 		= $values;
		    $a2[$result->fields['contactId']] 		= $values;
		    $a3[$result->fields['contactRealId']] 	= $values;

		    $result->MoveNext(); 
		}
		$result->Close();
	}


	
	return [$a1,$a2,$a3];
}

function getTheRolName($rolId){
	$role = ncmExecute('SELECT taxonomyName FROM taxonomy WHERE taxonomyType = "role" AND taxonomyExtra = ? LIMIT 1',[$rolId],true);
	return $role['taxonomyName'];
}

function getTheContactField($id,$array,$field='name'){
	//Esta función busca hacer un match entre el ID proveido que puede ser un UID, ID nuevo o ID viejo (AKA RealID), con alguno de los 3 posibles IDs en la DB, (ID, UID, RealID), si encuentra un match devuelve el field especificado
	if(validity($id)){
		$ck = 0;
		$out = '';
		while(1){
			$out = array_key_exists($id, $array[$ck]) && $array[$ck][$id][$field];
			if(validity($out) || $ck == 2){ break;}
			$ck++;
		}
		return $out;
	}else{
		return '';
	}
}

function getCustomerData($id, $type=false){
	return getContactData($id, $type);
}

function getCustomerTransactionAddress($transId,$encode=false){
	$out 	= false;
	$trAddr = ncmExecute('SELECT customerAddressId as id FROM toAddress WHERE transactionId = ? LIMIT 1',[$transId]);
	if($trAddr){
		$address = ncmExecute('SELECT * FROM customerAddress WHERE customerAddressId = ? AND companyId = ? LIMIT 1',[$trAddr['id'], COMPANY_ID]);
		if($address){
			$out = [
						'id' 		=> $encode ? enc($address['customerAddressId']) : $address['customerAddressId'],
						'name' 		=> $address['customerAddressName'],
						'address' 	=> $address['customerAddressText'],
						'location' 	=> $address['customerAddressLocation'],
						'city' 		=> $address['customerAddressCity'],
						'lat' 		=> $address['customerAddressLat'],
						'lng' 		=> $address['customerAddressLng']
					];
		}
	}

	return $out;
}

function getContactData($id, $type=false,$cache=false){
	global $db, $SQLcompanyId;

	$countries = [];

	$where 		= 'contactId = ' . $id;
	if($type == 'uid' || $type == 'contactUID'){
		$where 	= 'contactUID = ' . $id;
	}

	$genders 	= ['Masculino', 'Femenino', 'Otro'];
	
	$result 	= ncmExecute("SELECT * FROM contact WHERE " . $where . " AND companyId = ? LIMIT 1",[COMPANY_ID],$cache);

	if($result){

		if(validity($result['contactName']) || validity($result['contactSecondName'])){
			$name 				= toUTF8($result['contactName']);
			$sname 				= toUTF8($result['contactSecondName']);
			$note 				= toUTF8($result['contactNote']);
			$address 			= false;
			$location 		= false;
			$city 				= false;
			$lat 					= 0;
			$lng 					= 0;

			$address    	= ncmExecute('SELECT * FROM customerAddress WHERE customerId = ? AND companyId = ? AND customerAddressDefault = 1 LIMIT 1',[$id,COMPANY_ID]); 

			if($address){
				$address 		= toUTF8($result['customerAddressText'] ?? "");
				$location 	= toUTF8($result['customerAddressLocation'] ?? "");
				$city 			= toUTF8($result['customerAddressCity'] ?? "");
				$lat 				= $result['customerAddressLat'] ?? "";
				$lng 				= $result['customerAddressLng'] ?? "";
			}

			$age 					= '';
			if($result['contactBirthDay']){
				$age 				= date_diff(date_create($result['contactBirthDay']), date_create('today'))->y;
			}

			if($result['contactCountry']){
				include_once('libraries/countries.php');
			}
			
			return [
							'id'					=> $result['contactId'],
							'uid'					=> $result['contactUID'],
							'name'				=> $name,
							'secondName'	=> $sname,
							'ruc'					=> $result['contactTIN'],
							'phone'				=> $result['contactPhone'],
							'phone2'			=> $result['contactPhone2'],
							'addressId'		=> enc($result['customerAddressId'] ?? ""),
							'address'			=> $address,
							'location'		=> $location,
							'city' 				=> $city,
							'lat' 				=> $lat,
							'lng' 				=> $lng,
							'email'				=> $result['contactEmail'],
							'note'				=> $note,
							'rol' 				=> $result['role'],
							'bDay' 				=> $result['contactBirthDay'],
							'age' 				=> $age,
							'country'			=> $result['contactCountry'],
							'countryName' => $countries[$result['contactCountry'] ?? ""]['name'] ?? "",
							'gender'			=> $genders[$result['contactGender'] ?? ""] ?? "",
							'ci'					=> $result['contactCI'],
							'since'				=> $result['contactDate']
						];

		}else{

			return [
							'id'					=> $result['contactId'],
							'uid'					=> $result['contactUID'],
							'name'				=> 'Sin Nombre',
							'secondName'	=> 'Sin Nombre',
							'ruc'					=> '',
							'phone'				=> '',
							'address'			=> '',
							'lat'					=> '',
							'lng'					=> '',
							'city' 				=> '',
							'country'			=> '',
							'location'		=> '',
							'email'				=> '',
							'note'				=> '',
							'rol' 				=> $result['role'],
							'bDay' 				=> '',
							'age' 				=> '',
							'gender'			=> '',
							'ci'					=> '',
							'since'				=> ''
						];

		}

	}else{

		return false;

	}
}

function getCustomerName($data,$part=false){
	$name   = $data['secondName'];
    if(!validity($name) || $name == 'Sin Nombre' || counts($name) < 2){
      $name = $data['name'];
    }

    if($part){
    	$part 	= ($part == 'first') ? 0 : 1;
    	$name 	= explodes(' ',$name,$part);    	
    }

    return $name;
}

function getContactName($name,$type=false){
	$out 	= '';
	$name 	= rtrim($name);

	if($type == 'first'){
		$out 	= explode(' ',$name)[0];
	}else if($type == 'last'){
		$names 	= explode(' ',$name);
		$out 	= array_pop($names);
	}else if($type == 'short'){
		$names 	= explode(' ',$name);

		foreach($names as $index => $value){
			if($index > 0){
				if($index == (count($names) - 1)){
					$out .= $names[$index][0] . '.';
				}else{
					$out .= $names[$index] . ' ';
				}
			}else{
				$out = $names[$index] . ' ';
			}
		}
	}

    return $out;
}

function getContactCreditLine($uid,$creditLine){
	if(empty($uid)){
		return 0;
	}
	$totalC  = ncmExecute('SELECT SUM(transactionTotal) as total, SUM(transactionDiscount) as discount, GROUP_CONCAT(transactionId) as ids 
	                        FROM transaction 
	                        WHERE customerId = ? 
	                        AND transactionType = 3
	                        AND transactionComplete = 0', 
	                        [$uid], 60
	                     );

	if(empty($totalC) || empty($totalC['ids'])){
		return $creditLine;
	}

  	$totalRetrns  = ncmExecute(' SELECT SUM(transactionTotal) as total 
                          FROM transaction 
                          WHERE customerId = ? 
                          AND transactionType = 6
                          AND transactionParentId IN(' . $totalC["ids"] . ')
                          ', [$uid]
                        );

  	$payedC  = ncmExecute(' SELECT SUM(transactionTotal) as payed 
                          FROM transaction 
                          WHERE transactionType = 5
                          AND transactionParentId IN(' . $totalC["ids"] . ')
                          AND customerId = ?', [$uid]
                        );

	$totalComprado  = $totalC['total'] - $totalC['discount'];
	$totalPagado    = $payedC['payed'] + abs($totalRetrns['total'] ?? 0);

	if($totalPagado >= $totalComprado){
		$totalDeuda     = 0;
		$debtList       = '';
	}else{
		$totalDeuda     = $totalComprado - $totalPagado;  
	}

	return $creditLine - $totalDeuda;

}

function checkCompanyStatus($id){
	$result = ncmExecute('SELECT companyStatus FROM company WHERE companyId = ? LIMIT 1',[$id]);

	if($result && $result['companyStatus'] == 'Active'){
		return true;
	}else{
		return false;
	}
}

function updateLastTimeEdit($id,$table=false){
	global $db;

	$date 					= TODAY;
	$SQLcompanyId 	= 'companyId = ' . $id;
	$record 				= [];

	if($table == 'customer'){
		$record['customersLastUpdate'] 	= $date;
	}else if($table == 'item'){
		$record['itemsLastUpdate'] 		= $date;
	}else if($table == 'calendar'){
		$record['calendarLastUpdate']   = $date;
	}else if($table == 'order'){
		$record['orderLastUpdate'] 		= $date;
	}else{
		
	}	
	$record['companyLastUpdate'] 		= $date;
	$db->AutoExecute('company', $record, 'UPDATE', $SQLcompanyId);
	return $date;
}

function updateRowLastUpdate($table,$where){
	global $db;
	$record 				= array();
	$record['updated_at'] 	= TODAY;
	$db->AutoExecute($table, $record, 'UPDATE', $where);
}

function updateDocNumber($field){
	global $db;
	if(!$field){ return false; }

	$update 	= $db->Execute('UPDATE register SET ' . $field . ' = ' . $field . ' + 1 WHERE registerId = ? AND companyId = ?',[REGISTER_ID,COMPANY_ID]);
	return $update;
}

function getSaleType($type){

	switch ($type) {
	    case '0':
	        $saleType 	= "cashsale";
	        $docType 	= "registerInvoiceNumber";
	        break;
	    case '1':
	        $saleType 	= "";
	        $docType 	= "";
	        break;
	    case '2':
	       	$saleType 	= "saved";
	        $docType 	= "";
	        break;
	    case '3':
	        $saleType 	= "creditsale";
	        $docType 	= "registerInvoiceNumber";
	        break;
	    case '4':
	        $saleType 	= "";
	        $docType 	= "";
	        break;
	    case '5':
	        $saleType 	= "creditpayment";
	        $docType 	= "registerTicketNumber";
	        break;
	    case '6':
	        $saleType 	= "return";
	        $docType 	= "registerReturnNumber";
	        break;
	    case '7':
	        $saleType 	= "void";
	        $docType 	= "";
	        break;
	    case '8':
	        $saleType 	= "recursive";
	        $docType 	= "";
	        break;
	    case '9':
	        $saleType 	= "quote";
	        $docType 	= "registerQuoteNumber";
	        break;
	    case '10':
	        $saleType 	= "delivery";
	        $docType 	= "";
	        break;
	    case '11':
	        $saleType 	= "opentable";
	        $docType 	= "";
	        break;
	    case '12':
	        $saleType 	= "order";
	        $docType 	= "registerPedidoNumber";
	        break;
	    case '13':
	        $saleType 	= "schedule";
	        $docType 	= "registerScheduleNumber";
	        break;
	    case '14':
	        $saleType 	= "";
	        $docType 	= "";
	        break;
	}

	return array($saleType,$docType);
}

function getNextDocNumber($number,$in,$company,$register){
	global $db;
	$lastUsed  = getValue('transaction','invoiceNo','WHERE companyId = ' . $company . ' AND registerId = ' . $register . ' AND (invoiceNo IS NOT NULL AND invoiceNo > 0) AND transactionType IN(' . $in . ') ORDER BY transactionDate DESC LIMIT 1');

    if($lastUsed > $number){
    	return $lastUsed;
    }else{
    	return $number;
    }
}

function directProductionLog($jdata){
	return;	
}

function activityLog($type,$jdata,$outletId=OUTLET_ID){
	return;
}

function getValue($table, $field, $where = '', $returnType = 'number', $cache = false){
	global $db;

	$limit = ' LIMIT 1';

	if(strpos($where, 'LIMIT') !== false){ // si where contine limit le saco
		$limit = '';
	}

	$result 	= ncmExecute("SELECT " . $field . " FROM " . $table . " " . $where . $limit, [], $cache);
	
	if($result){
		return $result[$field];
	}else{
		if($returnType == 'number'){
			return 0;
		}else if($returnType == 'boolean'){
			return false;
		}else if($returnType == 'string'){
			return '';
		}
	}
}

function getTaxonomyArray($type,$company,$compZero = false){
	global $db, $SQLcompanyId;

	$compZero 	= ($compZero) ? 'companyId = 1 OR ' : '';

	if($company){
		$company = $compZero . 'companyId = ' . $company;
	}else{
		$company = $compZero . $SQLcompanyId;
	}

	$result = ncmExecute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND taxonomyExtra != 'internal' AND (" . $company . ") LIMIT 500",[$type],false,true);
	
	$out 	= [];

	if($result){
		while (!$result->EOF) {//ID sin codificar a proposito
	        $out[] = ['tagid' => $result->fields['taxonomyId'],'tagname' => $result->fields['taxonomyName']];

	        $result->MoveNext(); 
	    }
       $result->Close();
	}

    return json_encode($out);
}

function getTagsDefaults($idsOnly=false){

	$result 	= ncmExecute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = 'tag' AND companyId = 1 AND taxonomyExtra != 'internal' LIMIT 20",[],false,true);
	
	$out = [];

	if($result){
		while (!$result->EOF) {
			if($idsOnly){
				$out[] = $result->fields['taxonomyId'];
			}else{
		        $out[] = ["tagid" => $result->fields['taxonomyId'],"tagname" => $result->fields['taxonomyName']];
		    }

	        $result->MoveNext(); 
	    }
	}

    return $out;
}

function getCustomTemplates($company){
	global $db;

	$result = ncmExecute("SELECT taxonomyId,taxonomyName,taxonomyExtra FROM taxonomy WHERE taxonomyType = 'printTemplate' AND (companyId = ".$company." OR companyId = 1)",[],false,true);
	
	$out = [];

	while (!$result->EOF) {
        $out[] = array('id'=>enc($result->fields['taxonomyId']),'name'=>$result->fields['taxonomyName'],'data'=>$result->fields['taxonomyExtra']);

        $result->MoveNext(); 
    }
    $result->Close();

    $out = json_encode($out);

    return $out;    
}

function getTaxonomyName($id,$numeric=false,$company=false){
	global $db, $SQLcompanyId;

	if(!$company){
		$company = $SQLcompanyId;
	}
	
	$result = $db->Execute("SELECT taxonomyName FROM taxonomy WHERE taxonomyId = ? AND ".$company,array($id));

	if(validity($result->fields['taxonomyName'])){
		return $result->fields['taxonomyName'];
	}else{
		if($numeric){
			return 0;
		}else{
			return 'None';
		}
	}
	
	$result->Close();
}

function getAllTaxonomyNames($companyId,$numeric=false){
	
	$a 		= [];
	$result = ncmExecute("SELECT taxonomyId, taxonomyName FROM taxonomy WHERE companyId = ?",[$companyId],false,true);

	if($result){
		while (!$result->EOF) {
		    $a[$result->fields['taxonomyId']] = [
													"name" => $result->fields['taxonomyName']
												];
		    $result->MoveNext(); 
		}
		$result->Close();
	}
	
	return $a;
}

function printOutTags($tags,$bg = 'bg-white'){
	$tagout = '';
	if($tags){
		foreach($tags as $tag){
	    	$tagout .= '<span class="label '.$bg.'">'.getTaxonomyName($tag).'</span> ';
	    }
	}
	return $tagout;
}

function getRegisterName($id){
	global $db;

	$obj = $db->Execute("SELECT registerName FROM register WHERE registerId = ?",array($id));
	$name = $obj->fields['registerName'];
	if(validity($name)){
		return $name;
	}else{
		return 'None';
	}
	$obj->Close();
}

function getCurrentOutletName($id=false){
	global $db;

	$id = ($id) ? $id : OUTLET_ID;

	$obj = $db->Execute("SELECT outletName FROM outlet WHERE outletId = ".$id);
	
	if(validity($obj->fields['outletName'])){
		return $obj->fields['outletName'];
	}else{
		return 'None';
	}
	$obj->Close();
}

function getAllOutletData($id=false){
	global $db;

	$id 		= ($id)?$id:OUTLET_ID;
	$result 	= $db->Execute("SELECT * FROM outlet WHERE outletId = ?",[$id]);
	$data 		= [];

	if(validateResultFromDB($result)){
		while (!$result->EOF) {
			$fields = $result->fields;
			$data[$fields['outletId']] = [];
			foreach($fields as $key => $value){
				if(strpos($key, "outlet") !== false){
					$key = str_replace("outlet","",$key);
					$key = lcfirst($key);
					$data[$fields['outletId']][str_replace("outlet","",$key)] = $value;
				}
			}
			$result->MoveNext();
		}
	}
	$result->Close();
	
	if($id){
    	return $data[$id];
	}else{
		return $data;
	}
}

function getOutletCount($compId){
	global $db;

	$obj = ncmExecute("SELECT COUNT(outletId) as count FROM outlet WHERE outletStatus = 1 AND companyId = ? LIMIT 100",[$compId]);

	if($obj){
		return $obj['count'];
	}else{
		return 1;
	}
}

function countTotalInDrawer($regId,$whattype=false){
	global $db;
	$regId 	= iftn($regId,REGISTER_ID);
	$pymnt = $db->Execute("SELECT registerDrawerData, registerIsOpen FROM register WHERE registerId = ? AND registerIsOpen = 1 LIMIT 1",array($regId));

    if($pymnt->fields['registerIsOpen'] > 0){
    
      $data = json_decode($pymnt->fields['registerDrawerData'],true);

      if($data){
        $total = 0;
        foreach($data as $val){
          $type     = $val['type'];
          $price    = $val['price'];

          if($whattype){ //si especifico un tipo y este es expense resto todo
          	if($whattype == 'expense'){
	            $total -= abs($price);
	        }else if($whattype == $type){//si no sumo todo lo de este tipo especificado
	            $total += $price;
	        }
          }else{//si no especifique nada sumo todo y resto los gastos como corresponde
          	if($type == 'expense'){
	            $total -= abs($price);
	        }else{
	            $total += $price;
	        }
          }
        }
        return $total;
      }
    }
    
    return false;
}

function updatePaymentMethodsInRegisterDB($new,$regId,$rest=false){
	global $db;
	$regId 	= iftn($regId,REGISTER_ID);
	//obtengo la caja actual con sus pagos
	$oldData = $db->Execute("SELECT registerDrawerData, registerIsOpen FROM register WHERE registerId = ? LIMIT 1",array($regId));

	if($oldData->RecordCount() > 0){//
		if(validity($oldData->fields['registerIsOpen']) && validity($oldData->fields['registerDrawerData'])){
			$old 	= json_decode($oldData->fields['registerDrawerData'],true);
			if(validity($old,'array') && validity($new,'array')){
				$upOld 	= updatePaymentMethodsInRegister($new,$old,$rest);
				if(validity($upOld)){
					$record['registerDrawerData'] = json_encode($upOld);
			    	$up 	= $db->AutoExecute('register', $record, 'UPDATE','registerId = '.$db->Prepare($regId));
			    }
		    }
	    }
	}
}

function updatePaymentMethodsInRegister($new,$old,$rest=false){
	$nuPrice = 0;
	$nuTotal = 0;
	foreach($new as $nu){
		$nuPrice 	= iftn(abs($nu['price']),0); // lo que se ingresa en el visor d epago
		$nuTotal 	= iftn(abs($nu['total']),0); // saldo a pagar

		//ej total a pagar es 20000 yo pongo 100000 total: 20000 price:100000 pero si es a pagar 30000 pongo ef: 15000 y cred 15000
		//va a seer total: 30000 price: 15000 y el segunod method va a ser pruice:15000 total:15000
		$nuType 	= $nu['type'];

		if(($nuPrice > $nuTotal) && !$rest){
			$nu['price'] 	= $nuTotal;
			$nuPrice 		= abs($nu['price']);
		}

	    $match = false;
	    foreach($old as $index => $ol){
	        if($nuType === $ol['type']){
	        	if($rest){
	        		$old[$index]['price'] = $ol['price']-$nuPrice;
	        	}else{
	            	$old[$index]['price'] = $ol['price']+$nuPrice;
	        	}
	            $match = true;
	        }
	    }

	    if(!$match){
	    	unset($nu['extra']);
	    	unset($nu['total']);
	        array_push($old,$nu);
	    }
	}

	return $old;
}

function selectInputTaxonomy($type,$match,$multi=false){
	global $db, $SQLcompanyId;
	$result = $db->Execute("SELECT taxonomyName,taxonomyId FROM taxonomy WHERE taxonomyType = ? AND ".$SQLcompanyId." ORDER BY taxonomyName ASC",array($type));
	if($multi){
		$type = $type.'[]';
	}
?>
	<select name="<?=$type?>" class="form-control">
		<?php while (!$result->EOF) {?>
			<?php
            $selected = '';
            if($result->fields['taxonomyId'] == $match){
                $selected = 'selected';
            }
            ?>
            <option value="<?=enc($result->fields['taxonomyId']);?>" <?=$selected?>><?=$result->fields['taxonomyName'];?></option>
            <?php 
            $result->MoveNext(); 
        }
        $result->Close();
        ?>
    </select>
<?php
}

function niceDate($date, $hours = false, $noDay = false, $year = true, $weekDay = false){
	global $dias,$meses;
	if($date == '0000-00-00 00:00:00' || !validity($date)){
		return 'Sin fecha';
	}

	$y 			= ($year) ? ', ' . date('Y',strtotime($date)) : '';
	$m			= date('m',strtotime($date));
	$d			= ($noDay) ? '' : date('d',strtotime($date)) . ' de ';
	$h			= date('H',strtotime($date));
	$mi			= date('i',strtotime($date));	
	$s			= date('s',strtotime($date));
	$w 			= date('w',strtotime($date));
	$l			= ($weekDay) ? translateNamesOfWeek(date('l',strtotime($date))) . ' ' : '';

	$hoursto 	= ($hours == true) ? ' a las ' . $h . ':' . $mi : '';

	return $l . $d . $meses[$m-1] . $y . $hoursto;
}

function niceDate2($datetime, $type = 'normal') {

	if($datetime == '0000-00-00 00:00:00' || !validity($datetime)){
		return 'Sin fecha';
	}

    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    $plural = '';

    $weekends = floor($diff->d / 7);
    $diff->d -= $weekends * 7;

    if($type == 'small'){
    	$string = array(
	        'y' => 'año',
	        'm' => 'mes',
	        'w' => 'sem',
	        'd' => 'día',
	        'h' => 'h',
	        'i' => 'min',
	        's' => 'seg',
	    );
    }else{
    	$string = array(
	        'y' => 'año',
	        'm' => 'mes',
	        'w' => 'semana',
	        'd' => 'día',
	        'h' => 'hora',
	        'i' => 'minutos',
	        's' => 'segundos',
	    );
    }

    foreach ($string as $k => &$v) {
        if (!empty($diff->$k)) {
        	if($type != 'small'){
        		$plural = ($k == 'm')?'es':'s';
        	}
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? $plural : '');
        } else {
            unset($string[$k]);
        }
    }

    if($type == 'normal'){
    	$string = array_slice($string, 0, 1);
    	return $string ? 'Hace '.implodes(', ', $string) : 'Ahora';
    }else if($type == 'full'){
    	return $string ? 'Hace '.implodes(', ', $string) : 'Ahora';
    }else if($type == 'small'){
    	$string = array_slice($string, 0, 1);
    	return $string ? implodes(', ', $string) : 'Ahora';
    }

}

function dateStartEndTime($startDate,$endDate){
	$date 	= explodes(' ',$startDate,0);
	$start 	= explodes(' ',$startDate,1);
	$end 	= explodes(' ',$endDate,1);

	$start 	= explodes(':',$start,0) . ':' . explodes(':',$start,1);
	$end 	= explodes(':',$end,0) . ':' . explodes(':',$end,1);

	return array($date,$start,$end);
}

function getNextDatePeriod($frecuency,$times,$date = TODAY,$format = 'Y-m-d 00:00:00'){
	if($frecuency == 'daily'){
		$strtotime = strtotime($date . ' +' . $times . ' day');
	}else if($frecuency == 'weekly'){
		$strtotime = strtotime($date . ' +' . $times . ' week');
	}else if($frecuency == 'fortnight'){
		//$strtotime = strtotime($date . ' +' . $times . ' week');
	}else if($frecuency == 'monthly'){
		$strtotime = strtotime($date . ' +' . $times . ' month');
	}else if($frecuency == 'quarterly'){
		$strtotime = strtotime($date . ' +' . ($times * 3) . ' month');
	}else if($frecuency == 'yearly'){
		$strtotime = strtotime($date . ' +' . $times . ' year');
	}else{
		$strtotime = strtotime($date);
	}

	return date($format,$strtotime);
}

function translateNamesOfWeek($word,$lang='es'){
	$src 	= ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
	$es 	= ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
	$change = $es;

	if($lang == 'br'){
		// $change = $br;
	}

	$out = str_replace($src, $es, $word);

	return $out;
}

function buildCalendarTop($options,$test=false){

	$date 		= $options['date'];
	$title 		= $options['title'];

	//MODES
	$modeBtnAr 	= [
					'month'		=>['mode'=>'calendar_month','name'=>'Mes'],
					'week'		=>['mode'=>'calendar_week','name'=>'Semana'],
					'resource'	=>['mode'=> ($test?'calendar_resources2':'calendar_resources'),'name'=>'Recursos'],
					'agenda'	=>['mode'=>'calendar_agenda','name'=>'Agenda']
					];

	$modeBtn 	= '';
	foreach($modeBtnAr as $type => $ops){
		$bg = 'no-bg';
		if($options['current'] == $type){
			$bg = 'bg-white rounded';
		}
		$modeBtn .= '<a href="#" class="btn font-bold clickeable ' . $bg . '" data-type="calendarView" data-mode="' . $ops['mode'] . '">' . 
						$ops['name'] . 
					'</a>';
	}

	$modes 	= '<div class="col-sm-6 no-padder text-left hidden-xs">' .
               	$modeBtn .
              '</div>';
    //MODES END

    //NEXTS
    //tyoe mode
    $typemode 	= '';
    $time 		= '';
    if($options['current'] == 'month'){
    	$typemode 	= 'calendar_month';
    	$time 		= 'month';
    	$title 		= niceDate($date,false,true);
    }else if($options['current'] == 'week'){
    	$typemode 	= 'calendar_week';
    	$time 		= 'week';
    	$title 		= niceDate($date,false,true);
    }else if($options['current'] == 'agenda'){
    	$typemode 	= 'calendar_agenda';
    	$time 		= 'month';
    	$title 		= niceDate($date);
    }else if($options['current'] == 'resource'){
    	$typemode 	= ($test?'calendar_resources2':'calendar_resources');
    	$time 		= 'day';
    	$title 		= niceDate($date,false,false,false,true);
    }

	$dateBtnAr = ['prev'=>[
								'date'=>getNextDatePeriod(0,0,$date . ' -1 ' . $time,'Y-m-d'),
								'icon'=>'&#xe5c4;',
								'mode'=>$typemode
							],
					'next'=>[
								'date'=>getNextDatePeriod(0,0,$date . ' +1 ' . $time,'Y-m-d'),
								'icon'=>'&#xe5c8;',
								'mode'=>$typemode
							]
				];

	$dateBtn = '';
	foreach($dateBtnAr as $type => $ops){
		$dateBtn .= '<a href="#" class="btn btn-default btn-rounded clickeable" data-type="calendarDateBtn"  data-mode="' . $ops['mode'] . '" data-date="' . $ops['date'] . '">' .
              		'	<i class="material-icons md-18">' . $ops['icon'] . '</i>' .
              		'</a>';
	}

	$dateNBtn = 	'<div class="col-sm-6 col-xs-12 no-padder text-right">' .
					' 	<a href="#" class="btn clickeable btn-rounded bg pull-left font-bold" id="calendarCurrentDate" data-type="calendarDatePicker" data-date="' . getNextDatePeriod(0,0,$date,'Y-m-d') . '" data-mode="' . $ops['mode'] . '">' .
							$title .
					'	</a>' .
					'	<a href="#" class="btn clickeable no-bg toggleCalendarBtn hidden-xs" data-type="calendarFullScreen" data-toggle="tooltip" data-placement="bottom" title="Expandir calendario"><i class="material-icons">fullscreen</i></a>' .
					'	<a href="#" class="btn clickeable no-bg hidden toggleCalendarBtn hidden-xs" data-type="calendarFullScreen" data-toggle="tooltip" data-placement="bottom" title="Contraer calendario"><i class="material-icons">fullscreen_exit</i></a>' .
					'	<a href="#" class="btn clickeable no-bg hidden-xs" data-type="calendarDateBtn" data-toggle="tooltip" data-placement="bottom" title="Hoy" data-mode="' . $ops['mode'] . '" data-date="' . getNextDatePeriod(0,0,TODAY,'Y-m-d') . '"><i class="material-icons">today</i></a>' .
	               	'	<div class="btn-group">' .
	               			$dateBtn .
	               	'	</div>' .
	               	'</div>';
    //NEXTS END

	

	$out =  '<div class="wrapper-xs col-xs-12">' .
			'	<div class="rounded dker no-border b-b wrapper-xs col-xs-12">' .
					$modes .
					$dateNBtn .
			'	</div>' .
			'</div>';

	return $out;
}

function calendarBuilder($options){
	$openH 			= (int)iftn($options['startHour'],7);
	$closeH 		= (int)iftn($options['endHour'],23);
	$maxCols		= iftn($options['maxCols'],7);
	$columnTitle 	= iftn($options['columnTitle'],[]);
	$columnId 		= iftn($options['columnId'],[]);
	$columnPosition = iftn($options['columnPosition'],[]);
	$columnDate 	= iftn($options['columnDate'],[]);
	$startDate 		= iftn($options['startDate'],TODAY);
	$endDate 		= iftn($options['endDate'],TODAY);
	$colTitleWidth 	= iftn($options['columnTitleWidth'],'100px');
	$columnSubtitle = iftn($options['columnSubTitle'],[]);
	$columnData 	= iftn($options['columnData'],[]);
	$trans 			= iftn($options['transaction'],[]);
	$table 			= '';
	$bgColor 		= iftn($options['columnColor'],[]);
	$ignoreBlocked 	= $options['ignoreBlocked'];

	$line   		= 1;
    $hour			= $openH;

    while($hour <= $closeH){
    	$table .= '<tr style="background-image:url(https://app.encom.app/images/calendar_dash_hours.png);background-repeat: repeat-x;">';

		if($line == 1){
			$table .= '<th class="bg-light dk text-center ncmCalendarClock"> <i class="material-icons text-primary">&#xe192;</i> </th>';
		}else{
			$table .= '<th class="bg-light dk text-center ncmCalendarTime" style="padding-top:0!important;width:50px;">' . $hour . ':00</th>';
		}

		$col = 0;
      	while($col < $maxCols){
      		$ohour 		= str_pad($hour, 2, '0', STR_PAD_LEFT);
      		$dateHour 	= $columnDate[$col] . ' ' . $ohour;
      		$resourceName = getContactName($columnTitle[$col],'short');

      		if($line == 1){
      			$bTopColor = '';
      			if($bgColor[$col]){
      				$bTopColor = 'border-top:1px solid ' . $bgColor[$col] . ';';
      			}

	        	$table .=   '<th class="bg-light dk clickeable pointer" ' . $columnData[$col] . ' style="min-width:' . $colTitleWidth . ';padding:0!important;height:60px;' . $bTopColor . '">' .
	                      	' <div class="m-t-sm m-l-xs">' . $resourceName . '</div>' .
	                      	' <div class="text-xs font-normal m-l-xs">' . $columnSubtitle[$col] . '</div>' .
	                      	'</th>';
	        }else{
	        	$table .= 	'<td class="text-left clickeable ncmCalendarTdData" data-type="newSchedule" data-id="' . $columnId[$col] . '" data-list-position="' . $columnPosition[$col] . '" data-hour="' . $hour . ':00" style="padding:0!important;" data-title="' . $resourceName . ' ' . $ohour . ':00">';

	        	
	        	if($trans){
	        		$table .= '<div style="z-index:0;" class="ncmCalendarBlockWrap">';

	        		foreach($trans as $transData){
	        			$block 		= '';
	        			$strStart   = strtotime($transData['fromDate']);
		                $strEnd     = strtotime($transData['toDate']);

		                $startH     = date('H',$strStart);
		                $startM     = date('i',$strStart);

		                $endH       = date('H',$strEnd);
		                $endM       = date('i',$strEnd);

		                $mTop       = $startM;
		                $height     = rester($strStart,$strEnd)/60;

		                $strOpenTo  = date('Y-m-d ' . $closeH . ':00:00',$strEnd);
		                $bColor 	= 'b-dark';
		                if($strEnd > strtotime($strOpenTo)){
		                  $bColor = 'b-danger';
		                  $txtEnd = 'text-danger';
		                }

		                $bg = $bgColor[$col];
		                if($transData['status'] == 'disabled'){
		                	$bg = '#a7b0b7';
		                }

		                $counter 		= countInDates($trans,'date',$dateHour);
		                $blockWidth 	= divider($colTitleWidth,$counter);
	        			$contactName  	= getCustomerName(['name'=>$transData['customerName']],'first');

	        			if($maxCols < 2){
	        				$tipPlace 		= 'top';
	        			}else{
	        				$tipPlace 		= ($col > counts($maxCols)/2)?'left':'right';//tooltip position
	        			}
	        			

	        			$jsonDetails = json_decode($transData['transactionDetails'],true);
				        for($i=0;$i<counts($jsonDetails);$i++){
				          $jsonDetails[$i]['user'] = enc($transData['userId']);
				        }
	        			
	        			if($transData['date'] == $dateHour){

	        				if($transData['status'] == 'blocked'){
	        					if(!$ignoreBlocked){
		        					$transStyle 	= 'style="width:' . $blockWidth . 'px; margin-top:' . $mTop . 'px; height:' . $height . 'px; background-image:url(https://app.encom.app/images/calendar_locks.png);"';
									$block        	= 	'<div class="wrapper-xs r-2x clear b-l b-5x clickeable ncmCalendarBlockData btnSchedule"
					                					data-blockno="' . $counter . '"
					                					data-placement="' . $tipPlace . '"
					                					data-type="unlockCalendar"
					                					' . $transStyle . '
					                					data-id="' . enc($transData['transactionId']) . '"
					                					data-date="' . $columnDate[$col] . '">' . $transData['icon'] . '</div>';
            					} 		
		                	}else{
		                		$transStyle 	= 'style="width:' . $blockWidth . 'px; margin-top:' . $mTop . 'px; height:' . $height . 'px; background-color:' . $bg . ';"';

				                $block        	= 	'<div class="wrapper-xs text-white pull-left r-2x clear text-left b-l b-5x ' .
				                					$bColor . ' ' . 
				                					$transData['status'] . ' clickeable ncmCalendarBlockData btnSchedule"' . 
				                					$transData['tooltip'] . 
				                					'data-blockno="' . $counter . '"' .
				                					'data-placement="' . $tipPlace . '"' .
				                					$transStyle . ' ' . 
				                					$options['groupData'] . 
				                					' data-date="' . $columnDate[$col] . '" ' . 
				                					( !$options['isJson'] ? getSalesDataList($transData,13,'reprintSale',$transData['customerId']) : 'data-type="getSale" data-id="'.enc($transData['transactionId']).'"' ) . '>' .

				                						$startH.':'.$startM.' - '.$endH.':'.$endM .
				                						$transData['icon'] .

				                					'	<div class="font-bold">' .
				                							$transData['customerName'] .
				                					'	</div>';
				                					if(!$options['isJson']){
								    $block     .= 		'	<span class="hidden '.enc($transData['transactionId']).'">' .
								                                json_encode($jsonDetails) .
								                        '	</span> ';
								                    }

						            $block     .=  	'</div>';
					        }
		        		}

	        			$table .= $block;
	        		}
	        		
	        		$table .= '</div>';	
	        	}

	        	$table .= '</td>';
	        }

	        $col++;
      	}

      	$table .= '</tr>';

    	if($line > 1){
	    	$hour++;
	    }

	    $line++;
    }

    return $table;
}

function calendarBuilder2($options){
	$openH 			= (int)iftn($options['startHour'],7);
	$closeH 		= (int)iftn($options['endHour'],23);
	$maxCols		= iftn($options['maxCols'],7);
	$columnTitle 	= iftn($options['columnTitle'],[]);
	$columnId 		= iftn($options['columnId'],[]);
	$columnPosition = iftn($options['columnPosition'],[]);
	$columnDate 	= iftn($options['columnDate'],[]);
	$startDate 		= iftn($options['startDate'],TODAY_START);
	$endDate 		= iftn($options['endDate'],TODAY_END);
	$colTitleWidth 	= iftn($options['columnTitleWidth'],'100px');
	$columnSubtitle = iftn($options['columnSubTitle'],[]);
	$columnData 	= iftn($options['columnData'],[]);
	$trans 			= iftn($options['transaction'],[]);
	$table 			= '';
	$bgColor 		= iftn($options['columnColor'],[]);
	$ignoreBlocked 	= $options['ignoreBlocked'];

	$line   		= 1;
    $hour			= $openH;

    while($hour <= $closeH){
    	$table .= '<tr style="background-image:url(https://app.encom.app/images/calendar_dash_hours.png);background-repeat: repeat-x;">';

		if($line == 1){
			$table .= '<th class="bg-light dk text-center ncmCalendarClock"> <i class="material-icons text-primary">&#xe192;</i> </th>';
		}else{
			$table .= '<th class="bg-light dk text-center ncmCalendarTime" style="padding-top:0!important;width:50px;">' . $hour . ':00</th>';
		}

		$col = 0;
      	while($col < $maxCols){
      		$ohour 		= str_pad($hour, 2, '0', STR_PAD_LEFT);
      		$dateHour 	= $columnDate[$col] . ' ' . $ohour;
      		$resourceName = getContactName($columnTitle[$col],'short');

      		if($line == 1){
      			$bTopColor = '';
      			if($bgColor[$col]){
      				$bTopColor = 'border-top:1px solid ' . $bgColor[$col] . ';';
      			}

	        	$table .=   '<th class="bg-light dk clickeable pointer" ' . $columnData[$col] . ' style="min-width:' . $colTitleWidth . ';padding:0!important;height:60px;' . $bTopColor . '">' .
	                      	' <div class="m-t-sm m-l-xs">' . $resourceName . '</div>' .
	                      	' <div class="text-xs font-normal m-l-xs">' . $columnSubtitle[$col] . '</div>' .
	                      	'</th>';
	        }else{
	        	$table .= 	'<td class="text-left clickeable ncmCalendarTdData" data-type="newSchedule" data-id="' . $columnId[$col] . '" data-list-position="' . $columnPosition[$col] . '" data-hour="' . $hour . ':00" style="padding:0!important;" data-title="' . $resourceName . ' ' . $ohour . ':00">';

	        	
	        	if($trans){
	        		$table .= '<div style="z-index:0;" class="ncmCalendarBlockWrap">';

	        		foreach($trans as $transData){
	        			$block 		= '';
	        			$strStart   = strtotime($transData['fromDate']);
		                $strEnd     = strtotime($transData['toDate']);

		                if($strStart < strtotime($startDate)){
		                	$startH     = $openH;
		                	$startM     = '0';
		                	$strStart   = strtotime(TODAY_START);
		                }else{
		                	$startH     = date('H',$strStart);
		                	$startM     = date('i',$strStart);
		                }

		                if($strEnd > strtotime($endDate)){
		                	$endH     = $closeH;
		                	$endM     = '59';
		                	$strEnd   = strtotime(TODAY_END);
		                }else{
		                	$endH       = date('H',$strEnd);
		                	$endM       = date('i',$strEnd);
		                }
		                

		                $mTop       = $startM;
		                $height     = rester($strStart,$strEnd)/60;

		                $strOpenTo  = date('Y-m-d ' . $closeH . ':00:00',$strEnd);
		                $bColor 	= 'b-dark';
		                if($strEnd > strtotime($strOpenTo)){
		                  $bColor = 'b-danger';
		                  $txtEnd = 'text-danger';
		                }

		                $bg = $bgColor[$col];
		                if($transData['status'] == 'disabled'){
		                	$bg = '#a7b0b7';
		                }

		                $counter 		= countInDates($trans,'date',$dateHour);
		                $blockWidth 	= divider($colTitleWidth,$counter);
	        			$contactName  	= getCustomerName(['name'=>$transData['customerName']],'first');

	        			if($maxCols < 2){
	        				$tipPlace 		= 'top';
	        			}else{
	        				$tipPlace 		= ($col > counts($maxCols)/2)?'left':'right';//tooltip position
	        			}
	        			

	        			$jsonDetails = json_decode($transData['transactionDetails'],true);
				        for($i=0;$i<counts($jsonDetails);$i++){
				          $jsonDetails[$i]['user'] = enc($transData['userId']);
				        }
	        			
	        			if($transData['date'] == $dateHour){

	        				if($transData['status'] == 'blocked'){
	        					if(!$ignoreBlocked){
		        					$transStyle 	= 'style="width:' . $blockWidth . 'px; margin-top:' . $mTop . 'px; height:' . $height . 'px; background-image:url(https://app.encom.app/images/calendar_locks.png);"';
									$block        	= 	'<div class="wrapper-xs r-2x clear b-l b-5x clickeable ncmCalendarBlockData btnSchedule"
					                					data-blockno="' . $counter . '"
					                					data-placement="' . $tipPlace . '"
					                					data-type="unlockCalendar"
					                					' . $transStyle . '
					                					data-id="' . enc($transData['transactionId']) . '"
					                					data-date="' . $columnDate[$col] . '">' . $transData['icon'] . '</div>';
            					} 		
		                	}else{
		                		$transStyle 	= 'style="width:' . $blockWidth . 'px; margin-top:' . $mTop . 'px; height:' . $height . 'px; background-color:' . $bg . ';"';

				                $block        	= 	'<div class="wrapper-xs text-white pull-left r-2x clear text-left b-l b-5x ' .
				                					$bColor . ' ' . 
				                					$transData['status'] . ' clickeable ncmCalendarBlockData btnSchedule"' . 
				                					$transData['tooltip'] . 
				                					'data-blockno="' . $counter . '"' .
				                					'data-placement="' . $tipPlace . '"' .
				                					$transStyle . ' ' . 
				                					$options['groupData'] . 
				                					' data-date="' . $columnDate[$col] . '" ' . 
				                					getSalesDataList($transData,13,'reprintSale',$transData['customerId']) . '>' .

				                						$startH.':'.$startM.' - '.$endH.':'.$endM .
				                						$transData['icon'] .

				                					'	<div class="font-bold">' .
				                							$transData['customerName'] .
				                					'	</div>' .

							                        '	<span class="hidden '.enc($transData['transactionId']).'">' .
							                                json_encode($jsonDetails) .
							                        '	</span> ' .

						                          	'</div>';
					        }
		        		}

	        			$table .= $block;
	        		}
	        		
	        		$table .= '</div>';	
	        	}

	        	$table .= '</td>';
	        }

	        $col++;
      	}

      	$table .= '</tr>';

    	if($line > 1){
	    	$hour++;
	    }

	    $line++;
    }

    return $table;
}

function countInDates($data,$field,$match){
	$counter = 0;
	
	if($data){
		foreach($data as $value){
			if($value[$field] === $match){
				$counter++;
			}
		}
	}
	return $counter;
}

function getCompoundsArray($itemId,$cache=false){
	$result = ncmExecute('SELECT * FROM toCompound WHERE itemId = ? ORDER BY toCompoundOrder LIMIT 1000',[$itemId],$cache,true,true);
	return $result;
}

function displayableCompounds($id){
	$out 				= [];
	$compounds 	= getCompoundsArray($id);

	if($compounds){
		foreach ($compounds as $key => $value) {
			$out[] 	= [ 'id' => enc($value['compoundId']), 'units' => $value['toCompoundQty'], 'select' => $value['toCompoundPreselected'] ];
		}
	}
	return $out;
}

function getItemCompounds($itemId){

	if(validity($itemId)){//obtengo el itemId
		//verifico si este item es child de algun otro producto (si es una OPCION), mientras no sea produccion previa
		$result = ncmExecute('SELECT itemParentId,itemId FROM item WHERE itemId = ? AND (itemProduction < 1 OR itemProduction IS NULL) LIMIT 1',[$itemId]);

		$itemId = $result['itemId'];
		if(validity($result['itemParentId'])){//si es child obtengo el compound list del padre
			$itemId = $result['itemParentId'];
		}

		$result = getCompoundsArray($itemId);

		if($result){
			//si no es child uso su compound list
			return $result;
		}else{
			//si no tiene compoundlist devuelvo false
			return false;
		}
	}else{
		return false;
	}
	
}

function getProductionCapacity($compounds,$inventory,$waste = false){
	//obtengo la capacidad de produccion de un articulo basandome en el inventario de sus compuestos
	//recibo los compuestos y un array de inventario
	//por cada compuesto sumo el total del inventario y divido por la cantidad que necesito
	//el resultado es la cantidad de unidades que puedo hacer con ese compuesto ej: 5,2,8
	//entonces guardo cada cantidad de produccion en un array y luego devuelvo el menor valor, ej: 2 (es la máxima cantidad que puedo producir)
	if(!$waste){
		$waste = [];
	}

	if(validity($compounds,'array') && $inventory){
		$canMake 	= 0;
		$eachAmount = [];

		foreach($compounds as $val){
			$need 				= $val['toCompoundQty'];
			$wasteP = 0;
			if(array_key_exists($val['compoundId'],$waste)){
				$wasteP = $waste[$val['compoundId']];
			}
			
			if($wasteP > 0){
				$need = getNeedWithWaste($need,$wasteP);
			}

			if($need > 0){//ignoro las cantidades en 0 para que no divida en 0
				$have = 0;
				if(array_key_exists($val['compoundId'], $inventory)){
					$have 			= $inventory[$val['compoundId']]['onHand'];
				}
				
				$divi 			= divider($need,$have);
				$eachAmount[] 	= round($divi,3);//limito los decimales a 3
			}
		}
		
		return ($eachAmount) ? min($eachAmount) : 0; //obtengo el menor valor del array
	}else{
		return 0;
	}
}

function getProductionCOGS($itemId,$wasted=true){
	$total 	= 0;
	$result = getCompoundsArray($itemId);
	if($result){
		$waste 	= getAllWasteValue();

		foreach ($result as $key => $value) {
			$id 	= $value['compoundId'];
			$count 	= (float)$value['toCompoundQty'];

			$wasteP = $waste[$id] ?? '';

			if($wasteP > 0 && $wasted){
				$count 	= getNeedWithWaste($count,$wasteP);
			}

			$avrg 	= getItemStock($id);
			$avrg 	= $avrg['stockOnHandCOGS'] ?? 0;
			
			$price 	= ($avrg * $count);
			$total += $price;
		}
	}
	return $total;
}

function getComboCOGS($parent){
	$result 			= getCompoundsArray($parent);
	$comboCOGS 		= 0;

	if(validity($result,'array')){
		foreach ($result as $resulta) {
			$id 			= $resulta['compoundId'];
		  $units 		= number_format($resulta['toCompoundQty'],2);//dejo en 2 ceros
	    
			$compData = ncmExecute('SELECT itemPrice FROM item WHERE itemId = ? LIMIT 1',[$id]);
		  $price 		= $compData['itemPrice'] ?? 0;

			$comboCOGS += $price * $units;
		}
	}

	return $comboCOGS;
}

function getItemTypeName($result){
	$realType = $result['itemType'];
	if($realType == 'product'){
      if($result['itemProduction'] > 0){
        $type 				= 'production';
        $typeName 			= 'Producción Previa';
        $inventoryTools 	= true;
      }else if($result['itemType'] == 'product' && $result['itemTrackInventory'] < 1 && validity(getCompoundsArray($result['itemId']))){
        $type 				= 'direct_production';
        $typeName 			= 'Producción Directa';
        $productionTools 	= true;
      }else if($result['itemCanSale']<1){
		$type 				= 'compound';
		$typeName 			= 'Activo/Compuesto';
		$inventoryTools 	= true;
	  }else if($result['itemIsParent'] > 1){
	  	$type 				= 'group';
		$typeName 			= 'Grupo';
      }else{
      	$typeName 			= 'Producto';
      	$productionTools 	= true;
      	$inventoryTools 	= true;
      }
    }else if($realType == 'precombo'){
    	$typeName 			= 'Combo Predefinido';
    	$comboTools 		= true;
    }else if($realType == 'combo'){
    	$typeName 			= 'Combo Dinámico';
    	$comboTools 		= true;
    }else if($realType == 'comboAddons'){
    	$typeName 			= 'Combo Add-on';
    	$comboTools 		= true;
    }else if($realType == 'production'){
    	$typeName 			= 'Producción Previa';
    	$productionTools 	= true;
    }else if($realType == 'direct_production'){
    	$typeName 			= 'Producción Directa';
    	$productionTools 	= true;
    }

    return $typeName;
}

function selectInputOutlet($match='',$multi=false,$class='',$name='outlet'){
	global $db, $SQLcompanyId;
	$result = $db->Execute("SELECT outletName,outletId FROM outlet WHERE ".$SQLcompanyId." ORDER BY outletName ASC");
	// if($multi){
	// 	$type = '[]';
	// }
?>
	<select name="<?=$name?>" class="form-control <?=$class?>">
		<?php while (!$result->EOF) {?>
			<?php
            $selected = '';
            if($result->fields['outletId'] == $match){
                $selected = 'selected';
            }
            ?>
            <option value="<?=enc($result->fields['outletId']);?>" <?=$selected?>><?=$result->fields['outletName'];?></option>
            <?php 
            $result->MoveNext(); 
        }
        $result->Close();
        ?>
    </select>
<?php
}

function formatCurrentNumber($number,$de='',$ts=''){
	global $DECIMAL,$THOUSAND_SEPARATOR;
	if(!$number){$number = 0;}

	$decimal 	= (!$de) ? $DECIMAL : $de;
	$thouS 		= (!$ts) ? $THOUSAND_SEPARATOR : $ts;

	if($decimal == 'no'){
		//$explode 	= explode($number); //esto es para eliminar los decimales
		//$number 	= $explode[0];
		$number 	= round($number);
		if($thouS == 'comma'){
			return number_format($number, 0, '.', ',');
		}else{
			return number_format($number, 0, ',', '.');
		}
	}else{
		if($thouS == 'comma'){
			return number_format($number, 2, '.', ',');
		}else{
			return number_format($number, 2, ',', '.');
		}
	}
}

function formatQty($val,$extDec=2){
	if(strpos($val . '', '.') === false){ //es entero
		return formatCurrentNumber($val,'no',false);
	}else{ //si tiene decimales
		$getDec = explode('.', $val);
	    if($getDec[1] > 0){//si los decimales no son 0 fuerzo a enviar decimales
	      return formatCurrentNumber($val,'yes',false,$extDec);
	    }else{//de lo contrario envio enteros
	      return formatCurrentNumber($val,'no',false);
	    }
	}
}

function addTax($tax,$price){
	if($tax && $price && $tax > 0){
		$taxVal 	= $price / (1 + ($tax / 100));
		$total 		= $price-$taxVal;

		if($total && $total > 0){
			return $total;
		}else{
			return 0;
		}
	}else{
		return 0;
	}
}

function checkAmount($table){
	global $db, $SQLcompanyId;
	$result = $db->Execute('SELECT COUNT('.$table.'Id) FROM '.$table.' WHERE '.$SQLcompanyId);
	return $result->fields[0];
}

function checkPlanMaxReached($table,$max){
	if(checkAmount($table) >= $max){
		return true;
	}else{
		return false;
	}
}

function checkIfExists($name, $field, $table){
	global $db, $SQLcompanyId;
	$name 	= $db->Prepare($name);
	$field 	= $db->Prepare($field);
	$table 	= $db->Prepare($table);
	if($name != '' && !empty($name)){
		$obj = $db->Execute("SELECT ".$table."Id FROM ".$table." WHERE ".$field." = '".$name."' AND ".$SQLcompanyId);
		
		if($obj->fields[0] == '' || $obj->fields[0] < 1){
			return false;
		}else{
			return true;
		}
		$obj->Close();
	}else{
		return false;
	}
}

function itemHasParent($id){
	global $db;
	
	if($id != '' && !empty($id)){
		$obj 		= $db->Execute("SELECT itemParentId FROM item WHERE itemId = ?", array($id));
		$parentId 	= $obj->fields['itemParentId'];

		$obj->Close();
		
		if($parentId < 1){
			return false;
		}else{
			return $parentId;
		}
	}else{
		return false;
	}
}

function groupByPaymentMethod($new,$old){
	$nuPrice = 0;
	$nuTotal = 0;

	if(!validity($new,'array')){
		return false;
	}

	foreach($new as $nu){
		$nuPrice 	= iftn(abs($nu['price']),0); // lo que se ingresa en el visor de pago
		$nuTotal 	= iftn(abs($nu['total']),0); // saldo a pagar
		$nuType 	= $nu['type'];

		if(!isset($nu['name']) || !$nu['name']){
			$nu['name'] = getPaymentMethodName($nu['type']);
		}

		if($nuPrice > $nuTotal){
			$nu['price'] 	= $nuTotal;
			$nuPrice 			= (float)abs($nu['price']);
		}

    $match 					= false;

    if(validity($old,'array')) {
	    foreach($old as $index => $ol){
	        if($nuType === $ol['type']){
	            $old[$index]['price'] = (float)$ol['price'] + (float)$nuPrice;
	            $match = true;
	        }
	    }
    }

    if(!$match){
    	unset($nu['extra']);
    	unset($nu['total']);
      array_push($old,$nu);
    }
	}

	return $old;
}


function groupOrdersItems($orders,$ltrl=false,$strng=false){
	$details = [];
	foreach($orders as $tbl => $jsn){
		if($ltrl){
			$json      = json_decode($jsn['transactionDetails'],true);
			array_push($json,$jsn['transactionId']);
		}else{
			$json      = json_decode($jsn,true);
		}

		if(isset($details[$tbl])){
			foreach($json as $js){
				array_push($details[$tbl],$js);    
			}
		}else{
			$details[$tbl] = $json;
		}
	}

	if($strng){
		return json_encode($details);
	}else{
		return $details;
	}
}

function isInternalSale($tags){
	global $_fullSettings;

	if(empty($_fullSettings['ignoreInternal'])){
		return false;
	}

	if(!validity($tags,'array')){
		return false;
	}

	if(in_array('166227', $tags) || in_array(166227, $tags)){
		return true;
	}else{
		return false;
	}

}

function isParentInternalSale($parentId){
	global $_fullSettings;

	if(empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']){
		return false;
	}

	if(!validity($parentId)){
		return false;
	}

	$ignore = false;
	$field 	= ncmExecute('SELECT tags FROM transaction WHERE transactionId = ? AND transactionType IN(0,3) AND companyId = ? LIMIT 1',[$parentId,COMPANY_ID]);

	if($field){
		$tags 	= json_decode($field['tags'],true);
		$ignore = isInternalSale($tags);
	}

	return $ignore;
}

function lessInternalTotals($roc,$from,$to,$tTypes = false){
	global $_fullSettings;

	if(empty($_fullSettings['ignoreInternal']) || !$_fullSettings['ignoreInternal']){
		return ['total' => 0, 'discount' => 0, 'tax' => 0, 'qty' => 0, 'count' => 0];
	}

	$tTypes = $tTypes ? db_prepare($tTypes) : '0,3';

	$result = ncmExecute('SELECT transactionTotal, tags, transactionDiscount, transactionUnitsSold, transactionTax FROM transaction USE INDEX(transactionType,transactionDate) WHERE transactionDate BETWEEN ? AND ? AND transactionType IN(' . $tTypes . ') ' . $roc . ' LIMIT 5000',[$from,$to],1200,true);

	$total  	= 0;
	$discount  	= 0;
	$tax  		= 0;
	$qty  		= 0;
	$count 		= 0;
	if($result){
		while (!$result->EOF) {
			$field = $result->fields;
			$tags = json_decode($field['tags'],true);
		    if(isInternalSale($tags)){
		    	$total 		+= $field['transactionTotal'];
		    	$discount  	+= $field['transactionDiscount'];
				$tax  		+= $field['transactionTax'];
				$qty  		+= $field['transactionUnitsSold'];

				$count++;
		    }
		    $result->MoveNext(); 
		}
		$result->Close();
	}

	return ['total' => (float) $total, 'discount' => (float) $discount, 'tax' => (float) $tax, 'qty' => (float) $qty, 'count' => (float) $count];
}

function getSalesByPayment($from,$to,$regId){
	global $db,$SQLcompanyId;
	$regId 	= iftn($regId,REGISTER_ID);
	//return false;
	//selecciono todas las transacciones filtradas por la fecha de apertura de caja y cierrre si es que tiene, es decir: fecha transacción && fecha apertura caja
	//hago un loop para agrupar todos los metodos de pago
	if(validity($from) && validity($regId)){
		if(($to == '0000-00-00 00:00:00') || !$to){
			$to = false;
		}

		$date 		= iftn($to,
											"transactionDate > '" . $db->Prepare($from) . "'",
											"transactionDate BETWEEN '" . $db->Prepare($from) . "' AND '" . $db->Prepare($to) . "'"
										);
		
		$result 	= ncmExecute($sql = "SELECT transactionId, abs(transactionTotal) as transactionTotal, abs(transactionDiscount) as transactionDiscount,transactionPaymentType, transactionType, transactionParentId, tags
									FROM transaction 
									WHERE  " . $date . "
									AND transactionType IN (0,5,6)
									AND registerId = ?"
									,[$regId],false,true);
		if($result){
			$group = [];
			while (!$result->EOF) {
				$fields 	= $result->fields;
				$new 			= json_decode($fields['transactionPaymentType'],true);
				
				if($fields['transactionType'] == 6){
					$new[0]['type'] = 'return';
					$new[0]['name'] = 'Nota de Crédito';
					$new[0]['price'] = $fields['transactionTotal'] - $fields['transactionDiscount'];
					$new[0]['total'] = $fields['transactionTotal'] - $fields['transactionDiscount'];
					$new[0]['extra'] = '';
				}

				if($fields['transactionType'] == 5){
		    		$ignore = isParentInternalSale($fields['transactionParentId']);
		    	}else{
			    	$tags 	= json_decode($fields['tags'], true);
				    $ignore = isInternalSale($tags);
				}

				if(validity($new) && !$ignore){
					$group 		= groupByPaymentMethod($new,$group);
				}
			
				$result->MoveNext(); 
		    }
		    $result->Close();
		    return $group;
		}else{
			return false;
		}
	}else{
		return false;
	}

}



function getSalesDataList($trs,$type,$typeAttr,$customerUID,$topay=0){
	//$customerUID 	= getTheContactField($trs['customerId'],$allCustomers,'uid');

	$paymentType    = json_decode($trs['transactionPaymentType'],true);
    $paymentTypes 	= [];

    if($paymentType){
	    foreach ($paymentType as $key => $value) {
	      $paymentTypes[] = $value['price'].','.$value['name'].','.$value['extra'].'|';
	    }
	}

	$tags   = json_decode($trs['tags']);

    $dueDate = '';
    if($trs['transactionDueDate'] != '0000-00-00 00:00:00'){
      $dueDate = explodes(' ',$trs['transactionDueDate'],0);
    }

    $total 		= $trs['transactionTotal'];
    $discount 	= $trs['transactionDiscount'];

    $startDate 	= false;
    $startH 	= false;
    $endH 		= false;

    if($trs['fromDate'] && $trs['toDate']){
    	list($startDate,$startH,$endH) = dateStartEndTime($trs['fromDate'],$trs['toDate']);
    	$startDate = niceDate($trs['fromDate']);
    }

	$data = 'data-type="getSale" ' .
		    'data-action="' . $typeAttr . '" ' .
		    'data-id="' . enc($trs['transactionId']) . '" ' .
		    'data-user="' . enc($trs['userId']) . '" ' .
		    'data-date="' . niceDate($trs['transactionDate'],true) . '" ' .
		    ( ($startDate) ? 'data-startdate="' . $startDate . '"' : '' ) .
		    ( ($startH) ? 'data-starth="' . $startH . '"' : '') .
		    ( ($endH) ? 'data-endh="' . $endH . '"' : '') .
		    ( ($trs['transactionParentId']) ? 'data-hassession="1"' : '') .
		    'data-raw-date="'.$trs['transactionDate'].'" ' .
		    'data-duedate="'.$dueDate.'" '.
		    'data-uid="'.$trs['timestamp'].'" '.
		    'data-note="'.$trs['transactionNote'].'" '.
		    'data-tags="'.@implodes(',',$tags).'" '.
		    'data-customer="'.enc($customerUID).'" '.
		    'data-name="'.$trs['transactionName'].'" '.
		    'data-topay="'.number_format($topay, 2, '.', '').'" '.
		    'data-discount="' . number_format($discount, 2, '.', '') . '"' .
		    'data-total="'.number_format(($total-$discount), 2, '.', '').'" '.
		    'data-thetype="'.$type.'" '.
		    'data-invoiceno="' . $trs['invoicePrefix'] . $trs['invoiceNo'].'" '.
		    'data-pmethods="'.@implodes('|',$paymentTypes).'" '.
		    'data-register="'.enc($trs['registerId']).'" ' .
		    'data-status="'.$trs['transactionStatus'].'" ';

	return $data;
}

function buildCalendarTooltip($data){
	if(validity($data,'array')){
		$tip  = 	"<div class='wrapper-xs panel no-bg m-n text-left text-default' style='width:180px;'>".

			        "	<div class='text-lg font-bold'>".
			            	$data['customerName'] .
			        "	</div>".
			        "	<div class='m-b-sm'>".
			            	$data['customerContact'] .
			        "	</div>".

			        "	<table class='table table-condensed no-bg'>".
			        "		<tr>".
			        "			<td>" . $data['list'] . "</td>".
			        "		</tr>".
			        "		<tr>".
			        "			<td>" . 
			        "				De " . $data['startH'] . ":" . $data['startM'] . " a " .
			        "				<span class='" . $data['textEnd'] . "'>" . $data['endH'] . ":" . $data['endM'] . "</span>" .
			        "				<i class='material-icons pull-right md-14'>" . $data['icon'] . "</i>" .
			        "			</td>".
			        "		</tr>".
			        "		<tr>".
			        "			<td>Por ".$data['userName']."</td>".
			        "		</tr>".
			        "		<tr>".
			        "			<td>Total: <strong class='pull-right'>" . $data['total'] . "</strong></td>".
			        "		</tr>".
			        "	</table>".

			        "</div>";

	    return $tip;
	}
}

function switchVals($val){
	$returns = false;
	foreach($val as $state => $result){
		if($state){
			$returns = $result;
			break;
		}
	}
	return $returns;
}

function getIdOrInsert($name, $table, $insertIt = true, $extra = ''){
	global $db, $SQLcompanyId;
	if($extra != ''){$extra = ' AND '.$extra;}
	$obj = $db->Execute("SELECT ".$table."Id FROM ".$table." WHERE ".$table."Name = '".$name."'".$extra." AND ".$SQLcompanyId);
	
	if($obj->fields[0] != ''){
		return $obj->fields[0];
	}else{
		if($insertIt == true){
			$record[$table.'Name'] 	= $name;
			$record['companyId'] 	= COMPANY_ID;
			
			$insert = $db->AutoExecute($table, $record, 'INSERT');
			if($insert === true){
				return $db->Insert_ID();
			}
		}
	}
}

function getTaxonomyIdOrInsert($name, $type, $insertIt = true){
	global $db, $SQLcompanyId;

	if(validity($name)){
		$obj = $db->Execute("SELECT taxonomyId FROM taxonomy WHERE taxonomyName = ? AND taxonomyType = ? AND ".$SQLcompanyId,array($name,$type));
		
		if(validity($obj->fields['taxonomyId'])){
			return $obj->fields['taxonomyId'];
		}else{
			if($insertIt == true){
				$record['taxonomyName'] = $name;
				$record['taxonomyType'] = $type;
				$record['companyId'] 	= COMPANY_ID;
				
				$insert = $db->AutoExecute('taxonomy', $record, 'INSERT');
				if($insert === true){
					return $db->Insert_ID();
				}
			}
		}
	}
}

function formatNumberToInsertDB($number,$forceDecimals=false,$decimalsCount=2){
	if(!validity($number)){$number = 0;}

	if(DECIMAL == 'no' && !$forceDecimals){
		if(THOUSAND_SEPARATOR == 'dot'){
			$explode 	= explode(',',$number); //esto es para eliminar los decimales
			$number 	= $explode[0];
			$number 	= str_replace('.','',$number);
		}else{
			$explode 	= explode('.',$number); //esto es para eliminar los decimales
			$number 	= $explode[0];
			$number 	= str_replace(',','',$number);
		}
		return $number;
	}else{
		if(THOUSAND_SEPARATOR == 'dot'){
			$number = str_replace('.','',$number);//1.000,00 => 1000,00
			$number = str_replace(',','.',$number);//1000,00 => 1000.00
		}else{
			$number = str_replace(',','',$number);//1,000.00 => 1000.00
		}

		$number = forceExtraDecimalsNumber($number,$decimalsCount);

		return $number;
	}
}

function forceExtraDecimalsNumber($num,$max=3){
	return number_format($num,$max,'.','');
}

function sanitizeForDB($str){
	$breaks 	= array("\r\n", "\n", "\r");
    $str 		= preg_replace('/[^a-zA-Z0-9_ %\[\]\.\(\)%&-]/s', '', str_replace($breaks, " - ", $str));
	return trim(htmlspecialchars($str));
}

function iftn($if, $else = false, $then = false){
	$else 	= validity($else)?$else:'';
	$final 	= validity($then)?$then:$if;
	return validity($if)?$final:$else;
}

function validateBool($value,$server=true,$type='get'){
	if($server === true){//verifico si realmente los metodos fueron pasados por post o get
		if($_SERVER['REQUEST_METHOD'] != 'POST' && $_SERVER['REQUEST_METHOD'] != 'GET'){
			return false;
		}
	}
	
	if($type == 'get'){
		return validity($_GET[$value]);
	}else if($type == 'post'){
		return validity($_POST[$value]);
	}else{
		return validity($value);
	}
}

function validateHttp($value,$type = 'get'){//alias de validateBool
	$result = db_prepare( validateBool($value, true, $type) );
	unset( $value, $type );
	return $result;
}

function validateResultFromDB($result,$num=false){
	if($result){
		if($result->RecordCount()>0){
			return ($num)?$result->RecordCount():true;
		}
	}
	return ($num)?0:false;
}

function validity($value,$force=false){
	if(!isset($value)){
		return false;
	}else{
		if(!$value || empty($value) || $value == 'undefined' || $value === null || $value == false || $value === false || $value == '' || counts($value) < 0.00001){
			return false;
		}else{
			if($force){
				if($force === 'email'){
					if(filter_var($value, FILTER_VALIDATE_EMAIL)){
						return $value;
					}else{
						return false;
					}
				}else if(gettype($value) === $force){
					return $value;
				}else{
					return false;
				}
			}
			return $value;
		}
	}
}

function ncmExecute( $sql, $array = false, $cache = false, $forceObj = false, $getAssoc = false ){
	global $db,$ADODB_CACHE_DIR;

	$go = false;

	if(!$cache){
		if($getAssoc){
			$result = $db->GetAssoc($sql, $array);
		}else{
			$result = $db->Execute($sql,$array);
		}
	}else{
		$cachTime = 3600;
		if(is_numeric($cache)){
			$cachTime = $cache;
		}

		if($getAssoc){
			$result = $db->CacheGetAssoc($cachTime,$sql, $array);
		}else{
			$result = $db->cacheExecute($cachTime,$sql,$array);
		}
		
	}

	if($getAssoc){
		$count = counts($result);
	}else{
		$count = validateResultFromDB($result,true);
	}

	if($getAssoc){
		if(validity($result,'array')){
			$go = true;
		}
	}else{
		if(validateResultFromDB($result)){
			$go = true;
		}
	}

	if($go){
		if($getAssoc){
			return $result;
		}else{
			if($count > 1 || $forceObj){
				return $result;
			}else if($count > 0){
				return $result->fields;
			}else{
				return 0;
			}
		}
	}else{
		// When forceObj is requested and the query succeeded but returned 0 rows,
		// return the empty recordset so callers can safely iterate (EOF=true from start)
		if($forceObj && $result && is_object($result)){
			return $result;
		}
		return false;
	}
}

function ncmUpdate($options){//records (arr), table (str), where (str)
	global $db;

	if( !validity($options,'array') || !validity($options['records'],'array') || !validity($options['table']) || !validity($options['where']) ){
		return false;
	}

	$table 		= $options['table'];
	$record 	= $options['records'];
	$where 		= $options['where'];

	$update 	= $db->AutoExecute($table, $record, 'UPDATE', $where);
	$updateId 	= $db->Insert_ID();

	if($update !== false){
		return ['error' => false, 'id' => $updateId];
	}else{
		return ['error' => $db->ErrorMsg()];
	}
}

function ncmInsert($options){
	global $db;

	if( !validity($options,'array') || !validity($options['records'],'array') || !validity($options['table']) ){
		return false;
	}

	$table 			= $options['table'];
	$record 		= $options['records'];

	$insert 		= $db->AutoExecute($table, $record, 'INSERT');
	$insertedId = $db->Insert_ID();

	if($insert !== false){
		return $insertedId;
	}else{
		return false;
	}
}

function ncmDelete($from,$where){//
	global $db;

	if(!validity($from) || !validity($where)){
		return false;
	}

	$deleted = $db->Execute("DELETE FROM ? WHERE ?",[$from,$where]);

	if($deleted !== false){
		return true;
	}else{
		return false;
	}
}

function ncmWhile($result,$callback,$vars){
	if($result){
	    while (!$result->EOF) {
	    	$field = $result->fields;
	    	if (is_callable($callback)) {
		        call_user_func($callback,$field,$vars);
		    }
	    	$result->MoveNext(); 
	    }
	    $result->Close();
	}
}

function switchIn($name, $status, $extraClass='',$val=1){
	$state = '';
	$selected = '';
	if($status > 0){
		$state  	= 'checked';
		$selected 	= 'selected';
	}

	return '<div class="switch-select switch '.$selected.' '.$extraClass.'" id="'.$name.'">
		        <div class="swinner">
		        	<input type="checkbox" name="'.$name.'" class="'.$name.'Class" value="'.$val.'" '.$state.' />
		        </div>
		    </div>';
}

function arrKey($array,$key,$returnOnFalse=false){
	if(array_key_exists($key, $array)){
		return $array[$key];
	}else{
		return iftn($returnOnFalse,false);
	}
}

function counts($val){
	if(is_numeric($val)){//primero check is numeric para procesar numeric strings
        return $val;
	}else if(is_string($val)){
		return strlen($val);
	}else if(is_array($val)){
		return count($val);
	}else{
		return 0;
	}
}

function explodes($str,$array,$return=-1){
	if(validity($array,'string')){
		if($return > -1){
			$out = explode($str,$array);
			return $out[$return];
		}else{
			return explode($str,$array);
		}
	}else{
		if($return > -1){
			return '';
		}else{
			return array();
		}
	}
}

function array_pushs($arr1,$arr2){
	if(validity($arr1,'array') && validity($arr2,'array')){
		array_push($arr1,$arr2);
	}else{
		return false;
	}
}

function getItemPrice($id){
	global $db, $SQLcompanyId;
	$result 	= $db->Execute('SELECT itemPrice FROM item WHERE itemId = ? AND '.$SQLcompanyId,array($id));
	$total 		= $result->fields['itemPrice'];
	return $total;
}

function getOperatingCost($outletId){
	global $db;
	$opCost = $db->Execute("SELECT outletOperatingCosts
								FROM outlet
								WHERE outletId = ".$outletId." 
								LIMIT 1");
								
	$operationCost = $opCost->fields[0];
	$opCost->Close();
	return $operationCost;
}

function isntDiscount($str){
	if($str != 'Descuento' && $str != 'Discount'){
		return true;
	}else{
		return false;
	}
}

function countUnitSold($saleDetail){
  $sold = 0;
  foreach($saleDetail as $key => $val){
    if(isntDiscount($val['type'])){
      $sold += $val['count'];
    }
  }
  return $sold;
}

//INVENTORY LOGIC
function removeFromArrayByKey($array, $key){
	$i=0;
	while($i < counts($array)){
		unset($array[$i][$key]);
		$i++;
	}
	return $array;
}

function removeFromArrayByValue($array, $value){
	$i=0;
	while($i < counts($array)){
		if(($key = array_search($value, $array)) !== false) {
		    unset($array[$key]);
		}
		$i++;
	}
	return $array;
}

function sumProperties($arr, $property) {
    $sum = 0;
    foreach($arr as $object) {
        $sum += isset($object[$property]) ? $object[$property] : 0;
    }
    return $sum;
}

function getItemStock($itemId,$outlet=false,$inLocation=false){
	if(!validity($itemId)){
		return false;
	}

	if($inLocation){
		$locationc 	= 0;
		$location 	= ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',[$itemId,$inLocation]);

		return ($location) ? $location['toLocationCount'] : 0;
	}

	if($outlet){
		$outletId = $outlet;
	}else{
		$outletId = OUTLET_ID;
	}
	
	$result = ncmExecute('SELECT * FROM stock WHERE itemId = ? AND outletId = ? ORDER BY stockId DESC LIMIT 1',[$itemId,$outletId]);

	return $result;
}

function getItemMainStock($itemId,$outletId){

	$inventory 	= getItemStock($itemId,$outletId);
	$count 		= formatQty($inventory['stockOnHand']);
	$depo 		= ncmExecute('SELECT * FROM taxonomy WHERE taxonomyType = "location" AND outletId = ? ORDER BY taxonomyName ASC',[$outletId],false,true);
	
	if($depo){
		$dTotal = 0;
		while (!$depo->EOF) {
			$dCount 	= 0;
			$depCount 	= ncmExecute('SELECT * FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',[$depo->fields['taxonomyId'],$itemId]);

			if($depCount){
				$dCount = $depCount['toLocationCount'];
			}
			
			$dTotal += $dCount;

			$count 	= $count - $dTotal;

			$depo->MoveNext();
		}
	}
	return $count;
}

/*function getAllItemStock($outlet=OUTLET_ID){
	global $db;

	$sql 	= '	SELECT t1.itemId as itemId, t1.stockOnHand as onHand, t1.stockOnHandCOGS as cogs
				FROM stock t1
				JOIN
				(
				  SELECT max(stockId) AS stockId
				  FROM stock
				  WHERE outletId = ' . $outlet . '
				  GROUP BY itemId
				) t2 ON t1.stockId = t2.stockId AND t1.outletId = ' . $outlet;

	$result = $db->GetAssoc($sql);

	if(validity($result)){
		return $result;
	}else{
		return [];
	}
}*/

function forcedAbotion(){
	//return false;
	//if(COMPANY_ID == 2829 || COMPANY_ID == 10){
      echo ' ';
      ob_flush();
      flush();
    //}
}

function getAllItemStock($outlet=false,$all=false){
	global $db;

	$sql 	= '	SELECT t1.itemId as itemId, t1.stockOnHand as onHand, t1.stockOnHandCOGS as cogs
				FROM stock t1
				JOIN
				(
				  SELECT max(stockId) AS stockId
				  FROM stock
				  WHERE outletId = ?
				  GROUP BY itemId
				) t2 ON t1.stockId = t2.stockId AND t1.outletId = ?';

	if($all){
		$allOutletsArray = getAllOutletData();
		$result = [];
		foreach ($allOutletsArray as $outlet => $val) {
			$item = ncmExecute($sql,[$outlet,$outlet],false,true,true);
			if($item){
				foreach ($item as $itemId => $values) {
					$result[$itemId]['itemId'] 	= $values['itemId'];
					$result[$itemId]['onHand'] += $values['onHand'];
					$result[$itemId]['cogs'] 	= $values['cogs'];
				}
			}
		}
	}else{
		$outlet = iftn($outlet,OUTLET_ID);

		$result = ncmExecute($sql,[$outlet,$outlet],false,true,true);
	}

	if(validity($result)){
		return $result;
	}else{
		return [];
	}
}

function manageStock($ops){
	global $db;
	$itemId 				= $ops['itemId'];
	$source 				= iftn($ops['source'],'adjustment');
	$count 					= $ops['count'];
	$type 					= iftn($ops['type'] ?? '','+');
	$COGS					= array_key_exists("cogs",$ops) ? $ops["cogs"] : "";
	$user 					= iftn(array_key_exists("userId",$ops) ? $ops["userId"] : "",USER_ID);
	$transaction			= $ops['transactionId'];
	$supplier				= array_key_exists("supplierId",$ops) ? $ops['supplierId'] : "";
	$outlet					= $ops['outletId'];
	$location				= $ops['locationId'];
	$note					= array_key_exists("note",$ops) ? $ops['note'] : "";
	$date					= $ops['date'];
	$company				= iftn(array_key_exists("companyId",$ops) ? $ops['companyId'] : "",COMPANY_ID);

	if(!validity($count) || !$itemId){
		return false;
	}

	//verifico si el item tiene control de stock y no es un servicio
	$isStockeable 		= ncmExecute('SELECT itemTrackInventory FROM item WHERE itemStatus = 1 AND itemId = ? AND companyId = ? LIMIT 1',[$itemId,COMPANY_ID]);

	if(!$isStockeable || $isStockeable['itemTrackInventory'] < 1){
		return false;
	}

	$stock 				= getItemStock($itemId);
	$oldStock			= is_numeric(array_key_exists("stockOnHand",is_array($stock) ? $stock : []) ? $stock['stockOnHand'] : "") ? $stock['stockOnHand'] : 0;
	$oldACOGS			= is_numeric(array_key_exists("stockOnHandCOGS",is_array($stock) ? $stock : []) ? $stock['stockOnHandCOGS'] : "") ? $stock['stockOnHandCOGS'] : 0;

	if(!validity($COGS)){
		$COGS = array_key_exists("stockCOGS",is_array($stock) ? $stock : []) ? $stock['stockCOGS'] : "";
	}

	if($type == '+'){
		$newOnHand 			= $oldStock + $count;//obtengo nueva cantidad en stock

		if($oldStock < 0){//si el stock viejo es menor a 0 el costo se calcula con el onhand
			//Es así para que pueda aumentar el negativo y comenzar de cero con el positivo
			$newCOGS 		= $COGS * $newOnHand;
		}else{//si no se calcula con la cantidad añadida
			$newCOGS 		= $COGS * $count;
		}
		
		$newTotalCOGS 		= (($oldACOGS * $oldStock) + $newCOGS);
		$newTotalCOGS 		= divider($newTotalCOGS, $newOnHand, true);
	}else{//si es venta o quito stock
		$newOnHand 			= $oldStock - $count;
		$COGS 				= $oldACOGS;

		if($newOnHand <= 0){
			$newTotalCOGS 		= 0;
		}else{
			$newTotalCOGS 		= $oldACOGS;
		}
		
	}

	$row['stockSource']   	= $source;
	$row['stockNote']   	= $note;
	$row['stockCount']   	= $type . $count;
	$row['stockCOGS']   	= $COGS;
	$row['stockOnHand']   	= $newOnHand;
	$row['stockOnHandCOGS'] = $newTotalCOGS;
	$row['itemId'] 			= $itemId;
	$row['transactionId']	= iftn($transaction,NULL);
	$row['userId'] 			= $user;
	$row['supplierId'] 		= iftn($supplier,NULL);
	$row['outletId'] 		= $outlet;
	$row['locationId'] 		= $location;
	
	$row['companyId']		= $company;

	if($date){
		$row['stockDate']	= $date;
	}
    
    $insert = $db->AutoExecute('stock', $row, 'INSERT');

    if($insert !== true){
    	return false;
    }else{
    	updateRowLastUpdate('item','itemId = ' . $itemId);
    	if($location){
    		$isLocation = ncmExecute('SELECT toLocationId FROM toLocation WHERE locationId = ? AND itemId = ? LIMIT 1',[$location,$itemId]);
    		if($isLocation){
				$db->Execute('UPDATE toLocation SET toLocationCount = toLocationCount' . $type . $count . ' WHERE toLocationId = ' . $isLocation['toLocationId']);
			}else{
	    		$db->AutoExecute('toLocation', ['locationId' => $location, 'toLocationCount' => $type . $count, 'itemId' => $itemId], 'INSERT');
	    	}
    	}
		try {
			$userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
            $registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
            $companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
            $outletName = getCurrentOutletName(OUTLET_ID);
			$itemName = getItemName($itemId);

            $auditoriaData = [
              'date'        => $date,
              'user'      => $userName,
              'module'       => 'STOCK',
              'origin'       => 'CAJA',
              'company_id'       => COMPANY_ID,
              'data'       => [
                'action' => "El usuario $userName ajustó el item $itemName desde la caja " . $registerName,
                'userId' => USER_ID,
                'userName' => $userName,
				'itemId'	=> $itemId,
				'itemName'=> $itemName,
                'operationData' => $row,
                'registerId' => REGISTER_ID,
                'registerName' => $registerName,
                'companyID' => COMPANY_ID,
                'companyName' => $companyName,
                'outletId' => OUTLET_ID,
                'outletName' => $outletName,
                'timestamp' => $ops['timestamp']
              ]
            ];

            sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
		} catch (\Throwable $th) {
			//throw $th;
			error_log("Error al enviar registro de auditoría de ajuste de stock: \n", 3, './error_log');
			error_log(print_r($th, true), 3, './error_log');
			error_log("data stock: \n", 3, './error_log');
            error_log(print_r($row, true), 3, './error_log');
		}

    	return $row;
    }
}

//INVENTORY LOGIC END

function emailTemplate($array,$tituloShare,$URLShare,$dec='',$ts=''){

	$z 			= 0;
	$data 		= $array['sale'];
	$length 	= counts($data);
	$total 		= $array['total'];
	$subtotal 	= $array['subtotal'];
	$discount 	= $array['discount'];
	$tax 		= $array['tax'];
	$taxName	= $array['taxName'];
	$storeName	= $array['companyName'];
	$companyId	= $array['companyId'];
	$outletName	= $array['outlet'];
	$date		= $array['date'];
	$tr 		= '';
	while($z < $length){
		if($data[$z]['name'] != 'Descuento' && $data[$z]['name'] != 'Discount'){
			$tr .= '<tr>';
				$tr .= '<td style="border-bottom:1px dashed #999; padding:10px; text-align:left;">'.$data[$z]['name'].'</td>';
				$tr .= '<td style="border-bottom:1px dashed #999; padding:10px; text-align:right;">'.formatCurrentNumber($data[$z]['total'],$dec,$ts).'</td>';
			$tr .= '</tr>';
		}

		$z++;
	}
	
	$template = '<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
	  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
	  <title>Recibo de Dinero</title>
	  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
	</head>
	<body style="margin: 0; padding: 0; font-family: Arial, sans-serif; text-align:center;">
	  <table align="center" border="0" cellpadding="0" cellspacing="0" width="98%"> 
	    <tbody>
	    <tr>
	      <td style="padding-top: 10px;">

	        <table cellpadding="0" cellspacing="0" style="width:330px; margin:0 auto; border: 1px solid #cccccc; border-collapse: collapse;">
	        	<tbody>
		            <tr>
		              <td align="center" bgcolor="#5a6a7a" style="padding: 40px 0 30px 0; color: #fff; font-size: 28px; text-align:center;">
		                <img src="'.getImage($companyId,150,150).'" width="80">
		                <br>
		                '.$storeName;

		                if($outletName){

			                $template .= '
			                <br>
			                <span style="color: #999; font-size: 14px;">Sucursal: '.$outletName.'</span>
			                ';
			            }

			            $template .= '
		                
		              </td>
		            </tr>';

		            if($URLShare){
			            $template .= '
			            <tr>
			                
			              <td bgcolor="#e8eff0" style="padding: 15px 0; color: #999; font-size: 14px; text-align:center;">
			                <span>Comparte tu experiencia:</span>
			                <br><br>
			                
			                <a href="https://api.addthis.com/oexchange/0.8/forward/facebook/offer?url='.$URLShare.'&pubid=juegosgratisla&ct=1&title='.$tituloShare.'&pco=tbxnj-1.0" target="_blank">
			                  <img src="https://cache.addthiscdn.com/icons/v2/thumbs/32x32/facebook.png" border="0" alt="Facebook"/></a>
			                <a href="https://api.addthis.com/oexchange/0.8/forward/twitter/offer?url='.$URLShare.'&pubid=juegosgratisla&ct=1&title='.$tituloShare.'&pco=tbxnj-1.0" target="_blank"><img src="https://cache.addthiscdn.com/icons/v2/thumbs/32x32/twitter.png" border="0" alt="Twitter"/></a>
			              </td>
			                        
			            </tr>';
		        	}
		            $template .= '
		            <tr>
		              <td bgcolor="#ffffff" style="padding: 20px 15px;">
		                <table border="0" cellpadding="0" cellspacing="0" width="100%">
		                  <tbody>
		                      <tr>
		                        <td style="color: #5a6a7a; text-align:center;">
		                          <span style="font-size:12px; color:#999">Total de la compra:</span>
		                          <br>
		                          <span style="margin:0; font-size: 40px;">'.$total.'</span>
		                          <br><br>
		                        </td>
		                      </tr>
		                      <tr>
		                        <td style=" color: #666; font-size: 16px; line-height: 20px;">
		                          

		                            <table style="width:100%;">
		                              <tbody>

		                                '.$tr.'

		                                <tr style="font-size:13px;">
		                                  <td style="border-bottom:1px dashed #999; padding:10px; text-align:left;">Subtotal: <br> Descuento: <br> '.$taxName.':</td>
		                                  
		                                  <td style="border-bottom:1px dashed #999; padding:10px; text-align:right;">
		                                    '.$subtotal.' <br> '.$discount.' <br> '.$tax.'
		                                  </td>
		                                </tr>
		                                
		                                <tr>
		                                  <td style="border-bottom:1px dashed #999; padding:10px; text-align:left;">Total:</td>
		                                  
		                                  <td style="border-bottom:1px dashed #999; padding:10px; text-align:right;">'.$total.'</td>
		                                </tr>

		                              </tbody>

		                            </table>


		                        </td>
		                      </tr>
		                      
		                  </tbody>

		                </table>
		              </td>
		            </tr>

					<tr>
						<td bgcolor="#e8eff0" style="padding:30px;">
						  <table border="0" cellpadding="0" cellspacing="0" width="100%">
						    <tbody><tr>
						      <td style="color: #999; font-size: 14px; text-align:center;">
						        '.$date.'
						        <br><br>
						        <span style="font-size:12px;">© 2015 '.$storeName.', Todos los derechos reservados</span>
						      </td>
						    </tr>
						  </tbody></table>
						</td>
					</tr>
	        	</tbody>
	        </table>

	      </td>
	    </tr>
	    
	    <tr>
	      <td style="font-size:14px; color:#999; padding:20px 0; text-align:center;">
	        © 2015 Income Point of Sale Paraguay. Todos los derechos reservados.
	        <br>
	        www.encom.app
	      </td>
	    </tr>

	  </tbody>
	  </table>
	</body>
	</html>';
	return $template;
}

function generateTicketTable($array,$currency='',$dec='',$ts='',$taxName='IVA'){

	$z 			= 0;
	$data 		= $array['sale'];
	$length 	= counts($data);
	$total 		= $array['total'];
	$subtotal 	= $array['subtotal'];
	$discount 	= $array['discount'];
	$tax 		= $array['tax'];
	$storeName	= $array['companyName'];
	$companyId	= $array['companyId'];
	$outletName	= $array['outlet'];
	$date		= $array['date'];
	$tr 		= '';
	while($z < $length){
		if($data[$z]['name'] != 'Descuento' && $data[$z]['name'] != 'Discount'){

			$name 	= htmlentities($data[$z]['name']);
			$count 	= $data[$z]['count'];
			$tota 	= formatCurrentNumber($data[$z]['total'],$dec,$ts);

			if($data[$z]['type'] == 'inCombo'){
				$name  	= '<span class="text-muted">' . $name . '</span>';
				$count = '';
				$tota = '';
			}else if($data[$z]['type'] == 'combo'){

			}

			$tr .= '<tr>';
				$tr .= '<td style="border-bottom:1px dashed #999999; padding:10px; text-align:right; width:20%;">' . $count . '</td>';
				$tr .= '<td style="border-bottom:1px dashed #999999; padding:10px; text-align:left;">' . $name . '</td>';
				$tr .= '<td style="border-bottom:1px dashed #999999; padding:10px; text-align:right;">' . $tota . '</td>';
			$tr .= '</tr>';
		}

		$z++;
	}
	
	$template = '<table style="width:100%; font-weight:bold;">
                  <tbody>

                    '.$tr.'

                    <tr style="font-size:13px;">
                      <td></td>
                      <td style="border-bottom:1px dashed #999999; padding:10px; text-align:left;">Subtotal: <br> Descuento: <br> '.$taxName.':</td>
                      <td style="border-bottom:1px dashed #999999; padding:10px; text-align:right;">
                        '.formatCurrentNumber($subtotal,$dec,$ts).' <br> '.formatCurrentNumber($discount,$dec,$ts).' <br> '.formatCurrentNumber($tax,$dec,$ts).'
                      </td>
                    </tr>
                    
                    <tr>
                      <td></td>
                      <td style="border-bottom:1px dashed #999999; padding:10px; text-align:left;">TOTAL:</td>
                      <td style="border-bottom:1px dashed #999999; padding:10px; text-align:right;">' . $currency . formatCurrentNumber($total,$dec,$ts) . '</td>
                    </tr>

                  </tbody>

                </table>';

	return $template;
}

function pdfFile($value,$fileName,$test=false){
	// Set parameters
	$apikey = PDF_API_KEY;
	                                            
	$postdata = http_build_query(
	    array(
	        'apikey' 			=> $apikey,
	        'value' 			=> $value,
	        'MarginBottom' 		=> '0',
	        'MarginTop' 		=> '0',
	        'DisableShrinking' 	=> true,
	        'Zoom' 				=> '1.28'
	    )
	);

	if($test){
		$postdata = $test;
	}
	 
	$opts = array('http' =>
					    array(
					        'method'  => 'POST',
					        'header'  => 'Content-type: application/x-www-form-urlencoded',
					        'content' => $postdata
					    )
					);
	 
	$context  = stream_context_create($opts);
	 
	// Convert the HTML string to a PDF using those parameters
	$result = file_get_contents('https://api.html2pdfrocket.com/pdf', false, $context);
	 
	// Save to root folder in website
	file_put_contents(SYSFILES_FOLDER.'/'.$fileName, $result);

	return SYSFILES_URL.'/'.$fileName;
}

function insertEmptySchedule($data){
	global $db;
	$record                           	= [];

	if($data['details']){
		$record['transactionDetails']   = $data['details'];
	}

	if($data['date']){
		$record['transactionDate']      = $data['date'];
	}

	if(isset($data['from'])){
		$record['fromDate']      		= $data['from'];
	}

	if(isset($data['to'])){
		$record['toDate']      			= $data['to'];
	}

	if($data['price']){
		$record['transactionTotal']     = $data['price'];
	}

	if($data['parent']){
		$record['transactionParentId']  = $data['parent'];
	}

	if(isset($data['status'])){
		$record['transactionStatus']  	= $data['status'];
	}else{
		$record['transactionStatus']    = 0;
	}

	$record['transactionType']        	= 13;

	if($data['invoice']){
		$record['invoiceNo']    		= $data['invoice'];
	}

	if($data['prefix']){
		$record['invoicePrefix']    	= $data['prefix'];
	}

	if($data['customerId']){
		$record['customerId']           = $data['customerId'];
	}

	if($data['packageId']){
		$record['packageId']             = $data['packageId'];
	}

	if($data['registerId']){
		$record['registerId']           = $data['registerId'];
	}

	if($data['userId']){
		$record['userId']               = $data['userId'];
	}

	if($data['outletId']){
		$record['outletId']             = $data['outletId'];
	}

	if($data['companyId']){
		$record['companyId']            = $data['companyId'];
	}

	$insertTransaction                	= $db->AutoExecute('transaction', $record, 'INSERT');

	return $insertTransaction;
}

function loginPart($result){
	global $db;
	$fields 	= $result->fields;
	$company 	= ncmExecute("SELECT
										companyStatus,
										companyPlan
									FROM company
									WHERE
										companyId = ? LIMIT 1"[$fields['companyId']]);

	if($company['companyStatus'] != 'Active'){
		return 'Cuenta inhabilitada, por favor contactenos al correo info@encom.app';
	}

	$outlet 	= ncmExecute("SELECT
									outletId
								FROM outlet
								WHERE
									companyId = ? LIMIT 1",[$fields['companyId']]);

	$register 	= ncmExecute("SELECT
									registerId
								FROM register
								WHERE
									outletId = ? LIMIT 1",[$outlet['outletId']]);

	
	// Here I am preparing to store the $row array into the $_SESSION by
	// removing the salt and password values from it.  Although $_SESSION is
	// stored on the server-side, there is no reason to store sensitive values
	// in it unless you have to.  Thus, it is best practice to remove these
	// sensitive values first.
	unset($fields['salt'],$fields['userPassword']);

	$_SESSION['last_activity'] 			= time();
	$_SESSION['user']['companyId']  	= enc($fields['companyId']);
	$_SESSION['user']['companyStatus']  = $company['companyStatus'];
	$_SESSION['user']['userId']  		= enc($fields['userId']);
	$_SESSION['user']['userName']  		= $fields['userName'];
	$_SESSION['user']['role']  			= enc($fields['role']);
	$_SESSION['user']['outletId'] 		= ($fields['role'] > 1) ? enc($fields['outletId']) : enc('1');
	$_SESSION['user']['registerId'] 	= ($fields['role'] > 1) ? enc($fields['registerId']) : enc('1');
	$_SESSION['user']['plan'] 			= enc($company['companyPlan']);

	return 'true';
}

function getRolePermissions($roleId,$companyId){
	global $_ROLES_DATA;

	$index 		= ncmExecute("SELECT sourceId FROM taxonomy WHERE taxonomyType = 'role' AND taxonomyExtra = ? LIMIT 1",[$roleId],true);
	$saved 		= ncmExecute("SELECT taxonomyExtra FROM taxonomy WHERE taxonomyType = 'roleData' AND sourceId = ? AND companyId = ? LIMIT 1",[$index['sourceId'],$companyId]);
	$roleSelected = "";
	if($saved){
		$roleSelected = json_decode($saved['taxonomyExtra'],true);
	}else if(!empty($_ROLES_DATA[$index['sourceId']])){
		$roleSelected = $_ROLES_DATA[$index['sourceId']];
	}
	return $roleSelected;
}

function allowUser($section,$action,$boolean=false){
	if(COMPANY_ID == 10){
		if($section && $action && !validateHttp('widget')){
			$permissions = getRolePermissions(ROLE_ID,COMPANY_ID);
			$permissions = $permissions['register'];
			$check 		 = $permissions[$section][$action];

			if(in_array($section, ['sales','expenses'])){
				$check 		 = $permissions['reports'][$section][$action];
			}

			if(!$check){
				if($boolean){
					return false;
				}else{
					include_once("a_stand_by_page.php");
					dai();
				}
			}else{
				if($boolean){
					return true;
				}
			}
		}
	}else{
		return true;
	}
}

function getAllCompanyItemsChildren($companyId, $itemsIds = false){

	$itemsIds 	= ($itemsIds) ? ' AND itemId IN (' . $itemsIds . ')' : '';
	$result		= ncmExecute("SELECT itemId, itemParentId FROM item WHERE itemParentId > 0" . $itemsIds . " AND companyId = ?",[$companyId],false,true);

	$out 		= [];
	$child 		= [];

	if($result){
		while(!$result->EOF) {
			$fields = $result->fields;
			$pId 	= enc($fields['itemParentId']);
			$iId 	= enc($fields['itemId']);

			if(array_key_exists($pId, $out)){
				$out[$pId] = $out[$pId] . ',' . $iId;
			}else{
				$out[$pId] = $iId;
			}
		
			$result->MoveNext(); 
		}
		$result->Close();
	}

	return $out;

}

function getAllItemCategories($companyId){
	global $db;
	//GET ALL CATEGORIES ARRAY
	$a 		= [];
	$result = ncmExecute("SELECT taxonomyId,taxonomyName, CAST(taxonomyExtra as UNSIGNED) as sort FROM taxonomy WHERE taxonomyType = ? AND companyId = ? ORDER BY sort ASC LIMIT 500",['category',$companyId],false,true);

	if($result){
		while (!$result->EOF) {
		    $a[enc($result->fields['taxonomyId'])] = 	[
															"name" => $result->fields['taxonomyName'],
															"sort" => (int) !array_key_exists("taxonomyExtra",$result->fields) ? 0 : $result->fields['taxonomyExtra']
														];
		    $result->MoveNext(); 
		}
		$result->Close();
	}
	
	return $a;
}

function getAllPriceList($companyId,$itemsIds=false){
	global $db;
	
	$itemsIds = ($itemsIds)?' AND itemId IN ('.$itemsIds.')':'';
	$a = array();
	$result = $db->Execute('SELECT * FROM priceList WHERE companyId = ?'.$itemsIds,array($companyId));
	while (!$result->EOF) {
	    $a[$result->fields['itemId']] = array(
            									"price"=>$result->fields['priceListPrice']
            								 );
	    $result->MoveNext(); 
	}
	$result->Close();
	//
	return $a;
}

function getAllWasteValue($id=false,$cache=false){
	$andId 		= ' LIMIT 500';

	if($id){
		$andId 	= ' AND itemId = ' . $id . ' LIMIT 1';
	}

	$sql 		= 'SELECT itemWaste, itemId FROM item WHERE itemWaste > 0 AND companyId = ? ' . $andId;
	$result 	= ncmExecute($sql,[COMPANY_ID],$cache,true);
	$out 		= [];

	if($result){
		while (!$result->EOF) {
			$fields 				= $result->fields;
			$out[$fields['itemId']] = $fields['itemWaste'];
		    $result->MoveNext(); 
		}

		$result->Close();
	}

	return $out;
}

function getNeedWithWaste($need,$wasteP){
	$wasteFactor 	= $wasteP / 100;
	$wasteValue 	= $need * $wasteFactor;
	return $need + $wasteValue;
}

/*function sendEmail($to,$subject,$body,$altbody,$from = 'info@encom.app',$smtp=true){
	//Create a new PHPMailer instance
	$mail = new PHPMailer;

	if($smtp){
		$mail->isSMTP();                                      // Set mailer to use SMTP
		$mail->Host 		= 'smtp.sendgrid.net';                    // Specify server
		$mail->SMTPAuth 	= true;                               // Enable SMTP authentication
		$mail->Username 	= 'incomeregister';           // SMTP username
		$mail->Password 	= 'Holasendgrid1!';           // SMTP password
		$mail->Port 		= 587;                                    // Recommended Port
		$mail->SMTPSecure 	= 'tls';
	}

	//Set who the message is to be sent from
	$mail->setFrom($from, 'Income Register');
	//Set an alternative reply-to address
	$mail->addReplyTo($from, 'Income Register');
	//Set who the message is to be sent to
	$mail->addAddress($to);
	//Set the subject line
	$mail->Subject = utf8_decode($subject);
	//Read an HTML message body from an external file, convert referenced images to embedded,
	//convert HTML into a basic plain-text alternative body
	$mail->msgHTML($body);
	//Replace the plain text body with one created manually
	//aca pasar un link con la fecha de compra y el register ID para evitar sale clash a un script que va a volver a build the bill
	$mail->AltBody = $altbody;

	//send the message, check for errors
	if (!$mail->send()){
	    //echo "Mailer Error: " . $mail->ErrorInfo;
	    return $mail->ErrorInfo;
	} else {
	    //echo "Message sent!";
	    return true;
	}
}*/

function sendSMTPEmail($meta,$template,$to,$subject,$body='ENCOM',$altbody='ENCOM'){

	if(!validity($to,'email')){
		return false;
	}

	$fromName 	= 'ENCOM';
	$replayTo 	= iftn(OUTLET_EMAIL,'info@encom.app');
	$from 		= 'info@encom.com.py';

	include_once("libraries/phpmailer/PHPMailerAutoload.php");

	//Create a new PHPMailer instance
	$mail = new PHPMailer;

	$options = json_encode(
					array(
	                    "to" 		=> array($to),
	                    "sub" 		=> $meta,
	                    "filters" 	=> array(
				                              "templates" 	=> array(
								                                        "settings" 	=> array(
											                                                  "enable" 	=> 1,
											                                                  "template_id" => $template
											                                                )
								                                      )
				                            )
                    	)
					);

	$mail->isSMTP();                            // Set mailer to use SMTP
	$mail->Host 		= 'smtp.sendgrid.net';  // Specify server
	$mail->SMTPAuth 	= true;                 // Enable SMTP authentication
	$mail->Username 	= 'incomeregister';     // SMTP username
	$mail->Password 	= 'Holasendgrid1!';     // SMTP password
	$mail->Port 		= 587;                  // Recommended Port
	$mail->SMTPSecure 	= 'tls';	
	
	$mail->setFrom($from, $fromName);
	$mail->addReplyTo($replayTo, $fromName);
	$mail->addAddress($to);

	$mail->isHTML(true);
	$mail->msgHTML($body);
	$mail->addCustomHeader("X-SMTPAPI: " . $options);
	$mail->addCustomHeader('MIME-Version: 1.0');
	$mail->addCustomHeader('Content-Type: text/html; charset=utf-8');

	$mail->Subject = utf8_decode($subject);
	$mail->Body 	= $altbody;
	//$mail->AltBody 	= $altbody;

	if (!$mail->send()){
	    return $mail->ErrorInfo;
	} else {
	    return true;
	}
}

function sendEmails($options){
  $from     = iftn($options['from'] ?? "","info@encom.com.py");
  $fromName = iftn($options['fromName'] ?? "","ENCOM");
  $to       = $options['to'];
  $subject  = $options['subject'];
  $data     = $options['data']['message'] ?? '';
//   $template = iftn($options['template'] ?? "","d-02e7f867251d4383af26d8c9cc2b4318");
//   $options['data']['subject'] = $subject;
//  $data     = json_encode($options['data'] ?? []); //paso php array y convierto a json
//   $apiKey   = iftn(SENDGRID_API_KEY ?? "","");

//   $data   = '{' .
//             ' "from":{' .
//             '   "email":"' . $from . '",' .
//             '	"name":"' . $fromName . '"' .
//             ' },' .
//             ' "personalizations":[{' .
//             '   "to":[' .
//             '         { "email":"' . $to . '"}' .
//             '        ],' .
//             '   "dynamic_template_data":' . $data .
//             ' }],'.
//             ' "template_id":"' . $template . '"' .
//             '}';

//   $header =   [
//                 "Accept: application/json",
//                 "Authorization: Bearer " . $apiKey,
//                 "Content-Type: application/json"
//               ];

   //return curlContents('https://api.sendgrid.com/v3/mail/send','POST',$data,$header);

   // Envio de correo con Mailgun
   $mgClient = MailgunClient::create(MAILGUN_TOKEN);
   $domain = "encom.com.py";

   # Make the call to the client.			
   try {
	   $resultMail = $mgClient->messages()->send($domain, [
		   'from'    => $fromName .'<' . $from . '>',
		   'to'      => $to,
		   'subject' => toUTF8($subject),
		   'html'    => toUTF8($data)
	   ]);

	   // Verificar el estado del envío
	   if ($resultMail->getId()) {
		   //error_log("Correo enviado exitosamente. ID: " . $resultMail->getId(), 3, './error_log');
		   return true;
	   } else {
		   //error_log("No se pudo enviar el correo.", 3, './error_log');
		   return "No se pudo enviar el correo.";
	   }
   } catch (\Exception $e) {
	   // Manejo de errores
	   //error_log("Error al enviar el correo: " . $e->getMessage(), 3, './error_log');
	   return "Error al enviar el correo: " . $e->getMessage();
   }
}

function sendEmail($options){
	global $db;

	$data =   [
	            'api_key'       => API_KEY,
	            'company_id'    => enc(COMPANY_ID),
	            'fromName'     	=> $options['fromName'],
	            'to'       			=> $options['to'],
	            'subject'    		=> $options['subject'],
	            'mode'       		=> 'notify',
	            'autoSend' 			=> $options['auto'],
	            'secret' 				=> NCM_SECRET
	          ];

	$out = curlContents('https://api.encom.app/send_email','POST',$data);
	
	return $out;
}

function sendSMS($number,$msg,$numvalidation=true,$auto=false){
	global $db;

	$data =   [
	            'api_key'       => API_KEY,
	            'company_id'    => enc(COMPANY_ID),
	            'phone'         => $number,
	            'country'       => COUNTRY_CODE,
	            'msg'       		=> $msg,
	            'credit'       	=> SMS_CREDIT,
	            'autoSend' 			=> $auto,
	            'secret' 				=> NCM_SECRET
	          ];

	$out = curlContents('https://api.encom.app/send_sms','POST',$data);
	
	return $out;
}

function sendPush($options){

	$companyId = !empty($options['companyId']) ? $options['companyId'] : null;//para el api auth
	$options['where'] = $options['where'] ? $options['where'] : 'caja';

	$data = [
						"api_key"       => API_KEY,
						"company_id"    => iftn($companyId,enc(COMPANY_ID)),
						"secret" 				=> NCM_SECRET,
						"ids"       		=> $options['ids'],
					  "message"    		=> $options['message'],
					  "where"      		=> $options['where'],
					  "title"      		=> $options['title'],
					  "web_url"     	=> $options['web_url'] ?? "",
					  "app_url"     	=> $options['app_url'] ?? "",
					  "filters"   		=> json_encode($options['filters'])
					];

	return json_decode( curlContents(API_ENCOM_URL.'/send_push', 'POST', $data) );
}

function sendNCMSMS($number,$msg,$country,$companyId=''){
	$sent 		= false;

	$data =   [
	            'api_key'       => '340f3033a868ce57b9300f6e1e3732e272639bdf',
	            'company_id'    => 'Og',
	            'phone'         => $number,
	            'country'       => $country,
	            'msg'       	=> $msg,
	            'credit'       	=> 100,
	            'secret' 		=> NCM_SECRET
	          ];
		
	$sent = curlContents('https://api.encom.app/send_sms','POST',$data);

	return [$sent,$number];
}

function SMSSegmentsCounter($str){
	if(validity($str,'string')){
		//$str 		= mb_convert_encoding($str,'UCS-2LE');//convierto a encoding ucs2, version latina de encoding
		//Como el server no soporta mb_convert_encoding multipllico el output por 2 ya que al convertir a ucs2 es el doble de largo de un SMS normal
		$charln 	= 160;
		$length 	= counts($str) * 1.2;
		$segments 	= divider($length,$charln,true,'up');
		return $segments;
	}else{
		return 0;
	}
}

function getPhoneFormat($number,$countryCode=false,$returnField='phone_number'){
	if(!validity($number)){
		return '';
	}
	$countryCode 	= iftn($countryCode,COUNTRY_CODE);
	$validate 		= curlContents('https://' . TWILIO_SID . ':' . TWILIO_AUTH_TOKEN . '@lookups.twilio.com/v1/PhoneNumbers/' . $number . '?CountryCode=' . $countryCode);
	
	if(!validity($validate)){
		return false;
	}

	$valid 			= json_decode($validate,true);
	$international	= $valid[$returnField];//formato internacional

	return iftn($international,'');

	/*
	$isLandLine 	= json_decode(curlContents('http://apilayer.net/api/validate?access_key=' . API_LAYER_KEY . '&number=' . $number . '&country_code=' . $countryCode),true);

	if($isLandLineDec['success'] != 'false' && $isLandLineDec['valid'] == 'true'){
		if($isLandLineDec['line_type'] != 'mobile'){
			return false;
		}else{
			return iftn($international,'');
		}
	}else{
		return iftn($international,'');
	}
	*/
	//
}

function makeEmailActionBtn($url,$txt){
	return 	'<div style="text-align:center;padding:10px">' .
            ' <a href="' . $url . '" style="color:white;background-color:#4CB6CB;padding:13px 25px;text-decoration:none;text-transform: uppercase;font-family:Arial;font-size:0.9em;border-radius:100px;font-weight:bold;">' .
            	$txt . 
            ' </a>' .
            '</div>';
}

function addWhatsAppLink($text=false,$sms=true){
	$add = '';
	if(OUTLET_WHATS_APP){

		if($text){
			$add = 'WA: ';
		}

		if($sms){
			$add = '\n' . $add;
		}

		if(OUTLET_WHATS_APP){
			return $add . ' https://wa.me/' . OUTLET_WHATS_APP;
		}else{
			return '';
		}
		
	}
}

function sendWS($ops = []){
	$channel 	= $ops['channel'];
	$event 		= $ops['event'];
	$message 	= $ops['message'];
 
	$data 		=   [
		              'api_key'       => API_KEY,
		              'company_id'    => enc(COMPANY_ID),
		              'channel'       => $channel,
		              'event'         => $event,
		              'message'       => $message
		            ];

  $result = curlContents('https://api.encom.app/send_webSocket.php','POST',$data);

  return $result;
}

function curlContents($url, $method = 'GET', $data = false, $headers = false, $returnInfo = false, $spoofRef = false, $timeout = 30) {    
    $ch = curl_init();
    
    if($method == 'POST') {
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        if($data !== false) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    } else {
        if($data !== false) {
            if(is_array($data)) {
                $dataTokens = array();
                foreach($data as $key => $value) {
                    array_push( $dataTokens, urlencode($key) . '=' . urlencode($value) );
                }
                $data = implode('&', $dataTokens);
            }
            curl_setopt($ch, CURLOPT_URL, $url . '?' . $data);
        } else {
            curl_setopt($ch, CURLOPT_URL, $url);
        }
    }

    if($spoofRef){
		curl_setopt($ch, CURLOPT_REFERER, $url);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    }

    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    if($headers !== false) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $contents = curl_exec($ch);

    if($returnInfo) {
        $info = curl_getinfo($ch);
    }

    curl_close($ch);

    if($returnInfo) {
        return array('contents' => $contents, 'info' => $info);
    } else {
        return $contents;
    }
}

function sendFE($data, $token){
	$curl = curl_init();

	curl_setopt_array($curl, array(
		CURLOPT_URL 						=> FACTURACION_ELECTRONICA_URL.'/api/transaction',
		CURLOPT_RETURNTRANSFER 				=> true,
		CURLOPT_ENCODING 					=> '',
		CURLOPT_MAXREDIRS 					=> 10,
		CURLOPT_TIMEOUT 					=> 0,
		CURLOPT_FOLLOWLOCATION 				=> true,
		CURLOPT_HTTP_VERSION 				=> CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST 				=> 'POST',
		CURLOPT_POSTFIELDS 					=> json_encode($data),
		CURLOPT_HTTPHEADER 					=> [
													'Content-Type: application/json',
													'Authorization: Bearer ' . $token
												]
	));

	$response = curl_exec($curl);
	curl_close($curl);
	return $response;
}

function sendAuditoria($data, $token){
	$curl = curl_init();

	curl_setopt_array($curl, array(
		CURLOPT_URL 						=> AUDITORIA_URL.'/api/auditoria',
		CURLOPT_RETURNTRANSFER 				=> true,
		CURLOPT_ENCODING 					=> '',
		CURLOPT_MAXREDIRS 					=> 10,
		CURLOPT_TIMEOUT 					=> 0,
		CURLOPT_FOLLOWLOCATION 				=> true,
		CURLOPT_HTTP_VERSION 				=> CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST 				=> 'POST',
		CURLOPT_POSTFIELDS 					=> json_encode($data),
		CURLOPT_HTTPHEADER 					=> [
													'Content-Type: application/json',
													'Authorization: Bearer ' . $token
												]
	));

	$response = curl_exec($curl);
	curl_close($curl);
	return $response;
}

function consultFE($data, $token){
	$curl = curl_init();

	curl_setopt_array($curl, array(
		CURLOPT_URL 						=> FACTURACION_ELECTRONICA_URL.'/api/companies/documents',
		CURLOPT_RETURNTRANSFER 				=> true,
		CURLOPT_ENCODING 					=> '',
		CURLOPT_MAXREDIRS 					=> 10,
		CURLOPT_TIMEOUT 					=> 0,
		CURLOPT_FOLLOWLOCATION 				=> true,
		CURLOPT_HTTP_VERSION 				=> CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST 				=> 'POST',
		CURLOPT_POSTFIELDS 					=> json_encode($data),
		CURLOPT_HTTPHEADER 					=> [
													'Content-Type: application/json',
													'Authorization: Bearer ' . $token
												]
	));

	$response = curl_exec($curl);
	curl_close($curl);
	return $response;
}

function getFileContent($url){//usar solo con urls propias y controladas por encom
	$ops = 	[
							    "ssl" => [
									        "verify_peer" 		=> false,
									        "verify_peer_name" 	=> false,
									    ],
								'http' => [
											'header' 			=> 
											'Cookie: ' . $_SERVER['HTTP_COOKIE'] . "\r\n"
										]
							];  

	return file_get_contents($url, false, stream_context_create($ops));
}

function getValidPhone($phone,$country=COUNTRY_CODE,$format=false){
    $phone      = preg_replace("/[^0-9]/", "", $phone);
    $format     = ($format) ? $format : 'international';
    $valid      = json_decode(curlContents('https://api.encom.app/phonevalidator.php?phone=' . $phone . '&country=' . $country . '&format=' . $format),true);

    return $valid;
}

function gCaptcha($gtoken){
    if(!$gtoken){
        jsonDieResult(['error'=>'gtoken not found'],404);
    }

    $captchaData   =   [
                        'secret'    => '6LfDSOoUAAAAAGLDr3dCnIri_YrtF7J-u2JBhzZF',
                        'response'  => $gtoken,
                        'remoteip'  => getUserIpAddr()
                        ];

    $captcha   = json_decode(curlContents('https://www.google.com/recaptcha/api/siteverify','POST',$captchaData),true);

    if(!$captcha['success']){
        jsonDieResult(['error'=>'invalid gtoken',$captcha],500);
    }
}

function getUserIpAddr(){
    if(!empty($_SERVER['HTTP_CLIENT_IP'])){
        //ip from share internet
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    }elseif(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])){
        //ip pass from proxy
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }else{
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

function signUp($post,$login = true){
	global $db,$countries;

	$doLet = false; //esta bar dice si se puede registrar o no los datos ingresados

	$email 		= strtolower($post['email']);
	$storeName 	= ucwords($post['storename']);
	$userName 	= ($post['username'])?ucwords($post['username']):'Usuario Principal';
	
	$resultEmail = $db->Execute("SELECT
								*
							FROM contact
							WHERE
								type = 0
							AND
								contactEmail = ?",array($email));
	
	$resultCompany = $db->Execute("SELECT
								*
							FROM company
							WHERE
								companyName = ?",array($storeName));
	
	if($resultEmail->fields['contactEmail'] == $email){
		return '{"error":"Ya existe una cuenta con esta dirección de correo"}';
		$doLet = false;
	}else if(strtolower($resultCompany->fields['companyName']) == strtolower($storeName)){
		return '{"error":"Ya existe una empresa con este nombre"}';
		$doLet = false;
	}else{
		$doLet = true;
	}


	if($doLet == true){

		$db->StartTrans();
		
		$accountId 		= rand(); //por ahora uso un numero random nomas como Account ID hasta que haga la DB de administracion de cuentas
		$companyRecord 	= array();
		$outletRecord 	= array();
		$registerRecord = array();
		$billRecord 	= array();
		$settingRecord 	= array();
		$userRecord 	= array();
		$itemRecord 	= array();
		//
		$companyRecord['companyName'] 		= $storeName;
		$companyRecord['companyPlan'] 		= 3;
		$companyRecord['companyStatus'] 	= 'Active';
		$companyRecord['companyExpiringDate'] 	= date('Y-m-d 00:00:00', strtotime("+14 days"));
		$companyRecord['accountId'] 		= $accountId;
		
		$companyInsert = $db->AutoExecute('company', $companyRecord, 'INSERT'); 
		$company = $db->Insert_ID();
		
		//
		$outletRecord['outletName'] 	= 'Central';
		$outletRecord['outletStatus'] 	= 1;
		$outletRecord['companyId'] 		= $company;
		//$outletRecord['outletTax'] 		= 0;

		$outletInsert = $db->AutoExecute('outlet', $outletRecord, 'INSERT'); 
		$outlet = $db->Insert_ID();
		
		 //
		$registerRecord['registerName']     = 'Caja Principal';
		$registerRecord['registerStatus']   = 1;
		$registerRecord['outletId']         = $outlet;
		$registerRecord['companyId']        = $company;

		$registerInsert = $db->AutoExecute('register', $registerRecord, 'INSERT');
		$register = $db->Insert_ID();

		//
		$cSymbol 	= $countries[$post['country']]['currency']['symbol'];
		$lang 		= explode(',',$countries[$post['country']]['languages']);
		$decim 		= ($countries[$post['country']]['currency']['decimal_digits']<1)?'no':'yes';
		$taxName 	= $countries[$post['country']]['currency']['vat_name'];
		$tin 		= $countries[$post['country']]['tin'];

		$settingRecord['settingName']           = $storeName;
		$settingRecord['settingCurrency']       = iftn($cSymbol,'$');
		$settingRecord['settingCountry']        = $post['country'];
		$settingRecord['settingLanguage']       = iftn($lang[0],'es');
		$settingRecord['settingTimeZone']       = 'America/Asuncion';
		$settingRecord['settingAcceptedTerms']  = 1;

		$settingRecord['settingBillTemplate']   	= 'ticket';
		$settingRecord['settingDecimal']        	= $decim;
		$settingRecord['settingThousandSeparator']  = 'dot';
		$settingRecord['settingTaxName']        	= iftn($taxName,'VAT');
		$settingRecord['settingTIN']        		= iftn($tin,'TIN');
		$settingRecord['settingCompanyCategoryId'] 	= $post['category'];

		$settingRecord['companyId']             = $company;
		
		$settingInsert = $db->AutoExecute('setting', $settingRecord, 'INSERT');

		$vat 		= iftn($countries[$post['country']]['currency']['vat'], false);
		if($vat){
			$taxonomyRecord['taxonomyName']	= $vat;
			$taxonomyRecord['taxonomyType']	= 'tax';
			$taxonomyRecord['companyId'] 	= $company;
			$taxonomyInsert = $db->AutoExecute('taxonomy', $taxonomyRecord, 'INSERT');
		}

		//
		$itemRecord['itemName'] 		= 'Artículo de Prueba';
		$itemRecord['itemSKU'] 			= "";
		$itemRecord['itemStatus'] 		= 1;
		$itemRecord['itemImage'] 		= 'false';
		$itemRecord['itemPrice'] 		= 50;

		$itemRecord['companyId'] 		= $company;
		
		$itemInsert = $db->AutoExecute('item', $itemRecord, 'INSERT'); 
		if($itemInsert === false){}else{
			$itemId = $db->Insert_ID();
		}
		
		if($outletInsert && $companyInsert && $registerInsert && $settingInsert){
				$pasSalt = passEncoder($post['password']);
				
				// Here we prepare our tokens for insertion into the SQL query.  We do not
				// store the original password; only the hashed version of it.  We do store
				// the salt (in its plaintext form; this is not a security risk).
				$userRecord['contactName']   	= $userName;
		        $userRecord['contactPassword'] = $pasSalt[0];
		        $userRecord['contactEmail']  	= $email;
		        
		        $userRecord['companyId']  	= $company;
		        $userRecord['outletId']  	= $outlet;
		        $userRecord['main']     	= 'true';
		        $userRecord['role']     	= 1; //1 = Super Admin
		        $userRecord['salt']     	= $pasSalt[1];
		        $userRecord['lockPass'] 	= '1111';
		        $userRecord['type'] 	= '0';
				
				$userInsert = $db->AutoExecute('contact', $userRecord, 'INSERT');

				$failedTransaction = $db->HasFailedTrans();
				$db->CompleteTrans();
				
				if(!$failedTransaction){
					//sendEmail($post['email'],'Su registro en Income Register',$userregistertemplate,'');
					
					if($login){
						$result = $db->Execute("SELECT
													*
												FROM contact
												WHERE
												type = 0
												AND
													contactEmail = ? LIMIT 1",array($email));
						return loginPart($result);

					}else{
						return 'true';
					}
					
					
				}else{
					return $db->ErrorMsg();
					return 'false';
				}
		}else{
			return $db->ErrorMsg();
			return 'false';
		}
	}
}

function jsonDieMsg($msg='true',$code=401,$type='error'){
	http_response_code($code);
	header('Content-Type: application/json');
    die(json_encode([$type=>$msg]));
}

function jsonDieResult($array,$code=200){
	http_response_code($code);
	header('Content-Type: application/json');
    die(json_encode($array));
}

function implodes($str,$array,$returnEmpty=false){
	if(is_array($array) && validity($array)){
		return implode($str,$array);
	}else{
		if($returnEmpty){
			return '';
		}else{
			return false;
		}
	}
}

function isHTML($string){
 return ( $string != strip_tags($string) ) ? true : false;
}

function markupt2HTML($options){
	if(is_array($options)){
		$text = $options['text'] ? $options['text'] : '';
		$type = $options['type'];//HtMor MtH
	}else{
		$text = $options;
		$type = false;
	}
	

	if(!$type){// si no especifico el tipo detecto
		if(isHTML($text)){
			$type = 'HtM';
		}else{
			$type = 'MtH';
		}
	}

	$HtMrules = [
		["find" => '<br>', "replace" 	=> '\n'],
		["find" => '<br/>', "replace" 	=> '\n'],
		["find" => '<br />', "replace" 	=> '\n'],
		["find" => '<b>', "replace" 	=> '*'],
		["find" => '</b>', "replace" 	=> '*'],
		["find" => '<strong>', "replace" => '*'],
		["find" => '</strong>', "replace" => '*'],
		["find" => '<em>', "replace" 	=> '_'],
		["find" => '</em>', "replace" 	=> '_'],
		["find" => '<i>', "replace" 	=> '_'],
		["find" => '</i>', "replace" 	=> '_'],
		["find" => '</i>', "replace" 	=> '_'],
		["find" => '<li>', "replace" 	=> '- '],
		["find" => '</li>', "replace" 	=> ''],
		["find" => '<u>', "replace" 	=> '~'],
		["find" => '</u>', "replace" 	=> '~'],
		["find" => '&nbsp;&nbsp;•&nbsp;', "replace" => '- '],
		["find" => '<div>', "replace" 	=> '\n'],
		["find" => '</div>', "replace" 	=> ''],
		["find" => '<p>', "replace" 	=> '\n'],
		["find" => '</p>', "replace" 	=> '']
	];

	$MtHrules = [
	    ["find" => '/\*(.*?)\*/', "replace" => '<strong>$1</strong>'],
	    ["find" => '/\_(.*?)\_/', "replace" => '<em>$1</em>'],
	    ["find" => '/\~(.*?)\~/', "replace" => '<u>$1</u>'],
	    ["find" => '/\- (.*?)/', "replace" => '<br>&nbsp;&nbsp;•&nbsp; $1 &nbsp;'],
	    ["find" => '/\```(.*?)\```/', "replace" => '<pre>$1</pre>']
	];

	if($type == 'HtM'){
		foreach($HtMrules as $rule){
		  	$texts 	= explode($rule['find'], $text); //text.split(rule.find).join(rule.replace);
		  	$text 	= implode($rule['replace'], $texts);
		}

		$text = strip_tags($text);
	}else{
		$text = strip_tags($text);
		
		$text = explode('\n', $text);
		$text = implode('<br>', $text);
		$text = explode('\r', $text);
		$text = implode('<br>', $text);

		$text = str_replace(['\n','\r'],['<br>','<br>'],$text);

		$text = nl2br($text);
        
		foreach($MtHrules as $rule){
			$text = preg_replace($rule['find'], $rule['replace'], $text);//text.replace(rule.find, rule.replace);
		}

	} 

	return $text;
}

function dai($val = '',$noclose = false){
	global $db;
	if(!$noclose && $db){
		$db->Close();
	}
	die($val);
	exit();
}

function confirmationEmail($email,$template){
	$email = $data['sendmail'];

	//Create a new PHPMailer instance
	$mail = new PHPMailer;
	//Set who the message is to be sent from
	$mail->setFrom('c.murphy@encom.app', 'ENCOM');
	//Set an alternative reply-to address
	$mail->addReplyTo('c.murphy@encom.app', 'ENCOM');
	//Set who the message is to be sent to
	$mail->addAddress($email);
	//Set the subject line
	$mail->Subject = 'Su cuenta en ENCOM';
	//Read an HTML message body from an external file, convert referenced images to embedded,
	//convert HTML into a basic plain-text alternative body
	$mail->msgHTML();
	//Replace the plain text body with one created manually
	//aca pasar un link con la fecha de compra y el register ID para evitar sale clash a un script que va a volver a build the bill
	$r = $_GET['registerId'];
	$c = $_GET['companyId'];
	$d = $email['date'];

	

	$mail->AltBody = "¡Muchas Gracias! puede ver su ticket en ".$shorturl." \n".$email['companyName'];

	//send the message, check for errors
	if (!$mail->send()) {
	    //echo 'false '.$mail->ErrorInfo;
	    return false;
	} else {
	    //echo 'true';
	    return true;
	}
}

function bigNumber($length = 10) {
    $output = rand(1,9);
    for($i=0; $i<$length; $i++) {
        $output .= rand(0,9);
    }

    return $output;
}

function passEncoder($pass){
	// A salt is randomly generated here to protect again brute force attacks
	// and rainbow table attacks.  The following statement generates a hex
	// representation of an 8 byte salt.  Representing this in hex provides
	// no additional security, but makes it easier for humans to read.
	// For more information:
	// http://en.wikipedia.org/wiki/Salt_%28cryptography%29
	// http://en.wikipedia.org/wiki/Brute-force_attack
	// http://en.wikipedia.org/wiki/Rainbow_table
	$salt = dechex(mt_rand(0, SALT)) . dechex(mt_rand(0, SALT));

	// This hashes the password with the salt so that it can be stored securely
	// in your database.  The output of this next statement is a 64 byte hex
	// string representing the 32 byte sha256 hash of the password.  The original
	// password cannot be recovered from the hash.  For more information:
	// http://en.wikipedia.org/wiki/Cryptographic_hash_function
	$password = hash('sha256', $pass . $salt);

	// Next we hash the hash value 65536 more times.  The purpose of this is to
	// protect against brute force attacks.  Now an attacker must compute the hash 65537
	// times for each guess they make against a password, whereas if the password
	// were hashed only once the attacker would have been able to make 65537 different 
	// guesses in the same amount of time instead of only one.
	for($round = 0; $round < HASH_TIMES; $round++){
		$password = hash('sha256', $password . $salt);
	}

	return array($password,$salt);
}

function passBuilder($pass,$salt,$hashTimes = HASH_TIMES){
    $check_password   = hash('sha256', $pass . $salt);

    for($round = 0; $round < $hashTimes; $round++){
      $check_password = hash('sha256', $check_password . $salt);
    }

    return $check_password;
}

function findEmailOrPhoneLogin($email){
	$result = ncmExecute("SELECT
                          *
                        FROM contact
                        WHERE contactEmail = ?
                        AND role = 1
                        AND type = 0
                        LIMIT 1",[$email]);

	if(!$result){
		$result = ncmExecute("SELECT
	                      *
	                    FROM contact
	                    WHERE contactPhone = ? 
	                    AND role = 1
	                    AND type = 0 
	                    LIMIT 1",[$email]);
	}

	return $result;
}

function isBase64Decode($str){
	$out = $str;
	if(preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $str)){
		$out = html_entity_decode( base64_decode($str) );
	}
	return $out;
}

function acceptCompanyPayment($amount,$companyId){//company ID seria el ID del cliente de Income
	global $db,$meses;

    $update 	= $db->Execute('UPDATE company SET companyBalance = companyBalance + ' . $amount . ' WHERE companyId = ?',array($companyId));
    if($update){
    	$m 		 = date('m');
    	$month	 = $meses[$m-1];

    	$email 	= getValue('contact', 'contactEmail', 'WHERE main = "true" AND type = 0 AND companyId = '.$companyId);
    	$meta 	= array(
							":previousmonth" 	=> array($month),
							":total" 			=> array('$'.$amount)
						);

		sendSMTPEmail($meta,"9f8ce200-803d-46c4-847b-5f9c162db288",$email,'Pago procesado exitosamente','ENCOM','ENCOM');

		return true;
    }else{
    	return false;
    }
    
}

function mailSaleBackUp($sale,$companyId,$outletId,$date,$customerId='',$user='',$registerId='',$where=''){
	
  require_once('libraries/phpmailer/class.phpmailer.php');

  $mail = new PHPMailer;
  
  $mail->setFrom('backup@incomepos.com', 'Income POS');
  $mail->addReplyTo('backup@incomepos.com', 'Income POS');
  $mail->addAddress('income.register@gmail.com');

  $mail->Subject = $where.' c: '.$companyId." d: ".$date." u: ".$user." o: ".$outletId." r: ".$registerId." cu: ".$customerId;
  $mail->msgHTML($sale);
  $mail->AltBody = $sale;

  $mail->send();
}

function flipOnReturn($type,$number){
	if(!validity($number)){
		return 0;
	}

	if($type == '6'){
		return $number*-1;
	}else{
		return $number;
	}
}

function voidSale($trId,$motive=''){
	global $db,$compId,$outId;
	$compId 	= iftn($compId,COMPANY_ID);
	$outId 		= iftn($outId,OUTLET_ID);
	$db->StartTrans(); //Esto hace que verifique si mas de una transaccion fallo, en el caso de que solo una falle, todas fallan

    ///Recordarme que tengo que ver como actualizar los lotes cuando elimino una transaccion en el panel y ver que pasa con una transaccion anulada o devuelta, puedo eliminarlas? porque hay que reponer el inventario de forma invertida y es quilombo

    $trId         = $db->Prepare( dec($trId) );

    //veo si tiene cliente la venta y si se uso loyalty obtengo el monto para reponer
    $customer = ncmExecute("SELECT
                                 customerId,
                                 transactionPaymentType,
                                 outletId
                            FROM transaction
                            WHERE
                              transactionId = ? LIMIT 1",[$trId]);

    if($customer){
    	$group 		= [];
    	$payments 	= json_decode($customer['transactionPaymentType'],true);
    	$group 		= groupByPaymentMethod($payments,$group);

    	if($group){
			foreach($group as $dat){
				if(validity($customer['customerId'])){
					if($dat['type'] == 'points'){//devuelvo loyalties
						$db->Execute('UPDATE contact SET contactLoyaltyAmount = contactLoyaltyAmount+'.$dat['price'].' WHERE contactUID = ?',array($customer['customerId']));
					}else if($dat['type'] == 'storeCredit'){//devuelvo credito interno
						$db->Execute('UPDATE contact SET contactStoreCredit = contactStoreCredit+'.$dat['price'].' WHERE contactUID = ?',array($customer['customerId']));
					} 
				}

				if($dat['type'] == 'giftcard' && $dat['price'] > 0){//si es giftcard devuelvo
					$db->Execute('UPDATE giftCardSold SET giftCardSoldValue = giftCardSoldValue + ' . $dat['price'] . ' WHERE (giftCardSoldCode = ? OR timestamp = ?) AND outletId = ',array($dat['extra'],$dat['extra'],$customer['outletId']));
				}
			}
		}
    }

    //

    //flagueo la transaccion anulada
    $record['transactionType'] 	= '7';
    $record['transactionNote'] 	= $motive;
    $record['responsibleId'] 	= USER_ID;
    //$db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . $trId);
    ncmUpdate(['records' => $record, 'table' => 'transaction', 'where' => 'transactionId = ' . $trId]);//records (arr), table (str), where (str)
    //elimino pagos si hay
    ncmExecute("DELETE FROM transaction WHERE transactionParentId = ?", [$trId]);

    //inventario
    $items = ncmExecute("SELECT
                                 itemId, itemSoldUnits
                            FROM itemSold
                            WHERE
                              transactionId = ?",[$trId],false,true);

    if($items){
	    while(!$items->EOF) {
	    	$fields 	= $items->fields;
	    	$compound   = getCompoundsArray($fields['itemId']);

			if(validity($compound,'array')){
				foreach ($compound as $comr){
					$itmData = ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[$comr['compoundId'],COMPANY_ID]);
			        manageStock([
			                      'itemId'    		=> $comr['compoundId'],
			                      'outletId'  		=> OUTLET_ID,
			                      'date'          	=> TODAY,
			                      'count'     		=> abs($comr['toCompoundQty'] * $fields['itemSoldUnits']),
			                      'source'    		=> 'void',
			                      'locationId' 		=> $itmData['locationId'],
			                      'transactionId' 	=> $trId
			                    ]);
				    
				}
			}

			$itmData = ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[$fields['itemId'],COMPANY_ID]);
			manageStock([
				              'itemId'    		=> $fields['itemId'],
				              'outletId'  		=> OUTLET_ID,
				              'date'          	=> TODAY,
				              'locationId' 		=> $itmData['locationId'],
				              'count'     		=> abs($fields['itemSoldUnits']),
				              'source'    		=> 'void',
				              'transactionId' 	=> $trId
			            ]);

			$items->MoveNext();
	    }
	    $items->Close();
	}
    
    //inventario//

    //Elimino item solds poruqe voy a usar los que quedan guardados en la transaccion en json
    $db->Execute("DELETE FROM itemSold WHERE transactionId = " . $trId);
    $db->Execute("DELETE FROM giftCardSold WHERE transactionId = " . $trId);

    $failedTransaction = $db->HasFailedTrans();
    $db->CompleteTrans();

    if($failedTransaction){
      jsonDieMsg($db->ErrorMsg());
    }else{
      updateLastTimeEdit($compId,'item');

	  try {
		$transaction = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? LIMIT 1',[$trId]);
	  	$userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
      	$registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
      	$companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
      	$outletName = getCurrentOutletName(OUTLET_ID);

      	$auditoriaData = [
      	  'date'        => TODAY,
      	  'user'      => $userName,
      	  'module'       => 'FACTURACION',
      	  'origin'       => 'CAJA',
      	  'company_id'       => COMPANY_ID,
      	  'data'       => [
      	    'action' => "El usuario $userName anuló una factura desde la caja ". $registerName,
      	    'userId' => USER_ID,
      	    'userName' => $userName,
      	    'operationData' => $transaction,
      	    'registerId' => REGISTER_ID,
      	    'registerName' => $registerName,
      	    'companyID' => COMPANY_ID,
      	    'companyName' => $companyName,
      	    'outletId' => OUTLET_ID,
      	    'outletName' => $outletName,
      	    'timestamp' => $transaction['timestamp']
      	  ]
      	];
      	sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
	  } catch (\Throwable $th) {
		//throw $th;
		error_log("Error al enviar registro de auditoría de anulación de factura: \n", 3, './error_log');
		error_log(print_r($th, true), 3, './error_log');
		error_log("transaction: \n", 3, './error_log');
		error_log(print_r($transaction, true), 3, './error_log');
	  }

      jsonDieMsg('true',200,'success');
    }
}

//other

function divider($val1,$val2,$force=false,$round=false){
	if($val1>0 && $val2>0){
		if($force){
			$out = $val1/$val2;
            return rounder($out,$round);
		}

		if($val1>$val2){
			$out = ($val1/$val2);
		}else{
			$out = ($val2/$val1);
		}
	}else{
		$out = 0;
	}

	return rounder($out,$round);
}

function rounder($value,$round=false){
	if($round){
		if($round == 'down'){
			$out = floor($value);
		}else if($round == 'up'){
			$out = ceil($value);
		}else if($round == 'auto'){
			$out = round($value);
		}else{
			$out = $value;
		}
	}else{
		$out = $value;
	}
	return $out;
}

function rester($first,$second,$round=false){
	if($first>$second){
		$out = $first-$second;
	}elseif($first<$second){
		$out = $second-$first;
	}else{
		$out = 0;
	}
	return rounder($out,$round);
}

function print_gzipped_page() {
    global $HTTP_ACCEPT_ENCODING;

    if( headers_sent() ){
        $encoding = false;
    }elseif( strpos($HTTP_ACCEPT_ENCODING ?? "", 'x-gzip') !== false ){
        $encoding = 'x-gzip';
    }elseif( strpos($HTTP_ACCEPT_ENCODING ?? "",'gzip') !== false ){
        $encoding = 'gzip';
    }else{
        $encoding = false;
    }
 
    if( $encoding ){
        $contents = ob_get_contents();
        ob_end_clean();
        header('Content-Encoding: '.$encoding);
        echo "\x1f\x8b\x08\x00\x00\x00\x00\x00";
        $size = strlen($contents);
        $contents = gzcompress($contents, 9);
        $contents = substr($contents, 0, $size);
        echo $contents;
        exit();
    }else{
        ob_end_flush();
        exit();
    }
}

function array_flatten(array $array) {
    $return = array();
    array_walk_recursive($array, function($a) use (&$return) { $return[] = $a; });
    return $return;
}

function toUTF8($text){

	if(!validity($text)){
		return '';
	}

	$return = '-';
	$wrong 	= ['Ã¡','Ã©',	'Ã³',	'º',	'Ã±',	'í±',	'Ã']; //la í ('Ã') siempre poner al final
	$right 	= ['á',	'é',	'ó',	'ú',	'ñ',	'ñ',	'í'];

	$text = str_replace($wrong, $right, $text);
	$text = rtrim($text);
	//return $text;

	if(validity($text)){
		$utfd = mb_convert_encoding($text,'UTF-8');

		if(validity($utfd)){
			$return = $utfd;
		}
	}

	return $return;
}

function unicode2utf8($str){
    $repl = ['\u00e1','\u00e9','\u00ed','\u00f3','\u00fa','\u00f1','\u00c1','\u00c9','\u00cd','\u00d3','\u00da','\u00d1'];
    $with = ['á','é','í','ó','ú','ñ','Á','É','Í','Ó','Ú','Ñ'];
	return str_replace($repl,$with,$str);
}

function cleanNonUTF8Str($str){
	$replace 	= ['"',"'",'null','false','\u00e1','\u00e9','\u00ed','\u00f3','\u00fa','\u00f1','\u00c1','\u00c9','\u00cd','\u00d3','\u00da','\u00d1'];
	$repwith 	= ['','','','','á','é','í','ó','ú','ñ','Á','É','Í','Ó','Ú','Ñ'];

	$str 		= iftn(json_encode($str),'');
	$str 		= str_replace($replace,$repwith,$str);
	$str 		= Utf8_ansi($str);

	return $str;
}

function Utf8_ansi($valor='') {

    $utf8_ansi2 = array(
    "\u00c0" =>"À",
    "\u00c1" =>"Á",
    "\u00c2" =>"Â",
    "\u00c3" =>"Ã",
    "\u00c4" =>"Ä",
    "\u00c5" =>"Å",
    "\u00c6" =>"Æ",
    "\u00c7" =>"Ç",
    "\u00c8" =>"È",
    "\u00c9" =>"É",
    "\u00ca" =>"Ê",
    "\u00cb" =>"Ë",
    "\u00cc" =>"Ì",
    "\u00cd" =>"Í",
    "\u00ce" =>"Î",
    "\u00cf" =>"Ï",
    "\u00d1" =>"Ñ",
    "\u00d2" =>"Ò",
    "\u00d3" =>"Ó",
    "\u00d4" =>"Ô",
    "\u00d5" =>"Õ",
    "\u00d6" =>"Ö",
    "\u00d8" =>"Ø",
    "\u00d9" =>"Ù",
    "\u00da" =>"Ú",
    "\u00db" =>"Û",
    "\u00dc" =>"Ü",
    "\u00dd" =>"Ý",
    "\u00df" =>"ß",
    "\u00e0" =>"à",
    "\u00e1" =>"á",
    "\u00e2" =>"â",
    "\u00e3" =>"ã",
    "\u00e4" =>"ä",
    "\u00e5" =>"å",
    "\u00e6" =>"æ",
    "\u00e7" =>"ç",
    "\u00e8" =>"è",
    "\u00e9" =>"é",
    "\u00ea" =>"ê",
    "\u00eb" =>"ë",
    "\u00ec" =>"ì",
    "\u00ed" =>"í",
    "\u00ee" =>"î",
    "\u00ef" =>"ï",
    "\u00f0" =>"ð",
    "\u00f1" =>"ñ",
    "\u00f2" =>"ò",
    "\u00f3" =>"ó",
    "\u00f4" =>"ô",
    "\u00f5" =>"õ",
    "\u00f6" =>"ö",
    "\u00f8" =>"ø",
    "\u00f9" =>"ù",
    "\u00fa" =>"ú",
    "\u00fb" =>"û",
    "\u00fc" =>"ü",
    "\u00fd" =>"ý",
    "\u00ff" =>"ÿ");

    return strtr($valor, $utf8_ansi2);      
}

function taxObjSanitizer($array){
	$out 	= [];
	$i 		= 0;

	if(!validity($array)){
		return false;
	}

	foreach ($array as $key => $value) {

		if($i > 10){
			break;
		}

		$name 	= $value['name'] ? markupt2HTML(['text' => $value['name'], 'type' => 'HtM']) : "0";
		$amount = number_format( floatval($value['val']), 3);

		$out[] 	= ['name' => $name, 'val' => $amount];
		$i++;
	}

	return $out;
}

function paymentMObjSanitizer($array){
	//[{"type":"creditcard","name":"Cr\u00e9dito","price":2750,"total":2750,"extra":""}]
	$out 	= [];
	$i 		= 0;

	if(!validity($array)){
		return false;
	}

	foreach ($array as $key => $value) {

		if($i > 10){
			break;
		}

		$type = $value['type'];
		if(is_numeric($type)){
			$type = intval($type);
		}else{
			$type = markupt2HTML(['text' => $type, 'type' => 'HtM']);
		}

		$name 	= markupt2HTML(['text' => $value['name'], 'type' => 'HtM']);
		$price 	= floatval($value['price']);
		$total 	= floatval($value['total']);
		$extra 	= markupt2HTML(['text' => $value['extra'], 'type' => 'HtM']);
		$name 	= substr($name,0,20);
		$extra 	= substr($extra,0,30);

		$out[] 	= ['type' => $type, 'name' => $name, 'price' => $price, 'total' => $total, 'extra' => $extra];
		$i++;
	}

	return $out;
}

function saleArraySanitizer($array){
	/*if(COMPANY_ID != 10){
		return $array;
	}*/

	$out 	= [];
	$i 		= 0;
	foreach ($array as $key => $value) {

		$tags = [];
		if(array_key_exists('tags',$value) && is_array($value['tags'])){
			foreach ($value['tags'] as $key) {
				$tags[] = markupt2HTML(['text' => $key, 'type' => 'HtM']);
			}
		}

		if($value['type'] == 'giftcard'){
			$out[] = 	[
								'itemId' 				=> markupt2HTML(['text' => $value['itemId'], 'type' => 'HtM']),
								'count' 				=> floatval($value['count']),
								'oQty' 					=> floatval($value['oQty']),
								'uniPrice' 			=> floatval($value['uniPrice']),
								'price' 				=> floatval($value['price']),
								'total' 				=> floatval($value['total']),
								'tax' 					=> floatval($value['tax']),
								'user' 					=> markupt2HTML(['text' => $value['user'], 'type' => 'HtM']),
								'type' 					=> markupt2HTML(['text' => $value['type'], 'type' => 'HtM']),
								'date' 					=> markupt2HTML(['text' => $value['date'], 'type' => 'HtM']),
								'note' 					=> markupt2HTML(['text' => $value['note'], 'type' => 'HtM']),
								'beneficiaryId' => markupt2HTML(['text' => $value['beneficiaryId'], 'type' => 'HtM']),
								'giftDate' 			=> markupt2HTML(['text' => $value['giftDate'], 'type' => 'HtM']),
								'giftcardColor' => markupt2HTML(['text' => $value['giftcardColor'], 'type' => 'HtM']),
								'giftcardExp' 	=> markupt2HTML(['text' => $value['giftcardExp'], 'type' => 'HtM']),
								'giftcardId' 		=> (int)$value['giftcardId'],
								'uId' 					=> (int)$value['uId'],
								'currency' 			=> markupt2HTML(['text' => $value['currency'], 'type' => 'HtM'])
							];
		}else{
			$out[] = 	[
						'itemId' 				=> markupt2HTML(['text' => $value['itemId'], 'type' => 'HtM']),
						'count' 				=> floatval($value['count']),
						'oQty' 					=> floatval($value['oQty'] ?? 0),
						'name' 					=> markupt2HTML(['text' => $value['name'], 'type' => 'HtM']),
						'uniPrice' 			=> floatval($value['uniPrice'] ?? 0),
						'price' 				=> floatval($value['price'] ?? 0),
						'total' 				=> floatval($value['total'] ?? 0),
						'tax' 					=> floatval($value['tax'] ?? 0),
						'discount'			=> floatval(array_key_exists("discount",$value) ? $value['discount'] : 0),
						'totalDiscount'	=> floatval(array_key_exists("totalDiscount",$value) ? $value['totalDiscount'] : 0),
						'tags' 					=> $tags,
						'user' 					=> markupt2HTML(['text' => $value['user'] ?? '', 'type' => 'HtM']),
						'type' 					=> markupt2HTML(['text' => $value['type'], 'type' => 'HtM']),
						'date' 					=> markupt2HTML(['text' => array_key_exists('date',$value) ? $value['date'] : "", 'type' => 'HtM']),
						'note' 					=> markupt2HTML(['text' => array_key_exists('note',$value) ? $value['note'] : "", 'type' => 'HtM']),
						'currency' 			=> markupt2HTML(['text' => array_key_exists('currency',$value) ? $value['currency'] : "", 'type' => 'HtM']),
						'uId' 					=> (int) array_key_exists('uId',$value) ? $value['uId'] : 0,
						'parent' 				=> array_key_exists("parent",$value) ? ($value['parent'] ? (int)$value['parent'] : NULL) : NULL,
						'isParent' 			=> array_key_exists("isParent",$value) ? ($value['isParent'] ? (int)$value['isParent'] : NULL) : NULL
					];
		}

		
		

		$i++;
	}

	return $out;

}
?>