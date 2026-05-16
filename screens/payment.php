<?php
include_once('sa_head.php');


$nro_operacion 				= strtotime(date('Y-m-d H:i:s'));
$nro_operacion 				= substr($nro_operacion, -6);

function fltrSentData($data)
{
	$allowedData 	= ['bin', 'status', 'currency', 'hook_alias', 'account_type', 'response_code', 'authorizationResult', 'purchaseCurrencyCode', 'paymentReferenceCode', 'brand', 'IDTransaction'];
	$aSentData 		= [];

	foreach ($data as $key => $value) {
		if (in_array($key, $allowedData)) {
			$aSentData[$key] = $value;
		}
	}

	$sentData 		= array_filter($aSentData, 'strlen'); //elimino los valores null o vacios
	return $sentData;
}
function getIdFormBancard($data)
{
	$url = BANCARD_CARD_API . "/api/payments/occasional";
	$authorization = BANCARD_CARD_API_TOKEN;
	$options = array(
		'http' => array(
			'header' => "Content-type: application/x-www-form-urlencoded\r\n" .
				"Authorization: $authorization\r\n" .
				"Accept: application/json\r\n",
			'method' => 'POST',
			'content' => http_build_query($data),
		),
	);

	$context = stream_context_create($options);
	$result = file_get_contents($url, false, $context);

	if ($result === FALSE) {
		// Manejar el error
		echo "Error en la solicitud";
	}

	return $result;
}
function APIGet($data)
{
	// Convert the data array to a query string
	$query_string = http_build_query($data);

	// Append the query string to the URL
	$url = BANCARD_CARD_API . "/api/payments/$data/status";
	// return $url;
	$authorization = BANCARD_CARD_API_TOKEN;

	$options = array(
		'http' => array(
			'header' => "Authorization: $authorization\r\n" .
				"Accept: application/json\r\n",
			'method' => 'GET',
		),
	);

	$context = stream_context_create($options);
	$result = file_get_contents($url, false, $context);

	if ($result === FALSE) {
		// Handle the error
		echo "Error en la solicitud";
	}

	return $result;
}

if ($ese == 'bancardQR' && isset($_GET['qr_payment_bancard_response'])) {

	$identifier 							= json_decode($_GET['identifier'], true);
	$B_DATA									= $_GET['qr_payment_bancard_response'];
	$B_DATA 								= $B_DATA['payment'];
	$COMPANY_ID 							= $identifier['companyID'];
	$OUTLET_ID 								= $identifier['outletID'];
	$REGISTER_ID							= $identifier['registerID'];
	$USER_ID								= $identifier['userID'];
	$UID 									= $identifier['UID'];
	$AMOUNT									= $identifier['amount'];
	$SALE_AMOUNT							= $identifier['saleAmount'];
	$COMISSION								= $identifier['comission'];
	$TAX									= $identifier['tax'];
	$ISONLINE								= array_key_exists('isOnline', $identifier) ? $identifier['isOnline'] : false;

	$STATUS 								= $_GET['status'];
	$OP_NO 									= $B_DATA['ticket_number'];
	$AUTH_NO 								= $B_DATA['authorization_code'];
	$API_KEY 								= getAPICreds(dec($COMPANY_ID));

	if ($STATUS == 'confirmed') {
		$STATUS 	= 'APPROVED';
	} else {
		$STATUS 	= 'DENIED';
	}

	$source 		= 'bancardQR';

	if ($ISONLINE) {
		$source = 'bancardQROnline';
	}

	$sentData 	= fltrSentData($B_DATA);

	if ($STATUS == 'APPROVED') {

		$data 				= [
			'api_key'     	=> $API_KEY,
			'company_id'  	=> $COMPANY_ID,
			'outlet' 		=> $OUTLET_ID,
			'register' 		=> $REGISTER_ID,
			'user' 			=> $USER_ID,
			'UID' 			=> $UID,
			'status' 		=> $STATUS,
			'amount' 		=> $AMOUNT,
			'saleAmount'	=> $SALE_AMOUNT,
			'comission'		=> $COMISSION,
			'tax'			=> $TAX,
			'source' 		=> $source,
			'paymentType' 	=> $sentData['account_type'],
			'data' 			=> json_encode($sentData),
			'order' 		=> $nro_operacion,
			'authCode' 		=> $AUTH_NO,
			'operationNo' 	=> $OP_NO
		];

		$result 			= curlContents(API_URL . '/add_vpayment', 'POST', $data);
	}

	$data 				= [
		'api_key'       => $API_KEY,
		'company_id'    => $COMPANY_ID,
		'channel'       => $COMPANY_ID . '-' . $REGISTER_ID . '-qrBancard',
		'event'         => 'qrBancard',
		'message'       => json_encode(['UID' => $UID, 'status' => $STATUS, 'op_no' => $OP_NO])
	];

	curlContents(API_URL . '/send_webSocket.php', 'POST', $data);


	//Notifico al panel encom si es un pago en el momento
	if (validInArray($result, 'success') && validInArray($result, 'payNow')) {
		$data 				= [
			'api_key'       => $API_KEY,
			'company_id'    => $COMPANY_ID,
			'channel'       => 'ncm-ePOS',
			'event'         => 'payoutNow',
			'message'       => json_encode(['title' => 'ePOS', 'msg' => 'Nuevo pago realizado'])
		];

		curlContents(API_URL . '/send_webSocket.php', 'POST', $data);
	}

	die();
}

