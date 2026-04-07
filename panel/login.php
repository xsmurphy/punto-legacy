<?php
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("includes/functions.php");
include_once("libraries/countries.php");

/*if ($_SERVER["SERVER_PORT"] != 443) {
    $redir = "Location: https://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'];
    header($redir);
    exit();
}*/

session_start();

// Limpiar sesiones con objetos no serializables (e.g. CaseInsensitiveArray de versión anterior)
if (isset($_SESSION['user']['companySettings']) && !is_array($_SESSION['user']['companySettings'])) {
	session_unset();
}

define('OUTLETS_COUNT', 0);

$redirect 		= $_GET['ref'];

if(isset($_SESSION['user'])){
	header('Location:/@#dashboard');
}

if(validateHttp('phpinfo')){

	echo dec('55Q9B');

	//phpinfo();

	die();
}

if(validateHttp('token')){

	$decoded 	= ncmDecode( validateHttp('token') );
	$decodedX 	= explodes('[@]',$decoded);
	$usrId 		= dec($decodedX[0]);
	$usrPs 		= $decodedX[1];

	if(!validity($usrId) || !validity($usrPs)){
		header('Location: /login');
		dai('false');
	}

	$result = ncmExecute('SELECT * FROM contact WHERE contactId = ? AND type = 0 AND contactPassword = ? AND role IN(1,2,7) LIMIT 1',[$usrId,$usrPs]);

	$login_ok = false;
	if($result){
		$login_ok = true;
	}else{
		header('Location: /login');
		dai('false');
	}
	
	// If the user logged in successfully, then we send them to the private members-only page
	// Otherwise, we display a login failed message and show the login form again
	if($login_ok){
		if(loginPart($result) == 'true'){
			header('Location: /@');
		}
	}
	
	header('Location: /login');
	dai();
}

if(validateHttp('recovery')){
	$result = findEmailOrPhoneLogin( validateHttp('email','post') );

	if($result){
		$newPass 			= random_password();
		list($pass,$salt) 	= passEncoder($newPass);

		$record	 					= [];
		$record['contactPassword'] 	= $pass;
		$record['salt']           	= $salt;

		$update = ncmUpdate(['records' => $record, 'table' => 'contact', 'where' => 'contactId = ' . $result['contactId']]);

		if($update !== false){
			
			if(validity($result['contactEmail'],'email')){
				$meta['subject'] = '[ENCOM] Su nueva contraseña';
				$meta['to']      = $result['contactEmail'];
				$meta['fromName']= APP_NAME;
				$meta['data']    = [
				                    "message"     => 'Su nueva contraseña es <strong>' . $newPass . '</strong>, una vez que haya ingresado a su cuenta vuelva a cambiarla ingresando a Contactos > Usuarios',
				                    "companyname" => APP_NAME,
				                    "companylogo" => '/assets/150-150/0/' . enc(ENCOM_COMPANY_ID) . '.jpg'
				                	];

				$sent = sendEmails($meta);
			}else if( validity($result['contactPhone']) ){

				$companyData = ncmExecute( 'SELECT settingCountry FROM company WHERE companyId = ? LIMIT 1',[$result['contactPhone']] );
				$sent = sendSMS($result['contactPhone'],'[ENCOM] Su nueva contraseña es ' . $newPass,$companyData['settingCountry'],100,16);
			}

			echo 'true';
			
		}else{
			echo 'false';
		}

		dai();
	}else{
		dai('No existe un usuario con ese (email / nro. de celular) o no posee permisos para ingresar al panel');
	}
}

