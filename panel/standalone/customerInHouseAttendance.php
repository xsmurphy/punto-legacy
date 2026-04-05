<?php
session_start();
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define("COMPANY_ID", dec($data[0]));
define("OUTLET_ID", dec($data[1]));
$TIMES = $data[2];

$setting      = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",array(COMPANY_ID));
$companyData  = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);
define('BALANCE', $companyData['companyBalance']);
define('SMS_CREDIT', $companyData['companySMSCredit']);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('COUNTRY', $setting['settingCountry']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
define('TODAY', date('Y-m-d H:i:s'));
define('TODAY_FROM', date('Y-m-d 00:00:00'));
define('TODAY_TO', date('Y-m-d 23:59:59'));

date_default_timezone_set(TIMEZONE);

$nao      = strtotime("now");
$oneHAgo  = strtotime("-1 hours");
//el timestamp debe ser menor a ahora pero mayor a ahora menos una hora

/*if(!isset($_SESSION['times'])){
  $_SESSION['times'] = $TIMES;
}else{
  $TIMES = $_SESSION['times'];
}*/

if(validateHttp('chk') && (!validateHttp('t') || ( validateHttp('t') < $nao || validateHttp('t') < $oneHAgo ) )){
  

  $out = [  'status'    => "error",
            'message'   => "Vuelva a escanear el código QR",
            'title'     => "Por favor "
          ];

  //header('Content-Type: application/json');
  //dai( json_encode($out) );

}

if(validateHttp('phone')){
  $phone    = str_replace(' ', '', validateHttp('phone'));
  $contact  = ncmExecute('SELECT contactName as name, contactSecondName as secondName, contactUID FROM contact WHERE (contactPhone = ? OR contactPhone2 = ? OR contactCI = ?) AND companyId = ? LIMIT 1',[$phone,$phone,$phone,COMPANY_ID]);

  if($contact){
    $welcome  = welcomeMessage();
    $name     = getCustomerName($contact,'first');
    $expired  = ncmExecute('SELECT transactionId 
                            FROM transaction 
                            WHERE transactionType   = 3 
                            AND transactionComplete = 0
                            AND transactionDueDate  < ?
                            AND customerId          = ? 
                            AND companyId           = ? 
                            ORDER BY transactionDueDate 
                            DESC LIMIT 1',[TODAY,$contact['contactUID'],COMPANY_ID]);
    
    if($expired){
      $out = [  
                'status'  => "error",
                'message' => "Le recordamos que posee cuentas pendientes de pago<br>Por favor pongase en contacto",
                'title'   => $welcome . " " . $name
              ];
    }else{
      $toExpire  = ncmExecute('SELECT transactionDueDate
                                FROM transaction 
                                WHERE transactionType = 3 
                                AND transactionDueDate
                                BETWEEN "' . date('Y-m-d 00:00:00', strtotime('today +5 days')) . '"
                                AND "' . date('Y-m-d 23:59:59', strtotime('today +5 days')) . '"
                                AND transactionComplete   = 0
                                AND customerId            = ? 
                                AND companyId             = ? 
                                ORDER BY transactionDueDate 
                                DESC LIMIT 1',[$contact['contactUID'],COMPANY_ID]);
      $msg       = '';

      if($toExpire){
        $msg       = 'Su próxima factura vence el ' . niceDate($toExpire['transactionDueDate']);
      }

      $out = [  'status'  => "success",
                'message' => $msg,
                'title'   => $welcome . " " . $name
              ];
    }

    $atendance = ncmExecute('SELECT transactionId FROM transaction WHERE customerId = ? AND transactionType = 13 AND transactionStatus IN(0,1) AND fromDate > ? AND toDate < ? AND companyId = ? AND outletId = ? LIMIT 1',[$contact['contactUID'],TODAY_FROM,TODAY_TO,COMPANY_ID,OUTLET_ID]);

    if($atendance){
      //AQUI CAMBIO EL ESTADO A ASISTIO
      $db->AutoExecute('transaction', ['transactionStatus'=>2], 'UPDATE', 'transactionId = ' . $atendance['transactionId'] . ' AND companyId = ' . COMPANY_ID);

      //Inserto asistencia
      $db->AutoExecute('taxonomy', ['taxonomyName'=>$contact['contactUID'],'taxonomyType'=>'customerAssistanceFromWidget','sourceId'=>$atendance['transactionId'],'companyId'=>COMPANY_ID], 'INSERT');

      $smsMsg = '[' . COMPANY_NAME . '] ' . $name . ', su asistencia fue confirmada.';
      sendSMS($phone,$smsMsg,COUNTRY,SMS_CREDIT);
    }else{
      $out['alert'] = '<i class="material-icons m-r-sm">info</i>No posee agendamientos para el día de hoy';
    }
  }else{
    $out = [  'status'    => "warning",
              'message'   => "No hemos podido encontrar el número ingresado <br> Por favor pruebe con otro número o pongase en contacto",
              'title'     => "Número no reconocido"
            ];
  }

  header('Content-Type: application/json');
  dai(json_encode($out));
}

?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  
  <link rel="icon" type="image/png" href="https://panel.encom.app/favicon-196x196.png" sizes="196x196">
  <link rel="icon" type="image/png" href="https://panel.encom.app/favicon-96x96.png" sizes="96x96">
  <link rel="icon" type="image/png" href="https://panel.encom.app/favicon-32x32.png" sizes="32x32">
  <link rel="icon" type="image/png" href="https://panel.encom.app/favicon-16x16.png" sizes="16x16">
  <link rel="icon" type="image/png" href="https://panel.encom.app/favicon-128.png" sizes="128x128">
  
  <title>Control de Acceso</title>

  <?php
  loadCDNFiles([],'css');
  ?>
  <style type="text/css">
    input::placeholder {
      color: #d7e5e8;
    }
    input{
      -webkit-appearance: none;
    }
    input:focus {
        outline-width: 0;
    }
  </style>
</head>
<body class="bg-light lt col-xs-12 no-padder clear">

<div class="col-xs-12 wrapper m-t text-center">
  <div class="m-b-lg">
    <a href="#" class="thumb-md animated fadeInDown"> 
      <img src="https://assets.encom.app/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
    </a>
  </div>

  <div class="col-xs-12 col-sm-1"></div>
  <div id="card" class="col-xs-12 col-sm-10 wrapper-lg r-24x clear text-center">
    
    <div id="action">

      <div class="col-xs-12 wrapper-md"></div>

      <div class="h3 text-muted col-xs-12 no-padder font-bold m-t">Por favor ingrese su número de documento o de celular</div>
      
      <div class="wrapper-md col-xs-12">
        <form id="sendPhone">
          <input type="tel" id="phone" class="no-border b-b b-light no-bg text-center font-bold col-xs-12 text-info pointer" style="font-size:3em;" placeholder="### ### ###">
        </form>
        <a href="#" class="btn btn-info btn-rounded text-u-c font-bold btn-lg m-t-lg wrapper" id="access">Acceder</a>
      </div>
      
      <div class="col-xs-12 wrapper-md"></div>
    </div>

    <div id="warning" class="text-white" style="display:none;">

      <i class="material-icons animated bounceIn" style="font-size: 10em !important; line-height: 1 !important;">warning</i>

      <div class="h2 col-xs-12 no-padder font-bold m-t-lg msgTitle"></div>

      <div class="col-xs-12 text-lg msg">
        
      </div>
      
      <div class="col-xs-12 wrapper-lg"></div>
    </div>

    <div id="error" class="text-white" style="display:none;">

      <i class="material-icons animated bounceIn" style="font-size: 10em !important; line-height: 1 !important;">close</i>

      <div class="h2 col-xs-12 no-padder font-bold m-t-lg msgTitle"></div>

      <div class="col-xs-12 text-lg msg">
       
      </div>
      <div class="col-xs-12 wrapper text-center text-white alerti"></div>
      
      <div class="col-xs-12 wrapper-lg"></div>
    </div>

    <div id="success" class="text-white" style="display:none;">

      <i class="material-icons animated bounceIn" style="font-size: 10em !important; line-height: 1 !important;">check</i>

      <div class="h2 col-xs-12 no-padder font-bold m-t-lg msgTitle"></div>

      <div class="col-xs-12 text-lg msg">
        
      </div>

      <div class="col-xs-12 wrapper text-center text-white alerti"></div>
      
      <div class="col-xs-12 wrapper-lg"></div>
    </div>

  </div>
  <div class="col-xs-12 col-sm-1"></div>

  <div class="col-xs-12 wrapper text-muted text-center text-sm animated bounceInUp" id="policy">
    
    <div class="m-t-md col-xs-12">
      <span class="text-muted">Usamos</span> <br>
      <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
      <div class="text-muted">www.encom.app</div>
    </div>
    <div id="sound" style="display:none;">&nbsp;</div>
    
  </div>

</div>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>

<?php
loadCDNFiles(['https://cdnjs.cloudflare.com/ajax/libs/libphonenumber-js/1.6.8/libphonenumber-js.min.js'],'js');
?>

<script>
    $(document).ready(function(){

      onClickWrap('#access',function(event,tis){
        access();
      });

      $(document).on('submit','#sendPhone',function(e){
        e.preventDefault();
        access();
        return false;
      });

      $(document).on('keyup','#phone',function(){
        var val     = $(this).val();

        try {
          var phone     = new libphonenumber.AsYouType('PY');
          var val     = phone.input(val);
        } catch(error){

        }

        $(this).val(val);
      });

      var access = function(){
        var phone = $('#phone').val();

        if(!phone){
          alert('Ingrese un numero de celular');
          return false;
        }

        spinner('body', 'show');

        $.get("?s=<?=$_GET['s']?>&phone=" + phone, function(result){
          spinner('body', 'hide');
          var status  = result.status;
          var msg     = result.message;
          var title   = result.title;
          var alert   = result.alert;

          $('#action').hide();
          $('#card').removeClass('bg-white');

          setScreen(status,title,msg,alert);
          
          if(!isMobile.phone){
            resetScreen();
          }

        },'json');
      };

      $.get("?s=<?=$_GET['s']?>&chk=1&t=<?=$TIMES?>", function(result){
        var status  = result.status;
        var msg     = result.message;
        var title   = result.title;
        var alert   = result.alert;

        if(status){
          $('#action').hide();
          $('#card').removeClass('bg-white');

          setScreen(status,title,msg,alert);
        }
      },'json');

      var resetScreen = function(){
        setTimeout(function(){
          $('#action').show();
          $('#error,#success,#warning').hide();
          $('#card').removeClass('bg-success gradBgGreen bg-danger gradBgRed bg-warning gradBgYellow').addClass('bg-white');
          $('#phone').val('').focus();
          $('.alerti').html('');
          playSound('reset');
        },6000);
      };

      var setScreen = function(type,title,msg,alert){

        if(type == 'success'){
          $('#success').show();
          $('#card').addClass('gradBgGreen');
          $('#success .msg').html(msg);
          $('#success .msgTitle').html(title);
          $('#success .alerti').html(alert);
          playSound('success');
        }else if(type == 'error'){
          $('#error').show();
          $('#card').addClass('bg-danger gradBgRed');
          $('#error .msg').html(msg);
          $('#error .msgTitle').html(title);
          $('#error .alerti').html(alert);
          playSound('error');
        }else if(type == 'warning'){
          $('#warning').show();
          $('#card').addClass('bg-warning gradBgYellow');
          $('#warning .msg').html(msg);
          $('#warning .msgTitle').html(title);
          playSound('error');
        }
      };

       var playSound = function(type){
        var snd = 'https://assets.encom.app/sounds/payment_success.m4a';

        if(type == 'error'){
          snd = 'https://assets.encom.app/sounds/payment_failure.m4a';
        }

        if(type == 'reset'){
          $('#sound').html('');         
        }else{
          $('#sound').append('<audio class="audios" id="yes-audio" controls preload="true" autoplay> <source src="' + snd + '" type="audio/mpeg"> </audio>');
        }
        
       };
      
    });
  </script>

</body>
</html>
<?php
dai();
?>
