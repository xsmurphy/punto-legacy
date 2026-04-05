<?php
require_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

if(!validity($data[0]) || !validity($data[1]) ){
  include_once('../includes/404.inc.php');
  dai();
}

define('COMPANY_ID', dec($data[0]));
define('OUTLET_ID', dec($data[1]));
define('FROM_QR', $data[2]);

$NOW     = time();
$qrS     = base64_encode($data[0] . ',' . $data[1] . ',' . $NOW);


$setting = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
define('OUTLETS_COUNT', 1);
define('OUTLET_NAME', getValue('outlet', 'outletName', 'WHERE outletId = ' . OUTLET_ID . ' LIMIT 1'));

$apiKey = getAPICreds(COMPANY_ID);
define('API_KEY', $apiKey);

$customerName   = 'Hola!';

date_default_timezone_set(TIMEZONE);

if(validateHttp('level')){
	$record  = [];
  $contact = [];

  if(validateHttp('email')){
    $contact['contactEmail'] = strtolower(preg_replace('/[^A-Za-z0-9._+-]*$/', '', validateHttp('email')));
  }

  if(validateHttp('phone')){
    $contact['contactPhone'] = validateHttp('phone');
  }

  if(validateHttp('email') || validateHttp('phone')){
    $update = $db->AutoExecute('contact', $contact, 'UPDATE','contactId = ' . CUSTOMER_ID);
  }
  
	$record['satisfactionLevel'] 		= $_GET['level'];
  $record['satisfactionComment']  = $_GET['comment'];
  $record['satisfactionDate']     = date('Y-m-d H:i:s');
	$record['outletId'] 			      = OUTLET_ID;
	$record['companyId'] 		        = COMPANY_ID;

  $insert = $db->AutoExecute('satisfaction', $record, 'INSERT'); 
	 
	if($insert === false){
		echo 'false';
	}else{
    $ops = [
            "title"     => "Nueva calificación",
            "message"   => "Calificación Anónima.",
            "type"      => 1,
            "company"   => COMPANY_ID,
            "push"      => [
                        "tags" => [[
                                        "key"   => "outletId",
                                        "value" => enc(OUTLET_ID)
                                    ],
                                    [
                                        "key"   => "isResource",
                                        "value" => "false"
                                    ]],
                        "where"     => 'caja'
                        ]
          ];
    insertNotifications($ops);
    
		echo 'true';
	}
	dai();
}

