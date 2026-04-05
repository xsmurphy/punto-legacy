<?php
include_once('includes/compression_start.php');
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");

topHook(); 

if((validateHttp('action') == 'searchCustomerInputJson' && validateHttp('t') == 2) || validateHttp('action') == 'form'  || validateHttp('action') == 'insert'){
	//no bloqueo si es solo para buscar proveedores
}else{
	allowUser('contacts','view');
}

$baseUrl = '/' . basename(__FILE__,'.php');

$allRoleNames 		= getAllRoles();
$limitDetail		= 100;
$offsetDetail		= 0;

$_rol 				= iftn(validateHttp('rol'),'customer');

/*if($_GET['noo'] == 'naa'){
	dai();
	//selecciono todos los contactos que tienen dirección
	//obtengo los datos de direcciones 1 y 2
	//si no existe en tabla de direcciones inserto
	$sql = "SELECT 
				contactName as name, 
				contactDate as dates, 
				contactAddress as address, 
				contactAddress2 as address2, 
				contactUID as id, 
				contactCity as city, 
				contactLocation as location, 
				contactLatLng as latlng,
				companyId as company
			FROM contact co
			WHERE co.contactDate 
			BETWEEN '2020-06-01 00:00:00' 
			AND '2020-12-01 00:00:00' 
			AND co.contactAddress IS NOT NULL 
			AND co.contactAddress != '' 
			AND type = 1 
			AND NOT EXISTS 
			(
				SELECT * FROM customerAddress ca
				WHERE co.contactUID = ca.customerId
			)";

	$allAddress = ncmExecute($sql,[],false,true);

	if($allAddress){
		$inserteds = [];
	    while (!$allAddress->EOF) {
	    	$recs 		= [];
	    	$fields 	= $allAddress->fields;
	    	$address 	= rtrim($fields['address']);
	    	$address2 	= rtrim($fields['address2']);
	    	$city 		= strtoupper( rtrim($fields['city']) );
	    	$location 	= strtoupper( rtrim($fields['location']) );
	    	$latlng 	= $fields['latlng'];
	    	$lat 		= NULL;
	    	$lng 		= NULL;
	    	if($latlng){
	    		$lat = explode(',', $latlng)[0];
	    		$lng = explode(',', $latlng)[1];
	    	}

	    	$recs = [
	    				'customerAddressText' 		=> $address,
	    				'customerAddressDefault' 	=> 1,
	    				'customerAddressLocation' 	=> $location,
	    				'customerAddressCity' 		=> $city,
	    				'customerAddressLat' 		=> $lat,
	    				'customerAddressLng' 		=> $lng,
	    				'companyId' 				=> $fields['company'],
	    				'customerId' 				=> $fields['id']
	    			];

	    	if(counts($address) > 10){
		    	//inserto
		    	$options = ['table' => 'customerAddress', 'records' => $recs];
		    	$inserteds[] = ncmInsert($options);

		    	if(counts($address2) > 10){
		    		$recs['customerAddressText'] 		= $address2;
		    		$recs['customerAddressDefault'] 	= NULL;
		    		$recs['customerAddressLocation'] 	= NULL;
		    		$recs['customerAddressCity'] 		= NULL;
		    		$recs['customerAddressLat'] 		= NULL;
		    		$recs['customerAddressLng'] 		= NULL;

		    		$options = ['table' => 'customerAddress', 'records' => $recs];
		    		$inserteds[] = ncmInsert($options);
		    	}

		    }

	    	$allAddress->MoveNext(); 
	    }
	    $allAddress->Close();
	}

	echo '<pre>Inserteds: ';
	print_r($inserteds);
	echo '</pre>';

	dai();

}*/

if(validateHttp('action') == 'searchCustomerInputJson'){
	$type 	= validateHttp('t') ? validateHttp('t') : 1;
	$query  = $db->Prepare(validateHttp('q'));
	$notIn 	= '';
	if(validateHttp('not')){
		$notIn 	= ' AND contactId != ' . $db->Prepare(dec(validateHttp('not')));
	}
	$sql    = 'SELECT contactUID, contactName, contactSecondName, contactTIN, contactId FROM contact WHERE (contactName LIKE "%' . $query . '%" OR contactTIN LIKE "%' . $query . '%")' . $notIn . ' AND type = ? AND ' . $SQLcompanyId . ' LIMIT 50';

  $result = ncmExecute($sql,[$type],false,true);
  $json   = [];

  if($result){
    while (!$result->EOF) {
        $json[] = [
        			'name' 			=> $result->fields['contactName'],
        			'sname' 		=> strtolower($result->fields['contactName']),
        			'secondname' 	=> strtolower($result->fields['contactSecondName']),
        			'stin' 			=> strtolower($result->fields['contactTIN']),
        			'uid' 			=> ($type == 1) ? enc($result->fields['contactUID']) : enc($result->fields['contactId'])
        			];

        $result->MoveNext(); 
    }
    $result->Close();
  }
  header('Content-Type: application/json');
  dai(json_encode($json));
}

//Insertar contacto
if(validateHttp('action') == 'insert'){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(!$_POST['type']){
		dai('false');
	}

	$record 	= [];
	$type 		= dec(validateHttp('type','post'));
	$name 		= iftn(validateHttp('name','post'),null);
	$tin 		= iftn(validateHttp('tin','post'),null);
	$person 	= iftn(validateHttp('person','post'),null);
	$email 		= preg_replace('/\s+/', '', iftn(validateHttp('email2','post'),null));
	$address 	= iftn(validateHttp('address','post'),null);
	$phone 		= iftn(validateHttp('phone','post'),null);
	$note 		= iftn(validateHttp('note','post'),null);
	$password 	= iftn(validateHttp('password2','post'),null);
	$role 		= iftn(dec(validateHttp('role','post')),null);
	$lockPass 	= iftn(validateHttp('lockPass','post'),null);
	$salt 		= iftn(validateHttp('salt','post'),null);
	$outletId 	= iftn(validateHttp('outletId','post'),null);
	$outletId 	= dec(validateHttp('outletId','post'));
	$color 		= iftn(validateHttp('color','post'),null);
	$agendable 	= iftn(validateHttp('agendable','post'),null);
	$schedulePosition = iftn(validateHttp('schedulePosition','post'),null);

	if($email){
		if(!validity( str_replace('+', '', $email),'numeric' )){
			if(!filter_var($email, FILTER_VALIDATE_EMAIL)){dai('Error, La dirección de correo no es válida');}
		}
	}

	if($type == 0){ // usuario
		$totalAllowedUsers = ($plansValues[PLAN]['max_users'] * OUTLETS_COUNT) + EXTRA_USERS;
		if(checkPlanMaxReached('contact',$totalAllowedUsers,'type = 0')){
			dai('max');
		}

		if($lockPass && !is_numeric($lockPass)) {
			dai('Error, el código de desbloqueo debe ser númerico');
		}

		if(checkIfExists($lockPass, 'lockPass', 'contact')){
		    dai('Ya existe un usuario con el mismo LockPass');
		}

		if($password){
			if(!$email && !$phone){dai('Es necesario una dirección de e-mail o número de celular');}

		    $pasSalt   					= passEncoder($password);
		    $record['contactPassword']  = $pasSalt[0];
		    $record['salt']           	= $pasSalt[1];
		}

		if(validity($email,'email')){
			$emailExists = ncmExecute('SELECT contactEmail FROM contact WHERE contactEmail = ? AND type = 0',[$email]);

			if($emailExists){
			    dai('Este email ya esta siendo usado por alguien.');
			}
		}

		$role = ($role > 0) ? $role : 1;

		$record['outletId'] 		= $outletId;
		$record['role'] 			= $role;
		$record['lockPass'] 		= $lockPass;
		$record['contactInCalendar'] = $agendable;
		$record['contactCalendarPosition'] 		= $schedulePosition;

		$updateTable = false;

	}else if($type == 1){ //cliente
		if(checkIfExists($tin, 'contactTIN', 'contact')){
			dai('Ya existe un cliente con el mismo '.TIN_NAME);
		}

		$record['contactTIN'] = $tin;
		$record['contactUID'] = generateUID();

		$updateTable = 'customer';

	}else if($type == 2){ //proveedor
		$record['contactTIN'] = $tin;
		$record['contactSecondName'] = $person;

		$updateTable = false;
	}

	if(!$name){dai('El nombre es obligatorio');}	

	$record['contactName'] 		= $name;
	$record['contactDate'] 		= TODAY;
	$record['contactNote'] 		= $note;
	$record['contactAddress'] 	= $address;
	$record['contactPhone'] 	= $phone;
	$record['contactEmail'] 	= $email;
	$record['type'] 			= $type;
	$record['companyId'] 		= COMPANY_ID;
	$record['updated_at']      	= TODAY;
	$record['contactColor'] 	= $color;

	$insert = $db->AutoExecute('contact', $record, 'INSERT');
	$contactId = $db->Insert_ID(); 
	if($insert === false){
		echo 'false';
	}else{
		echo 'true|0|'.enc($contactId);
		updateLastTimeEdit(COMPANY_ID,$updateTable);
		$db->cacheFlush('SELECT * FROM contact WHERE '.$SQLcompanyId.' ORDER BY contactId ASC');
	}
	
	dai();
}

//Editar contacto
if(validateHttp('action') == 'update'){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	$contactId  = validateHttp('id','post');

	if(!$contactId){
		dai('false');
	}

	$trackEvent	= [];
	$record 	= [];
	$id 		= dec($contactId);
	//obtengo el type
	$tpe 		= ncmExecute('SELECT type FROM contact WHERE contactId = ? AND companyId = ?',[$id,COMPANY_ID]);
	$type 		= $tpe['type'];
	$name 		= iftn($_POST['name'],null);
	$tin 		= iftn($_POST['tin'],null);
	$person 	= iftn($_POST['person'],null);
	$email 		= preg_replace('/\s+/', '', iftn($_POST['email2'],null));
	$address 	= iftn($_POST['address'],null);
	$address2 	= iftn($_POST['address2'],null);
	$phone 		= iftn($_POST['phone'],null);
	$phone2 	= iftn($_POST['phone2'],null);
	$note 		= iftn($_POST['note'],null);
	$loyalty 	= formatNumberToInsertDB($_POST['loyalty']);
	$storeCredit= formatNumberToInsertDB($_POST['storeCredit']);
	$creditLine = formatNumberToInsertDB($_POST['storeCreditLine']);
	$loystatus 	= iftn($_POST['loyaltyStatus'],null);
	$password 	= iftn($_POST['password2'],null);
	$role 		= iftn($_POST['role'],null,dec($_POST['role']));
	$lockPass 	= iftn($_POST['lockPass'],null);
	$salt 		= iftn($_POST['salt'],null);
	$status 	= iftn($_POST['status'],'0');
	$outletId 	= iftn($_POST['outletId'],null);
	$outletId 	= dec($outletId);
	$creditable	= iftn($_POST['creditable'],null);
	$category	= iftn($_POST['category'],null);
	$category	= dec($category);
	$color 		= iftn($_POST['color'],null);
	$agendable 	= iftn($_POST['agendable'],null);
	$trackeable = iftn($_POST['trackeable'],null);
	$comission 	= iftn($_POST['comission'],null);
	$schedulePosition = iftn($_POST['schedulePosition'],null);

	if($email){
		if(!validity( str_replace('+', '', $email),'numeric' )){
			if(!filter_var($email, FILTER_VALIDATE_EMAIL)){dai('Error, La dirección de correo no es válida');}
		}
	}

	if($type == 0){ // usuario
		$oldEmail = getValue('contact','contactEmail','WHERE contactId = ' . $id);
		if($lockPass && !is_numeric($lockPass)) {
			dai('Error, el código de desbloqueo debe ser numérico');
		}

		if(checkIfExists($lockPass, 'lockPass', 'contact', $id)){
		    dai('Ya existe un usuario con el mismo LockPass');
		}

		if(validity($email,'email') && $email != $oldEmail){
			if(checkIfExists($email, 'contactEmail', 'contact', $id, false)){
			    dai('Ya existe un usuario con la misma dirección de Email');
			}
		}

		if($password){
			if(!$email && !$phone){dai('Es necesario una dirección de e-mail o número de celular');}

		    $pasSalt   					= passEncoder($password);
		    $record['contactPassword']  = $pasSalt[0];
		    $record['salt']           	= $pasSalt[1];
		}

		$role = ($role > 0) ? $role : 1;

		$record['outletId'] 				= $outletId;
		$record['role'] 					= $role;
		$record['lockPass'] 				= $lockPass;
		$record['contactCalendarPosition'] 	= $schedulePosition;
		$record['contactInCalendar'] 		= $agendable;
		$record['contactTrackLocation'] 	= $trackeable;
		if(!$trackeable){
			$record['contactLatLng'] = NULL;
		}

		$trackEvent['type'] 		= 'user';

		$updateTable = false;

	}else if($type == 1){ //cliente
		$record['contactTIN'] 	= $tin;
		$trackEvent['type'] 	= 'customer';
		$updateTable 			= 'customer';

		//CONSOLIDO INFO
		if(validateHttp('customerConsolidation','post')){
			$consFrom 	= dec(validateHttp('customerConsolidation','post'));
			$consTo 	= dec(validateHttp('customerConsolidationTo','post'));

			$consolidate['customerId'] 	= $consTo;
			$consolidated = $db->AutoExecute('transaction', $consolidate, 'UPDATE', 'customerId = ' . $consFrom . ' AND ' . $SQLcompanyId); 

			if($consolidated !== false){
				$delete = $db->Execute('DELETE FROM contact WHERE contactUID = ? AND ' . $SQLcompanyId . ' LIMIT 1', [$consFrom]);
			}
		}

	}else if($type == 2){ //proceedor
		$record['contactTIN'] 			= $tin;
		$record['contactSecondName'] 	= $person;
		$trackEvent['type'] 			= 'supplier';
		$updateTable 					= false;
	}

	if(!$name){dai('El nombre es obligatorio');}

	$record['contactName'] 			= $name;
	$record['contactSecondName']	= $person;
	$record['contactNote'] 			= $note;
	$record['contactAddress'] 		= $address;	
	$record['contactAddress2'] 		= $address2;	
	$record['contactPhone'] 		= $phone;
	$record['contactPhone2'] 		= $phone2;
	$record['contactEmail'] 		= $email;
	$record['contactStatus'] 		= $status;
	$record['contactLoyaltyAmount']	= $loyalty;
	$record['contactLoyalty']		= $loystatus;
	$record['contactStoreCredit']	= $storeCredit;
	$record['contactCreditLine']	= $creditLine;
	$record['contactCreditable'] 	= $creditable;
	$record['contactFixedComission'] = $comission;
	
	$record['contactColor'] 		= $color;
	$record['categoryId'] 			= $category;
	
	$record['updated_at']      		= TODAY;

	$trackEvent['name'] 	= $name;
	$trackEvent['note'] 	= $note;
	$trackEvent['address'] 	= $address;
	$trackEvent['phone'] 	= $phone;
	$trackEvent['email'] 	= $email;

	$update = $db->AutoExecute('contact', $record, 'UPDATE', 'contactId = ' . $id . ' AND ' . $SQLcompanyId); 

	if($insert === false){
		echo 'false';
	}else{
		echo 'true|0|'.enc($id);
		updateLastTimeEdit(COMPANY_ID,$updateTable);
		trackEvent('update_contact',$trackEvent);
	}
	
	dai();
}

//eliminar contacto
if(validateHttp('action') == 'delete' && validateHttp('id')){
	if(!allowUser('contacts','delete',true)){
		jsonDieResult(['error'=>'No permissions']);
	}

	if(validateHttp('id') == 'all'){
		$delete = $db->Execute('DELETE FROM contact WHERE type = 1 AND companyId = ? LIMIT 1000', [COMPANY_ID]); 
	}else{
		$delete = $db->Execute('DELETE FROM contact WHERE contactId = ? AND '.$SQLcompanyId.' LIMIT 1', [dec($_GET['id'])]); 
	}

	
	if($delete === false){
		echo 'false';
	}else{
		echo 'true';
		updateLastTimeEdit();
		$db->cacheFlush('SELECT * FROM contact WHERE '.$SQLcompanyId.' ORDER BY contactId ASC');
	}
	dai();
}

if(validateHttp('tableExtra')){
	adm($_GET['valExtra'],$_GET['tableExtra'],dec($_GET['idExtra']),$_GET['actionExtra']);
}

