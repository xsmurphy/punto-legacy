<?php
include_once('sa_head.php');
include_once('../libraries/enLetras.class.php');

$data = explodes(',', base64_decode($_GET['s']));

define('TRANSACTION_ID', dec($data[0])); 
define('COMPANY_ID', dec($data[1]));

if(!TRANSACTION_ID || !COMPANY_ID){
	dai();
}

$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID],true);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
$apiKey = getAPICreds(COMPANY_ID);
define('API_KEY', $apiKey);

date_default_timezone_set(TIMEZONE);

define('TODAY', date('Y-m-d H:i:s'));

$companyId 		= $setting['companyId'];
$companyName 	= $setting['settingName'];
$companyPhone 	= $setting['settingPhone'];
$companyAddress = $setting['settingAddress'] . ' ' . $setting['settingCity'] . ', ' . $setting['settingCountry'];
$companyRuc 	= $setting['settingRUC'];
$companyEmail 	= $setting['settingEmail'];
$companyRazon 	= $setting['settingBillingName'];
$URLShare 		= $setting['settingWebSite'];
$taxName 		= $setting['settingTaxName'];
$CURRENCY 		= $setting['settingCurrency'];

$result 		= ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND transactionType = 9 AND companyId = ? LIMIT 1',[TRANSACTION_ID,COMPANY_ID]);

if(!$result){
	include_once('/home/encom/public_html/panel/includes/404.inc.php');
	dai();
}

$details 		= json_decode(($result['transactionDetails']),true);
$status 		= $result['transactionStatus'];
$contactData 	= getContactData($result['customerId'],'uid',true);

$customerName 	= iftn($contactData['secondName'],$contactData['name']);
$cCompName 		= '';

if($contactData['secondName']){
	$cCompName 		= $contactData['name'];
}

$customerCI 	= $contactData['ci'] ? $contactData['ci'] : $contactData['ruc'];

$userName 		= ncmExecute('SELECT contactName FROM contact WHERE contactId = ? AND companyId = ? LIMIT 1',[$result['userId'],COMPANY_ID],true);
$userName 		= $userName['contactName'];

$outletData 	= ncmExecute('SELECT * FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1',[$result['outletId'],COMPANY_ID]);
$outletName 	= $outletData['outletName'];
$outletAddress 	= $outletData['outletAddress'] ? $outletData['outletAddress'] : $companyAddress;

if($result['transactionCurrency']){
	$CURRENCY = $result['transactionCurrency'];
}

if(validateHttp('action') == 'approve'){
	$db->Execute('UPDATE transaction SET transactionStatus = 2 WHERE transactionId = ? AND companyId = ?',[TRANSACTION_ID,COMPANY_ID]);

	$ops = [
            "title"     => "Cotización Aprobada",
	        "message"   => $customerName . " aprobó la cotización #" . $result['invoiceNo'],
	        "type"      => 1,
	        "register"  => 1,
	        "company"   => COMPANY_ID,
	        "push"      => [
	                        "tags" => [
	                        			[
	                                        "key"   => "outletId",
	                                        "value" => enc($result['outletId'])
	                                    ],
	                                    [
	                                        "key"   => "isResource",
	                                        "value" => "false"
	                                    ]],
	                        "where"     => 'caja'
	                        ]


          ];

    insertNotifications($ops);

	dai();
}

$_modules = ncmExecute('SELECT * FROM company WHERE companyId = ?',[COMPANY_ID]);

$array = [		'total'     => formatCurrentNumber($result['transactionTotal'] - $result['transactionDiscount']),
				'totalRaw'  => ($result['transactionTotal'] - $result['transactionDiscount']),
				'subtotal' 	=> formatCurrentNumber($result['transactionTotal']),
				'discount' 	=> formatCurrentNumber($result['transactionDiscount']),
				'discountNo'=> $result['transactionDiscount'],
				'tax' 		=> formatCurrentNumber($result['transactionTax']),
				'companyId'	=> enc($result['companyId']),
				'outletId' 	=> enc($result['outletId']),
				'transactionId'=> enc($result['transactionId']),
				'date' 		=> $result['transactionDate'],
				'expires' 	=> ($result['transactionDueDate'])?$result['transactionDueDate']:$result['transactionDate'],
				'taxName' 	=> $taxName,
				'number' 	=> $result['invoiceNo'],
				'companyName'=> $companyName,
				'outlet' 	=> $outletName,
				'note' 		=> $result['transactionNote'],
				'sale' 		=> $details
		];


?>
<!DOCTYPE html>
<html class="bg-light lt">
<head>
	<!-- meta -->
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
	<title><?=$customerName?> - Cotización de <?=$companyName?> </title>
	<meta property="og:title" content="<?=$companyName;?> Cotización" />
  	<meta property="og:image" content="/assets/100-100/0/<?=enc(COMPANY_ID)?>.jpg" />

	<?php
	loadCDNFiles([],'css');
	?>
