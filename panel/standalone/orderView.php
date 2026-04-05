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

date_default_timezone_set(TIMEZONE);

$result 		= ncmExecute('SELECT * FROM transaction WHERE transactionType = 12 AND transactionId = ? AND companyId = ? LIMIT 1',[TRANS_ID,COMPANY_ID]);

$outlet 		= ncmExecute('SELECT * FROM outlet WHERE outletId = ? AND companyId = ?',[$result['outletId'],COMPANY_ID]);
define('WHATSAPP', $outlet['outletWhatsApp']);

if(!in_array($result['transactionStatus'], [1,2,3,4,5])){
	if(PERSON == 'driver'){
		header('location: https://encom.app');
		dai();
	}
	//se finalizo la orden le llevo al form de calificacion
	$url = 'https://public.encom.app/feedback?s=' . base64_encode( implode(',', [enc(COMPANY_ID), enc($result['outletId']), enc($result['customerId']), enc($result['transactionId'])] ));
	header('location: ' . $url);
	dai();
}

if(validateHttp('action') == 'finish'){
	$options['records'] = ['transactionStatus' => 4];
	$options['table'] 	= 'transaction';
	$options['where'] 	= 'transactionId = ' . $result['transactionId'] . ' AND companyId = ' . COMPANY_ID;
	ncmUpdate($options);//records (arr), table (str), where (str)

	dai();
}

//type of order
$orderType 			= isDeliveryOrPickup(json_decode($result['tags'],true));

$contact 			= ncmExecute('SELECT * FROM contact WHERE contactUID = ? AND companyId = ? LIMIT 1',[$result['customerId'],COMPANY_ID]);
$customerName 		= iftn($contact['contactSecondName'],$contact['contactName']);
$customerRuc 		= iftn($contact['contactTIN'],'');
$address 			= ncmExecute('SELECT * FROM customerAddress WHERE customerId = ? AND customerAddressDefault > 0 AND companyId = ? LIMIT 1',[$result['customerId'],COMPANY_ID]);

$feed 			= base64_encode(enc(COMPANY_ID) . ',' . enc($result['outletId']) . ',' . enc($result['customerId']) . ',' . enc(TRANS_ID));
$feedbackUrl 	= 'https://public.encom.app/feedback?s=' . $feed;

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

$mapTheme 	= validateHttp('isdarkmode') ? 'dark' : 'light';

$z 			= 0;
$length 	= counts($array);
$tr 		= '';
$label 		= 'bg-light';

if($result['transactionStatus'] == '1'){
	$title = 'Sin confirmar';
}else if($result['transactionStatus'] == '2'){
	$title = 'En espera';
}else if($result['transactionStatus'] == '3'){
	$title = 'En proceso';
}else if($result['transactionStatus'] == '4'){
	$title = 'Entregado';
}else if($result['transactionStatus'] == '5'){
	if($orderType == 'delivery'){
		$title = 'En camino';
	}else if($orderType == 'pickup'){
		$title = 'Listo para retirar';
	}else{
		$title = 'Listo';
	}		
}else{
	$title = $result['transactionStatus'];
}

function progressBar($type){
	$bg1 = 'bg-light dk animated infinite speed-2x';
	$bg2 = 'bg-light dk animated infinite speed-2x';
	$bg3 = 'bg-light dk animated infinite speed-2x';
	$bg4 = 'bg-light dk animated infinite speed-2x';
	
	if($type == '1'){
		$bg1 .= ' pulse';
	}else if ($type == '2') {
		$bg1 = 'bg-success';
		$bg2 .= ' pulse';
	}else if ($type == '3') {
		$bg1 = 'bg-success';
		$bg2 = 'bg-success';
		$bg3 .= ' pulse';
	}else if ($type == '4') {
		$bg1 = 'bg-success';
		$bg2 = 'bg-success';
		$bg3 = 'bg-success';
		$bg4 = 'bg-success';
	}else if ($type == '5') {
		$bg1 = 'bg-success';
		$bg2 = 'bg-success';
		$bg3 = 'bg-success';
		$bg4 .= ' pulse';
	}else if ($type == '6') {}

	return ['bg1' => $bg1, 'bg2' => $bg2, 'bg3' => $bg3, 'bg4' => $bg4];
}

$progressBar = progressBar($result['transactionStatus']);

?>

