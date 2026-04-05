<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode( validateHttp('s') ));


define('PERSON', $data[2]);
define('COMPANY_ID', dec($data[1]));
define('TRANS_ID', dec($data[0]));

$setting 	= ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$_modules 	= ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('TAX_NAME', $setting['settingTaxName']);
define('TIN_NAME', $setting['settingTIN']);
define('COMPANY_NAME', $setting['settingName']);

$apiKey = getAPICreds(COMPANY_ID);
define('API_KEY', $apiKey);

date_default_timezone_set(TIMEZONE);

$result 		= ncmExecute('SELECT * FROM transaction WHERE transactionType = 12 AND transactionId = ? AND companyId = ? LIMIT 1',[TRANS_ID,COMPANY_ID]);

$outlet 		= ncmExecute('SELECT * FROM outlet WHERE outletId = ? AND companyId = ?',[$result['outletId'],COMPANY_ID]);
define('WHATSAPP', $outlet['outletWhatsApp']);

if(!in_array($result['transactionStatus'], [1,2,3,5])){
	if(PERSON == 'driver'){
		header('location: https://encom.app');
		dai();
	}
	//se finalizo la orden le llevo al form de calificacion
	$url = PUBLIC_URL . '/feedback?s=' . base64_encode( implode(',', [enc(COMPANY_ID), enc($result['outletId']), enc($result['customerId']), enc($result['transactionId'])] ));
	header('location: ' . $url);
	dai();
}