if (isset($_POST['acquirerId']) && isset($_POST['idCommerce'])) {
	// error_log($_POST['reserved1']);
	// error_log(print_r($_POST));
	$response = $_POST;
	if (array_key_exists('javax_faces_ViewState', $response)) {
		unset($response['javax_faces_ViewState']);
	}
	$reserved1 								= explode(';', validateHttp('reserved1', 'post'));
	$reserved2 								= explode(';', validateHttp('reserved2', 'post'));
	$B_DATA 								= array_filter($_POST, 'strlen');
	$UID 									= $reserved1[0];
	$COMPANY_ID 							= $reserved1[1];
	$OUTLET_ID 								= $reserved1[2];
	$REGISTER_ID 							= $reserved1[3];
	$AMOUNT									= intval($reserved2[0]);
	$SALE_AMOUNT							= $reserved2[1];
	$COMISSION								= $reserved2[2];
	$TAX									= $reserved2[3];

	$API_KEY 								= getAPICreds(dec($COMPANY_ID));

	$authorizationResult 					= validateHttp('authorizationResult', 'post');
	$errorCode 								= validateHttp('errorCode', 'post');
	$AUTH_NO 								= validateHttp('authorizationCode', 'post');
	$OP_NO 									= validateHttp('purchaseOperationNumber', 'post');

	if (intval($authorizationResult) < 1 && intval($errorCode) < 1) { //procesado correctamente
		$STATUS 		= 'APPROVED';
	} else { //error al procesar
		$STATUS 		= 'DENIED';
	}

	if ($STATUS == 'APPROVED') {

		$sentData 		= fltrSentData($B_DATA);

		$data 				= [
			'api_key'     	=> $API_KEY,
			'company_id'  	=> $COMPANY_ID,
			'outlet' 		=> $OUTLET_ID,
			'UID' 			=> $UID,
			'status' 		=> $STATUS,
			'amount' 		=> $AMOUNT,
			'saleAmount'	=> $SALE_AMOUNT,
			'comission'		=> $COMISSION,
			'tax'			=> $TAX,
			'source' 		=> 'dinelcoVPOS',
			'paymentType' 	=> $sentData['brand'],
			'data' 			=> json_encode(array("sent" => $sentData, "reserved1" => $reserved1, "reserved2" => $reserved2, "response" => $response)),
			'order' 		=> $OP_NO,
			'authCode' 		=> $AUTH_NO,
			'operationNo' 	=> $OP_NO
		];

		$result 			= curlContents(API_URL . '/add_vpayment', 'POST', $data);
	}

	$data 				= [
		'api_key'       => $API_KEY,
		'company_id'    => $COMPANY_ID,
		'channel'       => $COMPANY_ID . '-' . $REGISTER_ID . '-qrBancard',
		'event'         => 'qrBancard',
		'message'       => json_encode(['UID' => $UID, 'status' => $STATUS, 'op_no' => $OP_NO])
	];

	$result 			= json_encode(curlContents(API_URL . '/send_webSocket.php', 'POST', $data));

	include('paymentResult.php');

	die();
}