$_modules = ncmExecute('SELECT * FROM module WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$qtion = '¿Cómo calificaría su experiencia?';
if($_modules['feedbackQuestion']){
  $qtion = $_modules['feedbackQuestion'];
}

?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Calificación</title>

  <?php
  loadCDNFiles([],'css');
  ?>
  <style type="text/css">
    .svg {
    cursor: pointer;
    filter: invert(.3) sepia(1) saturate(1) hue-rotate(175deg);
  }
  </style>
</head>
<body class="bg-light lter">
 
  <div class="col-xs-12 text-center m-t-md m-b-md">
    <img src="https://assets.encom.app/80-80/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle m-r" width="50">
    <span class="h2 m-t font-bold block hideOnSelect" style="display: none;"><?=$qtion;?></span>
  </div>

  <div class="col-xs-12 text-center no-padder animated fadeIn speed-3x" id="select" style="display: none;">

    <div class="col-xs-12 no-padder">
      <div class="col-sm-2 hidden-xs"></div>
      <div class="col-sm-8 no-padder">

        <div class="col-xs-4 m-b-sm level pointer wrapper r-24x" data-level="1">
          <img src="https://panel.encom.app/images/badface.png" class="m-t" style="max-width:60%">
          <span class="h2 font-bold block m-t hidden-xs">Mala</span>
          <span class="h4 font-bold block m-t visible-xs">Mala</span>
        </div>
        <div class="col-xs-4 m-b-sm level pointer wrapper r-24x" data-level="3">
          <img src="https://panel.encom.app/images/goodface.png" style="max-width:70%">
          <span class="h2 font-bold block m-t hidden-xs">Excelente</span>
          <span class="h4 font-bold block m-t visible-xs">Excelente</span>
        </div>
        <div class="col-xs-4 m-b-sm level pointer wrapper r-24x" data-level="2">
          <img src="https://panel.encom.app/images/mediumface.png" class="m-t" style="max-width:60%">
          <span class="h2 font-bold block m-t hidden-xs">Buena</span>
          <span class="h4 font-bold block m-t visible-xs">Buena</span>
        </div> </div>
      <div class="col-sm-2 hidden-xs"></div>
    </div>
    
    <div class="col-xs-12 wrapper-lg">

      <div class="col-xs-6 col-xs-offset-3 no-padder hidden-sm hidden-xs text-left">
        <div class="col-xs-5 text-right">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&amp;data=https://public.encom.app/anonFeedback?s=<?=$qrS;?>&qr=1" width="100">
        </div>
        <div class="col-xs-7">
          <span class="h2">O puede escanear el código y calificar desde su celular</span>
        </div>
      </div>
      
      <div class="col-md-8 col-md-offset-2 col-xs-12 no-padder visible-sm visible-xs" id="customerForm">
        <textarea class="r-24x b text-lg form-control" placeholder="Añada un comentario o sugerencia breve (max. 250)" id="comment" style="min-height:100px;"></textarea>
        <div class="text-xs text-right">Restante <span id="remaining" class="font-bold"></span></div>

        <div class="col-xs-12 m-t-sm hidden">
          <div class="text-center text-sm text-muted m-b-sm">Déjanos un contacto para poder comunicarnos con usted.</div>
          <div class="col-sm-6 m-b-xs">
            <input type="tel" class="form-control no-border rounded" id="phone" placeholder="Celular">
          </div>
          <div class="col-sm-6 m-b-xs">
            <input type="email" class="form-control no-border rounded" id="email" placeholder="Email">
          </div>
        </div>

        <div class="col-xs-12 no-padder">
          <a href="#" class="btn btn-lg btn-info text-u-c font-bold all-shadows btn-rounded" id="sendBtn" disabled>Calificar</a>
        </div>

      </div>

    </div>

  </div>

  <div class="col-xs-12 text-center m-t-lg animated zoomIn speed-3x" id="success" style="display:none;">

    <div class="visible-xs visible-sm">
      <i class="icon-check text-info" style="font-size:5em;"></i>
      <div class="block h1 m-t font-bold">
         Gracias por su calificación.
      </div>
    </div>

    <div class="hidden-xs hidden-sm">
      <i class="icon-check text-info" style="font-size:10em;"></i>
      <div class="block h1 m-t font-bold" style="font-size:4em;">
         Gracias por su calificación.
      </div>
    </div>

    <div class="h4 m-t visible-sm visible-xs">
      No olvide seguirnos en:
      <br><br>
      <?php
      $social   = json_decode($setting['settingSocialMedia'],true);

      $facebook   = 'https://facebook.com/' . str_replace('@','',$social['facebook']);
      $instagram  = 'https://instagram.com/' . str_replace('@','',$social['instagram']);
      $youtube    = 'https://youtube.com/' . str_replace('@','',$social['youtube']);
      $twitter    = 'https://twitter.com/' . str_replace('@','',$social['twitter']);
      ?>
      <?php
      if($social['facebook']){
      ?>
        <a href="<?=$facebook;?>"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/facebook.svg" class="svg" width="40"></a>
      <?php
      }
      if($social['instagram']){
      ?>
        <a href="<?=$instagram;?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/instagram.svg" class="svg" width="40"></a>
      <?php
      }
      if($social['youtube']){
      ?>
        <a href="<?=$youtube;?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/youtube.svg" class="svg" width="40"></a>
      <?php
      }
      if($social['twitter']){
      ?>
        <a href="<?=$twitter;?>" class="m-l-md"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/twitter.svg" class="svg" width="40"></a>
      <?php
      }
      ?>
    </div>
  </div>

  <div class="col-xs-12 text-center m-t b-t m-b">
    <a href="https://encom.app?utm_source=ENCOM_online_feedback&utm_medium=ENCOM_footer_icon&
utm_campaign=<?=COMPANY_NAME?>" class="m-t-md block">
      <span class="text-muted">Usamos</span> <br>
      <img src="https://app.encom.app/images/incomeLogoLgGray.png" width="80">
    </a>
  </div>

  <script type="text/javascript">
    var noSessionCheck  = true;
    window.standAlone   = true;
  </script>

  <?php
  loadCDNFiles([],'js');
  ?>

  <script>
    $(document).ready(function(){
      FastClick.attach(document.body);

      ncmUI.setDarkMode.auto();
      var goOn = true;

      <?php
      if(FROM_QR){
        ?>
        var dateRated = simpleStorage.get('dateRated');
        if( (dateRated + 86400) > <?=$NOW?> ){
          $('#success').show();
          goOn = false;
        }
        <?php
      }
      ?>

      if(goOn){

        $('#select, .hideOnSelect').show();

        $('.level').on('click',function(){
          $('.level').removeClass('dk');
          $(this).addClass('dk');
          $('#sendBtn').removeAttr('disabled');
          if(!$('#customerForm').is(':visible')){
            $('#sendBtn').trigger('click');
          }
        });

        $('#sendBtn').on('click',function(e){
          e.preventDefault();
          var level   = $('.level.dk').attr('data-level');
          var comment = $('#comment').val();
          var phone   = $('#phone').val();
          var email   = $('#email').val();
          var url     = '?level=' + level + '&comment=' + comment + '&phone=' + phone + '&email=' + email + '&s=<?=$_GET['s']?>';
          $.get(url,function(result){
            if(result == 'true'){
              $('#select, .hideOnSelect').hide();
              $('#success').show();
              <?php
              if(FROM_QR){
                ?>
                simpleStorage.set('dateRated',<?=$NOW?>);
                <?php
              }else{
              ?>
              if(!$('#customerForm').is(':visible')){
                setTimeout(function(){
                  $('#select, .hideOnSelect').show();
                  $('#success').hide();
                  $('.level').removeClass('dk');
                  $('textarea').val('');
                },4500);
              }
              <?php
              }
              ?>
            }
          });
        });

        var max = 250;
        $('#remaining').text(max);
        $('#comment').on('keypress',function(e){
          var lngth = $('#comment').val().length;
          var left  = max - lngth;
          $('#remaining').text(left);
          console.log(lngth);
          if(left < 1){
            console.log('eeep');
            e.preventDefault();
            return false;
          }
        });

      }

      <?
      //}else{
      ?>
        //  $('#success').show();
      <?php
      //}
      ?>
    });
  </script>

</body>
</html>

<?php
dai();
?>
