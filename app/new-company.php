<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type');

include_once("libraries/hashid.php");
include_once("libraries/countries.php");
include_once("includes/db.php");
include_once("includes/simple.config.php");
include_once("includes/functions.php");
require_once('/home/incomepos/public_html/panel/includes/emailtemplates.php');
require_once('libraries/phpmailer/PHPMailerAutoload.php');

// This if statement checks to determine whether the registration form has been submitted
// If it has, then the registration code is run, otherwise the form is displayed
if(!empty($_POST)){

	if(empty($_POST['storename']) || empty($_POST['password']) || empty($_POST['email']) || $_POST['category'] == 'false' || $_POST['country'] == 'false'){
		die('{"Error":" Todos los campos son requeridos"}');
	}
	if($_POST['password'] != $_POST['password_confirm']){
		die('{"Error":" Las contraseñas no son iguales"}');
	}
	if(!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)){
		die('{"Error":" La dirección de correo es inválida"}');
	}

	$sign = signUp($_POST,false);

	if($sign == 'true'){
		//sendEmail($_POST['email'],'Su registro en Income Register',str_replace('<%user_email%>',$_POST['email'],$userregistertemplate),$userregistertemplatealt);

		$options = json_encode(array(
							"to"=> array($_POST['email']),
							"sub"=> array(":email"=>array($_POST['email'])),
							"filters"=> array(
												"templates"=>array(
																	"settings"=>array("enable"=>1,"template_id"=>"e8e6642d-2174-4444-b9f7-a9372b26de0c")
																)
											)


							));
		sendSMTPEmail($options,$_POST['email'],'Su registro en Income Register','Income','Income');

		$nueva = 'Nombre: '.$_POST['storename'].'\n';
		$nueva .= 'Email: '.$_POST['email'].'\n';
		$nueva .= 'Pais: '.$_POST['country'].'\n';
		$nueva .= 'Categoria: '.$_POST['category'].'\n';
		$nueva .= 'Desde: App \n';
		sendEmail('drahgster+ncm.newcompany@gmail.com','Nueva empresa registrada',$nueva,$nueva,'info@incomepos.com',false);
	}

	echo $sign;
	
	die();
}
    
?>