if(validateHttp('action') == 'finish'){
	$options['records'] = ['transactionStatus' => 4];
	$options['table'] 	= 'transaction';
	$options['where'] 	= 'transactionId = ' . $result['transactionId'] . ' AND companyId = ' . COMPANY_ID;
	ncmUpdate($options);//records (arr), table (str), where (str)
	updateLastTimeEdit(COMPANY_ID,'order');

	$result = ncmExecute('SELECT userId, outletId, registerId, invoiceNo, companyId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[$result['transactionId'], COMPANY_ID]);

	if($result){
		$outletId   = enc($result['outletId']);
		$registerId = enc($result['registerId']);
		$userId 	= enc($result['userId']);
		$orderNo    = $result['invoiceNo'];

		$pushed 	= sendPush([
								"ids" 		=> enc(COMPANY_ID),
	                            "message"   => 'La orden # ' . $orderNo . ' fue entregada', 
	                            "title"     => COMPANY_NAME,
	                            "where"     => 'caja',
	                            "filters"   => [
	                            					[
		                                              "key"   => "outletId",
		                                              "value" => $outletId
		                                          	],
		                                          	[
		                                              "key"   => "isResource",
		                                              "value" => "false"
		                                          	]
		                                        ]
	                          ]);
	}

	dai();
}

//type of order
$orderType 			= isDeliveryOrPickup(json_decode($result['tags'],true));

$contact 			= ncmExecute('SELECT * FROM contact WHERE contactUID = ? AND companyId = ? LIMIT 1',[$result['customerId'],COMPANY_ID]);
$customerName 		= iftn($contact['contactSecondName'],$contact['contactName']);
$customerRuc 		= iftn($contact['contactTIN'],'');
$address 			= ncmExecute('SELECT * FROM customerAddress WHERE customerId = ? AND customerAddressDefault > 0 AND companyId = ? LIMIT 1',[$result['customerId'],COMPANY_ID]);

$array = [	
			'total' 		=> formatCurrentNumber($result['transactionTotal'] - $result['transactionDiscount']),
			'subtotal' 		=> formatCurrentNumber($result['transactionTotal']),
			'discount' 		=> formatCurrentNumber($result['transactionDiscount']),
			'tax' 			=> formatCurrentNumber($result['transactionTax']),
			'companyId' 	=> enc($result['companyId']),
			'outletId' 		=> enc($result['outletId']),
			'transactionId'	=> enc($result['transactionId']),
			'date' 			=> $result['transactionDate'],
			'payment' 		=> $result['transactionPaymentType'],
			'tags' 			=> $result['tags'],
			'expires' 		=> ($result['transactionDueDate']) ? $result['transactionDueDate'] : $result['transactionDate'],
			'taxName' 		=> TAX_NAME,
			'companyName' 	=> COMPANY_NAME,
			'outlet' 		=> $outletName,
			'sale' 			=> json_decode(stripslashes($result['transactionDetails']),true)
		];

$lat = false;
$lng = false;

if($orderType == 'delivery'){
	if($address['customerAddressLat']){
		$lat 	= $address['customerAddressLat'];
		$lng 	= $address['customerAddressLng'];
		$icon 	= 'person';
	}
}else if($orderType == 'pickup'){
	if($outlet['outletLatLng']){
		$lat 	= explode(',',$outlet['outletLatLng'])[0];
		$lng 	= explode(',',$outlet['outletLatLng'])[1];
		$icon 	= 'store';
	}
}

$mapTheme = validateHttp('isdarkmode') ? 'dark' : 'light';

$iMap = (!$lat) ? '' : '<iframe width="100%" height="200" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" id="iMap" src="https://public.encom.app/mapIframe?height=200&lat=' . $lat . '&lng=' . $lng . '&theme=' . $mapTheme . '&zoom=15&key=CNOdLR0O-IxjQLm0jNZl79mpvbH1W59sgVcDP_dufu8&icon=' . $icon . '"></iframe>';


$z 			= 0;
$length 	= counts($array);
$tr 		= '';
$label 		= 'bg-light';
?>

	<div class="wrapper text-center col-md-6 col-md-offset-3 col-sm-6 col-sm-offset-3 col-xs-12 momentumit">

		<div class="col-xs-12 text-left no-padder">
	      <a href="<?= PUBLIC_URL ?>/userOrders?s=<?=validateHttp('ese')?>" class="btn pull-left btn-md myMenuLoadPage m-r m-t-xs" data-container="#externalSource"><i class="material-icons md-24">chevron_left</i></a>

	      <img src="https://assets.encom.app/80-80/0/<?=enc(COMPANY_ID)?>.jpg" width="40" class="img-circle m-r m-b"> 
	      <span class="h3 m-t-xs m-b-md font-bold"><?=COMPANY_NAME?></span>
	    </div>

		<div class="h4 font-bold visible-print">

			<div class="text-muted font-normal text-sm m-b">
				<?php
				echo 'Orden Nro. ' . $result['invoiceNo'] . '<br>';
				?>
				<?='Fecha: ' . nicedate($result['transactionDate'],true);?>
				<br>
			</div>
			
		</div>

		<div class="r-24x md-whiteframe-16dp col-xs-12 no-padder clear">
			
			<div class="panel no-border col-xs-12 no-padder m-n">
				<div class="col-xs-12 wrapper-md text-left hidden-print">
	                <div class="col-xs-12 clear no-padder">

	                    <span class="col-xs-6 no-padder">
	                        <span class="h4 font-bold text-dark">Orden #<?=$result['invoiceNo']?></span>
	                    </span>
	                    <span class="col-xs-6 no-padder text-right">
	                    	<?=printOutTags(json_decode($array['tags'],true),'bg-light dk text-sm')?>
	                    </span>
	                    
	                </div>
	            </div>

				<?=$iMap;?>

				<div class="col-xs-12">
					<?php
					if($address['customerAddressText'] && $orderType == 'delivery'){
					?>

					<div class="col-xs-12 no-padder m-t">
						<div class="text-left">
							Cliente
							<span class="pull-right"><?=TIN_NAME?></span>
						</div>
						<div class="text-left font-bold m-b text-lg text-dark">
							<?=$customerName?> 
							<span class="pull-right"><?=iftn($customerRuc,'')?></span>
						</div>
					</div>

					<div class="col-xs-12 no-padder text-md text-dark text-left">
						<?=unXss($address['customerAddressText']);?>
						<span class="block text-u-c font-bold text-sm"><?=$address['customerAddressLocation']?> <?=$address['customerAddressCity']?></span>
					</div>

					<?php
					}else if($orderType == 'pickup'){
					?>
						<div class="col-xs-12 no-padder text-md text-dark text-left">
							<?=unXss($outlet['outletAddress']);?>
							<span class="block text-u-c font-bold text-sm"><?=$setting['settingCity']?> - <span class="text-muted distance">0 Km</span></span>
						</div>
					<?php
					}
					?>

					<div class="col-xs-12 no-padder b-b b-light m-t-md m-b-md"></div>

					<?php
					
						$hasPhone = '';
						if($contact['contactPhone'] || $contact['contactPhone2']){
							$phone = getValidPhone(iftn($contact['contactPhone'],$contact['contactPhone2']),$setting['settingCountry']);
							$phone = str_replace('+', '', $phone['phone']);
						}else{
							$hasPhone = 'disabled';
						}
					?>
						
						<div class="col-xs-12 no-padder ">
							<a href="<?=($address['customerAddressLat'] ? 'https://www.google.com/maps/dir/?api=1&travelmode=driving&layer=traffic&destination=' . $address['customerAddressLat'] . ',' . $address['customerAddressLng'] : '#')?>" target="_blank" class="btn btn-lg col-xs-4 r-3x hidden-print clickeable" data-type="linkOut" title="Ir a esta dirección" data-toggle="tooltip">
								<i class="material-icons md-36 text-info">alt_route</i>
							</a>

							<a href="tel:<?=$phone?>" target="_blank" class="<?=$hasPhone?> b-l b-r btn btn-lg col-xs-4 r-3x hidden-print clickeable" data-type="linkOut" title="Llamar al cliente" data-toggle="tooltip">
								<i class="material-icons md-36 text-info">call</i>
							</a>

							<a href="https://api.whatsapp.com/send/?phone=<?=$phone?>" target="_blank" class="<?=$hasPhone?> btn text-center btn-lg col-xs-4 r-3x hidden-print clickeable" data-type="linkOut" title="Escribir por WhatsApp" data-toggle="tooltip">
								<img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/whatsapp.svg" style="filter: invert(66%) sepia(81%) saturate(408%) hue-rotate(72deg) brightness(95%) contrast(78%);" width="36">
							</a>
						</div>

						<div class="col-xs-12 no-padder b-b b-light m-t-md m-b-md"></div>

						<div class="col-xs-12 m-b text-left text-dark text-u-c font-bold">
							TOTAL
							<span class="pull-right"><?=$array['total']?></span>							
						</div>

						<div class="col-xs-12 text-center hidden-print m-b">
							<a href="#" id="finish" class="btn btn-lg btn-success btn-rounded text-u-c font-bold">
								<i class="material-icons md-24 m-r">check</i>
								Entregado
							</a>
							<div class="text-center">Presiona esta opción solo si el pedido fue entregado</div>
						</div>
					
					
				</div>
			</div>
		</div>



	</div>



	<script type="text/javascript">

		$(document).ready(function(){

			//ncmEvents.a();
            ncmUIX.setDarkMode();            

			ncmHelpers.onClickWrap('#print',function(){
		        window.print();
		    });

		    ncmHelpers.onClickWrap('#finish',function(e,tis){
		    	ncmAlerts.alert({"title":'¿Seguro que quiere finalizar la entrega?', "type":"warning"},function(a){
		        	if(a){
		        		$.get('<?= PUBLIC_URL ?>/userOrderView?s=<?=validateHttp('s')?>&action=finish',function(){
		        			ncmUIX.myMenu.load();
		        		});
		        	}
		        });
		    });            
		    
		});
	</script>

<?php
dai();
?>