// PAGO POR VPOS BANCARD

if (isset($_GET['shop_process_id']) && isset($_GET['status'])) {

	// CONSULTAR EL ESTADO DE LA OPERACION
	$process_id 	= $_GET['shop_process_id'];
	$STATUS 		= $_GET['status'] == 'payment_fail'  ? 'DENIED' : 'APPROVED';
	$response 		=  APIGet($process_id);
	$responseJson	= json_decode($response);

	$items	  				= $responseJson->confirmation->items[0];
	$additional	  			= $responseJson->additional;
	$CUSTOMER_NAME 			= $responseJson->additional->customerName;
	$CUSTOMER_RUC 			= $responseJson->additional->customerRuc;
	$UID 					= $additional->uid;
	$COMPANY_ID 			= $additional->company;
	$OUTLET_ID 				= $additional->outlet;
	$REGISTER_ID 			= $additional->register;
	$AMOUNT					= intval($additional->amount);
	$SALE_AMOUNT			= $additional->saleAmount;
	$COMISSION				= 0;
	$TAX					= 0;
	$currency				=
	$API_KEY 				= getAPICreds(dec($COMPANY_ID));
	$currency				= $items->currency;
	$authorizationResult 	= $items->response_code;
	$errorCode 				= $items->response_code;
	$message 				= $items->response_description;
	$AUTH_NO 				= $items->authorization_code;
	$OP_NO 					= $items->authorization_id;
	// $STATUS									= $AUTH_NO !== null ? 'APPROVED' : 'DENIED';
	//  var_dump($responseJson);die();
	if ($STATUS == 'APPROVED') {

		$data 	= [
			'api_key'     	=> $API_KEY,
			'company_id'  	=> $COMPANY_ID,
			'outlet' 		=> $OUTLET_ID,
			'UID' 			=> $UID,
			'status' 		=> $STATUS,
			'amount' 		=> $AMOUNT,
			'saleAmount'	=> $SALE_AMOUNT,
			'comission'		=> $COMISSION,
			'tax'			=> $TAX,
			'source' 		=> 'bancardVPOS',
			'paymentType' 	=> 'bancardVPOS',
			'data' 			=> json_encode(array("account_type" => "", "response" => $response)),
			'order' 		=> $OP_NO,
			'authCode' 		=> $AUTH_NO,
			'operationNo' 	=> $OP_NO
		];

		$result 			= curlContents(API_URL . '/add_vpayment', 'POST', $data);
		 // Agregar código para desbloquear la cuenta
		 $updateQuery = "UPDATE company SET blocked = 0 WHERE companyId = ?";
		 $params = [$COMPANY_ID];
		 ncmExecute($updateQuery, $params);

	}


	$data 				= [
		'api_key'       => $API_KEY,
		'company_id'    => $COMPANY_ID,
		'channel'       => $COMPANY_ID . '-' . $REGISTER_ID . '-qrBancard',
		'event'         => 'qrBancard',
		'message'       => json_encode(['UID' => $UID, 'status' => $STATUS, 'op_no' => $OP_NO])
	];

	$result 			= json_encode(curlContents(API_URL . '/send_webSocket.php', 'POST', $data));
	include('paymentResult.php');

	die();
}
$ese 	= $_GET['s'];
$data = base64_decode($ese);
$data = json_decode($data, true);



