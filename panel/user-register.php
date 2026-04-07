<?php

// First we execute our common code to connection to the database and start the session
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php"); 
include_once("includes/functions.php");
include_once("libraries/countries.php");

// This if statement checks to determine whether the registration form has been submitted
// If it has, then the registration code is run, otherwise the form is displayed

if(validateHttp('signup')){
	// Ensure that the user has entered a non-empty username

	if( !validateHttp('storename','post') || !validateHttp('password','post') || !validateHttp('email','post') || !validateHttp('category','post') || !validateHttp('country','post') || !validateHttp('username','post') ){
		dai('Todos los campos son requeridos');
	}

	$post = db_prepare($_POST);
	
	$sign = signUp($post,true);

	/*$options = json_encode(array(
					"to" 			=> array($_POST['email']),
					"sub" 			=> array(":email"=>array($_POST['email'])),
					"filters" 		=> array(
										"templates"=>array(
															"settings"=>array("enable"=>1,"template_id"=>"e8e6642d-2174-4444-b9f7-a9372b26de0c")
														)
									)
					));
	sendSMTPEmail($options,$_POST['email'],'Su registro en ENCOM',APP_NAME,APP_NAME);*/
	//sendEmail($_POST['email'],'Su registro en Income Register',str_replace('<%user_email%>',$_POST['email'],$userregistertemplate),$userregistertemplatealt);

	if($sign === true){
		
	}

	echo $sign;
	dai();
}

header('location: /');
dai();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Registrarse</title>
	<meta name="viewport" content="user-scalable=no, initial-scale=1, minimum-scale=1, width=device-width" />
   <?php
	loadCDNFiles([
		'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css'
	],'css');
	?>
</head>
<body class="bg-white">

	<div class="col-sm-3"></div>

	<div class="col-sm-6 text-center m-t-lg">
		<img src="/images/incomeLogoLgDark.png" width="120" style="margin-top:10%;"> 
		<h5 class="font-thin padder">Crear Cuenta</h5>
	
		<form action="/user-register?signup=1" method="POST" id="newAccount" name="newAccount" class="text-left m-t-lg">
			<div class="col-sm-12 text-center">
				<label class="text-xs font-bold text-u-c">Nombre de la empresa</label>
				<input type="text" class="form-control m-b no-bg no-border b-b b-light text-center" placeholder="" name="storename"/>
			</div>
			<div class="col-sm-6">
				<label class="text-xs font-bold text-u-c">Email</label>
				<input type="text" class="form-control m-b no-bg no-border b-b b-light" placeholder="" name="email"/>
				<label class="text-xs font-bold text-u-c">Password</label>
				<input type="password" class="form-control m-b no-bg no-border b-b b-light" placeholder=""  name="password"/>
				<label class="text-xs font-bold text-u-c">Confirmar Password</label>
				<input type="password" class="form-control m-b no-bg no-border b-b b-light" placeholder=""  name="password_confirm"/>
			</div>

			<div class="col-sm-6">
				<label class="text-xs font-bold text-u-c">Su Nombre y Apellido</label>
				<input type="text" class="form-control m-b no-bg no-border b-b b-light" placeholder="" name="username"/>
                
                <label class="text-xs font-bold text-u-c">País</label>
                <select name="country" class="form-control m-b no-bg no-border b-b b-light" autocomplete="off">
                    <?php
                    foreach($countriesHispanic as $key => $val){
                        echo '<option value="'.$key.'">'.$val['native'].'</option>';
                    }
                    ?>
                </select>

                <label class="text-xs font-bold text-u-c">Rubro</label>
                <select name="category" class="form-control m-b no-bg no-border b-b b-light" autocomplete="off">
                  <?php
                    foreach($companyCategories as $key => $val){
                        echo '<optgroup label="'.$key.'">';
                        foreach($val as $k => $v){
                          $selected = '';
                          if($result->fields['settingCompanyCategoryId'] == $v){$selected = 'selected';}
                          echo '<option value="'.$v.'" '.$selected.'>'.$k.'</option>';
                        }
                        echo '</optgroup">';
                    }
                    ?>
                </select>
                <input type="hidden" name="parent" value="<?=$_GET['p']?>">
			</div>
			<div class="text-center">
            	<button class="btn btn-info btn-rounded btn-lg text-u-c font-bold user_add m-t all-shadows" type="submit">Crear Empresa</button>
            </div>
            <div class="text-xs text-center m-t-md">Al presionar en Crear Empresa, usted acepta nuestros <a href="https://assets.incomepos.com/terminosycondiciones.pdf" target="_blank">Términos y Condiciones</a></div>
        </form>

		<div class="text-right block m-t-lg">
        	<a href="/login">Ya eres miembro?
			Ingresa aquí</a>
        </div>
	</div>

	<div class="col-sm-3"></div>

	<script>
		var noSessionCheck = true;//avoid autosession check
	</script>
	<?php
	loadCDNFiles([
					'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js',

					],'js');
	?>
	<script>
		$(document).ready(function(){
			//mixpanel.track("Pageview");
			$(document).on('submit','#newAccount',function(e) {
				var tis = $(this);
				spinner('body', 'show');
				$.ajax({
					data: tis.serialize(),
					type: tis.attr('method'),
					url : tis.attr('action'),
					success: function(response) {
						if(response == 'true'){
							window.location = 'login';
						}else if(response == 'false'){
							message('Error al intentar procesar su petición','error');
						}else{
							alert(response);
						}
						spinner('body', 'hide');
					}
				});
				return false;
			});
		});
    </script>

</body>
</html>