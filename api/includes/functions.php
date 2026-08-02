<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/phone.php';

use Mailgun\Mailgun as MailgunClient;
use PHPMailer\PHPMailer\PHPMailer;
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


/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getPaymentMethodName()`. ~25 callers.
 */
function getPaymentMethodName($id,$decode=false){
    return \Punto\App\Domain\Taxonomy::getPaymentMethodName($id, (bool) $decode);
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
	$creator 	= '/screens/shorturl.php?c=';
	$short 		= file_get_contents($creator . rawurlencode($url));//@file_get_contents($creator . $url);
	if($short && $short != 'false'){
		return $short;
	}else{
		return '/';
	}
}

function getImage($name,$w,$h){
	$compId 	= enc(COMPANY_ID);

    $img 		= '../assets/sysimages/'.$name.'.jpg';
    $isImg 		= the_file_exists($img);
    if(!$isImg){
      $img 		= 'images/transparent.png';
    }else{
      $img 		= '/assets/src.php?src='.$img.'&w='.$w.'&h='.$h.'&'.rand();
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
		    $plans[$fields['plan_code'] ?? $fields['id']] = $fields;
		    $result->MoveNext(); 
		}
		$result->Close();
	}
	
	//
	if($planId){
    	return $plans[$planId] ?? [];
	}else{
		return $plans;
	}
}

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getCategoriesIds()`. ~1 caller.
 */
function getCategoriesIds($companyId){
    return \Punto\App\Domain\Taxonomy::getCategoriesIds($companyId);
}

function getItemName($id){
	global $db;
	$obj = ncmExecute("SELECT itemName FROM item WHERE itemId = ?",[$id]);
	return $obj['itemName'];
}