define('COMPANY_ID', dec($data['company']));
define('TOTAL', (int)$data['amount']);
define('CUSTOMER', dec($data['customer']));
define('DATE', $data['date']);
define('UID', $data['uid']); //uid de la transaccion
define('OUTLET', dec($data['outlet']));
define('REGISTER', dec($data['register']));
define('USER_ID', $data['user']);


$company = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [COMPANY_ID]);
$modules = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1", [COMPANY_ID]);

if($company['status'] != 'Active'){
	header('location: /');
	die();
}
if($setting['blocked'] > 0){
	header('location: /');
	die();
}

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);

define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);

define('TAX_NAME', $setting['settingTaxName']);
define('TIN_NAME', $setting['settingTIN']);

define('COMPANY_NAME', $setting['settingName']);
define('LANGUAGE', $setting['settingLanguage']);

loadLanguage(LANGUAGE);
date_default_timezone_set(TIMEZONE);




$API_KEY 			 			= getAPICreds(COMPANY_ID);
$eposData 		 				= json_decode($modules['eposData'], true);

//PARAMS BEPSA
$acquirerId 	 				= '54';
$idCommerce 	 				= '5800167'; //$eposData['idCommerce'];//'18331'
$purchaseCurrencyCode 			= '600';
$apiKey 			 			= 'wKaQGqADzVFjXdtJ.777935329'; //$eposData['hash'];//'yVVePEKVWWNFkfnLqy@45835525258';//'wKaQGqADzVFjXdtJ.777935329';
$userCodePayme 					= '8--580--4390';
//PARAMS BEPSA

// error_log('hola',3,'/var/www/panel-standalone-v1/screens/mierror.log');
// exit();
$hash 				 			= hash('sha512', $acquirerId . $idCommerce . $nro_operacion . TOTAL . $purchaseCurrencyCode . $apiKey);
// $datatxt 						= 'acquirerId : '.$acquirerId .'<br> idCommerce: '. $idCommerce .'<br> purchaseOperationNumber: '. $nro_operacion .'<br> purchaseAmount: '. TOTAL .'<br> p    <link rel="stylesheet" href="/assets/vendor/css/bootstrap-4.5.2.min.css">urchaseCurrencyCode: '. $purchaseCurrencyCode .'<br> apiKey: '. $apiKey .'<br> userCodePayme : '.$userCodePayme.'<br> purchaseVerification : '.$hash;

// print_r($datatxt);die();
$reserved1 						=  UID . ';' . $data['company'] . ';' . $data['outlet'] . ';' . $data['register'];
$reserved2 						=  $data['amount'] . ';' . $data['saleAmount'] . ';' . $data['comission'] . ';' . $data['tax'];
$customerName 					= 'Cliente Ocasional';
$customerRuc 					= '';
$customerEmail 					= 'encom@gmail.com';

if (CUSTOMER) {
	$contact 					= ncmExecute('SELECT * FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1', [CUSTOMER, COMPANY_ID]);
	$customerName 				= iftn($contact['contactName'], '');
	$customerRuc 				= iftn($contact['contactTIN'], '');
	$customerEmail 				= iftn($contact['contactEmail'], 'encom@gmail.com');
}

$data['customerName'] 	= $customerName;
$data['customerRuc']  	= $customerRuc;

$additional = array(
	'amount' => TOTAL,
	'currency' => 'PYG',
	"success_url" => PUBLIC_URL . "/payment",
	"error_url" => PUBLIC_URL . "/payment",
	'additional' => $data
);

$process_id = '';

if(TOTAL > 0){
	$resul = json_decode(getIdFormBancard($additional));
	if ($resul->status == 'success') {
		$process_id = $resul->process_id;
	}
}
$headerQR 						= [
	"Accept: application/json",
	"Authorization: Bearer " . BANCARD_QR_API_TOKEN,
	"Content-Type: application/json"
];