// This if statement checks to determine whether the login form has been submitted
// If it has, then the login code is run, otherwise the form is displayed
if(validateHttp('login')){
	$email 	= validateHttp('email','post');
	$pass 	= validateHttp('password','post');
 
	$result = findEmailOrPhoneLogin($email);
	
	// This variable tells us whether the user has successfully logged in or not.
	// We initialize it to false, assuming they have not.
	// If we determine that they have entered the right details, then we switch it to true.
	$login_ok = false;
   
	// Retrieve the user data from the database.  If $row is false, then the username
	// they entered is not registered.
	
	if($result){

		// rtrim contactPassword: PostgreSQL CHAR(68) pads with spaces, SHA-256 hashes never end in spaces
		if(checkForPassword($pass, $result['salt']) === rtrim($result['contactPassword'])){
			$login_ok = true;
		}

	}else{
		dai('Usuario o contraseña incorrectos o no posee permisos para ingresar al Panel');
	}
	
	// If the user logged in successfully, then we send them to the private members-only page
	// Otherwise, we display a login failed message and show the login form again
	if($login_ok){
		echo loginPart($result);
	}else{
		echo "Usuario o contraseña incorrectos";
	}
	dai();
}

$tips = [
			[
				'title' 	=> 'Organiza tus listados',
				'message' 	=> 'Oculta líneas en tus listados de reportes para trabajar solo con datos relevantes. '
			]
		];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Ingresar</title>
	<meta name="viewport" content="user-scalable=no, initial-scale=1, minimum-scale=1, width=device-width" />
	<meta property="og:title" content="ENCOM - Panel de Control" />
  	<meta property="og:image" content="/images/iconincomesmw.png" />
   <?php
	loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css'],'css');
	?>

	<style type="text/css">
		#msg {
			bottom 		: 10px;
		    position 	: absolute;
		    z-index 	: 0;
		    max-width 	: 650px;
		    left 		: 0;
		    right 		: 0;
		    margin 		: 0 auto;
		    overflow 	: hidden;
		}
	</style>
 