<?php
	if(!validateHttp('fromapp')){
?>
<!DOCTYPE html>
<html class="no-js <?=(validateHttp('darkmode') ? 'bg-dark dk' : '')?>">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0" />
  <title>Orden de <?=COMPANY_NAME;?></title>
  <meta property="og:title" content="Orden de <?=COMPANY_NAME;?>" />
  <meta property="og:image" content="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" />
 
  <?php
  loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css'],'css');
  ?>

  <style type="text/css">
  	.svg {
		cursor: pointer;
		filter: invert(.3) sepia(1) saturate(1) hue-rotate(175deg);
	}
	.thinH{
        height: 6px;
    }
  </style>
</head>
<body class="<?=(validateHttp('darkmode') ? 'bg-dark dk' : 'bg-light lter')?> col-xs-12 no-padder noscroll">

<?php
	}
?>

	<div class="col-xs-12 no-padder hidden-print hidden showOnDone" id="iMap" style="position:fixed;z-index:1;">
		<iframe width="100%" height="0" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src=""></iframe>
	</div>
	
	<div class="col-md-4 col-md-offset-4 col-sm-6 col-sm-offset-3 col-xs-12 no-bg no-padder text-center momentumit" style="top:0;position:absolute;z-index:2;">
		
		<div class="col-xs-12 no-padder m-t hidden showOnDone">
			<a href="<?=validateHttp('src') ? validateHttp('src') : '#'?>">
				<img height="40" width="40" src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle m-r m-b animated bounceIn" id="logo" style="display:none;" />
				<span class="h3 m-t-xs m-b-md font-bold"><?=COMPANY_NAME;?></span>
			</a>
			<div class="h4 font-bold visible-print">

				<div class="text-muted font-normal text-sm m-b">
					<?php
					echo 'Orden Nro. ' . $result['invoiceNo'] . '<br>';
					?>
					<?='Fecha: ' . nicedate($result['transactionDate'],true);?>
					<br>
				</div>
				
			</div>
			<div class="col-xs-12 spacer hidden-print"></div>
		</div>
		
		<div class="panel no-border col-xs-12 no-padder r-24x md-whiteframe-16dp clear animated fadeInUp speed-2x hidden showOnDone">
			
			<div class="col-xs-12 wrapper-xs m-t-sm" style="background: url(https://assets.encom.app/images/modal_handle_dk.png) no-repeat center top"></div>

			<div class="col-xs-12 wrapper-md text-left hidden-print">
                <div class="col-xs-12 clear no-padder">

                    <span class="col-xs-12 no-padder">
                        <span class="h3 font-bold text-dark"><?=$title?></span>
                        <span class="h3 text-muted font-bold pull-right">#<?=$result['invoiceNo']?></span> 
                    </span>

                    <span class="col-xs-12 no-padder m-t">
                        <span class="col-xs-2 wrap-l-n wrap-r">
                            <span class="block <?=$progressBar['bg1']?> rounded thinH"></span>
                        </span>
                        <span class="col-xs-4 wrap-l-n wrap-r">
                            <span class="block <?=$progressBar['bg2']?> rounded thinH"></span>
                        </span>
                        <span class="col-xs-4 wrap-l-n wrap-r">
                            <span class="block <?=$progressBar['bg3']?> rounded thinH"></span>
                        </span>
                        <span class="col-xs-2 no-padder">
                            <span class="block <?=$progressBar['bg4']?> rounded thinH"></span>
                        </span>
                    </span>
                    
                </div>
                <?php
				if($_modules['feedback'] > 0 && $result['transactionStatus'] == '4'){
				?>
				<div class="col-xs-12 no-padder m-t-md m-b-n-md hidden-print bg-white">
					<div class="col-xs-12 text-center wrapper bg r-24x">
						<div>Califique su experiencia</div>
						<a href="<?=$feedbackUrl;?>"><img src="https://panel.encom.app/images/facesgroup.png" height="80"></a>
					</div>
				</div>
				<?php
				}
				?>
            </div>
            <div class="col-xs-12 no-padder" id="details">
				<div class="col-xs-12 m-t-md">
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

					
					<div class="col-xs-12 no-padder">
						<div class="text-left font-bold m-b text-lg text-dark">
							Detalle
						</div>
						<?php
						$tr = '';
						foreach($array['sale'] as $key => $val){
							if($val['name'] != 'Descuento' && $val['name'] != 'Discount'){
								$name 	= unXss(getItemName(dec($val['itemId'])));
								$count 	= unXss($val['count']);
								$total 	= formatCurrentNumber($val['total']);

								if($val['type'] == 'inCombo' || $val['type'] == 'inComboAddons'){
									$name 	= str_replace('u21b3','\u21b3',$name);
									$name  	= '<span class="text-muted">' . $name . '</span>';
									$count = '';
									$total = '';
								}else if($val['type'] == 'combo'){
									
								}

								$tr .= 	'<tr>' .
										'	<td class="text-right">' . $count . '</td>' .
										'	<td>' . $name . '</td>' .
										'	<td class="text-right">' . $total . '</td>' .
										'</tr>';
							}
						}
						?>
						<table class="table text-left font-bold" id="saleDetails">
							<?=$tr;?>
							
							<tr class="text-dark">
								<td colspan="3" class="text-center font-bold text-u-c">
									<div class="text-center text-u-c text-sm">
										TOTAL
									</div>
									<div class="h2 font-bold"><?=$array['total']?></div>
								</td>
							</tr>
						</table>
					</div>

					<div class="col-xs-12 text-center hidden-print m-b">
						<a href="#" id="print" class="">Imprimir</a>
					</div>

					<div class="col-xs-12 no-padder hidden-print">
						<div class="badge m-b"><?=nicedate($array['date'],true);?></div>
					</div>

					<div class="col-xs-12 no-padder hidden-print">
						<?=companySocialSites($setting['settingSocialMedia'],WHATSAPP);?>
					</div>
				
				</div>
			</div>



			<?php
			if(!validateHttp('fromapp')){
			?>

			<div class="m-b-lg col-xs-12 m-t m-b-md animated bounceIn" style="display: none" id="encom">
			   <a href="https://encom.app?utm_source=ENCOM_online_receipt&utm_medium=ENCOM_footer_icon&
	utm_campaign=<?=COMPANY_NAME?>" class="block hidden-print">
			    	<span class="text-muted">Usamos</span> <br>
			    	<img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
			    </a>
			    <div class="visible-print font-bold">
			    	<span>Usamos</span> <br>
			      	<div>WWW.ENCOM.APP</div>
			    </div>
			</div>

			<?php
				}
			?>
			
        </div>
		

		


	</div>

	<?php
		if(!validateHttp('fromapp')){
	?>

	<script type="text/javascript">
	  var noSessionCheck  = true;
	  window.standAlone   = true;
	</script>

	<?php
	loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js','https://cdnjs.cloudflare.com/ajax/libs/jquery-touch-events/2.0.3/jquery.mobile-events.min.js'],'js');
	?>

	<?php
		}
	?>

	<script type="text/javascript">

		var setMap = function(height){
			var colorMode 	= ncmUI.setDarkMode.isSet ? 'dark' : 'light';
			var mapHeight 	= $(window).height();
			var spaceHeight	= height - 60;
			<?php
			$oLat = false;
			$oLng = false;

			if($outlet['outletLatLng']){
				$oLat 	= explode(',',$outlet['outletLatLng'])[0];
				$oLng 	= explode(',',$outlet['outletLatLng'])[1];
			}
			
			if($orderType == 'pickup' && $oLat){
			?>
				var oLat = <?=$oLat;?>;
				var oLng = <?=$oLng;?>;
				
				var height 		= iftn(height,200);
			
				var url 		= 'https://public.encom.app/mapIframe?height=' + mapHeight + '&lat=<?=$oLat?>&lng=<?=$oLng?>&theme=' + colorMode + '&zoom=15&blockZoom=1&padding=20&key=0VM_W3i3Uqi5E9P57uSsA_Q2-06swWpChj24kCv9WJ8&icon=store';

				<?php
				if($_GET['ulat'] && $_GET['ulng']){
				?>
					var driverLat 	= <?=$_GET['ulat']?>;
			        var driverLng 	= <?=$_GET['ulng']?>;
					var extraPins 	= [{ 
		            					lat : driverLat, 
		            					lng : driverLng, 
		            					icon : 'person', 
		            					trace : {
		            							fromLat : driverLat, 
		            							fromLng : driverLng,
		            							toLat 	: oLat,
		            							toLng 	: oLng
		            						}
		            					}];

		            url 			= url + '&extras=' + btoa(JSON.stringify(extraPins));
			            $('#iMap iframe').attr('src',url);

			            var distance = ncmHelpers.getDistanceInKM('<?=$oLat?>','<?=$oLng?>',driverLat,driverLng);
			            $('.distance').text(distance + ' Km');
				<?php
				}else{
					?>
					ncmHelpers.getUserLocation(function(position){
			        	var driverLat 	= position.coords.latitude;
			            var driverLng 	= position.coords.longitude;
			            var extraPins 	= [{ 
			            					lat : driverLat, 
			            					lng : driverLng, 
			            					icon : 'person', 
			            					trace : {
			            							fromLat : driverLat, 
			            							fromLng : driverLng,
			            							toLat 	: oLat,
			            							toLng 	: oLng
			            						}
			            					}];

			            url 			= url + '&extras=' + btoa(JSON.stringify(extraPins));
			            $('#iMap iframe').attr('src',url);

			            var distance = ncmHelpers.getDistanceInKM('<?=$oLat?>','<?=$oLng?>',driverLat,driverLng);
			            $('.distance').text(distance + ' Km');
			        });

					<?php
				}
				?>

		        $('#iMap iframe').attr('src',url).height(mapHeight);
		        $('.spacer').css('height',spaceHeight + 'px');
		        $('.showOnDone').removeClass('hidden');

			<?php
			}else{
			?>
				var cLat = '<?=$lat;?>';
				var cLng = '<?=$lng;?>';
				var oLat = '<?=$oLat;?>';
				var oLng = '<?=$oLng;?>';

				if(oLat && oLng && cLat && cLng){

					var url 		= 'https://public.encom.app/mapIframe?height=' + mapHeight + '&lat=' + cLat + '&lng=' + cLng + '&theme=' + colorMode + '&zoom=15&blockZoom=1&padding=20&key=0VM_W3i3Uqi5E9P57uSsA_Q2-06swWpChj24kCv9WJ8&icon=person';

		            var extraPins 	= [{
		            					lat : oLat, 
		            					lng : oLng, 
		            					icon : 'store',
		            					trace : {
		            							fromLat : oLat, 
		            							fromLng : oLng,
		            							toLat 	: cLat,
		            							toLng 	: cLng
		            						}
		            				}];

		            url 			= url + '&extras=' + btoa(JSON.stringify(extraPins));
		        }else if(oLat && oLng){
		        	var url 		= 'https://public.encom.app/mapIframe?height=' + mapHeight + '&lat=' + oLat + '&lng=' + oLng + '&theme=' + colorMode + '&zoom=15&blockZoom=1&padding=20&key=0VM_W3i3Uqi5E9P57uSsA_Q2-06swWpChj24kCv9WJ8&icon=store';
		        }

				$('#iMap iframe').attr('src',url).height(mapHeight);
				$('.spacer').css('height',spaceHeight + 'px');
		        $('.showOnDone').removeClass('hidden');
				<?php
			}
			?>
		}

		$(document).ready(function(){
			var wH 		= $(window).height();
			var wHrm 	= 120;
			var mapH 	= wH - wHrm;

			<?php
			 	if(validateHttp('darkmode')){
			 		?>
			 		ncmUI.setDarkMode.setDark();
			 		<?php
			 	}else{
			 		?>
			 		ncmUI.setDarkMode.auto();
			 		<?php
			 	}
			?>
			
			$('#logo').show();
			setTimeout(function(){
				$('#list').show();
			},100);
			setTimeout(function(){
				$('#loyalty,#companyData,#encom').show();
			},650);

			ncmHelpers.onClickWrap('#print',function(){
		        window.print();
		    });

		    setMap(mapH);

		    <?php
		    if(PERSON != 'driver'){
		    ?>
			    setInterval(function() {
	            	window.location.reload();
	            }, 600000); //10 mins
            <?php
        	}
            ?>

            if(isMobile.phone){
	            var bannerH = $(window).height();
				$(document).off('scroll').on('scroll',function(){
				    var scroll 		= $(this).scrollTop();
				    if(scroll > 0){
				    	var pad = '-' + (scroll * 0.4);
				    	$('#iMap').css({'top' : pad + 'px'});
				    }					    
				});
			}



            /*var autoScrolled 	= false;
			var scrollBoundary 	= 60;

			if(!isMobile.any){

				$(window).off('scroll').on('scroll',function() {
				    var windowTop = $(window).scrollTop();

				    if (!autoScrolled && windowTop > scrollBoundary) {

				        autoScrolled 	= true;
				        var scrollSpace = $('.spacer').height() + 60;
				        $('html,body').animate({scrollTop: scrollSpace});

				    } else if(autoScrolled && windowTop <= scrollBoundary) {
				         autoScrolled = false;
				    }
				});

			}else{
				$('html,body').on('tap', function(e) { 
					var scrollSpace = $('.spacer').height() + 60;
				    $('html,body').animate({scrollTop: scrollSpace});
				});
			}*/

            
		    
		});
	</script>


</body>
</html>

<?php
dai();
?>