if (isset($_GET['createQR'])) {
	$get 					= $_GET;
	$comission   	= round(($eposData['rate'] / 100) * TOTAL);
	$comTax      	= round(($eposData['tax'] / 100) * $comission);

	$data   			=   [
		"amount"      => TOTAL,
		"description" => 'Pago a ' . COMPANY_NAME,
		"identifier"  => json_encode([
			'companyID'   => enc(COMPANY_ID),
			'outletID'    => enc(OUTLET),
			'registerID'  => enc(REGISTER),
			'UID'         => UID,
			'amount'      => TOTAL,
			'saleAmount'  => TOTAL,
			'userID' 		=> USER_ID,
			'customerName' => $customerName,
			'customerRuc'	=> $customerRuc,
			'isOnline' 		=> true
		])
	];

	echo curlContents(BANCARD_QR_API . '/create', 'POST', json_encode($data), $headerQR);

	die();
}

if (isset($_GET['checkIfPayed'])) {

	$data 				= 	[
		'api_key'       => $API_KEY,
		'company_id'    => enc(COMPANY_ID),
		'UID'       	  => UID
	];
	
	$result = json_decode(curlContents(API_URL . '/get_vpayments.php', 'POST', $data), true);

	if (array_key_exists('success', $result)) {
		// jsonDieMsg('exists', 401, 'error');
		jsonDieResult($result, 401);
	} else {
		jsonDieMsg('does_not_exists', 200, 'success');
	}
}

?>

<!DOCTYPE html>
<html class="no-js">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
    <title><?= COMPANY_NAME; ?></title>
    <meta property="og:title" content="<?= COMPANY_NAME; ?>" />
    <meta property="og:image" content="/assets/150-150/0/<?= enc(COMPANY_ID) ?>.jpg" />
    <link rel="stylesheet" href="/assets/vendor/css/bootstrap-4.5.2.min.css">
    <?php loadCDNFiles([], 'css'); ?>
    <style>
        body, html {
            height: 100%;
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .centered-container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100%;
            width: 100%;
        }
        .qr-button, .submit-button {
            background-color: #007bff;
            border: none;
            color: white;
            padding: 10px 20px;
            text-align: center;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
            margin: 4px 2px;
            cursor: pointer;
            border-radius: 4px;
        }
        .qr-button:hover, .submit-button:hover {
            background-color: #0056b3;
        }
    </style>
</head>