</head>
<body class="bg-white">

	

	<div class="col-md-7 col-sm-5 col-xs-12 no-padder bg-dark gradBgBlack animateBg text-center hidden-xs" style="height: 100vh;">
		<img src="/images/incomelogo.png" class="m-t" height="30" style="margin-top: 43vh;">
		<div class="text-center text-white font-bold text-u-c m-t-xs">Tu negocio en tus manos</div>
	</div>


	<div class="col-md-5 col-sm-7 col-xs-12 bg-white wrapper-lg" id="login" style="height: 100vh;">
		<div class="col-md-10 col-md-offset-1 col-xs-12 no-padder">
			<div class="col-xs-12 no-padder bg-white r-3x" id="loginBlock" style="margin-top: 23vh;">
				<div class="text-center m-b-lg">
					<img src="/images/incomeLogoLgDark.png" height="30">
				</div>

				<h5 class="padder font-bold text-u-c text-center m-b-md hidden">Bienvenido al panel de control</h5>	
				
				<form role="form" id="loginForm" method="post" action="?login=true">
					<div class="col-xs-12 no-padder panel-body no-border">
						<label class="block font-bold text-u-c text-xs">Celular o eMail</label>
						<div class="col-xs-12 no-padder m-b-md">

		                    <div class="col-xs-3 no-padder animated fadeInLeft speedUpAnimation loginEmailCountryCodes"> 
		                      <button type="button" class="btn btn-default dropdown-toggle btn-lg no-border no-bg countriesBtn" data-toggle="dropdown">
		                        +595
		                      </button>
		                      <ul class="dropdown-menu signInCountriesList" style="max-height:180px;overflow: scroll;">
		                      </ul>
		                    </div>
		                    <div class="col-xs-9 no-padder emailWrap">
		                      <input  name="email" type="text" class="form-control input-lg no-border no-bg b-b loginEmail" placeholder="Nro de celular o e-mail" value="<?=validateHttp('email')?>" required>
		                    </div>
		                    
		                </div>

		                <label class="block font-bold text-u-c text-xs">Contraseña</label>
	                    <input type="password" name="password" id="password" class="form-control m-b-md input-lg no-border no-bg b-b b-light" placeholder="******" value="" required style="letter-spacing: 5px;">
					</div>
					<div class="col-xs-12 no-padder text-center m-b">
						<button class="btn btn-info btn-rounded btn-lg btn-block text-u-c font-bold m-b-md disabled" type="submit" id="btn-login" disabled="disabled">Cargando..</button>

						<div class="text-right text-u-c font-bold">
	                        <a href="#" id="recoverybtn">¿Olvidó su contraseña?</a>
	                    </div>
						
						<?php
						if(validateHttp('from') != 'app'){
						?>
						<div class="m-t text-md">
							¿No tiene una cuenta en ENCOM?
							<a href="" class="text-u-l m-t">Regístrate</a>
						</div>
						<?php
						}
						?>

					</div>

				</form>
			</div>

			<div class="col-xs-12 wrapper bg-white r-3x" id="recover" style="display:none; margin-top: 25vh;">
				<span class="arrow top"></span>
				<div class="text-center m-t">
					<h4 class="padder font-bold text-u-c text-muted">Recupere su contraseña</h4>
				</div>
				
				<div class="text-left no-border">
					<form role="form" id="recoverForm" method="post" action="?recovery=true">
						<div class="col-xs-12 panel-body no-border">

							<div class="text-xs font-bold text-u-c">Nro de celular o e-mail</div>
							<div class="col-xs-12 no-padder m-b">

			                    <div class="col-xs-3 no-padder animated fadeInLeft speedUpAnimation loginEmailCountryCodes"> 
			                      <button type="button" class="btn btn-default dropdown-toggle btn-lg no-border no-bg countriesBtn" data-toggle="dropdown">
			                        +595
			                      </button>
			                      <ul class="dropdown-menu signInCountriesList" style="max-height:180px;overflow: scroll;">
			                      </ul>
			                    </div>
			                    <div class="col-xs-9 no-padder emailWrap">
			                      <input  name="recoverEmail" type="text" class="form-control input-lg no-border no-bg b-b recoveryEmail" placeholder="Nro de celular o e-mail" required>
			                    </div>
			                    
			                </div>
						</div>
						<div class="col-xs-12 text-center wrapper">
							<button class="btn btn-info btn-rounded btn-lg text-u-c font-bold all-shadows" type="submit" id="btn-recover">Recuperar</button>
						</div>
					</form>
				</div>
			</div>

			<div class="col-xs-12 no-padder visible-xs">
				<div class="wrapper bg-light dk text-center m-t-lg">
					Al ingresar usted acepta nuestros <a href="/assets/terminos.pdf" target="_blank"><span class="text-info">Términos y Condiciones</span></a>
				</div>
			</div>

			
		</div>
	</div>

	

	<div class="col-md-5 col-sm-7 col-md-offset-7 col-sm-offset-5 col-xs-12 hidden-xs">
		<div class="wrapper bg-light dk text-center" id="msg">
			Al ingresar usted acepta nuestros <a href="/assets/terminos.pdf" target="_blank"><span class="text-info">Términos y Condiciones</span></a>
		</div>
	</div>

	<script type="text/javascript">
	  var noSessionCheck  = true;
	  window.standAlone   = true;
	</script>
	<?php
	loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js'],'js');
	?>
	 
	<script type="text/javascript">
    	var countries = <?=json_encode($countriesHispanic);?>
  	</script>
	<script>
		 if(document.location.href.indexOf('/login') < 0 && document.location.hostname !== 'localhost') {
	        document.location.href = '/login';
	    }

		$(document).ready(function(){
			ncmUI.setDarkMode.autoSelected();
			
			FastClick.attach(document.body);
			var ref = false;//'<?=$redirect?>';	

			helpers.loginInputUserManager({
									'load' 			: true
								});

	        $('#loginForm input.loginEmail').on('keyup',function(){
	        	helpers.loginInputUserManager({
									'input'  		: $('#loginForm input.loginEmail'),
									'areacode' 		: $('#loginForm .loginEmailCountryCodes'),
									'inputWrap' 	: $('#loginForm .emailWrap')
								});
	        });

	        $('#recoverForm input.recoveryEmail').on('keyup',function(){
	        	helpers.loginInputUserManager({
									'input'  		: $('#recoverForm input.recoveryEmail'),
									'areacode' 		: $('#recoverForm .loginEmailCountryCodes'),
									'inputWrap' 	: $('#recoverForm .emailWrap')
								});
	        });

			helpers.btnIndicator({
									btn : $('#btn-login'),
									enabledText : 'Ingresar'
								});

			$(document).on('submit','#loginForm',function(e) {

				var emailVal 	= $('#loginForm .loginEmail').val();
				var pCode 		= $('#loginForm .selectedPhoneCode').text();
				pCode 			= pCode ? pCode : '+595';

				var phoneCode 	= ($('#loginForm .loginEmailCountryCodes').is(':visible') && $.isNumeric(emailVal)) ? pCode : '';


				var email 		= phoneCode + emailVal;
				var pass 		= $('#password').val();
				var loginUrl 	= $(this).attr('action');

				console.log('pCode ' + pCode + ' phoneCode ' + phoneCode + ' email ' + email + ' emailVal ' + emailVal);

				helpers.btnIndicator({
										btn 			: $('#btn-login'),
										status 			: 'disable',
										disabledText 	: 'Verificando'
									});

				helpers.load({
								url 	: loginUrl,
								data 	: {'email' : email, 'password' : pass},
								success : function(response) {
											if(response == 'true'){
												helpers.btnIndicator({
																			btn 			: $('#btn-login'),
																			status 			: 'disable',
																			disabledText 	: 'Redireccionando'
																		});
												window.location = (ref)?ref:'/@#dashboard';
												return false;
											}else if(response == 'false'){
												message('Error al intentar procesar su petición','error');
											}else if(response.length > 250){
												window.location = (ref) ? ref : '/@#dashboard';
											}else{
												ncmDialogs.alert(response,'danger');
											}

											helpers.loadIndicator();
											helpers.btnIndicator({
												btn : $('#btn-login'),
												enabledText : 'Ingresar'
											});
										},
								fail 	: function(){
											message('Error al intentar procesar su petición','error');
										}
				});

				return false; // cancel original event to prevent form submitting
			});

			$(document).on('submit','#recoverForm',function(e) {
				var tis 		= $(this);

				helpers.loginInputUserManager({
												'load' 			: true
											});

				var pCode 		= $('#recoverForm .selectedPhoneCode').text();
				pCode 			= pCode ? pCode : '+595';


				var phoneCode 	= ($('#recoverForm .loginEmailCountryCodes').is(':visible') && $.isNumeric(emailVal)) ? pCode : '';
				var email 		= phoneCode + $('#recoverForm .recoveryEmail').val();

				helpers.btnIndicator({
										btn 			: $('#btn-recover'),
										status 			: 'disable',
										disabledText 	: 'Verificando'
									});

				helpers.load({
								url 	: tis.attr('action'),
								data 	: {'email' : email},
								success : function(response) {
											if(response == 'true'){
												$('#loginBlock, #recover').toggle();
												ncmDialogs.alert('Hemos enviado una nueva contraseña a su celular/email');
											}else if(response == 'false'){
												ncmDialogs.alert('Error al intentar procesar su petición');
											}else{
												ncmDialogs.alert(response);
											}
											helpers.loadIndicator();
											helpers.btnIndicator({
												btn : $('#btn-recover'),
												enabledText : 'Ingresar'
											});
										},
								fail 	: function(){
											ncmDialogs.alert('Error al intentar procesar su petición');
										}
				});

				return false; // cancel original event to prevent form submitting
			});

			<?php
			if(validateHttp('recover')){
				echo "$('#loginBlock, #recover').toggle();";
			}

			if(validateHttp('darkMode')){
				echo 'simpleStorage.set("darkMode",true); ncmUI.setDarkMode.autoSelected();';
			}
			?>

			onClickWrap('#recoverybtn',function(){
				helpers.loginInputUserManager({
									'load' 			: true
								});
				
				$('#loginBlock, #recover').toggle();
			});

		});

    </script>
</body>
</html>