</head>
<body>

	<section class="col-lg-8 col-lg-offset-2 col-xs-12 no-padder">
		
		<?php
		if($result){
		?>
		<div class="col-xs-12 text-left wrapper">
			<div class="col-sm-8 col-xs-12 wrapper-md m-b-n">
				<img src="/assets/100-100/0/<?=enc(COMPANY_ID)?>.jpg" height="80" class="m-r m-b rounded"><span class="h1 font-bold">Cotización</span>
			</div>

			<div class="col-sm-4 col-xs-12 text-right m-b">
				<div class="wrapper-md hidden-xs"></div>
				<?php
				if($status == 1){
				?>
				<a href="#" class="btn btn-lg btn-info btn-rounded text-u-c font-bold hidden-print m-r" id="confirm">Aprobar Cotización</a>
				<div id="confirmed" class="text-success h3 font-bold m-t" style="display:none;">Aprobado</div>
				<?php
				}else if($status == 2){
				?>
				<div class="text-success h3 font-bold m-t">Aprobado</div>
				<?php
				}else if($status == 5){
				?>
				<div class="text-danger h3 font-bold m-t">Rechazado</div>
				<?php
				}
				?>
			</div>

			<div class="col-xs-12 wrapper m-t-n">
				<div class="col-sm-8 col-xs-12 m-b">
					<div class="h3 col-xs-12 no-padder">
						<?=$customerName?>
					</div>
					<div class="col-xs-12 no-padder m-b"><em><?=$customerCI;?></em></div>
					<div class="col-xs-12 no-padder">
						<strong>Nro.</strong> <?=$array['number']?>
					</div>
					<div class="col-xs-12 no-padder">
						<strong>Emitido</strong>  <?=niceDate($array['date'])?>
					</div>
					<div class="col-xs-12 no-padder">
						<strong>Vencimiento</strong> <?=niceDate($array['expires'])?>
					</div>
					<div class="col-xs-12 no-padder">
						<strong>Por</strong> <?=$userName;?>
					</div>
				</div>
				<div class="col-sm-4 col-xs-12 text-right m-b">
					<div class="font-bold h4 m-b-xs"><?=$companyName;?> <span class="font-normal text-muted">(<?=$outletName?>)</span></div>
					<div><?=$outletAddress;?></div>
					<div><?=$companyEmail;?></div>
					<div><?=$companyPhone;?></div>
				</div>
			</div>

			<div class="col-xs-12  wrapper m-t-n panel r-24x table-responsive">
				<div class="hidden">
					<?php 
						print_r($details);
					?>
				</div>
				<table class="table col-xs-12 no-padder m-b">
					<thead class="text-u-c">
						<tr>
							<th class="text-center">
								Cant.
							</th>
							<th class="hidden-xs hidden-sm hidden-md">
								Imagen
							</th>
							<th class="hidden-xs hidden-sm hidden-md">
								Código
							</th>
							<th>
								Detalle
							</th>
							<th class="text-center">
								Precio Uni.
							</th>
							<th class="text-center">
								Total
							</th>
						</tr>
					</thead>
					<tbody>
					<?php
					if(validity($details)){
						foreach ($details as $key => $value) {

							if($value['type'] == 'discount'){
								continue;
							}

							$itm = getItemData(dec($value['itemId']));
					?>
					<tr>
						<td class="text-right">
							<?=$value['count'];?>
						</td>
						<td class="hidden-xs hidden-sm hidden-md">
							<img src="/assets/60-60/0/<?=enc(COMPANY_ID) . '_' . $value['itemId'] ?>.jpg" height="40">
						</td>
						<td class="hidden-xs hidden-sm hidden-md">
							<?=$value['sku'];?>
						</td>
						<td>
							<strong><?=$itm['itemName'];?></strong>
							<div>
								<?php
								$note = str_replace(['u00a0','n-', 'u2022'], [' ',' -', '&nbsp;&nbsp;•&nbsp; '], $value['note']);
								?>
								<?=markupt2HTML(['type' => 'MtH', 'text' => $note ]);?>
							</div>
						</td>
						<td class="text-right">
							<?=formatCurrentNumber($value['price']);?>
						</td>
						<td class="text-right">
							<strong><?=formatCurrentNumber($value['total']);?></strong>
						</td>
					</tr>
					<?php

						}
					}
					?>
					</tbody>
					<tfoot>
						<tr class="font-bold text-right">
							<td colspan="2"></td>
							<td class="hidden-xs hidden-sm hidden-md"></td>
							<td class="hidden-xs hidden-sm hidden-md"></td>
						
							<td>
								Subtotal
							</td>
							<td>
								<?=$array['subtotal']?>
							</td>
						</tr>
						<?php
						if($array['discountNo'] > 0){
						?>
						<tr class="font-bold text-right">
							<td colspan="2"></td>
							<td class="hidden-xs hidden-sm hidden-md"></td>
							<td class="hidden-xs hidden-sm hidden-md"></td>
						
							<td>
								Descuentos
							</td>
							<td>
								<?=$array['discount']?>
							</td>
						</tr>
						<?php
						}
						?>

						<tr class="">
							<td colspan="4">
								<div class="text-u-c">
									<?php
									$V 			= new EnLetras();
									$totalRaw 	= (DECIMAL == 'no') ? (int)$array['totalRaw'] : (float)$array['totalRaw'];
	 								echo '<div class="text-xs font-bold">Son</div> ' . $CURRENCY . ' ' . $V->ValorEnLetras($totalRaw,"") . '.-';
									?>
								</div>
							</td>
						
							<td class="h3 font-bold text-right text-dark">
								Total
							</td>
							<td class="h3 font-bold text-right text-dark">
								<?=$array['total']?>
							</td>
						</tr>						
					</tfoot>
				</table>

				<div class="col-xs-12 wrapper text-md m-b">
					<div class="wrapper r-3x bg-light lter">
						<?php
							$saleNote = toUTF8(isBase64Decode($array['note']));
							echo markupt2HTML(['type' => 'MtH', 'text' => $saleNote ]);
						?>
						<?//toUTF8( isBase64Decode($array['note']) )?>
					</div>
				</div>

				<div class="col-xs-12 wrapper text-center m-t-md visible-print">
					<div class="col-sm-3 col-sm-offset-9 b-t b-black">
						<div class="m-t-sm font-bold">Firma de conformidad</div>
					</div>
				</div>

				<?php
				if($_modules['dropbox'] && 1 == 2){
					$data   = '{"path" : "/transactions/' . $array['transactionId'] . '","include_deleted" : true,"recursive" : true}';

				  	$header =   [
					                "Accept: application/json",
					                "Authorization: Bearer " . $_modules['dropboxToken'],
					                "Content-Type: application/json"
					            ];

				   //$files = json_decode( curlContents('https://api.dropboxapi.com/2/files/list_folder','POST',$data,$header), true );

				   if(!$files['error_summary']){
				   	//	echo '<table class="table">';
				   		foreach ($files['entries'] as $key => $value) {
				   			
				   		}
				   	//	echo '</table>';
				   }

				   //print_r($files);
				}
				?>

				<div class="text-center m-b-md no-border panel r-24x col-xs-12 table-responsive" id="DBFiles"></div>
			</div>

			<div class="text-center wrapper col-xs-12">
				<div class="visible-print font-bold text-sm m-b-xs">
			      	<div>Usamos WWW.ENCOM.APP</div>
			    </div>
				<img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&amp;data=/screens/quoteView?s=<?=$_GET['s']?>" width="80">
			</div>

			<div class="col-xs-12 text-center">
				<a href="#" class="btn btn-info font-bold rounded hidden-print text-u-c" id="print">Imprimir o Guardar PDF</a>
			</div>
			
			<div class="col-xs-12 text-center">
				<div class="m-t m-b-lg" id="encom">
				   	<a href="/?utm_source=ENCOM_online_receipt&utm_medium=ENCOM_footer_icon&
		utm_campaign=<?=COMPANY_NAME?>" class="m-t-md block hidden-print">
				      <span class="text-muted">Usamos</span> <br>
				      <img src="/images/incomeLogoLgGray.png" width="80">
				    </a>
				    
				</div>
			</div>
		</div>
		<?php
		}else{
		?>
			<div class="col-xs-12 wrapper-lg text-center">
				<h2>Esta cotización no existe</h2>
			</div>
		<?php
		}	
		?> 
		<script type="text/javascript">
		  var noSessionCheck  = true;
		  window.standAlone   = true;
		</script>
		<?php
		footerInjector();
		loadCDNFiles(['/assets/scripts/ncmDropbox.min.js'],'js');
		?>	
	</section>

	<script type="text/javascript">
		$(document).ready(function(){
			<?php
			if($_modules['dropbox'] && 1 == 2){
			?>
			var opts = {
						  "loadEl" : '#DBFiles',
						  "listEl" : '#DBFiles',
						  "readOnly": true,
						  "token"  : '<?=$_modules['dropboxToken']?>',
						  'folder' : '/transactions/<?=$array['transactionId']?>'
						};

			ncmDropbox(opts);
			<?php
			}
			?>

			$('#print').click(function (e) {
				e.preventDefault();
				window.print();
			});

			$('#confirm').click(function (e) {
				e.preventDefault();
				$(this).hide();
				$.get('?action=approve&s=<?=$_GET['s']?>');
				$('#confirmed').show();
			});
		});
	</script>
</body>
</html>

<?php
dai();
?>