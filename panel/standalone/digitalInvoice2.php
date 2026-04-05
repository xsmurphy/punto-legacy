<?php
include_once('sa_head.php');
include_once('../libraries/enLetras.class.php');

$data 		= explodes(',', base64_decode($_GET['s']));
$baseUrl 	= 'https://public.encom.app/digitalInvoice?s=' . validateHttp('s');

define('TRANS_ID', dec($data[0]));
define('COMPANY_ID', dec($data[1]));

//verifico si existe la factura
$exists = ncmExecute('SELECT transactionId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[TRANS_ID,COMPANY_ID]);
 
if(!$exists){
	include_once('/home/encom/public_html/panel/includes/404.inc.php');
	die();
}

$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('TAX_NAME', $setting['settingTaxName']);
define('TIN_NAME', $setting['settingTIN']);
define('COMPANY_NAME', $setting['settingName']);
define('TODAY', date('Y-m-d'));
define('LANGUAGE', $setting['settingLanguage']);

loadLanguage(LANGUAGE);
date_default_timezone_set(TIMEZONE);

$_modules 	= ncmExecute('SELECT digitalInvoice, digitalInvoiceData FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

$modData 	= json_decode($_modules['digitalInvoiceData'],true);
$_template 	= ncmExecute('SELECT taxonomyExtra as template FROM taxonomy WHERE taxonomyType = ? AND taxonomyId = ? AND companyId = ? LIMIT 1',['printTemplate',dec($modData['template']),COMPANY_ID]);

if(!$_modules['digitalInvoice'] || !$_template){
	include_once('/home/encom/public_html/panel/includes/404.inc.php');
	die();
}

if(validateHttp('make')){

	//dai();

	$apikey 				= PDF_API_KEY;
	$page 					= validateHttp('page');
	$pageSize 			= 'A4';
	$value 					= validateHttp('data','post');
	$url 						= urlencode( "https://public.encom.app/digitalInvoice?s=" . validateHttp('s') . "&secret=iwfyita" );

	if($pageSize == 'letterpage'){
		$pageSize 			= 'Letter';
	}else if($pageSize == 'legalpage'){
		$pageSize 			= 'Legal';
	}

	$pdf   					=   [
			                        'apikey'    			=> $apikey,
			                        'value'  				=> $value,
			                        'PageSize' 				=> $pageSize,
			                        'JavascriptDelay' 		=> 1000,
			                        'DisableJavascript' 	=> false,
			                        'UsePrintStylesheet' 	=> true
			                    ];

	$pdfd  					= curlContents('http://api.html2pdfrocket.com/pdf', 'POST', $pdf);

	header('Content-Description: File Transfer');
	header('Content-Type: application/pdf');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Length: ' . strlen($pdfd));
	header('Content-Disposition: attachment; filename=' . COMPANY_NAME . ' ' . TODAY . '.pdf' );
	 
	echo $pdfd;

	dai();
}

$result 			= ncmExecute('SELECT * FROM transaction WHERE transactionType IN(0,3,5) AND transactionId = ? AND companyId = ? LIMIT 1',[TRANS_ID,COMPANY_ID]);

if(!$result){
	header('location: https://encom.app');
	dai();
}

$contact 			= ncmExecute('SELECT * FROM contact WHERE contactUID = ? AND companyId = ? LIMIT 1',[$result['customerId'],COMPANY_ID]);

$customerName 		= iftn($contact['contactName'],'Sin cliente');
$customerRuc 		= iftn($contact['contactTIN'],'');

$array = [	
					'total' 				=> formatCurrentNumber($result['transactionTotal'] - $result['transactionDiscount']),
					'totalRaw' 				=> ($result['transactionTotal'] - $result['transactionDiscount']),
					'subtotal' 				=> formatCurrentNumber($result['transactionTotal']),
					'discount' 				=> formatCurrentNumber($result['transactionDiscount']),
					'tax' 					=> formatCurrentNumber($result['transactionTax']),
					'companyId' 			=> enc($result['companyId']),
					'outletId' 				=> enc($result['outletId']),
					'transactionId'			=> enc($result['transactionId']),
					'date' 					=> $result['transactionDate'],
					'payment' 				=> $result['transactionPaymentType'],
					'expires' 				=> ($result['transactionDueDate']) ? $result['transactionDueDate'] : $result['transactionDate'],
					'taxName' 				=> TAX_NAME,
					'companyName' 			=> COMPANY_NAME,
					'sale' 					=> json_decode(stripslashes($result['transactionDetails']),true)
				];


$register 		= ncmExecute('SELECT * FROM register WHERE registerId = ? AND companyId = ?',[$result['registerId'],COMPANY_ID]);
$outlet 		= ncmExecute('SELECT * FROM outlet WHERE outletId = ? AND companyId = ?',[$result['outletId'],COMPANY_ID]);

$docName = '';
if(in_array($result['transactionType'], [0,3])){
	$docName = L_INVOICE;
}else if($result['transactionType'] == 5){
	$docName = L_RECEIPT;
}

$jsonSale = [
				"outletName" 						=> $outlet['outletName'],
				"outletAddress"						=> $outlet['outletAddress'],
				"outletPhone"						=> $outlet['outletPhone'],
				"companyName" 						=> $array['companyName'],
				"company_billing_name" 				=> $setting['settingBillingName'],
				"companyTIN" 						=> $setting['settingRUC'],
				"companyAddress" 					=> $setting['settingAddress'],
				"companyEmail" 						=> $setting['settingEmail'],
				"companyPhone" 						=> $setting['settingPhone'],
				"total" 							=> $array['total'],
				"rawtotal" 							=> $array['totalRaw'],
				"subtotal" 							=> $array['subtotal'],
				"discount" 							=> $array['discount'],
				"tax" 								=> $array['tax'],
				"customerName" 						=> $contact['contactName'],
				"customerFullName" 					=> $contact['contactSecondName'],
				"customerTIN" 						=> $contact['contactTIN'],
				"customerCI" 						=> $contact['contactCI'],
				"customerAddress" 					=> $contact['contactAddress'],
				"customerEmail" 					=> $contact['contactEmail'],
				"customerPhone" 					=> $contact['contactPhone'],
				"customerCity" 						=> $contact['contactCity'],
				"customerLocation" 					=> $contact['contactLocation'],
				"note" 								=> $result['transactionNote'],
				"date" 								=> date(L_DATETIME_FORMAT,strtotime($array['date'])),
				"dueDate" 							=> date(L_DATE_FORMAT,strtotime($array['expires'])),
				"type" 								=> $result['transactionType'],
				"typeDocument" 						=> $docName,
				"authExpiration" 					=> date(L_DATE_FORMAT,strtotime($register['registerInvoiceAuthExpiration'])),
				"invoiceAuthNo" 					=> $register['registerInvoiceAuth'],
				"invoicePrefix" 					=> $register['registerInvoicePrefix'],
				"invoiceSufix" 						=> $result['invoiceSufix'],
				"invoiceNo" 						=> leadingZeros($result['invoiceNo'], $register['registerDocsLeadingZeros']),
				"sale" 								=> $array['sale']
			];

?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Factura <?=COMPANY_NAME;?></title>  	
</head>
<body>
	<p id="content">
		
	</p>

	<script type="text/javascript">
		var noSessionCheck, isMobile = {phone : false};
	</script>
	
	<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
	
	<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.3.2/html2canvas.min.js"></script>
	<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.4.0/jspdf.umd.min.js"></script>

	<script type="text/javascript" src="https://panel.encom.app/scripts/written-number.min.js"></script>
	<script type="text/javascript" src="https://panel.encom.app/scripts/common.js"></script>
	<script type="text/javascript" src="https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/assets/scripts/documentPrintBuilder.source.js?<?=rand()?>"></script>
	<script type="text/javascript" src="https://panel.encom.app/scripts/rb.min.js"></script>

	<script type="text/javascript">
		var saleArray 				= <?=json_encode($jsonSale);?>,
			template 						= <?=$_template['template']?>,
			resultAction 				= "<?php echo (validateHttp('secret') == 'iwfyita') ? 'print' : 'send'; ?>",
			fileName 						= "<?=COMPANY_NAME . ' ' . TODAY?>";
			window.jsPDF 				= window.jspdf.jsPDF;

	</script>
	
	<script type="text/javascript">

		(function() {
			
				var receiptConf 					= {};

		    receiptConf.isTicket  		= false;
		    receiptConf.isHTML    		= false;
		    receiptConf.chars     		= 0;
		    receiptConf.space     		= ' ';
		    receiptConf.EOL       		= '<br>';
		    var result 			  				= '<div style="font-family:' + 
		    															template.page_font_family + 
		    															'!important;font-size:' + 
		    															template.page_font_size + 
		    															'!important;letter-spacing:.3px;">' + 
		    															ducumentPrintBuilder.build(template.data,saleArray,receiptConf) +
		    														'</div>';

	    	var html      		  			= '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> @page{size:auto;margin:0;padding:0;border:0;}*{padding: 0; margin: 0;border:0;font-family:' + template.page_font_family + '!important;font-size:' + template.page_font_size + '!important; color: black;}</style></head><body>' + result + '</body></html>';
				var head 									= '<meta charset="utf-8"> <style type="text/css" media="print"> @page{size:auto;margin:0;padding:0;border:0;}*{padding: 0; margin: 0;border:0;font-family:' + template.page_font_family + '!important;font-size:' + template.page_font_size + '!important; color: black;}</style>';


		   //if(resultAction == 'print'){
		    $('body #content').html(result);
				$('head').html(head);

				html2canvas($('body')[0]).then(canvas => {	
					var doc 		= new jsPDF("p", "mm", "a4"),
					imgData 		= canvas.toDataURL('image/png', wid = canvas.width, hgt = canvas.height);
					var hratio 	= hgt / wid;

					var width 	= doc.internal.pageSize.width;    
					var height 	= width * hratio;

					doc.addImage(imgData, 'PNG', -3, -3, width, height);
					doc.save(fileName + '.pdf');
				});

				$('#content').hide();
				
		    /*}else{

			    $("<form>")
		        .attr("action", '<?=$baseUrl?>' + '&make=1&page=' + template.page_size)
		        .attr("method", "post")
		        .append(
		            $("<input>")
		                .attr("type", "hidden")
		                .attr("name", "data")
		                .attr("value", html)
		        )
		        .appendTo("body")
		        .submit()
		        .remove();
		    }*/

		})();

	</script>
	
</body>
</html>