if(validateHttp('action') == 'form'){

	$type     = dec(validateHttp('type'));
	$id   	  = validateHttp('id');
	$showInfo = 'style="display:none;"';
	$showForm = 'style="display:none;"';

	if(validateHttp('id')){// 
		$result	= ncmExecute('SELECT * FROM contact WHERE (contactId = ? OR contactUID = ?) AND ' . $SQLcompanyId . ' LIMIT 1', [dec($id),dec($id)]);

		if(!$result){
			echo 	'<div class="modal-body clear bg-white r-24x no-padder">' .
					'	<div class="text-center font-bold wrapper">' .
					'		<h1>Este contacto no existe</h1>' .
					'	</div>' .
					'</div>';

			dai();
		}

		$isUser 	= ($result['type'] == 0)?true:false;
		$isCustomer = ($result['type'] == 1)?true:false;
		$isSupplier = ($result['type'] == 2)?true:false;

		if($isUser){
			$itsId 				= iftn($result['contactRealId'],$result['contactId']);
			$totalPurNSold 		= false;//getContactInSales($itsId,false,false,'userId',true);
			$totalPurchased 	= iftn($totalPurNSold[0],0);
		    $totalComprado 		= iftn($totalPurNSold[1],0);
		    $totalItems 		= false;//iftn(getContactPurchasedItems($itsId,COMPANY_DATE,TODAY,'userId',true),0);
		    $promedio 			= ($totalPurchased && $totalComprado)?($totalPurchased/$totalComprado):0;
		    $schedulePosition 	= $result['contactCalendarPosition'];

		    //calculo cantidad de años como usuario
		    $startY 	= date('Y', strtotime($result['contactDate']));
		    $nowY 		= date('Y');
		    $yearsApart = ($nowY-$startY);

			$main 		= $result['main'];
			$itsRoleId 	= $result['role'];
		}else if($isCustomer){
			$totalPurNSold 		= getContactInSales($result['contactUID']);
			$totalPurchased 	= iftn($totalPurNSold[0],0);
		    $totalComprado 		= iftn($totalPurNSold[1],0);
		    $totalItems 		= iftn(getContactPurchasedItems($result['contactUID']),0);
		    $promedio 			= ($totalPurchased && $totalComprado)?($totalPurchased/$totalComprado):0;

		    //calculo cantidad de años como cliente
		    $startY 	= date('Y', strtotime($result['contactDate']));
		    $nowY 		= date('Y');
		    $yearsApart = ($nowY-$startY); //canidad de años que es cliente

		}else if($isSupplier){
			$itsId 				= iftn($result['contactRealId'],$result['contactId']);
			$totalPurchased 	= iftn(getContactInSales($itsId,false,false,'supplierId')[0],0);
		    $totalComprado 		= iftn(getContactInSales($itsId,false,false,'supplierId')[1],0);
		    $totalItems 		= iftn(getContactPurchasedItems($itsId,false,false,'supplierId'),0);
		    $promedio 			= ($totalPurchased && $totalComprado)?($totalPurchased/$totalComprado):0;

			$secName = $result['contactSecondName'];
		}

		$realId 		= enc($result['contactRealId']);
		$id 				= enc($result['contactId']);
		$name 			= toUTF8($result['contactName']);
		$fullname 	= toUTF8($result['contactName']);
		$address 		= toUTF8($result['contactAddress']);
		$email 			= $result['contactEmail'];
		$phone 			= $result['contactPhone'];
		$note 			= $result['contactNote'];
		$role 			= $result['role'];
		$tin 				= $result['contactTIN'];
		$dateIn			= $result['contactDate'];
		$loyalty 		= ($result['contactLoyaltyAmount'])?$result['contactLoyaltyAmount']:'0';
		$storeCredit= $result['contactStoreCredit'];
		$storeCreditLine= $result['contactCreditLine'];
		$creditable = $result['contactCreditable'];

	    $url = $baseUrl . '?action=update';
	    $showInfo = 'style=""';
	}else{//insert
		$isUser 	= ($type == 0)?true:false;
		$isCustomer = ($type == 1)?true:false;
		$isSupplier = ($type == 2)?true:false;

		$result 	= [];
		$id 		= false;
		$name 		= '';
		$address 	= '';
		$email 		= '';
		$phone 		= '';
		$note 		= '';
		$tin 		= '';
		$url 		= $baseUrl . '?action=insert';
		$showForm 	= 'style=""';
	}

	if($isUser){
		$icon 		= 'person_pin';
		$iconColor 	= 'text-white';
		$titl 		= $allRoleNames[$role]['name'];
		$usrPlchldr = 'Nombre y Apellido';
	}else if($isCustomer){
		$icon 		= 'person';
		$iconColor 	= 'text-white';
		$titl 		= 'Cliente';
		$usrPlchldr = 'Razón Social';
	}else if($isSupplier){
		$icon 		= 'local_shipping';
		$iconColor 	= 'text-white';
		$titl 		= 'Proveedor';
		$usrPlchldr = 'Razón Social';
	}
	?>
	<div class="modal-body clear gradBgBlue text-white r-24x no-padder">

		<div id="contactInfo" <?=$showInfo?>>
			<input type="email" name="email" value="fake field to prevent safari from filling" tabindex="-1" style="top:-100px; position:absolute;">
			<div class="col-sm-5 col-xs-12 matchCols text-center" style="min-height:400px;">
				<i class="material-icons <?=$iconColor?>" style="font-size:10em!important;margin-top:60px;"><?=$icon?></i>
				<div class="text-center font-bold h1 m-b">
					<?=$name?>
				</div>
				<div class="m-b text-center"> <span class="label text-sm bg-light lter"><?=($tin) ? TIN_NAME . ' ' . $tin : 'Sin ' . TIN_NAME?></span></div>
				<div class="text-left wrapper">

					<div class="m-b-xs">
						<i class="material-icons md-14 m-r-sm">perm_contact_calendar</i> 
						<?=$titl?> desde hace <?=timeago($dateIn)?>
					</div>

					<div class="m-b-xs">
						<i class="material-icons md-14 m-r-sm">email</i> 
						<?=iftn($email,'Sin email')?>
					</div>
					<div class="m-b-xs">
						<i class="material-icons md-14 m-r-sm">phone</i> 
						<?=iftn($phone,'Sin teléfono')?>
					</div>
					<div class="m-b-xs">
						<i class="material-icons md-14 m-r-sm">location_on</i> 
						<?=iftn($address,'No posee dirección','<a href="https://maps.google.com/?q=' . urlencode($address) . '" target="_blank"><span class="text-white">' . $address . '</span></a>')?>
					</div>

					<?php
					if($isCustomer){
						if(ENCOM_COMPANY_ID == COMPANY_ID){
					?>
						<div class="m-b-xs">
							<i class="material-icons md-14 m-r-sm">person</i> 
							<?=iftn($result['contactUID'],'No posee ID')?>
						</div>
						<?php
						}
						?>
						<div class="m-b-xs">
							<i class="material-icons md-14 m-r-sm">timelapse</i> 
							<?=nicedate($dateIn,true)?>
						</div>
						<div class="m-b-xs">
							<i class="material-icons md-14 m-r-sm">person</i> 
							<?php
							$userName = 'Sin Usuario';
							if($result['userId']){
								$userName = ncmExecute('SELECT contactName FROM contact WHERE contactId = ?',[$result['userId']]);
								if($userName){
									$userName = toUTF8($userName['contactName']);
								}
							}
							?>
							Por: <?=$userName;?>
						</div>
						<div class="b-t m-t-sm wrapper-xs"></div>
						<div class="m-b-xs">
							<a href="/@#report_transactions?ci=<?=enc($result['contactUID'])?>" target="_blank">
								<span class="text-white text-u-l">Historial de transacciones</span>
							</a>
						</div>
						<div class="m-b-xs">
							<a href="/@#report_products?ci=<?=enc($result['contactUID'])?>" target="_blank">
								<span class="text-white text-u-l">Artículos adquiridos</span>
							</a>
						</div>

						<div class="m-b-xs">
							<a href="#" class="viewAccount" data-id="<?=enc($result['contactUID'])?>">
								<span class="text-white text-u-l">Estado de Cuenta</span>
							</a>
						</div>						
					<?php
					}
					?>

					<?php
	            	if(SCHEDULE && $isUser && $result['contactInCalendar']){
	            		$agendaUrl 		= 'https://public.encom.app/userAgenda?s=' . base64_encode(enc(COMPANY_ID) . ',' . enc($result['contactId']));
	            	?>

					<div class="m-b-xs">
						<a href="<?=$agendaUrl;?>" target="_blank">
							<span class="text-white text-u-l">Ver Agenda</span>
						</a>
					</div>

					<div class="m-b-xs">
						<a href="/@#report_schedule?ui=<?=enc($result['contactId'])?>" target="_blank">
							<span class="text-white text-u-l">Reporte de Agendamientos</span>
						</a>
					</div>

					<?php
					}
					?>

					<?php
	            	if($isUser){
	            	?>
	            	<div class="m-b-xs">
						<a href="/@#report_working_hours?ui=<?=enc($result['contactId'])?>" target="_blank">
							<span class="text-white text-u-l">Horas trabajadas</span>
						</a>
					</div>
					<div class="m-b-xs">
						<a href="/@#report_products?ui=<?=enc($result['contactId'])?>" target="_blank">
							<span class="text-white text-u-l">Artículos vendidos</span>
						</a>
					</div>
					<?php
					}
					?>

					<?php
	            	if($isSupplier){
	            	?>
					<div class="m-b-xs">
						<a href="/@#report_purchases?ci=<?=enc($result['contactId'])?>" target="_blank">
							<span class="text-white text-u-l">Historial de Transacciones</span>
						</a>
					</div>
					<?php
					}
					?>
					
				</div>
			</div>

			<div class="col-sm-7 col-xs-12 matchCols bg-white no-padder">

				<div class="col-xs-12 wrapper" id="customerInfoPanel" style="min-height:321px;">
					<div class="col-xs-12 no-padder panel">
						<div class="col-xs-12 no-padder h3 font-bold m-b">
							Resumen
						</div>
					<?php

					if($isUser && allowUser('sales','view',true)){//solo boss y manger pueden ver esta info
					?>
						<table class="table">
							<tbody>
								<tr>
									<td>Nro. de ventas realizadas</td>
									<td class="text-right font-bold"><?=formatCurrentNumber($totalComprado,'no')?></td>
								</tr>
								<tr>
									<td>Cant. de artículos vendidos</td>
									<td class="text-right font-bold"><?=formatCurrentNumber($totalItems,'no')?></td>
								</tr>
								<tr>
									<td>Total vendido</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($totalPurchased)?></td>
								</tr>
								<tr>
									<td>Promedio por venta</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($promedio)?></td>
								</tr>

								<tr>
									<td>Estado</td>
									<td class="text-right font-bold"><i class="material-icons"><?=($result['contactStatus']) ? 'check' : 'not_interested'?></i></td>
								</tr>

								<tr>
									<td>Sucursal</td>
									<td class="text-right font-bold"><?=($result['outletId']) ? getCurrentOutletName($result['outletId']) : 'Todas'?></td>
								</tr>

								<tr>
									<td>Rol</td>
									<td class="text-right font-bold"><?=$titl?></td>
								</tr>

								<tr>
									<td>Comisión %</td>
									<td class="text-right font-bold"><?=formatCurrentNumber($result['contactFixedComission'])?></td>
								</tr>

								<?php
				            	if(SCHEDULE){
				            	?>
								<tr>
									<td>Agendable</td>
									<td class="text-right font-bold"><i class="material-icons"><?=($result['contactInCalendar']) ? 'check' : 'not_interested'?></i></td>
								</tr>

								<tr>
									<td>Posición en Agenda</td>
									<td class="text-right font-bold"><i class="material-icons"><?=$schedulePosition?></i></td>
								</tr>
								<?php
				            	}
				            	?>


							</tbody>
						</table>
					
					<?php
					}
					
					if($isCustomer && allowUser('sales','view',true)){//solo boss y manger pueden ver esta info
						?>
						<table class="table">
							<tbody>
								<tr>
									<td>Nro. de compras realizadas</td>
									<td class="text-right font-bold"><?=formatCurrentNumber($totalComprado,'no')?></td>
								</tr>
								<tr>
									<td>Cant. de artículos adquiridos</td>
									<td class="text-right font-bold"><?=formatCurrentNumber($totalItems,'no')?></td>
								</tr>
								<tr>
									<td>Total Gastado</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($totalPurchased)?></td>
								</tr>
								<tr>
									<td>Promedio por compra</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($promedio)?></td>
								</tr>

								<tr>
									<td>Línea de Crédito</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($storeCreditLine)?></td>
								</tr>

								<tr>
									<td>Loyalty acumulado</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($loyalty)?></td>
								</tr>

								<tr>
									<td>Crédito interno a favor</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($storeCredit)?></td>
								</tr>


							</tbody>
						</table>
						<?php
					}

					if($isSupplier && allowUser('expenses','view',true)){
						?>
						<table class="table">
							<tbody>
								<tr>
									<td>Nro. de compras realizadas</td>
									<td class="text-right font-bold"><?=formatCurrentNumber($totalComprado,'no')?></td>
								</tr>
								<tr>
									<td>Cant. de artículos comprados</td>
									<td class="text-right font-bold"><?=formatCurrentNumber($totalItems,'no')?></td>
								</tr>
								<tr>
									<td>Total comprado</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($totalPurchased)?></td>
								</tr>
								<tr>
									<td>Promedio por compra</td>
									<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($promedio)?></td>
								</tr>

								<tr>
									<td>Estado</td>
									<td class="text-right font-bold"><i class="material-icons"><?=($result['contactStatus']) ? 'check' : 'not_interested'?></i></td>
								</tr>
							</tbody>
						</table>
						<?php						
					}
					
					?>    

					</div>
	            </div>

	            <?php
	            if(!validity($_GET['ro'])){
	            ?>
	            <div class="col-xs-12 m-t bg-light lter wrapper" style="bottom:0; position:absolute;">
				    <a class="btn btn-info text-u-c font-bold btn-rounded btn-lg pull-right clicker editContact" href="#" data-type="toggle" data-target="#contactInfo,#contactForm">Editar perfil</a>
				    <a href="#" class="cancelItemView m-r-lg m-t pull-right">Cancelar</a>
				</div> 		
				<?php
				}
				?>
			</div>
			
		</div>

		<form action="<?=$url?>" method="post" id="contactForm" name="contactForm" <?=$showForm?> class="animated fadeIn">
			<div class="col-sm-5 text-center" style="min-height:400px;">
				<i class="material-icons <?=$iconColor?>" style="font-size:10em!important;margin-top:60px;"><?=$icon?></i>
				<input type="text" class="form-control m-b m-t no-bg no-border b-b b-light text-center text-white font-bold" style="font-size:25px; height:55px;" placeholder="<?=$usrPlchldr?>" name="name" tabindex="1" value="<?=$name?>" autocomplete="name" required />

				<?php
	            if($main){
	              echo '<input type="text" class="form-control no-bg no-border b-b b-light text-center text-white disabled" name="" value="'.$email.'" disabled  style="width:70%; margin: 0 auto; background:none;" placeholder="Email" tabindex="2" />'; 
	              echo '<input type="hidden" class="form-control hidden" name="email2" value="'.$email.'"/>';                
	            }else{
	            	$disabled = (USER_ID == $itsId)?'disabled':'';
	            	echo '<input type="email" class="form-control no-bg no-border b-b b-light text-center text-white '.$disabled.'" name="email2" value="'.$email.'" style="width:70%; margin: 0 auto;background:none;" placeholder="Email" autocomplete="email" tabindex="2" />';  
	            }

	            ?>

			</div>
			<div class="col-sm-7 bg-white no-padder">

				<div class="col-md-6 wrapper" style="min-height:321px;">
					<?php
					if($isUser){
					?>
						<div class="form-group">
		                    <span class="font-bold text-u-c text-xs">Contraseña:</span>
		                    <input type="password" class="form-control input-lg b-b b-default no-border" name="password2" value=""  autocomplete="new-password"/>
		                </div>

		                <div class="form-group">
						  <span class="font-bold text-u-c text-xs">Lock Pass: (4 números)</span>
						  <input type="text" class="form-control input-lg no-bg no-border b-b b-light lockpass" name="lockPass" value="<?=($result['lockPass']==0)?'':$result['lockPass'];?>"  data-toggle="tooltip" data-placement="top" title="Contraseña de 4 números que identifica a cada usuario, requerida para ingresar a la caja registradora" autocomplete="off" />
						</div>
						<?php
						if(isBoss()){
						?>
						<div class="form-group">
						  <span class="font-bold text-u-c text-xs">Sucursal:</span>
						  <?php echo selectInputOutlet($result['outletId'],false,'no-border b-b','outletId',true);?>
						</div>
						<?php
						}else{
							echo '<input type="hidden" name="outlet" value="'.enc(OUTLET_ID).'"/>';
						}
						?>

						<div class="form-group">
							<div class="col-sm-6 text-left">
					            <span class="font-bold text-u-c text-xs">Color:</small>
						        <select id="colorselector_1" name="color">
						          <option value="e57373" data-color="#e57373">e57373</option>
						          <option value="F06292" data-color="#F06292">F06292</option>
						          <option value="BA68C8" data-color="#BA68C8">BA68C8</option>
						          <option value="9575CD" data-color="#9575CD">9575CD</option>
						          <option value="7986CB" data-color="#7986CB">7986CB</option>
						          <option value="64B5F6" data-color="#64B5F6">64B5F6</option>
						          <option value="4FC3F7" data-color="#4FC3F7">4FC3F7</option>
						          <option value="4DD0E1" data-color="#4DD0E1" selected="selected">4DD0E1</option>
						          <option value="4DB6AC" data-color="#4DB6AC">4DB6AC</option>
						          <option value="81C784" data-color="#81C784">81C784</option>
						          <option value="AED581" data-color="#AED581">AED581</option>
						          <option value="DCE775" data-color="#DCE775">DCE775</option>
						          <option value="FFF176" data-color="#FFF176">FFF176</option>
						          <option value="FFD54F" data-color="#FFD54F">FFD54F</option>
						          <option value="FFB74D" data-color="#FFB74D">FFB74D</option>
						          <option value="FF8A65" data-color="#FF8A65">FF8A65</option>
						          <option value="A1887F" data-color="#A1887F">A1887F</option>
						          <option value="E0E0E0" data-color="#E0E0E0">E0E0E0</option>
						          <option value="90A4AE" data-color="#90A4AE">90A4AE</option>
						          <option value="ef5350" data-color="#ef5350">ef5350</option>
						        </select>
				            </div>
				            <div class="col-sm-6 text-left">
				            	<?php
				            	if(SCHEDULE){
				            		?>
				            		<div class="form-group">
					                    <span class="font-bold block text-u-c text-xs m-b-xs">Agendable:</span>
					            		<?=switchIn('agendable',$result['contactInCalendar'])?>
					            	</div>
				            		<?php
				            	}
					            ?>
				            </div>
				            <div class="form-group">
					            <span class="font-bold text-u-c text-xs">No. en Agenda:</span>
			                	<input type="phone" class="form-control text-right input-lg b-b b-default no-border maskInteger" name="schedulePosition" value="<?=$schedulePosition?>" autocomplete="off" />
				        	</div>
						</div>
						
	                <?php
	            	}

	            	if($isCustomer){
					?>
					<div class="form-group">
	                    <span class="font-bold text-u-c text-xs">Nombre y Apellido:</span>
	                    <input type="text" class="form-control input-lg b-b b-default no-border" name="person" value="<?=$result['contactSecondName']?>" autocomplete="off" />
	                </div>
					<?php
					}

	            	if($isCustomer || $isSupplier){
	            	?>
	            	<div class="form-group">
	                    <span class="font-bold text-u-c text-xs"><?=TIN_NAME?>:</span>
	                    <input type="text" class="form-control input-lg b-b b-default no-border" name="tin" value="<?=$tin?>" autocomplete="off" />
	                </div>
	            	<?php
	            	}else if($isSupplier){
	            	?>
	            	<div class="form-group">
		                <span class="font-bold text-u-c text-xs">Encargado/a:</span>
		                <input type="text" class="form-control input-lg b-b b-default no-border" name="person" value="<?=$result['contactSecondName']?>" autocomplete="off" />
		            </div>
	            	<?php
	            	}

	            	if(!$isUser){
	                ?>

	                <div class="form-group">      
						<?php 
						$pM = $db->Execute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "contactCategory" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC');
						?>
						<span class="font-bold text-u-c text-xs">Categoría</span>
						<select id="concatAdd" name="category" data-placeholder="Seleccione" class="form-control contactCategory no-bg no-border b-b b-light block m-b" autocomplete="off">
						  <option value="">Seleccionar</option>
						  <?php while (!$pM->EOF) {
						    $pMId = enc($pM->fields['taxonomyId']);
						  ?>
					          <option value="<?=$pMId;?>" <?=($pM->fields['taxonomyId'] == $result['categoryId'])?'selected':'';?>>
					          <?=$pM->fields['taxonomyName'];?>
					          </option>
						  <?php 
						    $pM->MoveNext(); 
						    }
						    $pM->Close();
						  ?>
						</select>

						<a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="contactCategory" title="Agregar"><i class="material-icons">add</i></a>
						<a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="contactCategory" data-select="concatAdd" title="Editar"><i class="material-icons">create</i></a>
						<a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="contactCategory" data-select="concatAdd" title="Remover"><i class="material-icons text-danger">close</i></a>

	                </div>


	                <?php
	            	}
	            	?>


		            <?php
		            if($isCustomer || $isUser){
	            		?>
	            		<div class="form-group m-t">
		                    <span class="font-bold block text-u-c text-xs m-b-xs">Estado</span>
		            		<?=switchIn('status',true)?>
		            	</div>
	            		<?php
	            	}
	            	?>

	            	<?php
		            if($isUser){
	            		?>
	            		<div class="form-group m-t">
		                    <span class="font-bold block text-u-c text-xs m-b-xs">Guardar ubicación</span>
		            		<?=switchIn('trackeable',(($result['contactTrackLocation']>0)?true:false))?>
		            	</div>
	            		<?php
	            	}
	            	?>

	            	<?php
	            	if($isCustomer && LOYALTY){
	            	?>
	            	<div class="form-group m-t">
	                    <span class="font-bold block text-u-c text-xs m-b-xs">Loyalty:</span>
	            		<?=switchIn('loyaltyStatus',(($result['contactLoyalty']>0)?true:false))?>
	            	</div>
	            	<?php
	            	}
	                ?>
	            </div>
	            <div class="col-md-6 wrapper" style="min-height:321px;">            	

		            <div class="form-group">
						<span class="font-bold text-u-c text-xs">Teléfono:</span>
						<input type="phone" class="form-control input-lg b-b b-default no-border" name="phone" value="<?=$phone?>" autocomplete="off" />
					</div>

					<div class="form-group">
						<span class="font-bold text-u-c text-xs">Teléfono 2:</span>
						<input type="phone" class="form-control input-lg b-b b-default no-border" name="phone2" value="<?=$result['contactPhone2']?>" autocomplete="off" />
					</div>

					<div class="form-group">
						<span class="font-bold text-u-c text-xs">Dirección:</span>
						<input type="text" class="form-control input-lg b-b b-default no-border" name="address" value="<?=$address?>" autocomplete="off" />
					</div>

					<div class="form-group">
						<span class="font-bold text-u-c text-xs">Dirección 2:</span>
						<input type="text" class="form-control input-lg b-b b-default no-border" name="address2" value="<?=$result['contactAddress2']?>" autocomplete="off" />
					</div>

	              <?php
	              	if($isCustomer){
	              		if(LOYALTY && $result['contactLoyalty']){
	            	?>
			            	<div class="form-group">
			                    <span class="font-bold text-u-c text-xs">Loyalty Acumulado:</span>
			                    <input type="text" class="form-control input-lg b-b b-default no-border maskCurrency" name="loyalty" value="<?=formatCurrentNumber($loyalty)?>" autocomplete="off" />
			                </div>
			            <?php
			        	}

			        	if(STORE_CREDIT){
			            ?>
			            	<div class="form-group">
			                    <span class="font-bold text-u-c text-xs">Crédito a favor:</span>
			                    <input type="text" class="form-control input-lg b-b b-default no-border maskCurrency" name="storeCredit" value="<?=formatCurrentNumber($storeCredit)?>" autocomplete="off" />
			                </div>
	              <?php
	              		}

			        	if($_fullSettings['creditLine']){
			            ?>
			            	<div class="form-group">
			                    <span class="font-bold text-u-c text-xs">Línea de Crédito:</span>
			                    <input type="text" class="form-control input-lg b-b b-default no-border maskCurrency" name="storeCreditLine" value="<?=formatCurrentNumber($storeCreditLine)?>" autocomplete="off" />
			                </div>
	              <?php
	              		}
	              	} 

			        if(($isUser)){
			        ?>
			        <div class="form-group">
			        	<span class="font-bold text-u-c text-xs">Rol:</span> <a href="https://docs.encom.app/panel-de-control/contactos/descripcion-de-roles-de-usuarios" target="_blank"><span class="text-info text-xs">Ver descripción de Roles</span></a>
			            <?php $role = ncmExecute('SELECT taxonomyName,taxonomyExtra FROM taxonomy WHERE taxonomyType = "role" ORDER BY taxonomyId DESC',[],false,true);?>
			            <select name="role" class="form-control no-border b-b role <?=$disabled?>" <?=$disabled?> id="selectRole">
			                <?php 
			                while (!$role->EOF) {
			                  $selected = '';
			                  if($result['role'] == $role->fields['taxonomyExtra']){
			                      $selected = 'selected';
			                  }

			                  if($role->fields['taxonomyExtra'] == 1){
			                    if(isBoss()){
			                ?>
			                <option value="<?=enc($role->fields['taxonomyExtra']);?>" <?=$selected?>><?=$role->fields['taxonomyName'];?></option>
			                <?php 
			                    }
			                  }else{
			                    ?>
			                    <option value="<?=enc($role->fields['taxonomyExtra']);?>" <?=$selected?>><?=$role->fields['taxonomyName'];?></option>
			                    <?php
			                  }
			                  $role->MoveNext(); 
			                }
			                $role->Close();
			                ?>
			            </select>

			        </div>

			        <div class="form-group">
	                    <span class="font-bold text-u-c text-xs">Comisión %:</span>
	                    <input type="text" class="form-control input-lg b-b b-default no-border maskInteger" name="comission" value="<?=formatCurrentNumber($result['contactFixedComission'])?>" autocomplete="off" />
	                </div>

			        <?php
		            	if(SCHEDULE){
			            ?>
				    
		        	<?php
	                	}
			        }
			        ?>
	            </div>

	            <?php
	            if(!$isUser){
	            ?>
	            <div class="col-xs-12 wrapper">
	            	<div class="form-group">
	                    <span class="font-bold text-u-c text-xs">Nota:</span>
	                    <textarea class="form-control" name="note" autocomplete="off"><?=$note?></textarea>
	                </div>
	            </div>
	            <?php
		        }
		        ?>

		        <?php
	            if($isCustomer){
	            ?>
		        <div class="col-xs-12 wrapper m-t-n">
	            	<div class="col-xs-12 m-b font-bold text-u-c h4 no-padder">Consolidar</div>
	            	<div class="col-sm-6 col-xs-12 no-padder">
	            		<div class="font-bold text-u-c text-xs">Pasar todas las transacciones de:</div>
	            		<select name="customerConsolidation" class="form-control contactSearch" placeholder="Seleccione un cliente" data-not="<?=$_GET['id'];?>">
	            		</select>
	            	</div>
	            	<div class="col-sm-6 col-xs-12">
	            		<div class="font-bold text-u-c text-xs">A:</div>
	            		<input type="hidden" class="hidden" name="customerConsolidationTo" value="<?=enc($result['contactUID'])?>">
	            		<div class="h4"><?=$name?></div>
	            	</div>
	            </div>
	            <?php
		        }
		        ?>

	            <div class="col-xs-12 m-t bg-light lter wrapper">
	            	<?php if(dec($id) != USER_ID){ ?>
					<a href="#" class="pull-left m-t deleteItem <?=iftn($id,'hidden','')?>" data-id="<?=$id?>" data-load="<?=$baseUrl?>?action=delete&id=<?=$id?>"><span class="text-danger">Eliminar</span></a>
					<?php } ?>

				    <input class="btn btn-info pull-right btn-rounded btn-lg font-bold text-u-c" type="submit" value="Guardar">
				    <a class="m-t cancelItemView m-r-lg pull-right" href="#">Cancelar</a>
				    <input type="hidden" value="<?=$id?>" name="id">
				    <input type="hidden" value="<?=enc($type)?>" name="type">
				</div> 		
			</div>
		</form>
	</div>

	<script type="text/javascript">
		$('[data-toggle="tooltip"]').tooltip();
		$('.matchCols').matchHeight();

		$('#colorselector_1').colorselector("setColor","#<?=$result['contactColor']?>");
		
	   /* $('#selectRole').on('change',function(){
	      if($(this).val() == 'zg'){
	        $('[name="outlet"]').removeClass('disabled').attr('disabled',false);
	      }else{
	        $('[name="outlet"]').addClass('disabled').attr('disabled',true);
	      }
	    });*/    
	</script>
		
	<?php
	// <!--
	// AQUI PONeER ESTADISTICAS DEL CLIENTE, EJ
	// -FRECUENCIA MENSUAL, CALCULAR LA FRECUENCIA APROXIMADA CON LA QUE VISITA EL NEGOCIO, EJ 3 VECES AL MES
	// -->
	dai();
}

