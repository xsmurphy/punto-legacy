<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode( validateHttp('s') ));

define('COMPANY_ID', dec($data[0]));
define('USER_ID', dec($data[1]));
define('OUTLETS_COUNT', 0);

$_company 	= ncmExecute("SELECT accountId FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$setting 	= ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
$_modules 	= ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
define('API_KEY', sha1($_company['accountId']) );

if(validateHttp('action') == 'clockIn' && validateHttp('outlet') && validateHttp('token')){
	$data = [
            'api_key'       => API_KEY,
            'company_id'    => enc(COMPANY_ID),
            'outlet'        => validateHttp('outlet'),
            'user'   		=> validateHttp('usr'),
            'token' 		=> validateHttp('token')
          ];

	$result = json_decode(curlContents('https://api.encom.app/set_attendance.php','POST',$data));

	jsonDieResult($result,200);
}

if(validateHttp('action') == 'setPos' && validateHttp('lat') && validateHttp('lng')){
  //records (arr), table (str), where (str)
  $lat = floatval( base64_decode( validateHttp('lat')) );
  $lng = floatval( base64_decode( validateHttp('lng')) );

  $result['records']  = ['contactLatLng' => $lat . ',' . $lng];
  $result['table']    = 'contact';
  $result['where']    = 'contactId = ' . USER_ID . ' AND companyId = ' . COMPANY_ID;

  $response = ncmUpdate($result);
  //updateLastTimeEdit(COMPANY_ID,'order');

  if($response['error']){
    dai('false'); 
  }else{
    dai('true');
  }
}

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('TAX_NAME', $setting['settingTaxName']);
define('TIN_NAME', $setting['settingTIN']);
define('COMPANY_NAME', $setting['settingName']);

date_default_timezone_set(TIMEZONE);

$result 		= ncmExecute('SELECT * FROM contact WHERE contactId = ? AND type = 0 AND contactStatus = 1 AND companyId = ? LIMIT 1',[USER_ID,COMPANY_ID]);

if(!$result){
	dai();
}

?>

	
	<div class="wrapper text-center col-md-6 col-md-offset-3 col-sm-6 col-sm-offset-3 col-xs-12">

		<div class="col-xs-12 text-left no-padder">
	      <a href="#" class="btn pull-left btn-md m-r m-t-xs"></a>
	      <img src="https://assets.encom.app/80-80/0/<?=enc(COMPANY_ID)?>.jpg" width="40" class="img-circle m-r m-b"> 
	      <span class="h3 m-t-xs m-b-md font-bold"><?=COMPANY_NAME?></span>
	    </div>

		<div class="r-24x md-whiteframe-16dp col-xs-12 no-padder clear" id="list">
			
			<div class="panel no-border col-xs-12 no-padder m-n text-left">
				<div class="col-xs-12 wrapper font-bold h3 text-left text-dark bg-light lter no-border">
					<span class="text-muted text-md block text-u-c">
						Hola
						<a href="#" class="clickeable pull-right" data-type="darkMode">
							<i class="material-icons">brightness_medium</i>
						</a>
					</span> 
					<?=$result['contactName']?>
				</div>

				<ul class="list-group list-group-lg no-bg auto no-border col-xs-12 no-padder">

					<a href="https://public.encom.app/userOrders?s=<?=base64_encode( enc(COMPANY_ID) . ',' . enc(USER_ID) )?>" class="list-group-item clearfix no-bg myMenuLoadPage" data-container="#externalSource">
						<span class="pull-left m-t-sm m-r text-md text-info">
							<i class="material-icons md-24">playlist_add_check</i>
						</span>
						<div class="text-lg font-bold text-u-c">Pedidos</div>
						<div class="text-muted">Ver todos mis pedidos asignados</div>
					</a>

					<a href="https://public.encom.app/userDayAgenda?s=<?=base64_encode( enc(COMPANY_ID) . ',' . enc(USER_ID) )?>" class="list-group-item clearfix no-bg myMenuLoadPage myMenuScheduleLink" data-container="#externalSource">
						<span class="pull-left m-t-sm m-r text-md text-info">
							<i class="material-icons md-24">event</i>
						</span>
						<div class="text-lg font-bold text-u-c">Agenda</div>
						<div class="text-muted">Ver mi agenda personal</div>
					</a>

					<a href="#" class="list-group-item clearfix no-bg" id="scanQRAttendance">
						<span class="pull-left m-t-sm m-r text-md text-info">
							<i class="material-icons md-24">qr_code_2</i>
						</span>
						<div class="text-lg font-bold text-u-c">Asistencia</div>
						<div class="text-muted">Escanear QR para marcar ingreso y salida</div>
					</a>

					<a href="https://panel.encom.app/login?token=<?=validateHttp('token')?>&email=<?=$result['contactEmail']?>&from=app" class="list-group-item clearfix no-bg clickeable" data-type="link">
						<span class="pull-left m-t-sm m-r text-md text-info">
							<i class="material-icons md-24">dashboard</i>
						</span>
						<div class="text-lg font-bold text-u-c">Panel de Control</div>
						<div class="text-muted">Ingrese al área administrativa</div>
					</a>

					<a href="#" class="list-group-item clearfix no-bg" id="scanQREnableDevice">
						<span class="pull-left m-t-sm m-r text-md text-info">
							<i class="material-icons md-24">qr_code_2</i>
						</span>
						<div class="text-lg font-bold text-u-c">Activar dispositivo</div>
						<div class="text-muted">Escanear QR para ingresar</div>
					</a>

					<a href="#lock" class="list-group-item clearfix no-bg navigate">
						<span class="pull-left m-t-sm m-r text-md text-info">
							<i class="material-icons md-24">fingerprint</i>
						</span>
						<div class="text-lg font-bold text-u-c">Salir</div>
						<div class="text-muted">Bloquear sesión</div>
					</a>

				</ul>

				<div class="col-xs-12 no-padder m-t font-bold h3 text-left text-dark no-border hidden myMenuMyLocation">
					<div class="text-muted m-l m-b text-md block text-u-c">Mi ubicación actual</div>
					<iframe class="m-b" width="100%" height="200" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src=""></iframe>
				</div>

				
			</div>

		</div>

		<div id="ncmDBMyMenuFiles" class="col-xs-12 wrapper"></div>

	</div>


	<script type="text/javascript">
		var ese 	= '<?=validateHttp('s')?>';

		if(typeof myLat === 'undefined'){
			var myLat 	= 0;
		}

		if(typeof myLng === 'undefined'){
			var myLng 	= 0;
		}

		if(typeof setPosInt !== 'undefined'){
			window.clearInterval(setPosInt);
		}

		

		if(!ncmGlobals.settings[0].calendar){
			$('.myMenuScheduleLink').addClass('disabled').attr('href','#');
			$('.myMenuScheduleLink span.text-info').toggleClass('text-info text-muted');
		}

		var savePosition = function(position,callback){
			myLat = position.coords.latitude;
	        myLng = position.coords.longitude;

	        if(!ncmAuth.activeUser.trackGPS){//si no tiene habilitado el trackeo no guardo su ubicación
	        	return false;
	        }

	        if($('.myMenuMyLocation').hasClass('hidden')){
	        	var ifr = 'https://public.encom.app/mapIframe?height=200&lat=' + myLat + '&lng=' + myLng + '&theme=' + (ncmUIX.isDarkMode ? 'dark' : 'light') + '&zoom=15&key=yVdtZeQfaXQhMKzdbjz5onZGWBSVcLaUEiY9KzVab-Q&icon=person';

	        	//$('.myMenuMyLocation').removeClass('hidden');
	        	//$('.myMenuMyLocation iframe').attr('src',ifr);
	        }

	        let url = 'https://public.encom.app/appMenu?s=' + ese + '&action=setPos&lat=' + btoa(myLat) + '&lng=' + btoa(myLng);

	        ncmHttp.getit(url,callback,function(result){
	        	ncmAlerts.alert({"title":"No pudimos guardar su ubicación","body":result, "type":"danger"});
	        });
		};

		$(document).ready(function(){

			if(navigator.geolocation){
		  		navigator.geolocation.getCurrentPosition(savePosition,function(){
			        ncmAlerts.alert({"title":"Sin conexión GPS","body":"Debe habilitar y permitir el uso de su ubicación", "type":"danger"});
			    });

			    let setPosInt = window.setInterval(function(){
			    	if(!ncmUIX.myMenu.in){
			    		window.clearInterval(setPosInt);
			    	}else{
				    	navigator.geolocation.getCurrentPosition(savePosition,function(){
					        ncmAlerts.alert({"title":"Sin conexión GPS","body":"Debe habilitar y permitir el uso de su ubicación", "type":"danger"});
					    });
				    }
			    },300000);

		  	}else{
		  		ncmAlerts.alert({"title":"Sin conexión GPS","body":"Debe habilitar y permitir el uso de su ubicación", "type":"danger"});
		  	}

			ncmHelpers.onClickWrap('#scanQRAttendance',function(){
				if(!myLat && !myLng){
					ncmAlerts.alert({"title":"Sin conexión GPS","body":"Debe habilitar y permitir el uso de su ubicación", "type":"danger"});
					return false;
				}

				var oCoors 	= ncmTransactions.cOutletData().outletLatLng,oLat,oLng;
				if(oCoors){
					oCoors 	= oCoors.split(',');
					oLat 	= oCoors[0];
					oLng 	= oCoors[1];
				}

				var distance = ncmMaps.getDistanceInKM(oLat,oLng,myLat,myLng);

				//alert('distance: ' + distance);

				if(distance > 0.5){
					ncmAlerts.alert({"title":"Distancia incorrecta","body":"Asegurese de estar cerca del código o reinicie la app", "type":"danger"});
					return false;
				}

				pluginExecute('barcodeScanner',function(){
					ncmHelpers.preloader('show');

					cordova.plugins.barcodeScanner.scan(
						function (result) {
							ncmHelpers.preloader('hide');
							if(result.cancelled == false){
								var find = result.text;

							    if(find && find == ncmTransactions.cOutletData().attendanceToken){
							    	var now = moment().format('HH:mm');
							    	var url = 'https://public.encom.app/appMenu?s=' + ese + '&action=clockIn&outlet=' + ncmTransactions.cOutletData().outletId + '&usr=' + ncmAuth.activeUser.activeUserId + '&token=' + find;
							    	
							    	ncmHttp.getit(url,function(result){
							    		var type = (result.type == 'closed') ? 'salida' : 'ingreso';

							    		if(result.error){
							    			ncmAlerts.alert({"title":"Hubo un error al registrar su " + type, "type":"danger"});
								    	}else{
								    		if(result.type == 'closed'){
								    			var title = "¡Adiós " + ncmAuth.activeUser.name + "!";
								    		}else{
								    			var title = "¡Hola " + ncmAuth.activeUser.name + "!";
								    		}

								    		ncmAlerts.toast(title, "success");
								    	}
							    	},false,false,'json');
							    }else{
							      ncmAlerts.nativeAlert('Código incorrecto','Cerrar');
							    }
							}
						},
						function (error) {
						  	ncmAlerts.nativeAlert('Hubo un error en la lectura','Cerrar');
							ncmHelpers.preloader('hide');
						}
					)
				});

		    });

		    ncmHelpers.onClickWrap('#scanQREnableDevice',function(){
				/*if(!myLat && !myLng){
					ncmAlerts.alert({"title":"Sin conexión GPS","body":"Debe habilitar y permitir el uso de su ubicación", "type":"danger"});
					return false;
				}*/

				pluginExecute('barcodeScanner',function(){
					ncmHelpers.preloader('show');

					cordova.plugins.barcodeScanner.scan(
						function (result) {
							ncmHelpers.preloader('hide');
							if(result.cancelled == false){
								var find = result.text;

							    if(find){
							    	var now 	= moment().format('HH:mm');
							    	var codeUrl = window.masterUrl + 'login?action=2FAQR&code=' + find + '&scan=1&outlet=' + ncmTransactions.cOutletData().outletId + '&company=' + ncmGlobals.settings[0].companyId;

							    	ncmHttp.getit(codeUrl,function(result){
							    		
							    	},false,false,'json');
							    }else{
							     	ncmAlerts.nativeAlert('Código incorrecto','Cerrar');
							    }
							}
						},
						function (error) {
						  	ncmAlerts.nativeAlert('Hubo un error en la lectura','Cerrar');
							ncmHelpers.preloader('hide');
						}
					)
				});

		    });

		    Intercom('update', {
			  hide_default_launcher: true
			});
		    
		});
	</script>
	

<?php
dai();
?>