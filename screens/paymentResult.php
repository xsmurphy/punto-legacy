<!DOCTYPE html>
<html class="no-js">

<head>
	<!-- meta -->
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
	<title><?= APP_NAME ?></title>
	<meta property="og:title" content=APP_NAME />
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Source+Sans+Pro:wght@300;400;900&display=swap" type="text/css" />
	<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons" type="text/css" />
	<link rel="stylesheet" href="/assets/vendor/css/bootstrap-3.4.1.min.css" type="text/css" />
	<link rel="stylesheet" href="/css/font.css" type="text/css" />
	<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons" type="text/css" />
	<link rel="stylesheet" href="/css/app.css" type="text/css" />
	<link rel="stylesheet" href="/css/style.css" type="text/css" />
	<link rel="manifest" href="/manifest.json" />
</head>

<body class="bg-light lter col-xs-12 no-padder">
	<section class="vbox" id="content">

		<div class="wrapper text-center col-md-4 col-md-offset-4 col-sm-6 col-sm-offset-3 col-xs-12">
			<div class="animated fadeInUp speed-3x r-24x md-whiteframe-16dp col-xs-12 no-padder clear" style="" id="list">
				<div class="panel <?= ($STATUS == 'APPROVED') ? 'bg-success' : 'bg-danger' ?> col-xs-12 wrapper m-n" id="approved" style="min-height:300px;">
					<?php
					if ($STATUS == 'APPROVED') {
					?>
						<div class="col-xs-12 no-padder text-center">
							<div class="text-xs text-white">Listo</div>
							<div class="h1 font-bold text-white m-b-md">Pago Realizado</div>
							<div class="table-responsive">
								<table class="table table-bordered" style="font-size: 18px; color:white ;font-weight: bold;text-align: left; border-color:white; ">
									<tr>
										<td class="col" style=" padding-top:0px">Nº Operación :</td>
										<td class="col" style="padding-top:0px"><?= $UID?></td>
									</tr>
									<tr>
										<td class="col">Cliente :</td>
										<td class="col"><?= $CUSTOMER_NAME ?></td>
									</tr>
									<tr>
										<td class="col">Nº documento :</td>
										<td class="col"><?= $CUSTOMER_RUC?></td>
									</tr>
									<tr>
										<td class="col">Monto :</td>
										<td class="col"><?= formatCurrentNumber($AMOUNT)?></td>
									</tr>
									<tr>
										<td class="col">Moneda :</td>
										<td class="col"><?= $currency ?></td>
									</tr>
								</table>
							</div>


						</div>
					<?php
					} else {
					?>
						<div class="col-xs-12 no-padder text-center">
							<div class="text-xs text-white">Pago</div>
							<div class="h1 font-bold text-white m-b-md">Rechazado</div>
						</div>
					<?php
					}
					?>

				</div>
			</div>

			<div class="m-b-md m-t-lg col-xs-12 animated bounceIn" id="encom">
				<a href="/?utm_source=saas_online_receipt&utm_medium=saas_footer_icon&utm_campaign=Roquetas" class="block">
					<span class="text-muted">Usamos</span> <br>
					<img src="/images/incomeLogoLgGray.png" width="80">
				</a>
				<br>
				<div class="visible-print font-bold">
					<span>Usamos</span> <br>
					<div><?= APP_URL ?></div>
				</div>
			</div>

		</div>

	</section>
</body>

</html>