if(validateHttp('action') == 'getCustomerAccount'){
	$id 	= validateHttp('id');
	$idd 	= dec($id);
	if($idd){// Update
		list($totalComprado,$totalPagado,$totalDeuda) = getContactAccountBalance($idd);
	?>
		<div class="col-xs-12 m-t panel">
			<div class="col-xs-12 no-padder h3 font-bold m-b">
				Estado de Cuenta
			</div>
			<table class="table">
				<thead>
					<tr>
						<th>Totales</th>
						<th class="text-right">Monto</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>Adquirido a Crédito</td>
						<td class="text-right"><?=CURRENCY.formatCurrentNumber($totalComprado)?></td>
					</tr>
					<tr>
						<td>Pagado</td>
						<td class="text-right"><?=CURRENCY.formatCurrentNumber($totalPagado)?></td>
					</tr>
					<tr>
						<td>Deuda</td>
						<td class="text-right font-bold"><?=CURRENCY.formatCurrentNumber($totalDeuda)?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="wrapper text-center col-xs-12">
			<a href="https://public.encom.app/customerAccountStatus?s=<?=base64_encode(enc(COMPANY_ID) . ',' . $id)?>" target="_blank" class="btn btn-default btn-rounded font-bold text-u-c">Ver detalles</a>
		</div>
		

	<?php
	}
	dai();
}

if(validateHttp('action') == 'file'){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['success'=>false,'error'=>true]);
	}

	$find 		= ['(',')','`',"'"];
	$replace 	= ['[',']',' '," "];

	$data 		= str_replace($find, $replace, validateHttp('data','post'));
	$data 		= toUTF8($data);
	$data 		= json_decode( $data, true );
	$totalMods 	= 0;//cantidad de items modificados
	$totalAdd 	= 0;//cantidad de items insertados
	$insertList = [];
	$maxMod 	= 100;
	$maxIns 	= 10000;
	$modCount 	= 0;
	$insCount 	= 0;
	$type 		= 1;
	$insert 	= true;

	foreach ($data as $key => $row) {
		if(!validity($row['Nombre/Razon Social']) && !validity($row['Nombre y Apellido'])){
			continue;
		}

		$type 			= 1;//cliente
		$roleId 		= null;
		$id 			= preg_replace('/[^A-Za-z0-9]*$/', '', $row['ID']);
		$name 			= preg_replace('/[^A-Za-z0-9]*$/', '', $row['Nombre/Razon Social']);
		$ruc 			= preg_replace('/[^A-Za-z0-9_-]*$/', '', $row[TIN_NAME]);
		$fullname		= preg_replace('/[^A-Za-z0-9]*$/', '', $row['Nombre y Apellido']);
		$phone 			= preg_replace('/[^\d]+/', '', $row['Telefono']);
		$phone2			= preg_replace('/[^\d]+/', '', $row['Telefono 2']);
		$email 			= strtolower(preg_replace('/[^a-zA-Z0-9.@_-]+/', '', $row['Email']));
		$address 		= preg_replace('/[^A-Za-z0-9]*$/', '', $row['Direccion']);
		$address2 		= preg_replace('/[^A-Za-z0-9]*$/', '', $row['Direccion 2']);
		$note 			= preg_replace('/[^A-Za-z0-9]*$/', '', $row['Nota']);
		$role 			= strtolower(preg_replace('/[^A-Za-z]*$/', '', $row['Rol']));
		$location		= strtoupper(preg_replace('/[^A-Za-z0-9]*$/', '', $row['Localidad']));
		$city			= strtoupper(preg_replace('/[^A-Za-z0-9]*$/', '', $row['Ciudad']));
		$ci				= preg_replace('/[^\d]+/', '', $row['Doc. de Identidad']);

		if($role == 'proveedor'){
			$type = 2;
		}else if($role == 'cliente'){
			$type = 1;
		}else if($role == 'dueño' || $role == 'jefe'){
			$roleId = 1;
			$type 	= 0;
		}else if (like_match('admin%', $role)) {//admin base
			$roleId = 7;
			$type 	= 0;
		}else if ($role == 'administrador') {//admin
			$roleId = 2;
			$type 	= 0;
		}else if (like_match('cajero%', $role)) {//cajero base
			$roleId = 5;
			$type 	= 0;
		}else if ($role == 'cajero') {//cajero
			$roleId = 3;
			$type 	= 0;
		}

		if(validity($id)){//va al array de edición
			$id = dec($id);
			if($modCount < $maxMod && $id){
				$record 						= [];
				$record['contactName'] 			= $name;
				$record['contactSecondName'] 	= $fullname;
				$record['contactTIN'] 			= $ruc;
				$record['contactCI'] 			= $ci;
				$record['contactPhone'] 		= $phone;
				$record['contactPhone2'] 		= $phone2;
				$record['contactAddress'] 		= $address;
				$record['contactAddress2'] 		= $address2;
				$record['contactEmail'] 		= $email;
				$record['contactLocation'] 		= $location;
				$record['contactCity'] 			= $city;
				$record['contactNote'] 			= $note;
				$record['role'] 				= $roleId;

				$db->AutoExecute('contact', $record, 'UPDATE', 'contactId = ' . $id . ' AND ' . $SQLcompanyId); 

				$modCount++;
			}
		}else if($insCount < $maxIns){//para insertar

			$insertList[] = "('" . 
								generateUID($insCount) . "','" . 
								$name . "','" . 
								TODAY . "','" . 
								$ruc . "','" . 
								$fullname . "','" . 
								$note . "','" . 
								$address . "','" . 
								$address2 . "','" . 
								$phone . "','" . 
								$phone2 ."','" . 
								$email . "','" . 
								COMPANY_ID . "','" . 
								$type . "','" . 
								$roleId . "','" . 
								$location . "','" . 
								$city . "','" . 
								$ci . 
							"')";

			$insCount++;
		}
	}

	if(validity($insertList)){

		$insert = $db->Execute("INSERT INTO contact (contactUID,contactName,contactDate,contactTIN,contactSecondName,contactNote,contactAddress,contactAddress2,contactPhone,contactPhone2,contactEmail,companyId,type,role,contactLocation,contactCity,contactCI) VALUES " . implodes(',', $insertList));
	}

	if($insert !== false){
		$insert = 'true';
	}
	
	jsonDieResult(['success'=>$insert,'updated'=>$modCount,'inserted'=>$insCount,'error'=>$db->ErrorMsg()]);
}