<body class="bg-light lter col-xs-12 no-padder">
    <section class="vbox" id="content">
        <div class="wrapper text-center col-md-4 col-md-offset-4 col-sm-6 col-sm-offset-3 col-xs-12">
            <div class="visible-print"><br><br></div>
            <img height="80" width="80" src="/assets/150-150/0/<?= enc(COMPANY_ID) ?>.jpg" class="img-circle animated bounceIn" id="logo" style="" />
            <h3 class="h3 m-t-xs m-b-md font-bold"><?= COMPANY_NAME; ?></h3>

            <div class="animated fadeInUp speed-3x r-24x md-whiteframe-16dp col-xs-12 no-padder clear" style="" id="list">
                <div class="panel col-xs-12 wrapper m-n hidden" id="default">
                    <div class="col-xs-12 no-padder hidden-print">
                        <div class="text-xs text-muted">Total a pagar</div>
                        <div class="h1 font-bold text-dark"><?= CURRENCY . formatCurrentNumber(TOTAL) ?></div>
                    </div>
                    <div>&nbsp;</div>
                    <table class="table table-borderless text-left">
                        <tbody>
                            <tr>
                                <td><?= L_CUSTOMER ?></td>
                                <td class="font-bold"><?= $customerName ?></td>
                            </tr>
                            <tr>
                                <td>Fecha</td>
                                <td class="font-bold"><?= DATE ?></td>
                            </tr>
                            <tr>
                                <td>Nro. de Operación</td>
                                <td class="font-bold">#<?= UID ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <form asp-for="f1" id="f1" method="post" class="alignet-form-vpos2" action="payment?s=<?= $ese ?>">
                        <input type="hidden" id="acquirerId" name="acquirerId" value="<?= $acquirerId ?>">
                        <input type="hidden" id="idCommerce" name="idCommerce" value="<?= $idCommerce ?>">
                        <input type="hidden" id="purchaseAmount" name="purchaseAmount" value="<?= TOTAL ?>">
                        <input type="hidden" id="purchaseCurrencyCode" name="purchaseCurrencyCode" value="<?= $purchaseCurrencyCode ?>">
                        <input type="hidden" id="purchaseOperationNumber" name="purchaseOperationNumber" value="<?= $nro_operacion ?>">
                        <input type="hidden" id="language" name="language" value="SP">
                        <input type="hidden" id="shippingFirstName" name="shippingFirstName" value="<?= $customerName; ?>">
                        <input type="hidden" id="shippingLastName" name="shippingLastName" value="<?= $customerName; ?>">
                        <input type="hidden" id="shippingEmail" name="shippingEmail" value="<?= $customerEmail; ?>">
                        <input type="hidden" id="shippingAddress" name="shippingAddress" value="Asuncion">
                        <input type="hidden" id="shippingZIP" name="shippingZIP" value="ZIP 123">
                        <input type="hidden" id="shippingCity" name="shippingCity" value="Asuncion">
                        <input type="hidden" id="shippingState" name="shippingState" value="Asuncion">
                        <input type="hidden" id="shippingCountry" name="shippingCountry" value="PY">
                        <input type="hidden" id="userCommerce" name="userCommerce" value="<?= COMPANY_NAME ?>">
                        <input type="hidden" id="userCodePayme" name="userCodePayme" value="<?= $userCodePayme ?>">
                        <input type="hidden" id="descriptionProducts" name="descriptionProducts" value="Compra en <?= COMPANY_NAME ?>">
                        <input type="hidden" id="programmingLanguage" name="programmingLanguage" value="PHP">
                        <input type="hidden" id="reserved1" name="reserved1" value="<?= $reserved1; ?>">
                        <input type="hidden" id="reserved2" name="reserved2" value="<?= $reserved2; ?>">
                        <input type="hidden" id="purchaseVerification" name="purchaseVerification" value="<?= $hash; ?>">
                        <?php if (TOTAL > 0) { ?>
                            <div class="col-xs-12 text-center">
                                <h4 class="text-u-c font-bold">Pagar con</h4>
                            </div>
                            <div class="btn-group m-b-lg">
                                <a href="#" data-type="qr" class="btn btn-lg btn-info makeOrder text-u-c font-bold btn-rounded"> <span class="material-icons">qr_code_2</span> QR</a>
                                <a href="#" data-type="card" class="btn btn-lg btn-info makeOrder text-u-c font-bold btn-rounded"> <span class="material-icons">credit_card</span> Tarjeta</a>
                            </div>
                        <?php } ?>
                    </form>
                </div>

                <div class="panel bg-danger col-xs-12 wrapper m-n hidden" id="rejected" style="min-height:280px;">
                    <div class="col-xs-12 no-padder hidden-print">
                        <div class="text-xs text-white">Pago</div>
                        <div class="h1 font-bold text-white">Rechazado</div>
                    </div>
                </div>

                <div class="panel bg-success col-xs-12 wrapper m-n hidden" id="approved" style="min-height:280px;">
                    <div class="col-xs-12 no-padder hidden-print">
                        <div class="text-xs text-white">Pago</div>
                        <div class="h1 font-bold text-white">Aprobado</div>
                    </div>
                </div>
            </div>

            <div class="m-b-md m-t-lg col-xs-12 animated bounceIn" id="encom">
                <a href="/?utm_source=ENCOM_online_receipt&utm_medium=ENCOM_footer_icon&utm_campaign=<?= COMPANY_NAME ?>" class="block hidden-print">
                    <span class="text-muted"><?= PRESENTADO ?></span> <br>
                    <img src="/images/incomeLogoLgGray.png" width="80">
                </a>
                <br>
                <div class="visible-print font-bold">
                    <span><?= PRESENTADO ?></span> <br>
                    <div>WWW.ENCOM.APP</div>
                </div>
            </div>
        </div>

        <div class="modal fade" tabindex="-1" id="modalView" role="dialog">
            <div class="modal-dialog">
                <div class="modal-content bg-white no-padder clear col-xs-12 r-24x all-shadows no-border">
                    <div class="modal-body">
                        <div style="height: 30%px; width: 100%; margin: auto" id="iframe-container"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script type="text/javascript">
        window.standAlone = true;
        var noSessionCheck = true;
        var ese = '<?= $ese ?>';
        var screen = '';
        var process_id = '<?= $process_id ?>';
    </script>
    <script type="text/javascript" src="https://vpayment.verifika.com/VPOS2/js/modalcomercio.js"></script>
    <script src="https://vpos.infonet.com.py/checkout/javascript/dist/bancard-checkout-3.0.0.js"></script>
    <?php loadCDNFiles([], 'js'); ?>

    <script type="text/javascript">
	$(document).ready(() => {
    var ese = '<?= $ese ?>';
    
    var checkStatus = $.get('?s=' + ese + '&checkIfPayed=true');
    
    checkStatus.done(() => {
        $('#default').removeClass('hidden');
    
        ncmHelpers.onClickWrap('.makeOrder', async function(event, tis) {
            var type = tis.data('type');
    
            if (type == 'card') {
                spinner('body', 'show');
                await FormBancard();
                spinner('body', 'hide');
            } else {
                spinner('body', 'show');
                $.get('?s=' + ese + '&createQR=true', (result) => {
                    result = JSON.parse(result);
                    var imgUrl = result.qr_url;
                    var img = `
                        <img src="${imgUrl}" width="100%">
                        <div class="col-xs-12 text-center m-t m-b-lg">
                            Mantenga presionado en la imagen para descargar
                            <a download href="${imgUrl}" title="QR" class="btn btn-md btn-rounded btn-lg btn-info text-u-c font-bold hidden">
                                descargar QR
                            </a>
                        </div>`;
    
                    $('#modalView .modal-content').html(img);
                    $('#modalView').modal('show');
                    spinner('body', 'hide');
                });
            }
        });
    
        $('#logo').show();
        setTimeout(() => $('#list').show(), 100);
        setTimeout(() => $('#loyalty,#companyData,#encom').show(), 650);
    });
    
    checkStatus.fail(() => {
        $('#approved').removeClass('hidden');
    });

    // Function to periodically check payment status
    const checkPaymentStatus = () => {
        $.get('?s=' + ese + '&checkPaymentStatus=true', (response) => {
            response = JSON.parse(response);
            if (response.status === 'approved') {
                $('#approved').removeClass('hidden');
                $('#default').addClass('hidden');
                $('#modalView').modal('hide'); // Ocultar el modal de QR
                alert('¡Pago aprobado!');
            } else if (response.status === 'rejected') {
                $('#rejected').removeClass('hidden');
                $('#default').addClass('hidden');
                $('#modalView').modal('hide'); // Ocultar el modal de QR
                alert('¡Pago rechazado!');
            }
        });
    };

    // Check payment status every 10 seconds
    setInterval(checkPaymentStatus, 10000);

    const FormBancard = async () => {
        $('#modalView').modal('show');
        let styles = {
            "form-background-color": "#001b60",
            "button-background-color": "#4faed1",
            "button-text-color": "#fcfcfc",
            "button-border-color": "#dddddd",
            "input-background-color": "#fcfcfc",
            "input-text-color": "#111111",
            "input-placeholder-color": "#111111"
        };
        Bancard.Checkout.createForm('iframe-container', process_id, styles);
    };
});


    </script>
</body>
</html>

<?php dai(); ?>

<?php
dai();