function getItemComsissionTotal($itemId,$count,$total,$isSession=false,$test=false){
	$comission 	= 0;
    // itemComissionPercent / itemComissionType / itemSessions están demoted a data JSONB (migración 07).
    // SELECT * + _flattenJsonb las re-expone como columnas en el CaseInsensitiveArray devuelto.
    $item 		= ncmExecute('SELECT * FROM item WHERE itemId = ? AND companyId = ? LIMIT 1',[$itemId,COMPANY_ID]);

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

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getTaxValue()`. ~8 callers.
 */
function getTaxValue($id){
    return \Punto\App\Domain\Taxonomy::getTaxValue($id);
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

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::getRealCustomerId()`. ~1 caller.
 */
function getRealCustomerId($id){
    return \Punto\App\Domain\Customer::getRealCustomerId($id);
}

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::manageLoyalty()`. ~4 callers.
 */
function manageCustomerLoyalty($type,$amount,$id,$compId=false){
    return \Punto\App\Domain\Customer::manageLoyalty($type, $amount, $id, $compId);
}

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::manageStoreCredit()`. ~2 callers.
 */
function manageCustomerStoreCredit($type,$amount,$id,$compId=false){
    return \Punto\App\Domain\Customer::manageStoreCredit($type, $amount, $id, $compId);
}

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::manageGiftCard()`. ~1 caller.
 */
function manageGiftCard($amount,$id){
    return \Punto\App\Domain\Customer::manageGiftCard($amount, $id);
}

/**
 * @deprecated Slice 14 (PSR-4). Usar `\Punto\App\Domain\GiftCard::insertNew()`. ~1 caller.
 */
function insertNewGiftCard($code,$price,$expires,$trsId,$note,$beneficiaryId,$timestamp,$sendDate,$color){
    return \Punto\App\Domain\GiftCard::insertNew($code, $price, $expires, $trsId, $note, $beneficiaryId, $timestamp, $sendDate, $color);
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
								AND transactionComplete = FALSE
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

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::getAllContacts()`. ~15 callers.
 */
function getAllContacts($type=false,$where=''){
    return \Punto\App\Domain\Customer::getAllContacts($type, (string) $where);
}
/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::getContactField()`. ~4 callers.
 */
function getTheContactField($id,$array,$field='name'){
    return \Punto\App\Domain\Customer::getContactField($id, $array, (string) $field);
}

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::getData()`. ~38 callers.
 */
function getCustomerData($id, $type=false){
    return \Punto\App\Domain\Customer::getData($id, $type);
}

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::getTransactionAddress()`. ~1 caller.
 */
function getCustomerTransactionAddress($transId,$encode=false){
    return \Punto\App\Domain\Customer::getTransactionAddress($transId, (bool) $encode);
}

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::getContactData()`. ~36 callers.
 */
function getContactData($id, $type=false,$cache=false){
    return \Punto\App\Domain\Customer::getContactData($id, $type, $cache);
}

/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::getName()`. ~36 callers.
 */
function getCustomerName($data,$part=false){
    return \Punto\App\Domain\Customer::getName($data, $part);
}
/**
 * @deprecated Slice 9 (PSR-4). Usar `\Punto\App\Domain\Customer::getContactCreditLine()`. ~1 caller.
 */
function getContactCreditLine($uid,$creditLine){
    return \Punto\App\Domain\Customer::getContactCreditLine($uid, $creditLine);
}

/**
 * Gate único de acceso al tenant (bootstrap.php + apiAuthPosContext.php).
 *
 * `blocked` (mora/billing) y `suspended` (mig 110 — suspensión manual admin,
 * columna propia desde P1 review F3: antes compartía `blocked`, y
 * unsuspend() lo pisaba a 0 perdiendo la señal de mora) deniegan acceso con
 * el MISMO efecto, sin importar `status`.
 */
function checkCompanyStatus($id){
	$result = ncmExecute('SELECT status, blocked, suspended FROM company WHERE companyId = ? LIMIT 1',[$id]);

	if (!$result) {
		return false;
	}
	if (!empty($result['blocked']) || !empty($result['suspended'])) {
		return false;
	}

	// Canónico minúscula desde mig 111 (CHECK company_status_allowed).
	return $result['status'] === 'active';
}

function updateLastTimeEdit($id,$table=false){
	global $db;

	$date = TODAY;
	// Las columnas *LastUpdate de company fueron demoted a config JSONB en la migración PG.
	// IMPORTANTE: NO usar ncmExecute aquí — _flattenJsonb desempaqueta `config` y se
	// pierde el JSON original. Read raw vía $db->Execute para preservar todas las keys.
	$rs = $db->Execute('SELECT config FROM company WHERE companyId = ? LIMIT 1', [$id]);
	$config = [];
	if ($rs && !$rs->EOF) {
		$raw = $rs->fields['config'] ?? null;
		if (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) { $config = $decoded; }
		}
	}

	$config['companyLastUpdate'] = $date;
	if ($table == 'customer')      { $config['customersLastUpdate']  = $date; }
	else if ($table == 'item')     { $config['itemsLastUpdate']      = $date; }
	else if ($table == 'calendar') { $config['calendarLastUpdate']   = $date; }
	else if ($table == 'order')    { $config['orderLastUpdate']      = $date; }

	$db->Execute('UPDATE company SET config = ?::jsonb WHERE companyId = ?', [json_encode($config), $id]);
	return $date;
}

function updateRowLastUpdate($table,$where){
	global $db;
	$record 				= array();
	$record['updated_at'] 	= TODAY;
	$db->AutoExecute($table, $record, 'UPDATE', $where);
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

/**
 * @deprecated Slice 11 (PSR-4). Usar `\Punto\App\Domain\Document::getNextDocNumber()`. ~12 callers.
 */
function getNextDocNumber($number,$in,$company,$register){
    return \Punto\App\Domain\Document::getNextDocNumber($number, $in, $company, $register);
}
/**
 * @deprecated Slice 10 (PSR-4). Usar `\Punto\App\Database\Query::getValue()`. ~99 callers.
 */
function getValue($table, $field, $where = '', $returnType = 'number', $cache = false){
    return \Punto\App\Database\Query::getValue((string) $table, (string) $field, (string) $where, (string) $returnType, $cache);
}

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getArray()`. ~6 callers.
 */
function getTaxonomyArray($type,$company,$compZero = false){
    return \Punto\App\Domain\Taxonomy::getArray((string) $type, $company, (bool) $compZero);
}

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getTagsDefaults()`. ~2 callers.
 */
function getTagsDefaults($idsOnly=false){
    return \Punto\App\Domain\Taxonomy::getTagsDefaults((bool) $idsOnly);
}

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getCustomTemplates()`. ~9 callers.
 */
function getCustomTemplates($company){
    return \Punto\App\Domain\Taxonomy::getCustomTemplates($company);
}

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getName()`. ~28 callers.
 */
function getTaxonomyName($id,$numeric=false,$company=false){
    return \Punto\App\Domain\Taxonomy::getName($id, (bool) $numeric, $company);
}

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getAllNames()`. ~1 caller.
 */
function getAllTaxonomyNames($companyId,$numeric=false){
    return \Punto\App\Domain\Taxonomy::getAllNames($companyId, (bool) $numeric);
}

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::printTags()`. ~12 callers.
 */
function printOutTags($tags,$bg = 'bg-white'){
    return \Punto\App\Domain\Taxonomy::printTags($tags, (string) $bg);
}
/**
 * @deprecated Slice 8 (PSR-4). Usar `\Punto\App\Domain\Store::getCurrentOutletName()`. ~41 callers.
 */
function getCurrentOutletName($id=false){
    return \Punto\App\Domain\Store::getCurrentOutletName($id);
}

/**
 * @deprecated Slice 8 (PSR-4). Usar `\Punto\App\Domain\Store::getAllOutletData()`. ~2 callers.
 */
function getAllOutletData($id=false){
    return \Punto\App\Domain\Store::getAllOutletData($id);
}

/**
 * @deprecated Slice 8 (PSR-4). Usar `\Punto\App\Domain\Store::getOutletCount()`. ~2 callers.
 */
function getOutletCount($compId){
    return \Punto\App\Domain\Store::getOutletCount($compId);
}
/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::selectInput()`. ~4 callers.
 *             La función original hacía echo directo; este wrapper echa el retorno de la clase.
 */
function selectInputTaxonomy($type,$match,$multi=false){
    echo \Punto\App\Domain\Taxonomy::selectInput((string) $type, $match, (bool) $multi);
}

/**
 * @deprecated Slice 5 (PSR-4). Usar `\Punto\App\Helpers\Date::nice()` en código nuevo.
 *             Wrapper mantenido para los ~166 callers legacy.
 */
function niceDate($date, $hours = false, $noDay = false, $year = true, $weekDay = false){
    return \Punto\App\Helpers\Date::nice($date, (bool) $hours, (bool) $noDay, (bool) $year, (bool) $weekDay);
}

/**
 * @deprecated Slice 5 (PSR-4). Usar `\Punto\App\Helpers\Date::niceAgo()` en código nuevo.
 *             Wrapper mantenido para los ~5 callers legacy.
 */
function niceDate2($datetime, $type = 'normal') {
    return \Punto\App\Helpers\Date::niceAgo($datetime, $type);
}

/**
 * @deprecated Slice 5 (PSR-4). Usar `\Punto\App\Helpers\Date::startEndTime()` en código nuevo.
 *             Wrapper mantenido para los ~5 callers legacy.
 */
function dateStartEndTime($startDate,$endDate){
    return \Punto\App\Helpers\Date::startEndTime((string) $startDate, (string) $endDate);
}

/**
 * @deprecated Slice 5 (PSR-4). Usar `\Punto\App\Helpers\Date::nextPeriod()` en código nuevo.
 *             Wrapper mantenido para los ~9 callers legacy (cron de recurrentes).
 */
function getNextDatePeriod($frecuency,$times,$date = TODAY,$format = 'Y-m-d 00:00:00'){
    return \Punto\App\Helpers\Date::nextPeriod((string) $frecuency, (int) $times, (string) $date, (string) $format);
}

/**
 * @deprecated Slice 5 (PSR-4). Usar `\Punto\App\Helpers\Date::translateWeekName()` en código nuevo.
 *             Wrapper mantenido para 0 callers externos (interno de niceDate vía wrapper).
 */
function translateNamesOfWeek($word,$lang='es'){
    return \Punto\App\Helpers\Date::translateWeekName((string) $word, (string) $lang);
}

function buildCalendarTop($options,$test=false){

	$date 		= $options['date'];
	$title 		= $options['title'];

	//MODES
	$modeBtnAr 	= [
					'month'		=>['mode'=>'calendar_month','name'=>'Mes'],
					'week'		=>['mode'=>'calendar_week_json','name'=>'Semana'],
					'resource'	=>['mode'=>'calendar_resources_json','name'=>'Recursos'],
					'agenda'	=>['mode'=>'calendar_agenda_json','name'=>'Agenda']
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
    	$typemode 	= 'calendar_week_json';
    	$time 		= 'week';
    	$title 		= niceDate($date,false,true);
    }else if($options['current'] == 'agenda'){
    	$typemode 	= 'calendar_agenda_json';
    	$time 		= 'month';
    	$title 		= niceDate($date);
    }else if($options['current'] == 'resource'){
    	$typemode 	= 'calendar_resources_json';
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
/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getCompoundsArray()`. ~23 callers.
 */
function getCompoundsArray($itemId,$cache=false){
    return \Punto\App\Domain\Inventory::getCompoundsArray($itemId, $cache);
}

/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::displayableCompounds()`. ~3 callers.
 */
function displayableCompounds($id){
    return \Punto\App\Domain\Inventory::displayableCompounds($id);
}
/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getProductionCapacity()`. ~5 callers.
 */
function getProductionCapacity($compounds,$inventory,$waste = false){
    return \Punto\App\Domain\Inventory::getProductionCapacity($compounds, $inventory, $waste);
}

/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getProductionCOGS()`. ~8 callers.
 */
function getProductionCOGS($itemId,$wasted=true){
    return \Punto\App\Domain\Inventory::getProductionCOGS($itemId, (bool) $wasted);
}

/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getComboCOGS()`. ~8 callers.
 */
function getComboCOGS($parent){
    return \Punto\App\Domain\Inventory::getComboCOGS($parent);
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

/**
 * @deprecated Slice 8 (PSR-4). Usar `\Punto\App\Domain\Store::selectInput()`. ~19 callers.
 *             La función original hacía echo directo; este wrapper echa el retorno de la clase.
 */
function selectInputOutlet($match='',$multi=false,$class='',$name='outlet'){
    echo \Punto\App\Domain\Store::selectInput($match, (bool) $multi, (string) $class, (string) $name);
}

/**
 * @deprecated Slice 12 (PSR-4). Usar `\Punto\App\Domain\Money::formatNumber()`. ~530 callers.
 */
function formatCurrentNumber($number,$de='',$ts=''){
    return \Punto\App\Domain\Money::formatNumber($number, $de, $ts);
}

/**
 * @deprecated Slice 12 (PSR-4). Usar `\Punto\App\Domain\Money::formatQty()`. ~85 callers.
 */
function formatQty($val,$extDec=2){
    return \Punto\App\Domain\Money::formatQty($val, (int) $extDec);
}

/**
 * @deprecated Slice 12 (PSR-4). Usar `\Punto\App\Domain\Money::addTax()`. ~7 callers.
 */
function addTax($tax,$price){
    return \Punto\App\Domain\Money::addTax($tax, $price);
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
	// $field/$table son IDENTIFICADORES (schema, controlados por el código) → NO se
	// prepara/quotea (qstr los rompía como string literal). $name es VALOR → param.
	if($name !== '' && !empty($name)){
		$obj = $db->Execute("SELECT ".$table."Id FROM ".$table." WHERE ".$field." = ? AND ".$SQLcompanyId, [$name]);

		if(!$obj || $obj->fields[0] == '' || $obj->fields[0] < 1){
			return false;
		}else{
			return true;
		}
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
		// PHP 8.5: abs() ya no acepta strings/null silenciosamente → castear a float.
		$nuPrice 	= iftn(abs((float)($nu['price'] ?? 0)), 0); // lo que se ingresa en el visor de pago
		$nuTotal 	= iftn(abs((float)($nu['total'] ?? 0)), 0); // saldo a pagar

		// Fallback: muchos writes guardan el pago como {name,total} SIN 'price' ni
		// 'type' → sin esto el reporte de medios de pago sumaba 0. Si no hay price
		// usamos total como monto, y agrupamos por name cuando falta type.
		if($nuPrice <= 0 && $nuTotal > 0){
			$nuPrice = $nuTotal;
		}
		$nuType 	= $nu['type'] ?? ($nu['name'] ?? '');
		$nu['type'] = $nuType;

		// Resuelve id/slug → nombre legible SIEMPRE (no solo cuando falta
		// 'name'). Ventas viejas persistieron el id/UUID del medio de pago
		// directamente en 'name' (bug de escritura ya corregido en
		// pay-dialog.tsx) — sin este resolve, Control de Caja mostraba el
		// slug nativo ('efectivo','tdebito') o el UUID de taxonomía crudo.
		// getPaymentMethodName() devuelve '' si no matchea (medio custom ya
		// borrado, o el valor ya era un nombre legible) → fallback al valor
		// crudo que ya traía la venta, nunca se rompe el resumen.
		$resolvedName = getPaymentMethodName($nuType);
		if ($resolvedName !== '') {
			$nu['name'] = $resolvedName;
		} elseif (!isset($nu['name']) || !$nu['name']) {
			$nu['name'] = $nuType;
		}

		// Clamp de pago parcial solo cuando total es significativo (no zerar un
		// método que trae price pero no total).
		if($nuPrice > $nuTotal && $nuTotal > 0){
			$nu['price'] 	= $nuTotal;
			$nuPrice 			= abs((float)$nu['price']);
		}

		// Persistir el monto resuelto en 'price' para que tanto la suma del match
		// como el push del método nuevo usen el valor correcto.
		$nu['price'] = $nuPrice;

    $match 					= false;

    // Clave de agrupación: el NOMBRE resuelto, no el type crudo. El mismo medio
    // convive con dos identificadores distintos en el histórico — el slug
    // ('efectivo') de las ventas viejas y el UUID de taxonomía de las nuevas —
    // así que agrupar por type mostraba "Efectivo" DOS veces, con el monto
    // partido entre ambas filas. Al resolver el nombre primero, las dos entradas
    // caen en la misma. Si no hay nombre resuelto (medio borrado), se cae al
    // type, que es el comportamiento anterior.
    $nuKey = $resolvedName !== '' ? mb_strtolower($resolvedName) : $nuType;
    $nu['groupKey'] = $nuKey;

    if(validity($old,'array')) {
	    foreach($old as $index => $ol){
	        if($nuKey === ($ol['groupKey'] ?? $ol['type'])){
	            $old[$index]['price'] = (float)$ol['price'] + (float)$nuPrice;
	            $old[$index]['count'] = (int)($ol['count'] ?? 0) + 1;
	            $match = true;
	        }
	    }
    }

    if(!$match){
    	unset($nu['extra']);
    	unset($nu['total']);
    	$nu['count'] = 1;
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
	$field 	= ncmExecute("SELECT meta->>'tags' AS tags FROM transaction WHERE transactionId = ? AND transactionType IN(0,3) AND companyId = ? LIMIT 1",[$parentId,COMPANY_ID]);

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

	// PG: sin USE INDEX (hint MySQL-only) y `tags` está demoted a meta JSONB (§22.8).
	$result = ncmExecute("SELECT transactionTotal, meta->>'tags' AS tags, transactionDiscount, transactionUnitsSold, transactionTax FROM transaction WHERE transactionDate BETWEEN ? AND ? AND transactionType IN(" . $tTypes . ") " . $roc . " LIMIT 5000",[$from,$to],1200,true);

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

function getSalesByPayment($from,$to,$regId,$drawerId=null){
	global $db,$SQLcompanyId;
	$regId 	= iftn($regId,REGISTER_ID);
	//return false;
	//selecciono todas las transacciones de la SESIÓN de caja (drawerId) actual.
	//hago un loop para agrupar todos los metodos de pago
	if(validity($from) && validity($regId)){
		if(($to == '0000-00-00 00:00:00') || !$to){
			$to = false;
		}

		// Fechas parametrizadas (no $db->Prepare, que quotea y además el código las
		// re-quoteaba → '<doble-quote>' roto en PG). El orden de los params sigue al
		// de los placeholders en el WHERE.
		//
		// Filtro de sesión de caja (mig 70): drawerId primario + fallback por fecha
		// para filas NULL (ventas viejas o sin caja abierta). Belt-and-suspenders —
		// JAMÁS perder una transacción. El OR es EXCLUYENTE: una fila con drawerId
		// matchea solo la rama "drawerId = ?"; una fila NULL solo la rama de fecha.
		// Sin doble-conteo. Si no se pasa $drawerId (backward-compat) → solo fecha.
		// companyId SIEMPRE en el WHERE (aislamiento multi-tenant) — bind al final.
		if($drawerId !== null && $drawerId !== ''){
			if($to){
				$date 	= '(drawerid = ? OR (drawerid IS NULL AND transactionDate BETWEEN ? AND ?))';
				$params = [$drawerId, $from, $to, $regId, COMPANY_ID];
			}else{
				$date 	= '(drawerid = ? OR (drawerid IS NULL AND transactionDate > ?))';
				$params = [$drawerId, $from, $regId, COMPANY_ID];
			}
		}else{
			if($to){
				$date 	= "transactionDate BETWEEN ? AND ?";
				$params = [$from, $to, $regId, COMPANY_ID];
			}else{
				$date 	= "transactionDate > ?";
				$params = [$from, $regId, COMPANY_ID];
			}
		}

		// `tags` no es columna de `transaction` (los tags viven en `meta` jsonb).
		// Pedir `tags` crudo aborta el resumen con 42703 → `meta->>'tags' AS tags`
		// (mismo patrón que la query de rollup en functions.php:~990).
		$result 	= ncmExecute("SELECT transactionId, abs(transactionTotal) as transactionTotal, abs(transactionDiscount) as transactionDiscount,transactionPaymentType, transactionType, transactionParentId, meta->>'tags' AS tags
									FROM transaction
									WHERE  " . $date . "
									AND transactionType IN (0,5,6)
									AND registerId = ?
									AND companyId = ?"
									,$params,false,true);
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

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getIdOrInsert()`. ~3 callers.
 */
function getTaxonomyIdOrInsert($name, $type, $insertIt = true){
    return \Punto\App\Domain\Taxonomy::getIdOrInsert($name, (string) $type, (bool) $insertIt);
}

/**
 * @deprecated Slice 12 (PSR-4). Usar `\Punto\App\Domain\Money::formatForDB()`. ~73 callers.
 */
function formatNumberToInsertDB($number,$forceDecimals=false,$decimalsCount=2){
    return \Punto\App\Domain\Money::formatForDB($number, (bool) $forceDecimals, (int) $decimalsCount);
}

/**
 * @deprecated Slice 12 (PSR-4). Usar `\Punto\App\Domain\Money::forceDecimals()`. ~2 callers.
 */
function forceExtraDecimalsNumber($num,$max=3){
    return \Punto\App\Domain\Money::forceDecimals($num, (int) $max);
}

function sanitizeForDB($str){
	$breaks 	= array("\r\n", "\n", "\r");
    $str 		= preg_replace('/[^a-zA-Z0-9_ %\[\]\.\(\)%&-]/s', '', str_replace($breaks, " - ", $str));
	return trim(htmlspecialchars($str));
}

/**
 * @deprecated Slice 6 (PSR-4). Usar `\Punto\App\Helpers\Cond::iftn()`. ~778 callers (la 3a más usada).
 */
function iftn($if, $else = false, $then = false){
    return \Punto\App\Helpers\Cond::iftn($if, $else, $then);
}

/**
 * @deprecated Slice 3 (PSR-4). Usar `\Punto\App\Helpers\Validation::fromRequest()` en código nuevo.
 *             Wrapper mantenido para los ~58 callers legacy.
 */
function validateBool($value,$server=true,$type='get'){
    return \Punto\App\Helpers\Validation::fromRequest($value, $server, $type);
}

/**
 * @deprecated Slice 3 (PSR-4). Usar `\Punto\App\Helpers\Validation::http()` en código nuevo.
 *             Wrapper mantenido para los ~1524 callers legacy (el más usado).
 */
function validateHttp($value,$type = 'get'){
    return \Punto\App\Helpers\Validation::http($value, $type);
}

/**
 * @deprecated Slice 3 (PSR-4). Usar `\Punto\App\Helpers\Validation::fromDbResult()` en código nuevo.
 */
function validateResultFromDB($result,$num=false){
    return \Punto\App\Helpers\Validation::fromDbResult($result, $num);
}

/**
 * @deprecated Slice 3 (PSR-4). Usar `\Punto\App\Helpers\Validation::isValid()` en código nuevo.
 *             Wrapper mantenido para los ~716 callers legacy (linchpin del refactor).
 */
function validity($value,$force=false){
    return \Punto\App\Helpers\Validation::isValid($value, $force);
}

/**
 * Aplana columnas JSONB (data, meta, config) en el resultado plano para que
 * `$row['settingName']` funcione aunque el campo viva dentro de `config`.
 * Copia idéntica de panel/includes/functions.php::_flattenJsonb para que
 * el módulo /app no necesite incluir el del panel. Phase PG.4.
 */
/**
 * @deprecated Slice 10 (PSR-4). Usar `\Punto\App\Database\Query::flattenJsonb()`. ~23 callers.
 *             Return type alineado con el wrapper canónico (sesión 28-jun: pasó
 *             de CaseInsensitiveArray a array plano). Esta firma se quedó atrás
 *             y rompía con TypeError a TODO endpoint que pasara por acá.
 */
function _flattenJsonb($row): \CaseInsensitiveArray
{
    return \Punto\App\Database\Query::flattenJsonb($row);
}

/**
 * JSON crudo de una columna JSONB (`data`/`meta`/`config`) que el flatten
 * desempaquetó. Ver el docblock de `Query::rawJsonb()` — ahí está el porqué.
 *
 *   $row  = ncmExecute('SELECT * FROM transaction WHERE transactionId = ?', [$id]);
 *   $meta = json_decode(_rawJsonb($row, 'meta') ?? '{}', true);
 */
function _rawJsonb(object $row, string $col): ?string
{
    return \Punto\App\Database\Query::rawJsonb($row, $col);
}

/**
 * @deprecated Slice 10 (PSR-4). Usar `\Punto\App\Database\Query::execute()`. ~1035 callers.
 *             God node de DB del POS — semántica crítica, no modificar sin regression suite.
 */
function ncmExecute( $sql, $array = false, $cache = false, $forceObj = false, $getAssoc = false ){
    return \Punto\App\Database\Query::execute((string) $sql, $array, $cache, (bool) $forceObj, (bool) $getAssoc);
}

/**
 * ncmUpdate con JSONB routing + non-destructive merge.
 * Reemplaza el wrapper que delegaba a Query::update (que NO hacía routing → bug:
 * campos demoted a JSONB caían como columnas inexistentes → 500 silente).
 * Port FIEL del ncmUpdate de panel/includes/functions.php:4979.
 *
 * Estrategia:
 *   1. Separar record en columnas reales vs JSONB (via _routeToJsonb).
 *   2. UPDATE columnas reales via AutoExecute (con whereParams si vienen).
 *   3. Si hay extras JSONB → segundo UPDATE con `jsonbCol = COALESCE(jsonbCol, '{}') || ?::jsonb`
 *      (PG `||` operator hace merge no-destructivo: claves nuevas se agregan, las viejas
 *      mantienen el valor previo si no están en el merge).
 *
 * @note El slice 10 PSR-4 hizo este wrapper delegar a `Query::update`, pero Query NO
 *       hacía JSONB routing → bug 500 silente. Revertido al cuerpo completo del panel.
 *       `Query::update` ahora delega de vuelta a esta función para evitar drift futuro.
 */
function ncmUpdate($options)
{
    global $db;

    if (!validity($options, 'array') || !validity($options['records'], 'array') || !validity($options['table']) || !validity($options['where'])) {
        return false;
    }

    $table       = $options['table'];
    $record      = $options['records'];
    $where       = $options['where'];
    $whereParams = $options['whereParams'] ?? [];

    // Enrutar campos desconocidos al JSONB de la tabla
    [$cleanRecord, $jsonbExtra, $jsonbCol] = _routeToJsonb($table, $record);

    // Actualizar columnas reales via AutoExecute (solo si hay campos reales)
    $update   = true;
    $updateId = null;
    if (!empty($cleanRecord)) {
        $update   = $db->AutoExecute($table, $cleanRecord, 'UPDATE', $where, $whereParams);
        $updateId = $db->Insert_ID();
    }

    // Fusionar campos JSONB usando el operador || de PostgreSQL (non-destructive merge).
    // COALESCE maneja el caso en que la columna sea NULL en la fila existente.
    if ($update !== false && !empty($jsonbExtra)) {
        $jsonSql    = "UPDATE $table SET $jsonbCol = COALESCE($jsonbCol, '{}') || ?::jsonb WHERE $where";
        $jsonParams = array_merge([json_encode($jsonbExtra)], $whereParams);
        $db->Execute($jsonSql, $jsonParams);
    }

    if ($update !== false) {
        return ['error' => false, 'id' => $updateId];
    }
    return ['error' => $db->ErrorMsg()];
}

// ── JSONB routing helpers ────────────────────────────────────────────────────
// Sin estas funciones, ncmInsert/ncmUpdate mandan TODAS las claves del record
// como columnas SQL — y campos demoted a JSONB (itemTaxIncluded, itemImage, etc.)
// tiran "column does not exist". Portados desde panel/includes/functions.php para
// que el path /api (que carga este archivo) tenga el mismo comportamiento que el
// path /panel. `function_exists` guard evita doble-definir cuando panel también
// está en juego (impossible hoy: api/bootstrap.php carga este file y solo este).
// Deuda: consolidar a un archivo compartido. Ver context/10-roadmap.md.

if (!function_exists('generateUuidV7')) {
    function generateUuidV7(): string
    {
        $timestamp = (int)(microtime(true) * 1000);
        $timeHex   = str_pad(dechex($timestamp), 12, '0', STR_PAD_LEFT);

        $rand = random_bytes(10);
        $rand[0] = chr((ord($rand[0]) & 0x0f) | 0x70); // version 7
        $rand[2] = chr((ord($rand[2]) & 0x3f) | 0x80); // variant

        $randHex = bin2hex($rand);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($timeHex, 0, 8),
            substr($timeHex, 8, 4),
            substr($randHex, 0, 4),
            substr($randHex, 4, 4),
            substr($randHex, 8, 12)
        );
    }
}

if (!function_exists('_getTableSchema')) {
    /**
     * Registro de esquemas de tablas con columnas JSONB. PORT FIEL de
     * panel/includes/functions.php:4632 — mismo map, mismo orden, misma semántica.
     */
    function _getTableSchema(): array
    {
        static $schema = null;
        if ($schema !== null) {
            return $schema;
        }
        $schema = [
            'company' => [
                'pk'       => 'companyId',
                'jsonbCol' => 'config',
                // aiCreditsBalance (mig 28) hoy solo se escribe por SQL crudo, así
                // que nunca pasó por este map — pero el día que alguien la escriba
                // con ncmUpdate, sin estar acá el saldo de créditos se perdería en
                // el JSONB `config` sin ningún error. Se lista por adelantado.
                'columns'  => ['companyId', 'status', 'plan', 'balance', 'slug', 'blocked',
                               'planExpired', 'isTrial', 'smsCredit', 'parentId', 'isParent',
                               'createdAt', 'updatedAt', 'expiresAt', 'config',
                               'aiCreditsBalance'],
            ],
            'item' => [
                'pk'       => 'itemId',
                'jsonbCol' => 'data',
                // hasVariants / variantParentId / variantAttributes son COLUMNAS
                // reales de `item` (los SELECT las leen directo). Faltaban acá, así
                // que _routeToJsonb las trataba como campos desconocidos y las
                // escribía dentro del JSONB `data`: el UPDATE respondía 200, el
                // dato quedaba en `data->>'hasVariants'` y la columna seguía en
                // false — activar el switch de variantes "no hacía nada"
                // (incidente 2026-07-29). Toda columna nueva de esta tabla tiene
                // que sumarse a esta lista o la escritura se pierde en silencio.
                'columns'  => ['itemId', 'itemName', 'itemDate', 'itemSKU', 'itemCost', 'itemPrice',
                               'itemIsParent', 'itemParentId', 'itemType', 'itemKind', 'itemStatus',
                               'itemTrackInventory', 'itemCanSale', 'itemSort', 'itemProduction',
                               'taxId', 'brandId', 'categoryId', 'supplierId', 'locationId',
                               'outletId', 'companyId', 'updated_at', 'data',
                               'hasVariants', 'variantParentId', 'variantAttributes'],
            ],
            'contact' => [
                'pk'       => 'contactId',
                'jsonbCol' => 'data',
                // Migración 25 (2026-06-13): contactSecondName, contactAddress,
                // contactAddress2, contactNote, contactCity, contactLocation,
                // contactCountry, contactCI, contactBirthDay demoted al JSONB
                // `data`. contactPhone2 ELIMINADO (decisión de producto).
                // lockPassHash (mig 49) y pinhash (mig 55) son columnas reales:
                // faltaban acá, así que UsersService::create/update las escribía
                // en el JSONB mientras el unlock del POS seguía validando contra
                // la columna — cambiar el PIN parecía funcionar y no tenía efecto.
                // Mismo bug que las variantes de item (2026-07-29).
                'columns'  => ['contactId', 'contactName', 'contactEmail',
                               'contactPhone', 'contactTIN', 'contactDate',
                               'contactPassword', 'contactLoyalty', 'contactLoyaltyAmount',
                               'contactStoreCredit', 'contactCreditable', 'contactCreditLine',
                               'contactStatus', 'contactLastNotificationSeen', 'debtLastNotify',
                               'type', 'main', 'role', 'lockPass', 'salt', 'parentId', 'categoryId',
                               'userId', 'outletId', 'companyId', 'updated_at', 'data',
                               'lockPassHash', 'pinhash'],
            ],
            'transaction' => [
                'pk'       => 'transactionId',
                'jsonbCol' => 'meta',
                'columns'  => ['transactionId', 'transactionDate', 'transactionDiscount',
                               'transactionTax', 'transactionTotal', 'transactionUnitsSold',
                               'transactionNote', 'transactionStatus', 'transactionType',
                               'transactionDoc', 'transactionDocNumber', 'transactionWriteOff',
                               'transactionRecurringId', 'transactionPurpose',
                               'transactionDeliveryDate', 'lastEditDate', 'lastUserId',
                               'parentId', 'roundedAmount', 'taxAmount', 'discountAmount',
                               'cashbackAmount', 'returnedAmount', 'customerId', 'supplierId',
                               'userId', 'registerId', 'outletId', 'companyId',
                               'transactionPaymentMethod', 'transactionPaymentDate',
                               'updated_at', 'meta',
                               // mig 101 — venta emitida sin IVA (toggle del POS).
                               'ivaRemoved',
                               // mig 102 — faltaba acá: ncmInsert (PurchasesService::create)
                               // enrutaba transactionPaymentType al JSONB `meta` en vez de la
                               // columna real, dejando las compras sin línea de pago legible
                               // para FinanceLedger::recordPurchase (Parte 2 del incidente
                               // 737M). SaleService/CreditPaymentService no sufrían el bug
                               // porque escriben con AutoExecute directo, no con ncmInsert.
                               'transactionPaymentType'],
            ],
            'outlet' => [
                'pk'       => 'outletId',
                'jsonbCol' => 'data',
                // Migración 14 (2026-06-11): outletAddress/Phone/WhatsApp/Email/
                // BillingName/RUC/Description demoted; latLng → lat/lng numéricas.
                // Migración 27 (2026-06-13): outletNextExpirationDate demoted.
                // Counters outletPurchaseOrderNo / outletOrderTransferNo se
                // mantienen en columnas por incremento atómico en hot path.
                'columns'  => ['outletId', 'outletName', 'outletStatus',
                               'lat', 'lng',
                               'outletCreationDate',
                               'outletPurchaseOrderNo', 'outletOrderTransferNo',
                               'taxId', 'companyId', 'data'],
            ],
            'register' => [
                'pk'       => 'registerId',
                'jsonbCol' => 'data',
                // Migración 26 (2026-06-13): registerInvoiceAuth, registerInvoiceAuthExpiration,
                // registerInvoicePrefix, registerInvoiceSufix, registerDocsLeadingZeros
                // demoted. Counters atómicos (Number, RemitoNumber, etc.) se mantienen.
                'columns'  => ['registerId', 'registerName', 'registerStatus', 'registerCreationDate',
                               'registerInvoiceNumber',
                               'registerRemitoNumber', 'registerQuoteNumber', 'registerReturnNumber',
                               'registerTicketNumber', 'registerOrderNumber', 'registerPedidoNumber',
                               'registerBoletaNumber', 'registerScheduleNumber',
                               'lastupdated', 'sessionId',
                               'outletId', 'companyId', 'data'],
            ],
            'plans' => [
                'pk'       => 'id',
                'jsonbCol' => 'features',
                'columns'  => ['id', 'name', 'type', 'price', 'duration_days', 'max_items',
                               'max_users', 'max_customers', 'max_outlets', 'max_registers',
                               'max_suppliers', 'max_categories', 'max_brands', 'features'],
            ],
            'recurring' => [
                'pk'       => 'recurringId',
                'jsonbCol' => 'data',
                'columns'  => ['recurringId', 'recurringNextDate', 'recurringEndDate',
                               'recurringFrecuency', 'recurringStatus', 'recurringTransactionData',
                               'companyId', 'data'],
            ],
            // itemSold: líneas de venta/compra. Schema verificado vía
            // information_schema.columns en prod. La columna 'taxId' que el
            // panel legacy mete en el record NO existe físicamente — termina
            // ruteada al JSONB 'meta' por _routeToJsonb. PK = itemSoldId (uuid).
            'itemSold' => [
                'pk'       => 'itemSoldId',
                'jsonbCol' => 'meta',
                'columns'  => ['itemSoldId', 'itemSoldTotal', 'itemSoldTax', 'itemSoldDate',
                               'itemSoldUnits', 'itemSoldDiscount', 'itemSoldCogs',
                               'itemSoldComission', 'itemSoldDescription',
                               'itemSoldParent', 'itemSoldCategory',
                               'itemId', 'userId', 'transactionId', 'meta'],
            ],
            'tasks' => [
                'pk'       => 'ID',
                'jsonbCol' => 'data',
                'columns'  => ['ID', 'date', 'dueDate', 'type', 'sourceId', 'status',
                               'outletId', 'companyId', 'data'],
            ],
            'customerRecord' => [
                'pk'       => 'customerRecordId',
                'jsonbCol' => 'data',
                'columns'  => ['customerRecordId', 'customerRecordSort', 'customerRecordName',
                               'companyId', 'data'],
            ],
            // Sin JSONB: `expenses` no tiene columna `data`, así que jsonbCol es
            // null y cualquier campo fuera de esta lista llega al SQL y falla
            // fuerte (no hay dónde guardarlo en silencio). Estaba SIN registrar,
            // y por eso ncmInsert caía al pk por defecto 'id' e inyectaba una
            // columna inexistente: toda extracción/ingreso de caja moría con
            // "Error al registrar extracción" (incidente 2026-07-29).
            'expenses' => [
                'pk'       => 'expensesId',
                'jsonbCol' => null,
                'columns'  => ['expensesId', 'expensesNameId', 'expensesAmount',
                               'expensesDescription', 'expensesDate', 'expensesUID',
                               'type', 'userId', 'registerId', 'outletId', 'companyId'],
            ],
            'inventoryCount' => [
                'pk'       => 'inventoryCountId',
                'jsonbCol' => 'data',
                'columns'  => ['inventoryCountId', 'inventoryCountDate', 'inventoryCountUpdated',
                               'inventoryCountName', 'inventoryCountStatus', 'inventoryCountCounted',
                               'inventoryCountNote', 'inventoryCountBlind',
                               'userId', 'outletId', 'companyId', 'data'],
            ],
            'priceList' => [
                'pk'       => 'ID',
                'jsonbCol' => 'data',
                'columns'  => ['ID', 'data', 'companyId'],
            ],
            'vPayments' => [
                'pk'       => 'ID',
                'jsonbCol' => 'data',
                'columns'  => ['ID', 'date', 'payoutDate', 'depositedDate', 'amount', 'payoutAmount',
                               'comission', 'tax', 'deposited', 'orderNo', 'authCode', 'operationNo',
                               'inBank', 'status', 'UID', 'source', 'transactionId', 'customerId',
                               'userId', 'outletId', 'companyId', 'updated_at', 'data'],
            ],
            'taxonomy' => [
                'pk'       => 'taxonomyId',
                'jsonbCol' => 'taxonomyExtra',
                'columns'  => ['taxonomyId', 'taxonomyName', 'taxonomyType', 'taxonomyExtra',
                               'sourceId', 'outletId', 'companyId'],
            ],
            'customerAddress' => [
                'pk'       => 'customerAddressId',
                'jsonbCol' => 'data',
                // 'reference' y 'status' agregados en mig 87 (libreta de
                // direcciones, context/27 PARTE D) — sin esta entrada,
                // ncmInsert/ncmUpdate los enrutarían silenciosamente al JSONB
                // `data` en vez de a las columnas reales (mismo footgun
                // documentado en context/04 para esta tabla).
                'columns'  => ['customerAddressId', 'customerAddressDate', 'customerAddressName',
                               'customerAddressText', 'customerAddressLat', 'customerAddressLng',
                               'customerAddressDefault', 'customerAddressLocation', 'customerAddressCity',
                               'customerId', 'companyId', 'updated_at', 'reference', 'status'],
            ],
            // Módulo Finanzas (mig 72). Tablas nuevas, columnas físicas lowercase
            // sin quotes (§40.1/§44) — escritas vía ncmInsert/ncmUpdate.
            'fin_account' => [
                'pk'       => 'accountid',
                'jsonbCol' => 'data',
                'columns'  => ['accountid', 'companyid', 'name', 'type', 'openingbalance',
                               'currentbalance', 'bankname', 'accountnumber', 'outletid',
                               'issystem', 'status', 'created_at', 'data'],
            ],
            'fin_category' => [
                'pk'       => 'categoryid',
                'jsonbCol' => 'data',
                'columns'  => ['categoryid', 'companyid', 'name', 'kind', 'parentid',
                               'sortorder', 'issystem', 'status', 'created_at', 'data'],
            ],
            'fin_movement' => [
                'pk'       => 'movementid',
                'jsonbCol' => 'data',
                'columns'  => ['movementid', 'companyid', 'accountid', 'categoryid', 'kind',
                               'amount', 'date', 'description', 'paymentmethod', 'source',
                               'sourceid', 'transfergroupid', 'checkid', 'reconciliationid',
                               'reconciled', 'reconciled_at', 'userid', 'outletid', 'status',
                               'created_at', 'data'],
            ],
            'fin_check' => [
                'pk'       => 'checkid',
                'jsonbCol' => 'data',
                'columns'  => ['checkid', 'companyid', 'direction', 'accountid', 'bankname',
                               'checknumber', 'amount', 'issuedate', 'duedate', 'contactid',
                               'partyname', 'categoryid', 'status', 'cleareddate', 'description',
                               'created_at', 'data'],
            ],
            'fin_reconciliation' => [
                'pk'       => 'reconciliationid',
                'jsonbCol' => 'data',
                'columns'  => ['reconciliationid', 'companyid', 'accountid', 'statementdate',
                               'statementbalance', 'status', 'closed_at', 'userid', 'created_at',
                               'data'],
            ],
        ];
        return $schema;
    }
}

if (!function_exists('_routeToJsonb')) {
    /**
     * Separa $record en columnas reales vs campos que van al JSONB.
     * Port FIEL de panel/includes/functions.php:4804.
     * @return array [$cleanRecord, $jsonbExtra, $jsonbCol]
     */
    function _routeToJsonb(string $table, array $record): array
    {
        $schema = _getTableSchema();
        if (!isset($schema[$table])) {
            return [$record, [], ''];
        }
        $jsonbCol  = $schema[$table]['jsonbCol'] ?? null;
        // Tabla registrada SIN columna JSONB (ej. `expenses`): no hay dónde
        // enrutar lo desconocido. Se devuelve el record entero como columnas —
        // un campo que no exista revienta en el SQL, que es lo correcto: el
        // silencio es peor que el error (ver hasVariants/pinhash, 2026-07-29).
        if (empty($jsonbCol)) {
            return [$record, [], ''];
        }
        $knownCols = array_flip($schema[$table]['columns']);

        $cleanRecord = [];
        $jsonbExtra  = [];
        foreach ($record as $key => $value) {
            if (isset($knownCols[$key])) {
                $cleanRecord[$key] = $value;
            } else {
                $jsonbExtra[$key] = $value;
            }
        }
        return [$cleanRecord, $jsonbExtra, $jsonbCol];
    }
}

/**
 * ncmInsert con JSONB routing + UUID v7 auto-generation.
 * Reemplaza el wrapper que delegaba a Query::insert (que NO hacía routing → bug:
 * itemTaxIncluded y otros campos demoted a `data` tiraban "column does not exist").
 * Port FIEL del ncmInsert de panel/includes/functions.php:4932.
 *
 * @note El slice 10 PSR-4 hizo este wrapper delegar a `Query::insert`, pero Query NO
 *       hacía JSONB routing ni UUID v7 → bug "column does not exist". Revertido al
 *       cuerpo completo del panel. `Query::insert` ahora delega de vuelta a esta función
 *       para evitar drift futuro.
 */
function ncmInsert($options)
{
    global $db;

    if (!validity($options, 'array') || !validity($options['records'], 'array') || !validity($options['table'])) {
        return false;
    }

    $table  = $options['table'];
    $record = $options['records'];

    // Determinar la columna PK. Antes el fallback para tablas no registradas era
    // 'id' a ciegas: si la tabla no tenía esa columna (la mayoría usa
    // <entidad>Id), el INSERT incluía una columna inexistente y fallaba entero.
    // Así moría toda extracción de caja — `expenses` no estaba en el map.
    // Ahora, si el map no la conoce, se le pregunta al catálogo de PG.
    $pkCol = _resolveTablePk($table);

    // Generar UUID v7 si el registro no trae el PK. Solo para PKs uuid: en una
    // PK serial/bigint el uuid rompería el INSERT, y ahí el default de la
    // columna ya sabe generarlo.
    if ($pkCol !== null && empty($record[$pkCol]) && _pkIsUuid($table, $pkCol)) {
        $record[$pkCol] = generateUuidV7();
    }

    // Enrutar campos desconocidos al JSONB de la tabla
    [$record, $jsonbExtra, $jsonbCol] = _routeToJsonb($table, $record);
    if (!empty($jsonbExtra)) {
        $existing = [];
        if (isset($record[$jsonbCol]) && is_string($record[$jsonbCol])) {
            $existing = json_decode($record[$jsonbCol], true) ?? [];
        }
        $record[$jsonbCol] = json_encode(array_merge($existing, $jsonbExtra));
    }

    $insert = $db->AutoExecute($table, $record, 'INSERT');
    if ($insert === false) {
        return false;
    }
    // Sin PK conocida (o generada por la DB) no hay id que devolver: `true`
    // dice "insertó" sin mentir con un id inventado. Los callers que necesitan
    // el id usan tablas registradas con PK uuid.
    return ($pkCol !== null && isset($record[$pkCol])) ? $record[$pkCol] : true;
}

/**
 * PK de una tabla: primero el schema map, y si no está registrada se consulta
 * el catálogo de PG (cacheado por request).
 *
 * Existe porque el fallback anterior era 'id' a ciegas y rompía el INSERT
 * entero en toda tabla no registrada cuya PK no se llamara así.
 */
function _resolveTablePk(string $table): ?string
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $schema = _getTableSchema();
    if (isset($schema[$table]['pk'])) {
        return $cache[$table] = $schema[$table]['pk'];
    }
    global $db;
    try {
        // Sin LIMIT 1: con PK compuesta la query devuelve N filas y quedarse con
        // una sería elegir a ciegas la columna equivocada. En ese caso no hay
        // una "PK" que generar — se devuelve null y la DB se encarga.
        $rs = $db->Execute(
            "SELECT a.attname
               FROM pg_index i
               JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
              WHERE i.indrelid = ?::regclass AND i.indisprimary",
            [$table]
        );
        $cols = [];
        while ($rs && !$rs->EOF) {
            $cols[] = (string) $rs->fields['attname'];
            $rs->MoveNext();
        }
        if (count($cols) === 1) {
            return $cache[$table] = $cols[0];
        }
        if (count($cols) > 1) {
            error_log('[ncmInsert] ' . $table . ' tiene PK compuesta (' . implode(',', $cols) . ') — no se genera id');
        }
    } catch (\Throwable $e) {
        error_log('[ncmInsert] no se pudo resolver la PK de ' . $table . ': ' . $e->getMessage());
    }
    return $cache[$table] = null;
}

/**
 * true si la PK es de tipo uuid — la única en la que tiene sentido generar un v7.
 *
 * Para tablas del schema map se responde sin tocar la DB: todas tienen PK uuid y
 * este chequeo corre en cada INSERT (transaction, itemSold, item son paths
 * calientes). El catálogo solo se consulta para tablas NO registradas.
 */
function _pkIsUuid(string $table, string $pkCol): bool
{
    $schema = _getTableSchema();
    if (isset($schema[$table]['pk'])) {
        return true;
    }
    static $cache = [];
    $key = $table . '.' . $pkCol;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    global $db;
    try {
        $rs = $db->Execute(
            'SELECT data_type FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND table_name = ? AND lower(column_name) = lower(?) LIMIT 1',
            [$table, $pkCol]
        );
        if ($rs && !$rs->EOF) {
            return $cache[$key] = (strtolower((string) $rs->fields['data_type']) === 'uuid');
        }
    } catch (\Throwable $e) {
        error_log('[ncmInsert] no se pudo leer el tipo de ' . $key . ': ' . $e->getMessage());
    }
    // Falla cerrada: ante la duda NO se inventa un id. Inyectar un uuid en una
    // PK que no lo es reintroduce exactamente el bug que esto arregla.
    return $cache[$key] = false;
}

/**
 * @deprecated Slice 10 (PSR-4). Usar `\Punto\App\Database\Query::delete()`. ~3 callers.
 */
function ncmDelete($from,$where){
    return \Punto\App\Database\Query::delete($from, $where);
}

/**
 * @deprecated Slice 10 (PSR-4). Usar `\Punto\App\Database\Query::iterate()`. ~1 caller.
 */
function ncmWhile($result,$callback,$vars){
    return \Punto\App\Database\Query::iterate($result, $callback, $vars);
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

/**
 * @deprecated Slice 6 (PSR-4). Usar `\Punto\App\Helpers\Arr::getKey()`. ~1 caller.
 */
function arrKey($array,$key,$returnOnFalse=false){
    return \Punto\App\Helpers\Arr::getKey((array) $array, $key, $returnOnFalse);
}

/**
 * @deprecated Slice 6 (PSR-4). Usar `\Punto\App\Helpers\Arr::sizeOf()`. ~34 callers.
 */
function counts($val){
    return \Punto\App\Helpers\Arr::sizeOf($val);
}

/**
 * @deprecated Slice 6 (PSR-4). Usar `\Punto\App\Helpers\Arr::safeExplode()`. ~134 callers.
 */
function explodes($str,$array,$return=-1){
    return \Punto\App\Helpers\Arr::safeExplode((string) $str, $array, (int) $return);
}
function getItemPrice($id){
	global $db, $SQLcompanyId;
	$result 	= $db->Execute('SELECT itemPrice FROM item WHERE itemId = ? AND '.$SQLcompanyId,array($id));
	$total 		= $result->fields['itemPrice'];
	return $total;
}

/**
 * @deprecated Slice 8 (PSR-4). Usar `\Punto\App\Domain\Store::getOperatingCost()`. ~3 callers.
 */
function getOperatingCost($outletId){
    return \Punto\App\Domain\Store::getOperatingCost($outletId);
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
function sumProperties($arr, $property) {
    $sum = 0;
    foreach($arr as $object) {
        $sum += isset($object[$property]) ? $object[$property] : 0;
    }
    return $sum;
}

/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getItemStock()`. ~16 callers.
 */
function getItemStock($itemId,$outlet=false,$inLocation=false){
    return \Punto\App\Domain\Inventory::getItemStock($itemId, $outlet, $inLocation);
}

/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getItemMainStock()`. ~1 caller.
 */
function getItemMainStock($itemId,$outletId){
    return \Punto\App\Domain\Inventory::getItemMainStock($itemId, $outletId);
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
/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getAllItemStock()`. ~8 callers.
 */
function getAllItemStock($outlet=false,$all=false){
    return \Punto\App\Domain\Inventory::getAllItemStock($outlet, (bool) $all);
}

/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::manageStock()`. ~27 callers. CRÍTICO.
 */
function manageStock($ops){
    return \Punto\App\Domain\Inventory::manageStock((array) $ops);
}

//INVENTORY LOGIC END
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
										status,
										plan
									FROM company
									WHERE
										companyId = ? LIMIT 1", [$fields['companyId']]);

	if($company['status'] !== 'active'){
		return 'Cuenta inhabilitada, por favor contactenos al correo <?= EMAIL_FROM ?>';
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
	$_SESSION['user']['companyStatus']  = $company['status'];
	$_SESSION['user']['userId']  		= enc($fields['userId']);
	$_SESSION['user']['userName']  		= $fields['userName'];
	$_SESSION['user']['role']  			= enc($fields['role']);
	$_SESSION['user']['outletId'] 		= ($fields['role'] > 1) ? enc($fields['outletId']) : enc('1');
	$_SESSION['user']['registerId'] 	= ($fields['role'] > 1) ? enc($fields['registerId']) : enc('1');
	$_SESSION['user']['plan'] 			= enc($company['plan']);

	return 'true';
}

function getRolePermissions($roleId,$companyId){
	global $_ROLES_DATA;

	$index = ncmExecute("SELECT sourceId FROM taxonomy WHERE taxonomyType = 'role' AND taxonomyExtra = ? LIMIT 1",[$roleId],true);

	if ($index && is_array($index) && isset($index['sourceId'])) {
		$saved = ncmExecute("SELECT taxonomyExtra FROM taxonomy WHERE taxonomyType = 'roleData' AND sourceId = ? AND companyId = ? LIMIT 1",[$index['sourceId'],$companyId]);
		if($saved){
			$perms = json_decode($saved['taxonomyExtra'],true);
			if (is_array($perms)) return $perms;
		}
		if(!empty($_ROLES_DATA[$index['sourceId']])){
			return $_ROLES_DATA[$index['sourceId']];
		}
	}

	// Fallback: si no hay taxonomy mapping (instancia recién seedeada),
	// usar el rol crudo: role=1 → $_BOSS, role=2 → $_MANAGER, etc.
	// Devolver array vacío (no string) para que el JS pueda hacer perms.register.
	$roleIdx = max(0, intval($roleId) - 1);
	return $_ROLES_DATA[$roleIdx] ?? [];
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

/**
 * @deprecated Slice 7 (PSR-4). Usar `\Punto\App\Domain\Taxonomy::getAllItemCategories()`. ~13 callers.
 */
function getAllItemCategories($companyId){
    return \Punto\App\Domain\Taxonomy::getAllItemCategories($companyId);
}
/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getAllWasteValue()`. ~9 callers.
 */
function getAllWasteValue($id=false,$cache=false){
    return \Punto\App\Domain\Inventory::getAllWasteValue($id, $cache);
}

/**
 * @deprecated Slice 13 (PSR-4). Usar `\Punto\App\Domain\Inventory::getNeedWithWaste()`. ~8 callers.
 */
function getNeedWithWaste($need,$wasteP){
    return \Punto\App\Domain\Inventory::getNeedWithWaste($need, $wasteP);
}

/*function sendEmail($to,$subject,$body,$altbody,$from = EMAIL_FROM,$smtp=true){
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

/**
 * @deprecated Slice 15 (PSR-4). Usar `\Punto\App\Services\Notification::sendSMTP()`. ~5 callers.
 */
function sendSMTPEmail($meta,$template,$to,$subject,$body=APP_NAME,$altbody=APP_NAME){
    return \Punto\App\Services\Notification::sendSMTP($meta, $template, $to, $subject, $body, $altbody);
}

/**
 * @deprecated Slice 15 (PSR-4). Usar `\Punto\App\Services\Notification::sendEmails()`. ~23 callers.
 */
function sendEmails($options){
    return \Punto\App\Services\Notification::sendEmails($options);
}

/**
 * @deprecated Slice 15 (PSR-4). Usar `\Punto\App\Services\Notification::sendEmail()`. ~9 callers.
 */
function sendEmail($options){
    return \Punto\App\Services\Notification::sendEmail($options);
}

/**
 * @deprecated Slice 15 (PSR-4). Usar `\Punto\App\Services\Notification::sendSMS()`. ~17 callers.
 */
function sendSMS($number,$msg,$numvalidation=true,$auto=false){
    return \Punto\App\Services\Notification::sendSMS($number, $msg, (bool) $numvalidation, (bool) $auto);
}

/**
 * @deprecated Slice 15 (PSR-4). Usar `\Punto\App\Services\Notification::sendPush()`. ~10 callers.
 */
function sendPush($options){
    return \Punto\App\Services\Notification::sendPush($options);
}

/**
 * @deprecated Slice 15 (PSR-4). Usar `\Punto\App\Services\Notification::sendNCMSMS()`. ~1 caller.
 */
function sendNCMSMS($number,$msg,$country,$companyId=''){
    return \Punto\App\Services\Notification::sendNCMSMS($number, $msg, $country, $companyId);
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
            ' <a href="' . $url . '" style="color:white;background-color:#01D7A1;padding:13px 25px;text-decoration:none;text-transform: uppercase;font-family:Arial;font-size:0.9em;border-radius:100px;font-weight:bold;">' .
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

/**
 * @deprecated Slice 15 (PSR-4). Usar `\Punto\App\Services\Notification::sendWS()`. ~11 callers.
 */
function sendWS($ops = []){
    return \Punto\App\Services\Notification::sendWS((array) $ops);
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


function getFileContent($url){//usar solo con urls propias y controladas por encom
	$ops = 	[
							    "ssl" => [
									        "verify_peer" 		=> false,
									        "verify_peer_name" 	=> false,
									    ],
								'http' => [
											'header' 			=>
											'Cookie: ' . $_SERVER['HTTP_COOKIE'] . "\r\n",
											'timeout'			=> 5,
											'ignore_errors'		=> true,
										]
							];  

	return file_get_contents($url, false, stream_context_create($ops));
}

function getValidPhone($phone,$country=COUNTRY_CODE,$format=false){
    $phone      = preg_replace("/[^0-9]/", "", $phone);
    $format     = ($format) ? $format : 'international';
    $valid      = json_decode(curlContents(API_URL . '/phonevalidator.php?phone=' . $phone . '&country=' . $country . '&format=' . $format),true);

    return $valid;
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
		$companyRecord['plan'] 		= 3;
		$companyRecord['status'] 	= 'active';
		$companyRecord['expiresAt'] 	= date('Y-m-d 00:00:00', strtotime("+14 days"));
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

/**
 * @deprecated Slice 2 (PSR-4). Usar `\Punto\App\Http\Response\Json::die()` en código nuevo.
 *             Este wrapper se mantiene para los ~61 callers legacy.
 */
function jsonDieMsg($msg='true',$code=401,$type='error'){
    \Punto\App\Http\Response\Json::die($msg, $code, $type);
}

/**
 * ¿Es esta una VENTA SIMPLE (cashsale/creditsale puro) elegible para SaleService?
 *
 * FUENTE ÚNICA DE VERDAD de la elegibilidad del path simple (35a), COMPARTIDA por
 * ambos tiers para no duplicar la regla:
 *   - api/lib/Sales/SaleInput::assertSimplePathEligible() (tier API) — lanza 422 si NO.
 *   - processData en app/action.php (tier legacy)            — RECHAZA las que SÍ lo son
 *     (SaleService las posee desde 35a.8; el legacy sólo retiene type 0/3 NO-simples).
 *
 * Devuelve null si la venta es simple-elegible; si no, el motivo por el cual no lo es.
 * Paths no-simples diferidos a sub-slices futuros (EI 35b, giftcard 35c, sesiones 35d,
 * inCredit 35e, recurrente 35f) — esos siguen en el legacy hasta migrarse.
 *
 * @param array<string,mixed>            $payload La transacción (dict) — claves del front.
 * @param array<int,array<string,mixed>> $sale    payload['sale'] CRUDO (sin sanitizar).
 */
function saleIsSimplePathEligible(array $payload, array $sale): ?string
{
	// `electronicInvoicePY` era el payload del proveedor de FE legacy, retirado
	// en F4 — la facturación electrónica vive en api/lib/EInvoice/ y no viaja en
	// el payload de la venta. No se rechaza.
	// `repeat` migrado en 35f (SaleService::persistRecurring). No se rechaza.
	if (!empty($payload['parentId'])) {
		return 'Venta con parentId no soportada en este path (usar legacy)';
	}
	// Todos los pagos que modifican balance están migrados en SaleService:
	//   giftcard    → 35c.1  (persistGiftCardRedemptions)
	//   points      → 35e    (persistBalanceRedemptions)
	//   storeCredit → 35e    (persistBalanceRedemptions)
	// No quedan pagos rechazados por modificar balance.

	foreach ($sale as $item) {
		$itemType = (string) ($item['type'] ?? '');
		// Líneas que pueden no tener itemId y son válidas:
		//   discount → sin itemSold/stock
		//   giftcard → 35c.2 (crea giftCardSold)
		//   inCredit → 35e   (acredita contactStoreCredit del cliente)
		if (in_array($itemType, ['discount', 'giftcard', 'inCredit'], true)) {
			continue;
		}
		// `duration > 0` migrado en 35d (persistScheduledSessions). No se rechaza.
		if (empty($item['itemId'])) {
			return 'Línea de venta sin itemId no soportada en este path (usar legacy)';
		}
	}
	return null;
}

/**
 * @deprecated Slice 2 (PSR-4). Usar `\Punto\App\Http\Response\Json::send()` en código nuevo.
 *             Este wrapper se mantiene para los ~158 callers legacy.
 */
function jsonDieResult($array,$code=200){
    \Punto\App\Http\Response\Json::send($array, $code);
}

/**
 * @deprecated Slice 6 (PSR-4). Usar `\Punto\App\Helpers\Arr::safeImplode()`. ~36 callers.
 */
function implodes($str,$array,$returnEmpty=false){
    return \Punto\App\Helpers\Arr::safeImplode((string) $str, $array, (bool) $returnEmpty);
}

/**
 * @deprecated Slice 4 (PSR-4). Usar `\Punto\App\Helpers\Str::isHtml()` en código nuevo.
 *             Wrapper mantenido para los ~2 callers legacy.
 */
function isHTML($string){
    return \Punto\App\Helpers\Str::isHtml((string) $string);
}

/**
 * @deprecated Slice 4 (PSR-4). Usar `\Punto\App\Helpers\Str::markupHtml()` en código nuevo.
 *             Wrapper mantenido para los ~19 callers legacy.
 */
function markupt2HTML($options){
    return \Punto\App\Helpers\Str::markupHtml($options);
}

/**
 * @deprecated Slice 2 (PSR-4). Usar `\Punto\App\Http\Response\Output::dai()` en código nuevo.
 *             Este wrapper se mantiene para los ~542 callers legacy.
 */
function dai($val = '',$noclose = false){
    \Punto\App\Http\Response\Output::dai((string) $val, $noclose);
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

function findPhoneLogin($phone){
	// Solo el Dueño puede loguearse al panel. Soporta dos formatos de role
	// porque la mig 58 cambió contact.role de smallint a varchar(64):
	//   - '1' legacy (users pre-mig 58)
	//   - UUID que apunta a taxonomy role con slug='owner'
	// Phone storage convention: contactPhone se guarda SIN el '+' inicial
	// (libphonenumber lo agrega al normalizar, hay que strippearlo para match).
	$phone = ltrim((string)$phone, '+');
	// Alias quoted para preservar camelCase — convención del proyecto
	// post-refactor 28-jun (flattenJsonb plano ya no es CaseInsensitive).
	// login.php lee $row['contactPassword'], 'companyId', 'contactId', 'role'.
	return ncmExecute("SELECT c.*,
                              c.contactpassword AS \"contactPassword\",
                              c.contactid       AS \"contactId\",
                              c.companyid       AS \"companyId\"
                        FROM contact c
                        WHERE c.contactPhone = ?
                          AND c.type = 0
                          AND (
                            c.role = '1'
                            OR EXISTS (
                              SELECT 1 FROM taxonomy t
                              WHERE t.taxonomyid::text = c.role
                                AND t.taxonomytype = 'role'
                                AND t.companyid = c.companyid
                                AND t.taxonomyextra::json->>'slug' = 'owner'
                            )
                          )
                        LIMIT 1",[$phone]);
}

/**
 * @deprecated Slice 4 (PSR-4). Usar `\Punto\App\Helpers\Str::tryBase64Decode()` en código nuevo.
 *             Wrapper mantenido para los ~9 callers legacy.
 */
function isBase64Decode($str){
    return \Punto\App\Helpers\Str::tryBase64Decode((string) $str);
}

function acceptCompanyPayment($amount,$companyId){//company ID seria el ID del cliente de Income
	global $db,$meses;

    $update 	= $db->Execute('UPDATE company SET balance = balance + ' . $amount . ' WHERE companyId = ?',array($companyId));
    if($update){
    	$m 		 = date('m');
    	$month	 = $meses[$m-1];

    	$email 	= getValue('contact', 'contactEmail', 'WHERE main = "true" AND type = 0 AND companyId = '.$companyId);
    	$meta 	= array(
							":previousmonth" 	=> array($month),
							":total" 			=> array('$'.$amount)
						);

		sendSMTPEmail($meta,"9f8ce200-803d-46c4-847b-5f9c162db288",$email,'Pago procesado exitosamente',APP_NAME,APP_NAME);

		return true;
    }else{
    	return false;
    }
    
}

function mailSaleBackUp($sale,$companyId,$outletId,$date,$customerId='',$user='',$registerId='',$where=''){
	
  $mail = new PHPMailer(true);
  
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

    // UUID crudo (no $db->Prepare, que lo qstr-quotea → al pasarlo como bind param
    // quedaría con comillas literales y no matchearía la columna UUID).
    $trId         = dec($trId);

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
						$db->Execute('UPDATE contact SET contactLoyaltyAmount = contactLoyaltyAmount+'.$dat['price'].' WHERE contactId = ?',array($customer['customerId']));
					}else if($dat['type'] == 'storeCredit'){//devuelvo credito interno
						$db->Execute('UPDATE contact SET contactStoreCredit = contactStoreCredit+'.$dat['price'].' WHERE contactId = ?',array($customer['customerId']));
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
    // PG: UUID quoteado en el where-string de ncmUpdate (§22.5).
    ncmUpdate(['records' => $record, 'table' => 'transaction', 'where' => "transactionId = '" . $trId . "'"]);//records (arr), table (str), where (str)
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
    $db->Execute("DELETE FROM itemSold WHERE transactionId = ?", [$trId]);
    $db->Execute("DELETE FROM giftCardSold WHERE transactionId = ?", [$trId]);

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

/**
 * @deprecated Slice 6 (PSR-4). Usar `\Punto\App\Helpers\Math::divide()`. ~50 callers.
 */
function divider($val1,$val2,$force=false,$round=false){
    return \Punto\App\Helpers\Math::divide($val1, $val2, (bool) $force, $round);
}

/**
 * @deprecated Slice 6 (PSR-4). Usar `\Punto\App\Helpers\Math::round()`. 0 callers externos.
 */
function rounder($value,$round=false){
    return \Punto\App\Helpers\Math::round($value, $round);
}

/**
 * @deprecated Slice 6 (PSR-4). Usar `\Punto\App\Helpers\Math::diff()`. ~3 callers.
 */
function rester($first,$second,$round=false){
    return \Punto\App\Helpers\Math::diff($first, $second, $round);
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

/**
 * @deprecated Slice 4 (PSR-4). Usar `\Punto\App\Helpers\Str::toUtf8()` en código nuevo.
 *             Wrapper mantenido para los ~238 callers legacy.
 */
function toUTF8($text){
    return \Punto\App\Helpers\Str::toUtf8($text);
}
/**
 * @deprecated Slice 12 (PSR-4). Usar `\Punto\App\Domain\Money::sanitizeTaxObj()`. ~2 callers.
 */
function taxObjSanitizer($array){
    return \Punto\App\Domain\Money::sanitizeTaxObj($array);
}

/**
 * @deprecated Slice 12 (PSR-4). Usar `\Punto\App\Domain\Money::sanitizePaymentObj()`. ~1 caller.
 */
function paymentMObjSanitizer($array){
    return \Punto\App\Domain\Money::sanitizePaymentObj($array);
}

/**
 * @deprecated Slice 12 (PSR-4). Usar `\Punto\App\Domain\Money::sanitizeSaleArray()`. ~2 callers.
 */
function saleArraySanitizer($array){
    return \Punto\App\Domain\Money::sanitizeSaleArray($array);
}
?>