if(validateHttp('action') == 'saveRoles'){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>'No permissions']);
	}
	
	$inserts 	= [];
	foreach ($_POST as $key => $value) {
		$result = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'roleData' AND sourceId = ? AND companyId = ? LIMIT 1",[$key,COMPANY_ID]);
		$jValue = json_encode($value);

		if($result){//si existe actualizo
			$added = $db->Execute("UPDATE taxonomy SET taxonomyExtra = '" . $jValue . "' WHERE sourceId = ? AND companyId = ?",[$key,COMPANY_ID]);
		}else{//si no inserto
			$insert['taxonomyName'] 	= $key;
			$insert['taxonomyType'] 	= 'roleData';
			$insert['taxonomyExtra'] 	= $jValue;
			$insert['sourceId'] 		= $key;
			$insert['companyId'] 		= COMPANY_ID;

			$added = $db->AutoExecute('taxonomy', $insert, 'INSERT');
		}
	}

	//$inserts[$key] = $value;

	if($added !== false){
		echo 'true';
	}else{
		echo 'false';
	}
	
	dai();
}

if(validateHttp('action') == 'rolePermissions'){
	$data 	= [];
	$roles 	= ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'role' ORDER BY sourceId ASC LIMIT 10",[],true,true);
	if($roles){
		while (!$roles->EOF) {
			$name 		= $roles->fields['taxonomyName'];
			$rolIndex 	= $roles->fields['sourceId'];
			$rol 		= ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'roleData' AND sourceId = ? AND companyId = ? LIMIT 1",[$roles->fields['sourceId'],COMPANY_ID]);

			$data[$rolIndex] 		= $rol['taxonomyExtra'] ? json_decode($rol['taxonomyExtra'],true) : $_ROLES_DATA[$rolIndex];

			$roles->MoveNext(); 
	    }
	    $roles->Close();
	}
	?>
	<div class="col-xs-12 wrapper panel m-n table-responsive bg-white">

		<!--default<br>
		<?php //echo json_encode($_SESSION['user']['rolePermisions']);?>
		<br><br>
		From profile<br>
		<?php //echo json_encode(getRolePermissions(ROLE_ID,COMPANY_ID));?>-->

		<div class="h2 font-bold m-b-sm">
			<?php
			if(!$plansValues[PLAN]['customRoles']){
				echo 'Aumenta de plan para personalizar los permisos!';
			}else{
				echo '<div class="text-md font-default text-muted">Configuración de</div> Roles y Permisos';
			}
			?>
		</div>
		<p class="m-b-md">Personalice los permisos de cada rol para tener un control específico sobre cada usuario.</p>
		<form action="<?=$baseUrl?>?action=saveRoles" method="post" id="rolesForm" name="rolesForm">
			<table class="table text-center">
				<thead>
					<tr class="text-u-c">
						<th>Panel</th>
						<th class="text-center">Cajero Base</th>
						<th class="text-center">Cajero</th>
						<th class="text-center">Admin. Base</th>
						<th class="text-center">Administrador</th>
						<th class="text-center">Jefe</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td class="bg-light font-bold text-left">Acceso</td>
						<td><?=switchIn('4[panel][access]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][access]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][access]',true,'disabled roles')?></td>
						<td><?=switchIn('1[panel][access]',true,'disabled roles')?></td>
						<td><?=switchIn('0[panel][access]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Dashboard</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[panel][dashboard][view]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][dashboard][view]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][dashboard][view]',$data[2]['panel']['dashboard']['view'],'roles')?></td>
						<td><?=switchIn('1[panel][dashboard][view]',$data[1]['panel']['dashboard']['view'],'roles')?></td>
						<td><?=switchIn('0[panel][dashboard][view]',true,'disabled roles roles')?></td>
					</tr>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Productos y Servicios</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[panel][items][view]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][items][view]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][items][view]',$data[2]['panel']['items']['view'],'roles')?></td>
						<td><?=switchIn('1[panel][items][view]',$data[1]['panel']['items']['view'],'roles')?></td>
						<td><?=switchIn('0[panel][items][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[panel][items][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][items][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][items][edit]',$data[2]['panel']['items']['edit'],'roles')?></td>
						<td><?=switchIn('1[panel][items][edit]',$data[1]['panel']['items']['edit'],'roles')?></td>
						<td><?=switchIn('0[panel][items][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Eliminar</td>
						<td><?=switchIn('4[panel][items][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][items][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][items][delete]',$data[2]['panel']['items']['delete'],'roles')?></td>
						<td><?=switchIn('1[panel][items][delete]',$data[1]['panel']['items']['delete'],'roles')?></td>
						<td><?=switchIn('0[panel][items][delete]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Contactos</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[panel][contacts][view]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][contacts][view]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][contacts][view]',$data[2]['panel']['contacts']['view'],'roles')?></td>
						<td><?=switchIn('1[panel][contacts][view]',$data[1]['panel']['contacts']['view'],'roles')?></td>
						<td><?=switchIn('0[panel][contacts][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[panel][contacts][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][contacts][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][contacts][edit]',$data[2]['panel']['contacts']['edit'],'roles')?></td>
						<td><?=switchIn('1[panel][contacts][edit]',$data[1]['panel']['contacts']['edit'],'roles')?></td>
						<td><?=switchIn('0[panel][contacts][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Eliminar</td>
						<td><?=switchIn('4[panel][contacts][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][contacts][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][contacts][delete]',$data[2]['panel']['contacts']['delete'],'roles')?></td>
						<td><?=switchIn('1[panel][contacts][delete]',$data[1]['panel']['contacts']['delete'],'roles')?></td>
						<td><?=switchIn('0[panel][contacts][delete]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Reportes - Ventas</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[panel][reports][sales][view]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][reports][sales][view]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][reports][sales][view]',$data[2]['panel']['reports']['sales']['view'],'roles')?></td>
						<td><?=switchIn('1[panel][reports][sales][view]',$data[1]['panel']['reports']['sales']['view'],'roles')?></td>
						<td><?=switchIn('0[panel][reports][sales][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[panel][reports][sales][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][reports][sales][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][reports][sales][edit]',$data[2]['panel']['reports']['sales']['edit'],'roles')?></td>
						<td><?=switchIn('1[panel][reports][sales][edit]',$data[1]['panel']['reports']['sales']['edit'],'roles')?></td>
						<td><?=switchIn('0[panel][reports][sales][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Eliminar</td>
						<td><?=switchIn('4[panel][reports][sales][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][reports][sales][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][reports][sales][delete]',$data[2]['panel']['reports']['sales']['delete'],'roles')?></td>
						<td><?=switchIn('1[panel][reports][sales][delete]',$data[1]['panel']['reports']['sales']['delete'],'roles')?></td>
						<td><?=switchIn('0[panel][reports][sales][delete]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Reportes - Compras y Gastos</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[panel][reports][expenses][view]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][reports][expenses][view]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][reports][expenses][view]',$data[2]['panel']['reports']['expenses']['view'],'roles')?></td>
						<td><?=switchIn('1[panel][reports][expenses][view]',$data[1]['panel']['reports']['expenses']['view'],'roles')?></td>
						<td><?=switchIn('0[panel][reports][expenses][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[panel][reports][expenses][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][reports][expenses][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][reports][expenses][edit]',$data[2]['panel']['reports']['expenses']['edit'],'roles')?></td>
						<td><?=switchIn('1[panel][reports][expenses][edit]',$data[1]['panel']['reports']['expenses']['edit'],'roles')?></td>
						<td><?=switchIn('0[panel][reports][expenses][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Eliminar</td>
						<td><?=switchIn('4[panel][reports][expenses][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][reports][expenses][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][reports][expenses][delete]',$data[2]['panel']['reports']['expenses']['delete'],'roles')?></td>
						<td><?=switchIn('1[panel][reports][expenses][delete]',$data[1]['panel']['reports']['expenses']['delete'],'roles')?></td>
						<td><?=switchIn('0[panel][reports][expenses][delete]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Configuración</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[panel][settings][view]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][settings][view]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][settings][view]',$data[2]['panel']['settings']['view'],'roles')?></td>
						<td><?=switchIn('1[panel][settings][view]',$data[1]['panel']['settings']['view'],'roles')?></td>
						<td><?=switchIn('0[panel][settings][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[panel][settings][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][settings][edit]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][settings][edit]',$data[2]['panel']['settings']['edit'],'roles')?></td>
						<td><?=switchIn('1[panel][settings][edit]',$data[1]['panel']['settings']['edit'],'roles')?></td>
						<td><?=switchIn('0[panel][settings][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Eliminar</td>
						<td><?=switchIn('4[panel][settings][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('3[panel][settings][delete]',false,'disabled roles')?></td>
						<td><?=switchIn('2[panel][settings][delete]',$data[2]['panel']['settings']['delete'],'roles')?></td>
						<td><?=switchIn('1[panel][settings][delete]',$data[1]['panel']['settings']['delete'],'roles')?></td>
						<td><?=switchIn('0[panel][settings][delete]',true,'disabled roles')?></td>
					</tr>
				</tbody>
			</table>

			<table class="table text-center">
				<thead>
					<tr class="text-u-c">
						<th>Caja</th>
						<th class="text-center">Cajero Base</th>
						<th class="text-center">Cajero</th>
						<th class="text-center">Admin. Base</th>
						<th class="text-center">Administrador</th>
						<th class="text-center">Jefe</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td class="bg-light font-bold text-left">Acceso</td>
						<td><?=switchIn('4[register][access]',true,'disabled roles')?></td>
						<td><?=switchIn('3[register][access]',true,'disabled roles')?></td>
						<td><?=switchIn('2[register][access]',true,'disabled roles')?></td>
						<td><?=switchIn('1[register][access]',true,'disabled roles')?></td>
						<td><?=switchIn('0[register][access]',true,'disabled roles')?></td>
					</tr>

					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Transacciones</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Crear</td>
						<td><?=switchIn('4[register][transactions][create]',$data[4]['register']['transactions']['create'],'roles')?></td>
						<td><?=switchIn('3[register][transactions][create]',$data[3]['register']['transactions']['create'],'roles')?></td>
						<td><?=switchIn('2[register][transactions][create]',$data[2]['register']['transactions']['create'],'roles')?></td>
						<td><?=switchIn('1[register][transactions][create]',$data[1]['register']['transactions']['create'],'roles')?></td>
						<td><?=switchIn('0[register][transactions][create]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[register][transactions][view]',$data[4]['register']['transactions']['view'],'roles')?></td>
						<td><?=switchIn('3[register][transactions][view]',$data[3]['register']['transactions']['view'],'roles')?></td>
						<td><?=switchIn('2[register][transactions][view]',$data[2]['register']['transactions']['view'],'roles')?></td>
						<td><?=switchIn('1[register][transactions][view]',$data[1]['register']['transactions']['view'],'roles')?></td>
						<td><?=switchIn('0[register][transactions][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[register][transactions][edit]',$data[4]['register']['transactions']['edit'],'roles')?></td>
						<td><?=switchIn('3[register][transactions][edit]',$data[3]['register']['transactions']['edit'],'roles')?></td>
						<td><?=switchIn('2[register][transactions][edit]',$data[2]['register']['transactions']['edit'],'roles')?></td>
						<td><?=switchIn('1[register][transactions][edit]',$data[1]['register']['transactions']['edit'],'roles')?></td>
						<td><?=switchIn('0[register][transactions][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Anular/Cancelar</td>
						<td><?=switchIn('4[register][transactions][delete]',$data[4]['register']['transactions']['delete'],'roles')?></td>
						<td><?=switchIn('3[register][transactions][delete]',$data[3]['register']['transactions']['delete'],'roles')?></td>
						<td><?=switchIn('2[register][transactions][delete]',$data[2]['register']['transactions']['delete'],'roles')?></td>
						<td><?=switchIn('1[register][transactions][delete]',$data[1]['register']['transactions']['delete'],'roles')?></td>
						<td><?=switchIn('0[register][transactions][delete]',true,'disabled roles')?></td>
					</tr>



					<!--Quotes-->
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Cotizaciones</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Crear</td>
						<td><?=switchIn('4[register][quotes][create]',$data[4]['register']['quotes']['create'],'roles')?></td>
						<td><?=switchIn('3[register][quotes][create]',$data[3]['register']['quotes']['create'],'roles')?></td>
						<td><?=switchIn('2[register][quotes][create]',$data[2]['register']['quotes']['create'],'roles')?></td>
						<td><?=switchIn('1[register][quotes][create]',$data[1]['register']['quotes']['create'],'roles')?></td>
						<td><?=switchIn('0[register][quotes][create]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[register][quotes][view]',$data[4]['register']['quotes']['view'],'roles')?></td>
						<td><?=switchIn('3[register][quotes][view]',$data[3]['register']['quotes']['view'],'roles')?></td>
						<td><?=switchIn('2[register][quotes][view]',$data[2]['register']['quotes']['view'],'roles')?></td>
						<td><?=switchIn('1[register][quotes][view]',$data[1]['register']['quotes']['view'],'roles')?></td>
						<td><?=switchIn('0[register][quotes][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[register][quotes][edit]',$data[4]['register']['quotes']['edit'],'roles')?></td>
						<td><?=switchIn('3[register][quotes][edit]',$data[3]['register']['quotes']['edit'],'roles')?></td>
						<td><?=switchIn('2[register][quotes][edit]',$data[2]['register']['quotes']['edit'],'roles')?></td>
						<td><?=switchIn('1[register][quotes][edit]',$data[1]['register']['quotes']['edit'],'roles')?></td>
						<td><?=switchIn('0[register][quotes][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Anular/Cancelar</td>
						<td><?=switchIn('4[register][quotes][delete]',$data[4]['register']['quotes']['delete'],'roles')?></td>
						<td><?=switchIn('3[register][quotes][delete]',$data[3]['register']['quotes']['delete'],'roles')?></td>
						<td><?=switchIn('2[register][quotes][delete]',$data[2]['register']['quotes']['delete'],'roles')?></td>
						<td><?=switchIn('1[register][quotes][delete]',$data[1]['register']['quotes']['delete'],'roles')?></td>
						<td><?=switchIn('0[register][quotes][delete]',true,'disabled roles')?></td>
					</tr>
					<!--quotes end-->

					<!--Schedule-->
					<?php
					if($_modules['calendar']){
					?>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Agendamientos</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Crear</td>
						<td><?=switchIn('4[register][schedule][create]',$data[4]['register']['schedule']['create'],'roles')?></td>
						<td><?=switchIn('3[register][schedule][create]',$data[3]['register']['schedule']['create'],'roles')?></td>
						<td><?=switchIn('2[register][schedule][create]',$data[2]['register']['schedule']['create'],'roles')?></td>
						<td><?=switchIn('1[register][schedule][create]',$data[1]['register']['schedule']['create'],'roles')?></td>
						<td><?=switchIn('0[register][schedule][create]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[register][schedule][view]',$data[4]['register']['schedule']['view'],'roles')?></td>
						<td><?=switchIn('3[register][schedule][view]',$data[3]['register']['schedule']['view'],'roles')?></td>
						<td><?=switchIn('2[register][schedule][view]',$data[2]['register']['schedule']['view'],'roles')?></td>
						<td><?=switchIn('1[register][schedule][view]',$data[1]['register']['schedule']['view'],'roles')?></td>
						<td><?=switchIn('0[register][schedule][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[register][schedule][edit]',$data[4]['register']['schedule']['edit'],'roles')?></td>
						<td><?=switchIn('3[register][schedule][edit]',$data[3]['register']['schedule']['edit'],'roles')?></td>
						<td><?=switchIn('2[register][schedule][edit]',$data[2]['register']['schedule']['edit'],'roles')?></td>
						<td><?=switchIn('1[register][schedule][edit]',$data[1]['register']['schedule']['edit'],'roles')?></td>
						<td><?=switchIn('0[register][schedule][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Anular/Cancelar</td>
						<td><?=switchIn('4[register][schedule][delete]',$data[4]['register']['schedule']['delete'],'roles')?></td>
						<td><?=switchIn('3[register][schedule][delete]',$data[3]['register']['schedule']['delete'],'roles')?></td>
						<td><?=switchIn('2[register][schedule][delete]',$data[2]['register']['schedule']['delete'],'roles')?></td>
						<td><?=switchIn('1[register][schedule][delete]',$data[1]['register']['schedule']['delete'],'roles')?></td>
						<td><?=switchIn('0[register][schedule][delete]',true,'disabled roles')?></td>
					</tr>
					<?php
					}
					?>
					<!--Schedule end-->

					<!--Tables-->
					<?php
					if($_modules['tables']){
					?>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Mesas</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Crear</td>
						<td><?=switchIn('4[register][tables][create]',$data[4]['register']['tables']['create'],'roles')?></td>
						<td><?=switchIn('3[register][tables][create]',$data[3]['register']['tables']['create'],'roles')?></td>
						<td><?=switchIn('2[register][tables][create]',$data[2]['register']['tables']['create'],'roles')?></td>
						<td><?=switchIn('1[register][tables][create]',$data[1]['register']['tables']['create'],'roles')?></td>
						<td><?=switchIn('0[register][tables][create]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[register][tables][view]',$data[4]['register']['tables']['view'],'roles')?></td>
						<td><?=switchIn('3[register][tables][view]',$data[3]['register']['tables']['view'],'roles')?></td>
						<td><?=switchIn('2[register][tables][view]',$data[2]['register']['tables']['view'],'roles')?></td>
						<td><?=switchIn('1[register][tables][view]',$data[1]['register']['tables']['view'],'roles')?></td>
						<td><?=switchIn('0[register][tables][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[register][tables][edit]',$data[4]['register']['tables']['edit'],'roles')?></td>
						<td><?=switchIn('3[register][tables][edit]',$data[3]['register']['tables']['edit'],'roles')?></td>
						<td><?=switchIn('2[register][tables][edit]',$data[2]['register']['tables']['edit'],'roles')?></td>
						<td><?=switchIn('1[register][tables][edit]',$data[1]['register']['tables']['edit'],'roles')?></td>
						<td><?=switchIn('0[register][tables][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Anular/Cancelar</td>
						<td><?=switchIn('4[register][tables][delete]',$data[4]['register']['tables']['delete'],'roles')?></td>
						<td><?=switchIn('3[register][tables][delete]',$data[3]['register']['tables']['delete'],'roles')?></td>
						<td><?=switchIn('2[register][tables][delete]',$data[2]['register']['tables']['delete'],'roles')?></td>
						<td><?=switchIn('1[register][tables][delete]',$data[1]['register']['tables']['delete'],'roles')?></td>
						<td><?=switchIn('0[register][tables][delete]',true,'disabled roles')?></td>
					</tr>
					<?php
					}
					?>
					<!--Tables end-->

					<!--Orders-->
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Órdenes</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Crear</td>
						<td><?=switchIn('4[register][orders][create]',$data[4]['register']['orders']['create'],'roles')?></td>
						<td><?=switchIn('3[register][orders][create]',$data[3]['register']['orders']['create'],'roles')?></td>
						<td><?=switchIn('2[register][orders][create]',$data[2]['register']['orders']['create'],'roles')?></td>
						<td><?=switchIn('1[register][orders][create]',$data[1]['register']['orders']['create'],'roles')?></td>
						<td><?=switchIn('0[register][orders][create]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Ver</td>
						<td><?=switchIn('4[register][orders][view]',$data[4]['register']['orders']['view'],'roles')?></td>
						<td><?=switchIn('3[register][orders][view]',$data[3]['register']['orders']['view'],'roles')?></td>
						<td><?=switchIn('2[register][orders][view]',$data[2]['register']['orders']['view'],'roles')?></td>
						<td><?=switchIn('1[register][orders][view]',$data[1]['register']['orders']['view'],'roles')?></td>
						<td><?=switchIn('0[register][orders][view]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[register][orders][edit]',$data[4]['register']['orders']['edit'],'roles')?></td>
						<td><?=switchIn('3[register][orders][edit]',$data[3]['register']['orders']['edit'],'roles')?></td>
						<td><?=switchIn('2[register][orders][edit]',$data[2]['register']['orders']['edit'],'roles')?></td>
						<td><?=switchIn('1[register][orders][edit]',$data[1]['register']['orders']['edit'],'roles')?></td>
						<td><?=switchIn('0[register][orders][edit]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Anular/Cancelar</td>
						<td><?=switchIn('4[register][orders][delete]',$data[4]['register']['orders']['delete'],'roles')?></td>
						<td><?=switchIn('3[register][orders][delete]',$data[3]['register']['orders']['delete'],'roles')?></td>
						<td><?=switchIn('2[register][orders][delete]',$data[2]['register']['orders']['delete'],'roles')?></td>
						<td><?=switchIn('1[register][orders][delete]',$data[1]['register']['orders']['delete'],'roles')?></td>
						<td><?=switchIn('0[register][orders][delete]',true,'disabled roles')?></td>
					</tr>
					<!--Orders end-->

					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Ventas</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Añadir descuentos</td>
						<td><?=switchIn('4[register][sales][discounts]',$data[4]['register']['sales']['discounts'],'roles')?></td>
						<td><?=switchIn('3[register][sales][discounts]',$data[3]['register']['sales']['discounts'],'roles')?></td>
						<td><?=switchIn('2[register][sales][discounts]',$data[2]['register']['sales']['discounts'],'roles')?></td>
						<td><?=switchIn('1[register][sales][discounts]',$data[1]['register']['sales']['discounts'],'roles')?></td>
						<td><?=switchIn('0[register][sales][discounts]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Modificar precios</td>
						<td><?=switchIn('4[register][sales][price]',$data[4]['register']['sales']['price'],'roles')?></td>
						<td><?=switchIn('3[register][sales][price]',$data[3]['register']['sales']['price'],'roles')?></td>
						<td><?=switchIn('2[register][sales][price]',$data[2]['register']['sales']['price'],'roles')?></td>
						<td><?=switchIn('1[register][sales][price]',$data[1]['register']['sales']['price'],'roles')?></td>
						<td><?=switchIn('0[register][sales][price]',true,'disabled roles')?></td>
					</tr>
					<tr>
						<td class="text-u-c font-bold text-left" colspan="6">Ajustes</td>
					</tr>
					<tr>
						<td class="bg-light font-bold text-left">Editar</td>
						<td><?=switchIn('4[register][settings][edit]',$data[4]['register']['settings']['edit'],'roles')?></td>
						<td><?=switchIn('3[register][settings][edit]',$data[3]['register']['settings']['edit'],'roles')?></td>
						<td><?=switchIn('2[register][settings][edit]',$data[2]['register']['settings']['edit'],'roles')?></td>
						<td><?=switchIn('1[register][settings][edit]',$data[1]['register']['settings']['edit'],'roles')?></td>
						<td><?=switchIn('0[register][settings][edit]',true,'disabled roles')?></td>
					</tr>
				</tbody>
			</table>
		</form>
		<script type="text/javascript">
			$(document).ready(function(){
				
				<?php
				echo '/*' . $plansValues[PLAN]['customRoles'] . '*/';
				//if($plansValues[PLAN]['customRoles']){
				?>
					switchit(function(tis,isActive){

						if(tis.hasClass('roles')){
							$('#rolesForm').submit();
							//var serialiced = $('#rolesForm').serialize();
							//console.log(serialiced);
						}
					},true);

					submitForm('#rolesForm',function(element,id){
						if(id){
							message('Modificado','success');
						}else{
							message('No se pudo modificar','success');
						}
					});	
				<?php
				//}
				?>
			});
		</script>
	</div>
	<?php

	dai();
}

if(validateHttp('action') == 'importCSVFichas'){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$procede = false;

	$mimes 	= ['application/vnd.ms-excel','text/plain','text/csv','text/tsv','application/octet-stream'];

	if(in_array($_FILES['csv']['type'],$mimes)){
		$procede = true;
	}else{
		$procede = false;
		$msg = 'el formato debe ser CSV';
	}
	
	if (!empty($_FILES['csv']['tmp_name']) && $procede){
		$procede = true;
	}else{
		$procede = false;
		$msg .= 'No pudimos subir el archivo';
	}

	if($procede){

		$fileData = file_get_contents($_FILES['csv']['tmp_name']);

		$record = [];
		$msg 	= '';
		$maxRows = 7000;

		$lines 	= str_getcsv($fileData, "\n");
		$rows 	= [];
		$noLines = 0;

		foreach ($lines as $line) {
			$rows[] = str_getcsv($line);
			$noLines++;
		}


		if($noLines > $maxRows){
			$msg = 'ERROR, máximo ' . $maxRows . ' líneas por archivo, ' . $noLines . ' líneas enviadas';
		}else{
			$insertList 	= [];
			$insertFicha 	= [];
			$fieldId 		= [];
			$insert 		= false;
			$msg 			= '';

			//print_r($rows);

			foreach ($rows as $col => $field) {
				$colsCount = count($field);
				$i = 0;
				while($i < $colsCount){
					$customerId		= dec($field[0]);
					$name 			= $field[1];
					$ruc 			= $field[2];

					if($col < 1){
						if($i > 2){
							$fieldId[$i] 			= dec(explodes('/',$field[$i],false,1));
						}
					}

					if($col > 1){
						if($i > 2){
							if($field[$i]){
								$insertList[] 	= "('" . $field[$i] . "','" . $fieldId[$i] . "','" . $customerId . "')";
							}
						}
					}

					$i++;
				}
			}

			if(validity($insertList)){
				//$msg = implodes(',', $insertList);
				$db->query("SET NAMES 'utf8'");

				$insert = $db->Execute("INSERT INTO cRecordValue (cRecordValueName,cRecordFieldId,customerId) VALUES " . implodes(',', $insertList));

				if($insert !== false){
					$msg = 'Archivo subido con éxito';
					updateLastTimeEdit();
				}else{
					$msg = 'Error al subir el archivo';
				}


			}else{
				$msg = 'Error al compilar el archivo';
			}
			
		}
	}else{
		$msg = 'Error, ' . $msg;
	}

	header('location: /@#contacts?msg=' . $msg);
}

if(validateHttp('action') == 'formCSV'){
	?>
	<div class="modal-body modal-body no-padder clear r-24x animateBg">
		
		<div class="col-xs-12 no-padder bg-white">
			<div class="wrapper-lg font-bold text-center h2 bg-info gradBgBlue">Carga y edición <br> masiva de contactos</div>
			<div class="text-md wrapper-md">
				<strong>1.</strong> Dirijase al listado de contactos y en el botón "Columnas" seleccione las columnas que desea importar.
				<br><br>
				<strong>2.</strong> Presione el botón "Exportar listado" y complete los campos del archivo descargado. Si en el archivo excel mantiene el campo "ID", estos contactos se actualizarán en su base de datos y los contactos que no tengan "ID" serán creados como nuevos.
				<br><br>
				<strong>3.</strong> En la columna "ROL" recuerde colocar "Cliente" o "Proveedor" respectivamente en cada línea, si es un usuario coloque el nombre del rol (Jefe, Administrador, Admin. Base, Cajero o Cajero Base).
				<br><br>
				<strong>4.</strong> Una vez que tenga su listado completo arrastrelo y sueltelo en el listado de contactos y listo. Recuerde dejar la primera línea (títulos de columnas) del archivo excel intacta.
			</div>
		</div>
		<div class="col-xs-12 wrapper-md bg-light lt text-right">
			<a href="#" class="m-t m-r" data-dismiss="modal" aria-hidden="true">Cerrar</a>
		</div>
		
	</div>
	<?php
	dai();
}

if(validateHttp('action') == 'formCSVFichas'){
	?>
	<div class="modal-body modal-body no-padder clear r-24x gradBgBlue animateBg">
		<form action="<?=$baseUrl?>?action=importCSVFichas" method="POST" id="csvForm" name="csvForm" enctype="multipart/form-data">
			<div class="col-xs-12 wrapper">
				<h2 class="font-bold">Carga masiva de fichas</h2>
				<div class="text-md">
					1. Descargue el modelo desde <a href="<?=$baseUrl?>?action=csvModelFichas" class="text-info" target="_blank"><span class="text-warning font-bold">aquí</span></a>
					<br>
					2. Complete los campos del archivo descargado
					<br>
					3. Suba el archivo en el botón de abajo
				</div>
				<input name="csv" type="file" class="form-control btn btn-default btn-rounded m-t" />
				<input type="hidden" name="MAX_FILE_SIZE" value="8M">
			</div>
			<div class="col-xs-12 wrapper bg-light lter text-right">
				<a href="#" class="m-t m-r" data-dismiss="modal" aria-hidden="true">Cancelar</a>
				<input class="btn btn-lg btn-info btn-rounded text-u-c font-bold" type="submit" value="Subir">
			</div>
		</form>
	</div>
	<?php
	dai();
}

if(validateHttp('action') == 'csvModelFichas'){

	$excellRow 	= [];
	$fichas		= [];

	$head 		= ['ID','CLIENTE',TIN_NAME];

	//fichas
	$record 	= ncmExecute('SELECT customerRecordId FROM customerRecord WHERE companyId = ? ORDER BY customerRecordId DESC',[COMPANY_ID],false,true);

	if($record){
		while (!$record->EOF) {
			$field 	= ncmExecute('SELECT cRecordFieldId,cRecordFieldName,cRecordFieldType FROM cRecordField WHERE customerRecordId = ? ORDER BY cRecordFieldId DESC',[$record->fields['customerRecordId']],false,true);
			if($field){
				$fichas = '';
				while (!$field->EOF) {
					$explicacion = ($field->fields['cRecordFieldType'] == 4) ? ' (1987-03-25)' : '';
					$fichas		.= $field->fields['cRecordFieldName'] . $explicacion . '/' . enc($field->fields['cRecordFieldId']) . '|';
					$field->MoveNext(); 
				}

				$head = array_merge($head,explodes('|', $fichas));
			}

			$record->MoveNext(); 
		}
	}

	$excellRow[] 	= $head;

	$contacts 		= ncmExecute('SELECT * FROM contact WHERE type = 1 AND companyId = ?',[COMPANY_ID],false,true);

	if($contacts){
		while (!$contacts->EOF) {
			$fields 		= $contacts->fields;
			$excellRow[]  	= [enc($fields['contactUID']),iftn($fields['contactName'],$fields['contactSecondName']),$fields['contactTIN']];

			$contacts->MoveNext(); 
		}
	}

	
	if(!$_GET['test']){
		generateXLSfromArray($excellRow,'carga_fichas');
	}else{
		echo '<pre>';
		print_r($excellRow);
		echo '</pre>';
	}

	dai();
}

if(validateHttp('action') == 'mandatory'){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	if(validateHttp('update')){
		$json = json_encode($_POST);

		if(!validity($_POST)){
			$json = NULL;
		}

		$record['settingMandatoryContactFields'] 	= $json;
		$update = $db->AutoExecute('setting', $record, 'UPDATE', $SQLcompanyId); 

		if($update !== false){
			echo 'true';
		}else{
			echo 'false';
		}

		dai();
	}

	$fields = json_decode($_cmpSettings['settingMandatoryContactFields'],true);
	?>
	<div class="modal-body modal-body no-padder clear r-24x bg-white">
		<form action="<?=$baseUrl?>?action=mandatory&update=true" method="POST" id="mandatoryForm" name="mandatoryForm">
			<div class="col-xs-12 text-center wrapper">
				<div class="h2 font-bold">Campos Obligatorios</div>
				<span class="text-sm">Active los campos que desee que sean obligatorios para <br> dar de alta a un cliente en la caja</span>
			</div>
			<div class="col-xs-12 wrapper">
				<table class="table h4 font-bold">
					<tbody>
						<tr>
							<td>Razón Social <div class="font-normal text-sm">Razón Social o Comercial del cliente</div></td>
							<td class="text-right"><?=switchIn('razon',$fields['razon'])?></td>
						</tr>
						<tr>
							<td><?=TIN_NAME?> <div class="font-normal text-sm">Identificador tributario</div></td>
							<td class="text-right"><?=switchIn('tin',$fields['tin'])?></td>
						</tr>
						<tr>
							<td>Nombre y Apellido <div class="font-normal text-sm"></div></td>
							<td class="text-right"><?=switchIn('name',$fields['name'])?></td>
						</tr>
						<tr>
							<td>E-mail <div class="font-normal text-sm">Dirección de email válida y vigente</div></td>
							<td class="text-right"><?=switchIn('email',$fields['email'])?></td>
						</tr>
						<tr>
							<td>Nro. de Celular <div class="font-normal text-sm">Nro. de celular con nacional</div></td>
							<td class="text-right"><?=switchIn('phone',$fields['phone'])?></td>
						</tr>
						<tr>
							<td>Dirección <div class="font-normal text-sm">Dirección principal del cliente</div></td>
							<td class="text-right"><?=switchIn('address',$fields['address'])?></td>
						</tr>
						<tr>
							<td>Fecha de Nacimiento</td>
							<td class="text-right"><?=switchIn('birthday',$fields['birthday'])?></td>
						</tr>
						<tr>
							<td>Teléfono 2</td>
							<td class="text-right"><?=switchIn('phone2',$fields['phone2'])?></td>
						</tr>
						<tr>
							<td>Dirección 2</td>
							<td class="text-right"><?=switchIn('address2',$fields['address2'])?></td>
						</tr>
						<tr>
							<td>Nota <div class="font-normal text-sm">Nota de uso interno referente al cliente</div></td>
							<td class="text-right"><?=switchIn('note',$fields['note'])?></td>
						</tr>
					</tbody>
				</table>

			</div>
			<div class="col-xs-12 wrapper bg-light lter text-right">
				<a href="#" class="m-t m-r" data-dismiss="modal" aria-hidden="true">Cancelar</a>
				<input class="btn btn-lg btn-info btn-rounded text-u-c font-bold" type="submit" value="Guardar">
			</div>
		</form>
	</div>
	<?php
	dai();
}

if(validateHttp('action') == 'download'){
	ini_set('memory_limit', '2048M');
	include_once("libraries/parsecsv.lib.php");

	$sql 		= 'SELECT contactId,contactName,contactSecondName,contactPhone,contactPhone2,contactEmail,contactTIN,contactAddress,contactAddress2,contactNote,role,type 
				FROM contact 
				WHERE '.$SQLcompanyId.' 
				AND (contactName != "" OR contactSecondName)
				ORDER BY contactName ASC';

	$result 	= $db->Execute($sql);
	$array 		= array();
	$fields 	= array('RAZÓN SOCIAL',TIN_NAME,'NOMBRE Y APELLIDO','TELEFONO','TELEFONO 2','EMAIL','DIRECCION','DIRECCION 2','NOTA','ROL');

	if($result->RecordCount() > 0){

		while (!$result->EOF) {
			if($result->fields['type'] == 0){
				$type = $allRoleNames[$result->fields['role']]['name'];
			}

			if($result->fields['type'] == 1){
				$type = 'Cliente';
			}

			if($result->fields['type'] == 2){
				$type = 'Proveedor';
			}

			$var 		= array(
								$result->fields['contactName'],
								$result->fields['contactTIN'],
								$result->fields['contactSecondName'],
								$result->fields['contactPhone'],
								$result->fields['contactPhone2'],
								$result->fields['contactEmail'],
								$result->fields['contactAddress'],
								$result->fields['contactAddress2'],
								$result->fields['contactNote'],
								$type
							);
	
			array_push($array, $var);
			$result->MoveNext(); 
		}
	}	

	$csv = new parseCSV();
	$csv->output("contacts_".date('Y-m-d').".csv", $array, $fields);
	dai();
}

if(validateHttp('action') == 'recordList'){
	$record 	= ncmExecute('SELECT * FROM customerRecord WHERE ' . $SQLcompanyId . ' ORDER BY customerRecordSort ASC',[],false,true);

	if($record){
		while (!$record->EOF) {
			$id 		= enc($record->fields['customerRecordId']);
			$name 		= $record->fields['customerRecordName'];
		?>
		<section class="panel panel-default no-bg clear r-3x" id="<?=$id?>">
			<header class="panel-heading bg-light no-border text-u-c">
				<span id="name<?=$id?>"><?=$name?></span>
				<ul class="nav nav-pills pull-right"> 
					<li><a href="<?=$baseUrl?>?action=editRecord&id=<?=$id?>" class="editRecord" data-id="<?=$id?>"><i class="material-icons text-info">create</i></a></li>
					<li><a href="<?=$baseUrl?>?action=deleteRecord&id=<?=$id?>" class="deleteRecord" data-id="<?=$id?>"><i class="material-icons text-danger">close</i></a></li>
					<li><a href="#" class="clicker" data-type="toggle" data-target="#collapse<?=$id?>"><i class="material-icons">keyboard_arrow_down</i></a></li> 
				</ul>
			</header>
			<div class="list-group" id="collapse<?=$id?>" style="display: none;">
				<div class="list-group-item col-xs-12 wrapper">
					<div class="tab-pane bg-white col-xs-12 no-padder active">

							<table class="table fichatable">
								<thead>
									<th>Nombre</th>
									<th>Tipo</th>
									<th>Progreso</th>
									<th>Parámetros</th>
									<th></th>
								</thead>
								<tbody id="options<?=$id?>">
							<?php
							$field 	= ncmExecute('SELECT * FROM cRecordField WHERE customerRecordId = '.dec($id).' ORDER BY cRecordFieldSort ASC',[],false,true);

							if($field){
								$i = 0;
								while (!$field->EOF) {
									$fName 		= $field->fields['cRecordFieldName'];
									$fType 		= $field->fields['cRecordFieldType'];
									$fProgress 	= $field->fields['cRecordFieldProgress'];
									$fId 		= enc($field->fields['cRecordFieldId']);
							?>
								<tr id="rField<?=$fId?>" data-position="<?=$i;?>">
									<td>
										<a href="#" id="" class="editRecordField block m-t-xs" data-id="<?=$fId?>">
											<i class="material-icons m-r-xs md-14">create</i> <span id="name<?=$fId?>"><?=$fName?></span>
										</a>
									</td>
									<td>
										<select id="type<?=$fId?>" data-id="<?=$fId?>" class="typeRecordField form-control no-border b-b no-bg">
										  <option value="0" <?=($fType==0)?'selected':''?>>Texto Corto</option>
										  <option value="1" <?=($fType==1)?'selected':''?>>Texto Largo</option>
										  <option value="2" <?=($fType==2)?'selected':''?>>Número</option>
										  <option value="3" <?=($fType==3)?'selected':''?>>Check</option>
										  <option value="4" <?=($fType==4)?'selected':''?>>Fecha</option>
										  <option value="5" <?=($fType==5)?'selected':''?>>Imagen</option>
										</select>
									</td>
									<td>
										<?=switchIn('switch_' . $fId,(($fProgress > 0) ? true : false),'cRFieldProgress')?>
									</td>
									<td>
										<a href="#" id="numericReportsSettingsBtn<?=$fId?>" class="numericReportsSettings <?=($fType == 2 && $fProgress > 0) ? '' : 'hidden'?> text-danger pull-right m-t-xs" data-id="<?=$fId?>">
											<i class="material-icons">settings</i>
										</a>
									</td>
									<td>
										<a href="#" id="" class="deleteRecordField text-danger pull-right m-t-xs" data-id="<?=$fId?>">
											<i class="material-icons text-danger">close</i>
										</a>
									</td>					
								</tr>
							<?php
									$i++;
									$field->MoveNext(); 
								}
							}

							?>
							</tbody>
						</table>
						<div class="col-xs-12 text-center m-b m-t">
							<a href="#" class="btn addRecordField text-u-c font-bold" data-id="<?=$id?>">
								<span class="text-info">Agregar Campo</span>
							</a>
						</div>

				</div>
			</div>
		</section>

		<?php
			$record->MoveNext(); 
		}
		dai();
	}

	dai('false');
}

if(validateHttp('action') == 'createRecord'){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$name = validateHttp('name');
	if($name){
		$record['customerRecordName']   = $name;
		$record['companyId'] 			= COMPANY_ID;

		$insert = $db->AutoExecute('customerRecord', $record, 'INSERT');

		if($insert === false){}else{
			$id = enc($db->Insert_ID());
			?>
			<section class="panel panel-default no-bg clear" id="<?=$id?>">
				<header class="panel-heading bg-light no-border text-u-c">
					<span id="name<?=$id?>"><?=$name?></span>
					<ul class="nav nav-pills pull-right">
						<li><a href="<?=$baseUrl?>?action=editRecord&id=<?=$id?>" class="editRecord" data-id="<?=$id?>"><i class="material-icons text-info">create</i></a></li> 
						<li><a href="<?=$baseUrl?>?action=deleteRecord&id=<?=$id?>" class="deleteRecord" data-id="<?=$id?>"><i class="material-icons text-danger">close</i></a></li>
						<li><a href="#" class="clicker" data-type="toggle" data-target="#collapse<?=$id?>"><i class="material-icons">keyboard_arrow_down</i></a></li> 
					</ul>
				</header>
				<div class="list-group" id="collapse<?=$id?>" style="display:none;">
					<div class="list-group-item col-xs-12 wrapper">
						<div class="tab-pane bg-white col-xs-12 no-padder active">
							<div id="options<?=$id?>"></div>
							<div class="col-xs-12 text-center m-b m-t">
								<a href="#" class="btn btn-rounded btn-lg btn-icon btn-default addRecordField" data-id="<?=$id?>"><span class="animated text-info font-bold" data-animation="bounceIn">+</span></a>
							</div>
						</div>
					</div>
				</div>
			</section>
			<?php
			dai();
		}
	}
	dai('false');
}

if(validateHttp('action') == 'deleteRecord' && validateHttp('id')){
	if(!allowUser('contacts','delete',true)){
		jsonDieResult(['error'=>true]);
	}

	$id = dec(validateHttp('id'));

	if($id){
		$db->Execute('DELETE FROM cRecordField WHERE customerRecordId = ?', array($id));
		$delete = $db->Execute('DELETE FROM customerRecord WHERE customerRecordId = ? AND '.$SQLcompanyId.' LIMIT 1', array($id));
		if($delete === false){
			dai('false');
		}else{	
			dai('true');
		}
	}
	dai('false');
}

if(validateHttp('action') == 'editRecord' && validateHttp('id')){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$id 	= dec(validateHttp('id'));
	$name 	= validateHttp('name');

	if($id && $name){

		$record['customerRecordName'] 	= $name;

		$update = $db->AutoExecute('customerRecord', $record, 'UPDATE', 'customerRecordId = ' . $id . ' AND '.$SQLcompanyId); 

		if($update === false){
			dai('false');
		}else{	
			dai('true');
		}
	}
	dai('false');
}

if(validateHttp('action') == 'editRecordSort' && validateHttp('id')){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$id 		= dec(validateHttp('id'));
	$name 	= validateHttp('name');

	if($id && $name){

		$record['customerRecordSort'] 	= $name;

		$update = $db->AutoExecute('customerRecord', $record, 'UPDATE', 'customerRecordId = ' . $id . ' AND '.$SQLcompanyId); 

		if($update === false){
			dai('false');
		}else{	
			dai('true');
		}
	}
	dai('false');
}

if(validateHttp('action') == 'createRField'){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$fName 		= validateHttp('name');
	$rid 		= validateHttp('id');
	$customerId	= validateHttp('customerId');
	if($fName && $rid){
		$record['cRecordFieldName'] = $fName;
		$record['customerRecordId'] = dec($rid);

		$insert = $db->AutoExecute('cRecordField', $record, 'INSERT');

		if($insert === false){}else{
			$fId = enc($db->Insert_ID());
			$fProgress 	= 0;
			$fType 		= 0;
			?>
			<tr id="rField<?=$fId?>">
				<td>
					<a href="#" id="" class="editRecordField" data-id="<?=$fId?>">
						<i class="material-icons m-r-xs md-14">create</i> <span id="name<?=$fId?>"><?=$fName?></span>
					</a>
				</td>
				<td>
					<select id="type<?=$fId?>" data-id="<?=$fId?>" class="typeRecordField form-control">
					  <option value="0" <?=($fType==0)?'selected':''?>>Texto Corto</option>
					  <option value="1" <?=($fType==1)?'selected':''?>>Texto Largo</option>
					  <option value="2" <?=($fType==2)?'selected':''?>>Número</option>
					  <option value="3" <?=($fType==3)?'selected':''?>>Check</option>
					  <option value="4" <?=($fType==4)?'selected':''?>>Fecha</option>
					  <option value="5" <?=($fType==5)?'selected':''?>>Imagen</option>
					</select>
				</td>
				<td>
					<?=switchIn($fId,(($fProgress>0)?true:false),'cRFieldProgress')?>
				</td>
				<td>
					<a href="#" id="" class="deleteRecordField text-danger pull-right" data-id="<?=$fId?>">
						<i class="material-icons text-danger">close</i>
					</a>
				</td>					
			</tr>
			<?php
			dai();
		}
	}
	dai('false');
}

if(validateHttp('action') == 'deleteRField' && validateHttp('id')){
	if(!allowUser('contacts','delete',true)){
		jsonDieResult(['error'=>true]);
	}

	$id = dec(validateHttp('id'));

	if($id){
		$delete = $db->Execute('DELETE FROM cRecordField WHERE cRecordFieldId = ?', array($id));
		if($delete === false){
			dai('false');
		}else{
			dai('true');
		}
	}
	dai('false');
}

if(validateHttp('action') == 'editRField' && validateHttp('id')){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$id 	= dec(validateHttp('id'));
	$name 	= validateHttp('name');

	if($id && $name){

		$record['cRecordFieldName'] 	= $name;

		$update = $db->AutoExecute('cRecordField', $record, 'UPDATE', 'cRecordFieldId = '.$id); 

		if($update === false){
			dai('false');
		}else{	
			dai('true');
		}
	}
	dai('false');
}

if(validateHttp('action') == 'editRFieldType' && validateHttp('id')){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$id 	= dec( validateHttp('id') );
	$value 	= ($_GET['val'] == 0) ? 0 : validateHttp('val');

	if($id && $value > -1){
		$record['cRecordFieldType'] 	= $value;
		$rupdate 	= ncmUpdate(['records' => $record, 'table' => 'cRecordField', 'where' => 'cRecordFieldId = ' . $id]);
		$update 	= $rupdate['error'] ? false : true;

		if($update === false){
			dai('false');
		}else{	
			dai('true');
		}
	}
	dai('false');
}

if(validateHttp('action') == 'editRFieldSort' && validateHttp('id')){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$id 	= dec(validateHttp('id'));
	$value 	= validateHttp('val');

	if($id && $value){
		$record['cRecordFieldSort'] 	= $value;
		$update = $db->AutoExecute('cRecordField', $record, 'UPDATE', 'cRecordFieldId = ' . $id); 

		if($update === false){
			dai('false');
		}else{	
			dai('true');
		}
	}
	dai('false');
}

if(validateHttp('action') == 'editRFieldProgress' && validateHttp('id')){
	if(!allowUser('contacts','edit',true)){
		jsonDieResult(['error'=>true]);
	}

	$id 	= dec(validateHttp('id'));
	$value 	= validateHttp('val');

	if($id){
		$record['cRecordFieldProgress'] 	= $value;
		$update = $db->AutoExecute('cRecordField', $record, 'UPDATE', 'cRecordFieldId = '.$id); 

		if($update === false){
			dai('false');
		}else{	
			dai('true');
		}
	}
	dai('false');
}

if(validateHttp('action') == 'generalTable'){

	//$db->query("SET NAMES 'utf8'");

	$search 	= '';
	$role 		= '';
	$nameTitle 	= 'Nombre/Razón Social';
	$fullnameTitle = '';
	$categories = getAllTaxonomy('contactCategory');
	$table 		= '';
	$cache 		= false;
	$singleRow 	= '';
	$customerFields = '*';
	$limits 	= getTableLimits($limitDetail,$offsetDetail);
	if(validateHttp('singleRow')){
		$singleRow 	= ' AND contactId = ' . dec(validateHttp('singleRow'));
		$limits 	= ' LIMIT 1';
	}

	//si es la primera carga de pagina muestro cache
	if(validateHttp('frst')){
		$cache = true;
	}

	if(validateHttp('src')){
		$word 	= validateHttp('src');
		$search = ' AND (contactName LIKE "%' . $word . '%" OR contactTIN LIKE "%' . $word . '%")';
	}

	if($_rol){
		
		if($_rol == 'user'){
			$nameTitle 	= 'Nombre y Apellido';
			$sql 	= 'SELECT *
					FROM contact 
					WHERE ' . $SQLcompanyId . $search . ' AND type = 0' . $singleRow . ' 
					ORDER BY role ASC, contactId ASC ' . $limits;
		}else if($_rol == 'customer'){
			$nameTitle 	= 'Razón Social';
			$sql 	= 'SELECT ' . $customerFields . '
					FROM contact 
					WHERE ' . $SQLcompanyId . $search . ' AND type = 1' . $singleRow . ' 
					ORDER BY contactId DESC ' . $limits;
		}else if($_rol == 'supplier'){
			$nameTitle 	= 'Razón Social';
			$sql 	= 'SELECT *
					FROM contact 
					WHERE ' . $SQLcompanyId . $search . ' AND type = 2' . $singleRow . ' 
					ORDER BY contactId DESC ' . $limits;
		}
	}else{
		/*$sql 		= 'SELECT *
					FROM contact 
					WHERE ' . $SQLcompanyId . $search . $role . $singleRow . ' 
					ORDER BY contactDate ASC ' . $limits;*/
		$sql 		= 'SELECT ' . $customerFields . '
					FROM contact 
					WHERE ' . $SQLcompanyId . $search . $role . $singleRow . ' 
					ORDER BY contactId DESC ' . $limits;
	}

	$result 	= ncmExecute($sql,[],$cache,true);
	$table 		= '';
	$userCount 	= 1;
	$supCount 	= 1;

	if($_rol == 'user'){
		$head = 		'<thead class="text-u-c">'.
						'	<tr>'.
						'		<th>ID</th>' .
						'		<th>Nombre y Apellido</th>' .
						' 		<th>Doc. de Identidad</th>' .
						'		<th>Creado</th>'.
						'		<th>Teléfono</th>'.
						'		<th>Email</th>'.
						'		<th>Dirección</th>'.
						'		<th>Rol</th>'.
						'		<th>Estado</th>'.
						'		<th>Sucursal</th>'.
						'	</tr>'.
						'</thead>'.
						'<tbody>';
	}

	if($_rol == 'customer'){
		$head = 		'<thead class="text-u-c">' .
						'	<tr>' .
						'		<th>ID</th>' .
						'		<th>Nombre/Razon Social</th>' .
						'		<th>' . TIN_NAME . '</th>' .
						'		<th>Nombre y Apellido</th>' . 
						' 		<th>Doc. de Identidad</th>' .
						'		<th>Creado</th>' .
						'		<th>Teléfono</th>' .
						'		<th>Teléfono 2</th>' .
						'		<th>Email</th>' .
						'		<th>Dirección</th>' .
						'		<th>Localidad</th>' .
						'		<th>Ciudad</th>' .
						'		<th>Nota</th>' .
						'		<th>Score</th>' .
						'		<th>Loyalty</th>' .
						'		<th>Distancia (Km)</th>' .
						'	</tr>' .
						'</thead>' .
						'<tbody>';
	}

	if($_rol == 'supplier'){
		$head = 		'<thead class="text-u-c">' .
						'	<tr>' .
						'		<th>ID</th>' .
						'		<th>Nombre/Razon Social</th>' .
						'		<th>'.TIN_NAME.'</th>' .
						'		<th>Encargado/a</th>' .
						'		<th>Creado</th>' .
						'		<th>Teléfono</th>' .
						'		<th>Email</th>' .
						'		<th>Dirección</th>' .
						'		<th>Categoria</th>' .
						'	</tr>' .
						'</thead>' .
						'<tbody>';
	}


	if($result){

		$allAddress     = [];
		$scoring 		= '-';
		$distance 		= '-';
		$loyalty 		= '-';		

		if($_rol == 'customer'){
			$customersIds 	= getAllByIDBuild($result,'contactUID');
	        $allAddress 	= getAllCustomersAddress($customersIds);
	    }

		while (!$result->EOF) {
			$field 		= $result->fields;
			$itemId 	= enc($field['contactId']);
			$typeEnc 	= enc($field['type']);

			if($field['type'] == 0){
				$type = $allRoleNames[$field['role']]['name'];
				$icon = 'person_pin';
				$label = 'bg-info lter';
				if(($plansValues[PLAN]['max_users'] * OUTLETS_COUNT) + EXTRA_USERS >= $userCount){
					$allow = true;
				}else{
					$allow = false;
				}
				$userCount++;

				$contactName = $field['contactName'];
			}

			if($field['type'] == 1){
				$type = 'Cliente';
				$label = 'bg-light';
				$icon = 'person';
				$allow = true;

				if($field['contactName']){
					$contactName = $field['contactName'];
				}else{
					$contactName = $field['contactSecondName'];
				}

				$loyalty = formatCurrentNumber($field['contactLoyaltyAmount']);				
			}

			if($field['type'] == 2){
				$type = 'Proveedor';
				$label = 'bg-dark lter';
				$icon = 'local_shipping';
				if($plansValues[PLAN]['max_suppliers'] >= $supCount){
					$allow = true;
				}else{
					$allow = false;
				}
				$supCount++;
				$contactName = $field['contactName'];
			}

			$iconColor 		= 'bg-light dk';
			$contactColor 	= '';
			$statusIcon 	= '<i class="material-icons text-success">check</i>';
			if($field['contactStatus'] < 1){
				$iconColor 		= 'bg-danger lter';
				$statusIcon 	= '<i class="material-icons text-danger">close</i>';
			}else if($field['type'] == 0 && $field['contactColor']){
				$iconColor = '';
				$contactColor = 'border-left:5px solid #' . $field['contactColor'];
			}

			$contactBillingName	= $field['contactName'];
			$contactName 		= $field['contactSecondName'];

			$outlet 			= iftn($field['outletId'], 'Todas', getCurrentOutletName($field['outletId']));
			$category 			= iftn($field['categoryId'], '-', $categories[$field['categoryId']]['name']);

			//if($type == 'Cliente' && $cAdd['customerAddressText']){
			if($_rol == 'customer'){
				$cAdd 						= $allAddress[$field['contactUID']];

				$field['contactAddress'] 	= $cAdd['address'];
				$field['contactLocation'] 	= $cAdd['location'];
				$field['contactCity'] 		= $cAdd['city'];
				$outletLatLng 				= getAllOutlets();
				$oLat 						= $outletLatLng[OUTLET_ID]['lat'];
				$oLng 						= $outletLatLng[OUTLET_ID]['lng'];
				$distance					= coorsToKms($oLat, $oLng, $cAdd['lat'], $cAdd['lng']);
			}

			if($allow){

				if($_rol == 'user'){
					$table .= 	'<tr data-id="' . $itemId . '" class="clickrow ' . $itemId . '">' .
								'	<td style="' . $contactColor . '">' . $itemId . '</td>' .
								'	<td class="font-bold">' .
										toUTF8($contactBillingName) .
								' 	</td>' .
								'	<td>' . $field['contactTIN'] . '</td>' .
								'	<td data-order="' . $field['contactDate'] . '"> ' . niceDate($field['contactDate'],true) . ' </td>' .
								'	<td>' . $field['contactPhone'] . '</td>' .
								'	<td>' . $field['contactEmail'] .'</td>' .
								'	<td>' . toUTF8($field['contactAddress']) .'</td>' .
								'	<td> <span class="label ' . $label . '">' . $type . '</span> </td>' .
								'	<td class="text-center">' . $statusIcon . '</td>' .
								'	<td>' . $outlet . '</td>' .
								'</tr>';
				}

				if($_rol == 'customer'){
					
					$table .= 	'<tr data-id="' . $itemId . '" class="clickrow ' . $itemId . '">' .
								'	<td style="' . $contactColor . '">' . $itemId . '</td>' .
								'	<td class="font-bold">' .
										toUTF8($contactBillingName) .
								' 	</td>' .
								'	<td>' . $field['contactTIN'] . '</td>' .
								'	<td class="font-bold">' .
										toUTF8($contactName) .
								' 	</td>' .
								'	<td>' . $field['contactCI'] . '</td>' .
								'	<td data-order="' . $field['contactDate'] . '"> ' . niceDate($field['contactDate'],true) . ' </td>' .
								'	<td>' . $field['contactPhone'] . '</td>' .
								'	<td>' . $field['contactPhone2'] .'</td>' .
								'	<td>' . $field['contactEmail'] .'</td>' .
								'	<td>' . $field['contactAddress'] .'</td>' .
								'	<td>' . $field['contactLocation'] .'</td>' .
								'	<td>' . $field['contactCity'] .'</td>' .
								'	<td>' . $field['contactNote'] . '</td>' .
								'	<td class="tdNumeric">' . $scoring . '</td>' .
								'	<td class="tdNumeric">' . $loyalty . '</td>' .
								'	<td class="tdNumeric">' . $distance . '</td>' .
								'</tr>';
				}

				if($_rol == 'supplier'){

					$table .= 	'<tr data-id="' . $itemId . '" class="clickrow ' . $itemId . '">' .
								'	<td style="' . $contactColor . '">' . $itemId . '</td>' .
								'	<td class="font-bold">' .
										toUTF8($contactBillingName) .
								' 	</td>' .
								'	<td>' . $field['contactTIN'] . '</td>' .
								'	<td class="font-bold">' .
										toUTF8($contactName) .
								' 	</td>' .
								'	<td data-order="' . $field['contactDate'] . '"> ' . niceDate($field['contactDate'],true) . ' </td>' .
								'	<td>' . $field['contactPhone'] . '</td>' .
								'	<td>' . $field['contactEmail'] .'</td>' .
								'	<td>' . toUTF8($field['contactAddress']) .'</td>' .
								'	<td>' . $category . '</td>' .
								'</tr>';

				}

				if(validateHttp('part') && !validateHttp('singleRow')){
		        	$table .= '[@]';
		        }
			}
			
			$result->MoveNext(); 
		}
	}

	if($_rol == 'user'){
		$foot = '</tbody><tfoot><tr><td colspan="10"></td></tr></tfoot>';
	}

	if($_rol == 'customer'){
		$foot = '</tbody><tfoot><tr><td colspan="16"></td></tr></tfoot>';
	}

	if($_rol == 'supplier'){
		$foot = '</tbody><tfoot><tr><td colspan="9"></td></tr></tfoot>';
	}

	if(validateHttp('part')){
		dai($table);
	}else{
		$fullTable = $head . $table . $foot;
		$jsonResult['table'] = $fullTable;

		header('Content-Type: application/json'); 
		dai(json_encode($jsonResult));
	}
}

?>

<div class="hidden-print col-xs-12">
	<div class="m-t-sm col-sm-6 col-xs-9 no-padder">
		<a href="/@#contacts?rol=user" class="m-r">Usuarios</a>
		<a href="/@#contacts?rol=customer" class="m-r">Clientes</a>
		<a href="/@#contacts?rol=supplier" class="m-r-sm">Proveedores</a>				
	</div>
	<div class="col-sm-6 col-xs-3 no-padder">
		<div class="btn-group m-r-xs pull-right"> 
			<button class="btn btn-info btn-rounded bg-info dk dropdown-toggle" data-toggle="dropdown"><span class="m-r-sm font-bold text-u-c">Crear</span><span class="caret"></span></button> 
			<ul class="dropdown-menu"> 
				<li class="create" data-type="<?=enc(0);?>"><a href="#">Usuario</a></li> 
				<li class="create" data-type="<?=enc(1);?>"><a href="#">Cliente</a></li> 
				<li class="create" data-type="<?=enc(2);?>"><a href="#">Proveedor</a></li>
				<li class="divider"></li>
				<li><a href="#" id="createRecord">Fichas</a>
				<li><a href="<?=$baseUrl?>?action=mandatory" id="mandatory">Campos Obligatorios</a></li>
				<li><a href="<?=$baseUrl?>?action=rolePermissions" id="rolesSettings">Roles</a></li>
				<li><a href="<?=$baseUrl?>?action=formCSV" id="bulkUpload">Múltiples contactos</a></li>
				<li><a href="<?=$baseUrl?>?action=formCSVFichas" id="createRecordBulk">Cargar Fichas</a></li>

				
			</ul>  
		</div>
	</div>
</div>

<div class="col-xs-12 wrapper text-right">
	<?=headerPrint();?>
    <span class="font-bold h1" id="pageTitle">
    	Contactos
    </span>
</div>

<div class="col-xs-12 wrapper bg-white panel r-24x tableContainer push-chat-down table-responsive">
    <table class="table table1 hover col-xs-12 no-padder" id="tableContacts">
        <?=placeHolderLoader('table')?>
    </table>
</div>


<div id="noRecordsFoundMsg" class="hidden">
	<?=noDataMessage("No posee fichas","Las fichas de clientes le permite crear un perfil personalizado de cada cliente","emptystate2.png")?>
</div>

<div class="modal fade" id="modalRecords" role="dialog">
    <div class="modal-dialog modal-md">
      <div class="modal-content no-bg no-border all-shadows">
        <div class="modal-body bg-white clear r-24x no-padder">
        	
        	<div class="h3 font-bold wrapper">Fichas de Clientes <a href="#" class="btn btn-info btn-md btn-rounded pull-right font-bold text-u-c" id="addRecord">Nueva Ficha</a></div>
        	
        	<div id="recordsList" class="wrapper">
        		<?=placeHolderLoader('table-sm');?>
        	</div>
		
        </div>
        <div class="hidden">
			<table>
				<tbody id="lineTemplateRecord">
					<tr class="lineAddRecord">
						<td><input type="text" name="name" class="form-control" placeholder="Nombre"></td>
						<td><input type="text" name="type" class="form-control"></td>
						<td><input type="text" name="value" class="form-control" placeholder="Valor"></td>
						<td></td>
					</tr>
				</tbody>
			</table>
		</div>
      </div>
    </div>
</div>

<div id="dropBag" class="ui-widget-header no-border"></div>

<script type="text/html" id="numberGraphConfig">
	<div class="col-xs-12 no-padder text-md text-left" style="width:300px; min-width:200px;">
		<div class="font-bold text-u-c col-xs-12 no-padder">Tipo de gráfico</div>
		<div class="col-xs-6 wrapper h3 font-bold r-24x b b-info m-t pointer numericReportsTypeGraph {{graphTypeLinear}}" data-id="{{id}}" data-type="line">Lineal</div><div class="col-xs-6 wrapper h3 font-bold r-24x b m-t pointer numericReportsTypeGraph {{graphTypeBars}}" data-id="{{id}}" data-type="bars">Barras</div>

		<div class="font-bold text-u-c col-xs-12 no-padder m-t-md">Parámetros</div><div class="col-xs-12 no-padder">
			<div class="col-xs-6 no-padder">
				<small class="font-bold text-u-c">Texto</small>
				<input type="text" name="" class="form-control no-bg no-border b-b" placeholder="Ej. Mínimo">
			</div><div class="col-xs-6 no-padder">
				<small class="font-bold text-u-c">Valor</small>
				<input type="tel" name="" class="form-control no-bg no-border b-b text-right font-bold" placeholder="Ej. 5">
			</div>
			<div class="col-xs-6 no-padder">
				<small class="font-bold text-u-c">Texto</small>
				<input type="text" name="" class="form-control no-bg no-border b-b" placeholder="Ej. Medio">
			</div><div class="col-xs-6 no-padder">
				<small class="font-bold text-u-c">Valor</small>
				<input type="tel" name="" class="form-control no-bg no-border b-b text-right font-bold" placeholder="Ej. 15">
			</div>
			<div class="col-xs-6 no-padder">
				<small class="font-bold text-u-c">Texto</small>
				<input type="text" name="" class="form-control no-bg no-border b-b" placeholder="Ej. Máximo">
			</div><div class="col-xs-6 no-padder">
				<small class="font-bold text-u-c">Valor</small>
				<input type="tel" name="" class="form-control no-bg no-border b-b text-right font-bold" placeholder="Ej. 20">
			</div>
			<div class="col-xs-6 no-padder">
				<small class="font-bold text-u-c">Texto</small>
				<input type="text" name="" class="form-control no-bg no-border b-b" placeholder="Ej. Muy alto">
			</div><div class="col-xs-6 no-padder">
				<small class="font-bold text-u-c">Valor</small>
				<input type="tel" name="" class="form-control no-bg no-border b-b text-right font-bold" placeholder="Ej. 25">
			</div>
		</div>

	</div>
</script>

<script>
	window.baseUrl 	= '<?=$baseUrl;?>';
	var loadUrl 		= baseUrl + "?action=generalTable<?=$_GET['rol']?'&rol='.$_GET['rol']:''?>";
	var _rol 				= '<?=$_rol?>';
	var offset 			= '<?=$offsetDetail?>';
	var limit 			= '<?=$limitDetail?>';
	var tin_name 		= '<?=TIN_NAME?>';

	<?php
	if($_GET['update']){
	  ob_start();
	?>
	$(document).ready(function(){
		FastClick.attach(document.body);
		
		var checkIfAdmin = function(){
	      var state = $('.role').val();
	      if(state == 1 || state == 2){
	        $(".pass").prop('disabled', false).val('');
	      }else{
	        $(".pass").prop('disabled', true).val('');
	      }
	    };

		$.get(loadUrl,function(result){

			if(_rol == 'user'){
				var sortBy = 3;
			
				var tableColumns = [
										{"index":0,"name":"ID","visible":false},
										{"index":1,"name":"Nombre y Apellido","visible":true},
										{"index":2,"name":'Doc. de Identidad',"visible":false},
										{"index":3,"name":"Creado","visible":true},
										{"index":4,"name":'Teléfono',"visible":true},
										{"index":5,"name":'Email',"visible":false},
										{"index":6,"name":'Dirección',"visible":false},
										{"index":7,"name":'Rol',"visible":true},
										{"index":8,"name":'Estado',"visible":false},
										{"index":9,"name":'Sucursal',"visible":true}
									];

			}

			if(_rol == 'customer'){
				var sortBy = 5;
				var tableColumns = [
										{"index":0,"name":"ID","visible":false},
										{"index":1,"name":"Nombre/Razón Social","visible":true},
										{"index":2,"name":tin_name,"visible":false},
										{"index":3,"name":"Nombre y Apellido","visible":false},
										{"index":4,"name":'Doc. de Identidad',"visible":false},
										{"index":5,"name":"Creado","visible":true},
										{"index":6,"name":'Teléfono',"visible":true},
										{"index":7,"name":'Teléfono 2',"visible":false},
										{"index":8,"name":'Email',"visible":true},
										{"index":9,"name":'Dirección',"visible":false},
										{"index":10,"name":'Localidad',"visible":false},
										{"index":11,"name":'Ciudad',"visible":false},
										{"index":12,"name":'Nota',"visible":false},
										{"index":13,"name":'Score',"visible":false},
										{"index":14,"name":'Loyalty',"visible":false},
										{"index":15,"name":'Distancia',"visible":false}
									];
			}
			if(_rol == 'supplier'){
				var sortBy = 4;
				var tableColumns = [
										{"index":0,"name":"ID","visible":false},
										{"index":1,"name":"Nombre/Razón Social","visible":true},
										{"index":2,"name":tin_name,"visible":false},
										{"index":3,"name":"Encargado/a","visible":true},
										{"index":4,"name":"Creado","visible":true},
										{"index":5,"name":'Teléfono',"visible":false},
										{"index":6,"name":'Email',"visible":false},
										{"index":7,"name":'Dirección',"visible":false},
										{"index":8,"name":'Categoría',"visible":true}
									];
			}

			window.tableOps = {
		            "container"   		: ".tableContainer",
		            "url"       		: loadUrl,
		            "rawUrl" 			: loadUrl,
		            "iniData" 			: result.table,
		            "table"     		: "#tableContacts",
		            "sort"      		: sortBy,
					"search" 				: 'detailTableSearch',
					"offset" 				: parseInt(offset),
					"limit" 				: parseInt(limit),
					"nolimit" 			: true,
					"tableName" 		: 'tableContacts',
					"fileTitle" 		: 'Contactos',
					"ncmTools"			: {
											left 	: 	'',
											right 	: 	'<input type="text" class="form-control rounded no-border bg-light lter" placeholder="Buscar por Nombre o ' + tin_name + '" id="detailTableSearch" data-url="' + loadUrl + '&qry=">'
										  },
					"colsFilter"		: {
											name 	: _rol + 'Listing',
											menu 	:  tableColumns
										  },
				  "clickCB" 			: function(event,tis){
										  	checkIfAdmin();
											var id = tis.data('id');
											helpers.loadPageLoad = false;
											window.location.hash = 'contacts&i=' + id;
											$(window).trigger('hashvarchange');
				  						}
			};

			ncmDataTables(tableOps,function(oTable,_scope){
				loadTheTable(tableOps,oTable,_scope);
			});

		});

		var loadTheTable = function(tableOps,oTable,_scope){
			
			$('[data-toggle="tooltip"]').tooltip();

			onClickWrap('#alphabet span',function(event,tis){
				$('#alphabet').find('.active').removeClass('active');
				
				if(tis.hasClass('null')){
					_alphabetSearch = false;
				}else{
					_alphabetSearch = tis.text();
					tis.addClass('active');
				}
				otable.draw();
			},false,true);

			onClickWrap('.deleteItem',function(event,tis){
				var $tr 		= $('.editting');

				confirmation('Realmente desea eliminar?',function(conf){
					if(conf){
						var load = tis.data('load'); 
						
						oTable.row($tr).remove().draw();
						$('#modalLarge').modal('hide');

						$.get(load, function(response) {
							if(response == 'true'){
								message('Contacto eliminado','success');
							}else{
								message('Error al eliminar','danger');
							}
						});
					}
				});
			},false,true);

			onClickWrap('.viewAccount',function(event,tis){
				var $panel = $('#customerInfoPanel');
				var id = tis.data('id');
				spinner('#customerInfoPanel', 'show');
				$.get(baseUrl + "?action=getCustomerAccount&id="+id,function(data){
					$panel.html(data);
					spinner('#customerInfoPanel', 'hide');
				});
			},false,true);

			onClickWrap('.cancelItemView',function(event,tis){
				$('#modalLarge').modal('hide');
			},false,true);				

		    var timout 		= false;
		    var srcValCache = '';
		    $('#detailTableSearch').on('keyup',function(e){
		    	var $tis 	= $(this);
		    	var value 	= $tis.val();
		    	var tmout 	= 800;
		    	var code 	= e.keyCode || e.which;

				if(code == 13) { //Enter keycode
					//Do something
			    	if(value.length > 3){
			    		value = $.trim(value);
			    		if(!value || srcValCache == value){
			    			return false;
			    		}

		    			spinner(tableOps.container, 'show');
		    			$.get(tableOps.rawUrl + '&src=' + value + '&part=1&nolimit=1',function(result){
		    				oTable.rows().remove().draw();
		    				if(result){
		    					var line 	= explodes('[@]',result);
		    					$.each(line,function(i,data){
		    						if(data){
	                        			oTable.row.add($(data)).draw();
	                        		}
		    					});
		    				}

		    				_scope.events();

		    				$('.lodMoreBtnHolder').addClass('hidden');
		    				spinner(tableOps.container, 'hide');
			    		});
			    		
			    		srcValCache = value;

			    	}else if(value.length < 1 || !value){
			    		srcValCache = '';
		    			ncmDataTablesReset(oTable,tableOps);
			    	}
			    }
		    });

		    <?php
		    if(validateHttp('a')){
		    	echo '$("#' . validateHttp('a') . '").trigger("click");';
		    }
		    ?>
		};

		switchit(function(tis,isActive){
			if(tis.hasClass('cRFieldProgress')){
				var tid 		= tis.attr('id');
				var id 			= tid.split('_')[1];
				var $checkbox 	= tis.find('input');
				var isChecked 	= $checkbox.attr('checked') ? true : false;
				var str 		= 0;
				if(isChecked){
					str = 1;
				}

				var typeVal 	= $('#type' + id).val();
				if(typeVal == 2 && isChecked){
					$('#numericReportsSettingsBtn' + id).removeClass('hidden');
				}else{
					$('#numericReportsSettingsBtn' + id).addClass('hidden');
				}

				
				spinner('body', 'show');
				$.get(baseUrl + '?action=editRFieldProgress&val=' + str + '&id=' + id,function(result){
					spinner('body', 'hide');
				});
			}
		},true);

		var opts = {
		  	readAsDefault: 'ArrayBuffer',
			dragClass : 'dker',
			on: {
				beforestart: function(){
					spinner('body', 'show');
				},
			    load: function(e, file) {
			    	var result 		= new Uint8Array(e.target.result);
			      	var xlsread 	= XLSX.read(result, {type: 'array'});
					var xlsjson 	= XLSX.utils.sheet_to_json(xlsread.Sheets.Sheet1);
			    	//console.log(xlsjson);

			    	$.ajax({
						url 			: '/a_contacts?action=file&debug=1',
						type 			: "POST",
						data 			: {"data":JSON.stringify(xlsjson)},
						success 		: function(result){
							if(result.success){
								message('Archivo subido, ' + result.inserted + ' creados y ' + result.updated + ' actualizados','success');

								setTimeout(function(){
									location.reload();
									spinner('body', 'hide');
								},2000);
							}
						}
					});
			    }
			}
		};

		$(".table-responsive").fileReaderJS(opts);

		onClickWrap('#createRecord',function(event,tis){
			$('#modalRecords').modal('show');
		},false,true);

		onClickWrap('#createProgress',function(event,tis){
			$('#modalLarge').modal('show');
		},false,true);

		$('#modalRecords').off('shown.bs.modal').on('shown.bs.modal', function () {
			spinner('body', 'show');
			$.get(baseUrl + '?action=recordList',function(result){
				//actualizo tabla de records
				if(result != 'false'){
					$('#recordsList').html(result);
				}else{
					$('#recordsList').html($('#noRecordsFoundMsg').html());
				}

				spinner('body', 'hide');

				$('.fichatable tbody').sortable({
					stop: function( event, ui ) {
						var $list = $(this).closest('tbody').find('tr');
                        $list.each(function(i,val){
                            var id = $(this).attr('id').replace('rField','');
                            $.get(baseUrl + '?action=editRFieldSort&val=' + i + '&id=' + id);
                        });
					}
				});

				$( "#recordsList" ).collapse().sortable({
			  	connectWith: "#dropBag",
			  	handle: ".panel-heading",
					stop: function( event, ui ) {
                        $('#recordsList .panel-heading').each(function(i,val){
                            var id = $(this).data('id');
                            $.get(baseUrl + '?action=editRecordSort&name=' + i + '&id=' + id);
                        });
          }
			  }); 

			  $( "#dropBag" ).sortable({
			    connectWith: "#recordsList"
			  }); 

			  /*$('#recordsList .panel-default').sortable({
					handle: ".panel-heading",
					stop: function( event, ui ) {
                        $('#recordsList .panel-heading').each(function(i,val){
                            var id = $(this).attr('id');
                            $.get(baseUrl + '?action=editRecordSort&name=' + i + '&id=' + id);
                        });
					}
				});*/

				$('select.typeRecordField').off('change').on('change',function(){
					var tis 	= $(this);
					var id 		= tis.data('id');
					var value 	= tis.val();

					var isChecked = $('#switch_' + id).find('input').val();
					if(value == 2 && isChecked){
						$('#numericReportsSettingsBtn' + id).removeClass('hidden');
					}else{
						$('#numericReportsSettingsBtn' + id).addClass('hidden');
					}
					
					spinner('body', 'show');
					$.get(baseUrl + '?action=editRFieldType&val=' + value + '&id=' + id,function(result){
						if(result != 'false'){
							$('#type' + id).val(value);
							message('Guardado','success');
						}
						spinner('body', 'hide');
					});
				});
				
			});

		});
		
		onClickWrap('#addRecord',function(e,tis){
			prompter('Nombre de la Ficha',function(str){
				if(str){
					spinner('body', 'show');
					$.get(baseUrl + '?action=createRecord&name=' + str,function(result){
						if(result != 'false'){
							$(result).prependTo('#recordsList');
							$('.noDataMessage').remove();
						}
						spinner('body', 'hide');
					});
				}
			});
		},false,true);

		onClickWrap('.editRecord',function(e,tis){
			var id 		= tis.data('id');
			var cname 	= $('#name' + id).text();
			prompter('Nuevo nombre de la Ficha',function(str){
				if(str){
					spinner('body', 'show');
					$.get(baseUrl + '?action=editRecord&name=' + str + '&id=' + id,function(result){
						if(result != 'false'){
							$('#name' + id).text(str);
						}
						spinner('body', 'hide');
					});
				}
			},cname);
		},false,true);

		onClickWrap('.deleteRecord',function(e,tis){
			confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
				if (e) {
					var id = tis.data('id');
					spinner('body', 'show');
					$.get(baseUrl + '?action=deleteRecord&id=' + id,function(result){
						if(result == 'true'){
							$('#' + id).remove();
							message('Eliminado','success');
						}
						spinner('body', 'hide');
					});
				}
			});
		},false,true);

		onClickWrap('.addRecordField',function(e,tis){
			var id 			= tis.data('id');
			prompter('Nombre del Campo ej. (Peso)',function(str){
				if(str){
					spinner('body', 'show');
					$.get(baseUrl + '?action=createRField&name=' + str + '&id=' + id,function(result){
						if(result != 'false'){
							$(result).prependTo('#options' + id);
							$('.noDataMessage').remove();
						}
						spinner('body', 'hide');
					});
				}
			});
		},false,true);

		onClickWrap('.editRecordField',function(e,tis){
			var id 		= tis.data('id');
			var cname 	= $('#name' + id).text();
			prompter('Nuevo nombre del Campo',function(str){
				if(str){
					spinner('body', 'show');
					$.get(baseUrl + '?action=editRField&name=' + str + '&id=' + id,function(result){
						if(result != 'false'){
							$('#name' + id).text(str);
						}
						spinner('body', 'hide');
					});
				}
			},cname);
		},false,true);

		onClickWrap('.deleteRecordField',function(e,tis){
			confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
				if (e) {
					var id = tis.data('id');
					spinner('body', 'show');
					$.get(baseUrl + '?action=deleteRField&id=' + id,function(result){
						if(result == 'true'){
							$('#rField' + id).remove();
							message('Eliminado','success');
						}
						spinner('body', 'hide');
					});
				}
			});
		});

		onClickWrap('.numericReportsSettings',function(e,tis){
			
			var content = ncmHelpers.mustacheIt($('#numberGraphConfig'),{},false,true);

			ncmDialogs.alert(content,false,'Configuración de reporte',function(){
				
			});

			return false;

			confirmation('Seguro/a que desea continuar? Esta acción no se podrá deshacer.', function (e) {
				if (e) {
					var id = tis.data('id');
					spinner('body', 'show');
					$.get(baseUrl + '?action=deleteRField&id=' + id,function(result){
						if(result == 'true'){
							$('#rField' + id).remove();
							message('Eliminado','success');
						}
						spinner('body', 'hide');
					});
				}
			});
		});

		$(window).off('hashvarchange').on('hashvarchange', function() {

			var rawHash 	= window.location.hash.substring(1);
			var jHash 		= rawHash.split('&').reduce(function (result, item) {
			    var parts 	= item.split('=');
			    result[parts[0]] = parts[1];
			    return result;
			}, {});

			//helpers.loadPageLoad = true;

			if(jHash['i']){
				var tis 	= $('.' + jHash['i']);
				if(!tis.length){
					return false;
				}

				var load 	= baseUrl + '?action=form&id=' + jHash['i'];

				loadForm(load,'#modalLarge .modal-content',function(){
					$('#modalLarge').modal('show');
					$('.lockpass').mask('0000');
					masksCurrency($('.maskInteger'),thousandSeparator,'no');
					masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
				});
			}
		});



		$('#modalLarge').off("hidden.bs.modal").on("hidden.bs.modal", function () {
			if(baseUrl == '/a_contacts'){
				helpers.loadPageLoad = false;
		        window.location.hash = 'contacts';
		        setTimeout(function(){
		        	helpers.loadPageLoad = true;
		        },100);
		    }else{
		    	$('#modalLarge').off("hidden.bs.modal");
		    }
	    });

		onClickWrap('.create',function(event,tis){
			var type = tis.data('type');
	        loadForm(baseUrl + '?action=form&type=' + type,'#modalLarge .modal-content',function(){
              $('#modalLarge').modal('show');
              $('.lockpass').mask('0000');
              masksCurrency($('.maskInteger'),thousandSeparator,'no');
              masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
            });
	    },false,true);

		onClickWrap('#reportDownloadGeneral',function(event,tis){
			var url = baseUrl + '?action=generalTable&download-report=true';
			window.open(url);
		},false,true);

		onClickWrap('#bulkUpload',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalSmall .modal-content',function(){
				$('#modalSmall').modal('show');
			});
		},false,true);

		onClickWrap('#mandatory',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalSmall .modal-content',function(){
				$('#modalSmall').modal('show');
			});
		},false,true);

		onClickWrap('#rolesSettings',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalLarge .modal-content',function(){
				$('#modalLarge').modal('show');
			});
		},false,true);


		onClickWrap('#createRecordBulk',function(event,tis){
			var url		 	= tis.attr('href');
			loadForm(url,'#modalSmall .modal-content',function(){
				$('#modalSmall').modal('show');
			});
		},false,true);

		onClickWrap('#downloadContacts',function(event,tis){
			window.open(baseUrl + '?action=download');
		},false,true);

		$('#modalLarge').off('shown.bs.modal').on('shown.bs.modal', function() {
			onClickWrap('.editContact',function(event,tis){
				var type 	= tis.data('type');
				if(type == 'toggle'){
					var notsearch = $('.contactSearch').data('not');
					select2Ajax({element:'.contactSearch',url:baseUrl + '?action=searchCustomerInputJson&not=' + notsearch,type:'contact'});
				}
			},false,true);

			submitForm('#contactForm',function(element,id){
				$('#modalLarge').modal('hide');
				var $tr 		= $('.editting');
				$.get(tableOps.url + '&part=1&singleRow=' + id,function(data){
					oTable.row($tr).remove();
					oTable.row.add($(data)).draw();
				});
			});
		});

		$('#modalSmall').off('shown.bs.modal').on('shown.bs.modal', function() {
			submitForm('#mandatoryForm',function(element,id){
				$('#modalSmall').modal('hide');

				if(id){
					message('Modificado','success');
				}else{
					message('No se pudo modificar','success');
				}
			});	
		});

		adm();		

		$(window).trigger('hashvarchange');	

	});
<?php
  $script = ob_gets_contents();
  minifyJS([$script => 'scripts' . $baseUrl . '.js']);
}
?>
</script>
<script src="scripts<?=$baseUrl?>.js?<?=date('d.i')?>"></script>

<?php
include_once('includes/compression_end.php');
dai();
?>