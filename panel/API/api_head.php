<?php
require_once(__DIR__ . '/../includes/cors.php');

if(empty($_POST)){
	$_POST = json_decode(file_get_contents('php://input'),true);
}

include_once('../libraries/rateLimiter.php');

$rateLimiter  = new RateLimiter($_POST['company_id']);
$limit        = 60;       //  number of connections to limit user to per $minutes
$minutes      = 1;        //  number of $minutes to check for.
$seconds      = floor($minutes * 60); //  retry after $minutes in seconds.

try {
  $rateLimiter->limitRequestsInMinutes($limit, $minutes);
} catch (RateExceededException $e) {
  header("HTTP/1.1 429 Too Many Requests");
  header(sprintf("Retry-After: %d", $seconds));
  $data = 'Rate Limit Exceeded ';
  die(json_encode($data));
}

include_once('../includes/db.php');
include_once('../includes/simple.config.php');
include_once('../includes/functions.php');

function enc($str): string { return (string)$str; }

function sendFE($data, $token){
	$curl = curl_init();

  curl_setopt_array($curl, array(
    CURLOPT_URL => FACTURACION_ELECTRONICA_URL.'/api/transaction',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => array(
      'Content-Type: application/json',
      'Authorization: Bearer ' . $token
    ),
  ));

  $response = curl_exec($curl);

  curl_close($curl);
  
  return $response;
}

function dec($str): string { return (string)$str; }
function getSaleType($type){

	switch ($type) {
	    case '0':
	        $saleType 	= 'cashsale';
	        $docType 	= 'registerInvoiceNumber';
	        break;
	    case '1':
	        $saleType 	= "";
	        $docType 	= "";
	        break;
	    case '2':
	       	$saleType 	= 'saved';
	        $docType 	= "";
	        break;
	    case '3':
	        $saleType 	= 'creditsale';
	        $docType 	= 'registerInvoiceNumber';
	        break;
	    case '4':
	        $saleType 	= "";
	        $docType 	= "";
	        break;
	    case '5':
	        $saleType 	= 'creditpayment';
	        $docType 	= 'registerTicketNumber';
	        break;
	    case '6':
	        $saleType 	= 'return';
	        $docType 	= 'registerReturnNumber';
	        break;
	    case '7':
	        $saleType 	= 'void';
	        $docType 	= "";
	        break;
	    case '8':
	        $saleType 	= 'recursive';
	        $docType 	= "";
	        break;
	    case '9':
	        $saleType 	= 'quote';
	        $docType 	= 'registerQuoteNumber';
	        break;
	    case '10':
	        $saleType 	= 'delivery';
	        $docType 	= "";
	        break;
	    case '11':
	        $saleType 	= 'opentable';
	        $docType 	= "";
	        break;
	    case '12':
	        $saleType 	= 'order';
	        $docType 	= 'registerPedidoNumber';
	        break;
	    case '13':
	        $saleType 	= 'schedule';
	        $docType 	= 'registerScheduleNumber';
	        break;
	    case '14':
	        $saleType 	= "";
	        $docType 	= "";
	        break;
	}

	return array($saleType,$docType);
}

$companyId 	= validateHttp('company_id','post');
$apiKey 		= validateHttp('api_key','post');
$post 			= db_prepare($_POST);
$get 				= db_prepare($_GET);

if(!validateAPIAccess($companyId,$apiKey,$get['debug'])){
	jsonDieMsg('Acceso denegado',403);
}

define('ECOMPANY_ID', $companyId);
define('COMPANY_ID', dec($companyId));
define('API_KEY', $apiKey);
setTimeZone(COMPANY_ID);//debe estar antes que date()
define('TODAY', date('Y-m-d H:i:s'));
define('TODAY_START', date('Y-m-d 00:00:00'));
define('TODAY_END', date('Y-m-d 23:59:59'));
define('ASSETS_URL', '/assets/');
define('SYSIMGS_FOLDER', '/home/encom/public_html/assets/sysimages');
define('OUTLET_ID', iftn($post['outletId'],''));
define('REGISTER_ID', iftn($post['registerId'],''));


//outlets count
$outlets = ncmExecute("SELECT COUNT(outletId) as count FROM outlet WHERE outletStatus = 1 AND companyId = ? LIMIT 30",[COMPANY_ID],true);
define('OUTLETS_COUNT', $outlets['count']);

$roc = getROC(1);
?>