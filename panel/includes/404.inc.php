<?php
require_once(__DIR__ . '/../includes/cors.php');
?>

<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
	<title>404</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css">
	<link rel="stylesheet" href="/css/font.css" type="text/css">
	<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">
	<link rel="stylesheet" href="https://panel.encom.app/css/app.css" type="text/css">
	<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.min.js"></script>
</head>
<body class="bg-light dk">
	<div class="col-xs-12 bg-white text-center" style="padding:0 0 100px 0;top:0;left:0;z-index:2000;position:fixed;height:100vh">
		<div class="col-xs-12 wrapper" style="margin-bottom:8%;">
			<img src="https://app.encom.app/images/iconincomesm.png" width="50">
		</div>
		<div class="col-xs-12 text-center wrapper-lg">
			<img src="https://panel.encom.app/images/emptystate6.png" width="250">
		</div>
		<div class="col-xs-12">
			<h1 class="font-bold" style="font-size: 4em;">Página no encontrada</h1>
			<p class="text-md">Es probable que la URL ingresada sea incorrecta, si tiene dudas por favor contáctenos</p>
			<div class="m-b m-t-md">
				<a href="https://status.encom.app" class="btn btn-md btn-default btn-rounded m-r">Ver el estado de los servicios</a>
				<a href="https://www.encom.app" class="text-u-l">Ir a la página principal</a>
				<a href="#" class="btn btn-md btn-default btn-rounded hidden">Reportar el problema</a>
			</div>
			<br>
			<code>
				<? //$exception?>
			</code>
			
		</div>
	</div>
</body>
</html>