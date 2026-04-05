<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define(COMPANY_ID, dec($data[0]));
define(OUTLET_ID, dec($data[1]));

$settings = $db->Execute("SELECT * FROM setting WHERE companyId = ?",array(COMPANY_ID));
$timezone = $settings->fields['settingTimeZone'];

date_default_timezone_set($timezone);

$obj = $db->Execute("SELECT outletName FROM outlet WHERE outletId = ?",array(OUTLET_ID));
$outletName = $obj->fields['outletName'];

if(isset($_GET['level'])){
	$record = array();

	$record['satisfactionLevel'] 		= $_GET['level'];
  $record['satisfactionDate']     = date('Y-m-d H:i:s');
  $record['outletId'] 			      = OUTLET_ID;
  $record['companyId'] 		        = COMPANY_ID;

  $insert = $db->AutoExecute('satisfaction', $record, 'INSERT'); 

  if($insert === false){
    echo 'false';
  }else{
    echo 'true';
  }
  die();
}


?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Roquetas CLiente</title>

  <!-- core styles -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css">
  <link rel="stylesheet" href="css/simple-line-icons.css" type="text/css">
  <link rel="stylesheet" href="css/font.css" type="text/css">
  <link rel="stylesheet" href="css/app.css" type="text/css">  
  <link rel="stylesheet" href="css/style.css" type="text/css">  
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/3.5.2/animate.min.css" type="text/css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.45/css/bootstrap-datetimepicker.min.css" type="text/css">
  <!-- /core styles -->
  <script type="text/javascript" src="https://code.jquery.com/jquery-3.1.1.min.js"></script>
</head>
<body class="bg-white">
<div class="col-xs-12 wrapper-lg m-t-lg hidden-xs"></div>
<div class="col-lg-2 col-md-1 no-padder hidden-xs"></div>

<div class="col-lg-8 col-md-10 no-padder text-center bg-white all-shadows r-3x" id="" style="min-height:550px;">

  <div class="col-sm-4 wrapper text-center text-white hidden-xs r-3x" style="height:550px; background-attachment:scroll,scroll;background-color:transparent;background-position:top left,center center;background-repeat:repeat,no-repeat;background-size:auto,cover;background-image: url(https://app.incomepos.com/images/overlay.png), url(https://assets.incomepos.com/appbackgrounds/2.2.jpg);">
    <div class="m-t-lg wrapper-lg col-xs-12"></div>
    <a href="#" class="thumb-lg m-t-lg">
      <img src="//assets.incomepos.com/150-150/0/J9.jpg" class="img-circle" id="companyImg">
    </a>
    <div class="h2 font-thin">Roquetas</div>
    <div>
      Av. Amigos No. 123
      <br>
      + 234 234 243
      <br>
      info@site.com
      <br>
      www.site.com
    </div>
  </div>

  <div class="col-xs-12 wrapper text-center text-white visible-xs" style="height:background-attachment:scroll,scroll;background-color:transparent;background-position:top left,center center;background-repeat:repeat,no-repeat;background-size:auto,cover;background-image: url(https://app.incomepos.com/images/overlay.png), url(https://assets.incomepos.com/appbackgrounds/2.2.jpg);">
    <div class="no-padder col-xs-12"></div>
    <a href="#" class="thumb-lg">
      <img src="//assets.incomepos.com/150-150/0/J9.jpg" class="img-circle" id="companyImg">
    </a>
    <div class="h2 font-thin">Roquetas</div>
  </div>

  <div class="col-sm-8 col-xs-12 wrapper" id="">
    <div class="text-left font-thin no-padder">
      <div class="font-thin h1">Completa tu perfíl</div>
    </div>
    <div class="text-left no-border">
      <form role="form" id="loginForm" method="post" action="">
        <div class="panel-body no-border no-padder">
          <div class="col-xs-12 no-padder m-t-md">
            <input type="text" class="form-control input-lg no-border no-bg b-b b-light text-center font-thin" placeholder="Nombre o Razón Social" id="crname" name="name" value="" style="font-size:25px; height:55px;">
          </div>
          <div class="col-xs-6 wrapper">
            <label class="text-xs">Email</label>
            <input type="text" name="email" class="form-control m-b-md input-lg no-border no-bg b-b b-light" placeholder="yo@misitio.com" value="">
            <label class="text-xs">Teléfono</label>
            <input type="text" name="phone" class="form-control m-b-md input-lg no-border no-bg b-b b-light" placeholder="### ### ###" value="">
            <label class="text-xs">Fecha de cumpleaños</label>
            <input type="text" name="birthday" class="form-control m-b-md input-lg no-border no-bg b-b b-light datepicker" placeholder="###/##/##" value="">
          </div>

          <div class="col-xs-6 wrapper">
            <label class="text-xs">RUC</label>
            <input type="text" name="tin" class="form-control m-b-md input-lg no-border no-bg b-b b-light" placeholder="### ###" value="">
            <label class="text-xs">Dirección</label>
            <input type="text" name="address" class="form-control m-b-md input-lg no-border no-bg b-b b-light" placeholder="Dirección de su casa u oficina" value="">
          </div>
        </div>
        <div class="text-center">
          <button class="btn btn-info btn-rounded btn-lg all-shadows m-t" type="submit" id="btn-login">Guardar Perfíl</button>
        </div>
      </form>
    </div>
  </div>
</div>
<div class="col-lg-4 col-md-1 no-padder hidden-xs"></div>
<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>

<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/fastclick/1.0.6/fastclick.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.19.1/moment.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.45/js/bootstrap-datetimepicker.min.js"></script>
<script>

    $(document).ready(function(){
      FastClick.attach(document.body);
      $('.datepicker').datetimepicker({
        format: 'YYYY-MM-DD'
      });
      
    });
  </script>

</body>
</html>
<?php
$db->Close();
?>
