<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('TRANSACTION_ID', dec($data[0])); 
define('COMPANY_ID', dec($data[1]));

if(!TRANSACTION_ID || !COMPANY_ID){
	dai();
}

$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID],true);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
 

date_default_timezone_set(TIMEZONE);

define('TODAY', date('Y-m-d H:i:s'));

$companyId 		= $setting['companyId'];
$companyName 	= $setting['settingName'];
$companyPhone 	= $setting['settingPhone'];
$companyAddress = $setting['settingAddress'].' '.$setting['settingCity'].', '.$setting['settingCountry'];
$companyRuc 	= $setting['settingRUC'];
$companyEmail 	= $setting['settingEmail'];
$companyRazon 	= $setting['settingBillingName'];
$URLShare 		= $setting['settingWebSite'];
$taxName 		= $setting['settingTaxName'];
$currency 		= $setting['settingCurrency'];

$result 		= ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[TRANSACTION_ID,COMPANY_ID]);

$details 		= json_decode(stripslashes($result['transactionDetails']),true);

$status 		= $result['transactionStatus'];

$customerName 	= getValue('contact', 'contactName', 'WHERE contactUID = '.$result['customerId']);
$customerRuc 	= getValue('contact', 'contactTIN', 'WHERE contactUID = '.$result['customerId']);

if(validateHttp('action') == 'approve'){
	$db->Execute('UPDATE transaction SET transactionStatus = 2 WHERE transactionId = ? AND companyId = ?',[TRANSACTION_ID,COMPANY_ID]);
	$ops = [
            "title"     => "Cotización Aprobada",
            "message"   => $customerName . " aprobó la cotización #" . $result['invoiceNo'],
            "type"      => 1,
            "company"   => COMPANY_ID
          ];
    insertNotifications($ops);
	dai();
}

$array = [		'total'     =>formatCurrentNumber($result['transactionTotal']-$result['transactionDiscount']),
				'subtotal' 	=>formatCurrentNumber($result['transactionTotal']),
				'discount' 	=>formatCurrentNumber($result['transactionDiscount']),
				'tax' 		=>formatCurrentNumber($result['transactionTax']),
				'companyId'	=>enc($result['companyId']),
				'outletId' 	=>enc($result['outletId']),
				'transactionId'=>enc($result['transactionId']),
				'date' 		=>$result['transactionDate'],
				'expires' 	=>($result['transactionDueDate'])?$result['transactionDueDate']:$result['transactionDate'],
				'taxName' 	=>$taxName,
				'number' 	=> $result['invoiceNo'],
				'companyName'=>$companyName,
				'outlet' 	=>$outletName,
				'note' 		=> $result['transactionNote'],
				'sale' 		=>$details
		];
?>
<!DOCTYPE html>
<html class="bg-light lt">
<head>
	<!-- meta -->
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
	<title><?=$companyName?> Cotización</title>
	<meta property="og:title" content="Comprobante de <?=$companyName;?>" />
  	<meta property="og:image" content="https://assets.encom.app/100-100/0/<?=enc(COMPANY_ID)?>.jpg" />

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
			<div class="col-sm-8 col-xs-12 wrapper-md">
				<img src="https://assets.encom.app/100-100/0/<?=enc(COMPANY_ID)?>.jpg" height="80" class="m-r m-b rounded"><span class="h1 font-bold">Cotización</span>
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

			<div class="col-xs-12 wrapper">
				<div class="col-sm-8 col-xs-12 m-b">
					<div class="h3 m-b col-xs-12 no-padder">
						<?=$customerName?>
					</div>
					<div class="col-xs-12 no-padder">
						<strong>Nro.</strong> <?=$array['number']?>
					</div>
					<div class="col-xs-12 no-padder">
						<strong>Emitido</strong>  <?=niceDate($array['date'])?>
					</div>
					<div class="col-xs-12 no-padder">
						<strong>Vencimiento</strong> <?=niceDate($array['expires'])?>
					</div>
				</div>
				<div class="col-sm-4 col-xs-12 text-right m-b">
					<div class="font-bold h4"><?=$companyName;?></div>
					<div><?=$companyAddress;?></div>
					<div><?=$companyEmail;?></div>
					<div><?=$companyPhone;?></div>
				</div>
			</div>

			<div class="col-xs-12  wrapper m-t panel r-24x table-responsive">
				<table class="table col-xs-12 no-padder">
					<thead class="text-u-c">
						<tr>
							<th class="text-center">
								Cant.
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
							$itm = getItemData(dec($value['itemId']));
					?>
					<tr>
						<td class="text-right">
							<?=$value['count'];?>
						</td>
						<td>
							<strong><?=$itm['itemName'];?></strong>
							<div><?=toUTF8( isBase64Decode($value['note'] ));?></div>
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
						
							<td>
								Subtotal
							</td>
							<td>
								<?=$array['subtotal']?>
							</td>
						</tr>
						<?php
						if($array['discount']){
						?>
						<tr class="font-bold text-right">
							<td colspan="2"></td>
						
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

						<tr class="font-bold h3 text-right text-dark">
							<td colspan="2"></td>
						
							<td class="">
								Total
							</td>
							<td class="">
								<?=$array['total']?>
							</td>
						</tr>
						<tr>
							<td colspan="4" class="text-md">
								<div class="wrapper r-3x bg-light lter">
									<?=toUTF8( isBase64Decode($array['note']) )?>
								</div>
							</td>
						</tr>
					</tfoot>
				</table>
			</div>

			<div class="col-xs-12 text-center">
				<a href="#" class="btn btn-info btn-lg font-bold rounded hidden-print text-u-c" id="print">Imprimir o Guardar PDF</a>
			</div>
			
			<div class="col-xs-12 text-center">
				<div class="m-t m-b-lg" id="encom">
				   	<a href="https://encom.app?utm_source=ENCOM_online_receipt&utm_medium=ENCOM_footer_icon&
		utm_campaign=<?=COMPANY_NAME?>" class="m-t-md block hidden-print">
				      <span class="text-muted">Usamos</span> <br>
				      <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
				    </a>
				    <div class="visible-print font-bold">
				    	<span>Usamos</span> <br>
				      	<div>WWW.ENCOM.APP</div>
				    </div>
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
		loadCDNFiles([],'js');
		?>	
	</section>

	<script type="text/javascript">
		$(document).ready(function(){
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