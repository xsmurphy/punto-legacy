<?php
require_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

if(!validity($data[0]) || !validity($data[1]) || (!validity($data[3]) && !validity($data[4])) ){
  include_once('../includes/404.inc.php');
  dai();
}

define('COMPANY_ID', dec($data[0]));
define('OUTLET_ID', dec($data[1]));
define('CUSTOMER_ID', dec($data[2]));
$TRANSACTION_ID   = dec($data[3]);
$TRANSACTION_UID  = $data[4];
$apiKey = getAPICreds(COMPANY_ID);
define('API_KEY', $apiKey);

if(validity($TRANSACTION_UID)){
  $TRANSACTION_ID = ncmExecute("SELECT transactionId FROM transaction WHERE transactionUID = ? AND companyId = ? LIMIT 1",[$TRANSACTION_UID,COMPANY_ID]);
  $TRANSACTION_ID = $TRANSACTION_ID['transactionId'];
}

$checkIfExists = ncmExecute("SELECT transactionId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1",[$TRANSACTION_ID,COMPANY_ID]);

if(!$checkIfExists){
  header('Location: /');
  dai();
}

$setting = ncmExecute("SELECT * FROM company WHERE companyId = ? LIMIT 1",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', $setting['settingName']);
define('OUTLETS_COUNT', 1);
define('OUTLET_NAME', getValue('outlet', 'outletName', 'WHERE outletId = ' . OUTLET_ID . ' LIMIT 1'));
define('LANGUAGE', $setting['settingLanguage']);

if(validity(CUSTOMER_ID)){
  $customerData   = getCustomerData(CUSTOMER_ID, 'uid');
  $customerName   = getCustomerName($customerData,'first');
  $customerPhone  = $customerData['phone'];
  $customerEmail  = $customerData['email'];
}else{
  $customerName   = L_HI;
}

loadLanguage(LANGUAGE);
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
  
	$record['satisfactionLevel'] 		= validateHttp('level');
  $record['satisfactionComment']  = validateHttp('comment');
  $record['satisfactionDate']     = date('Y-m-d H:i:s');
  $record['transactionId']        = $TRANSACTION_ID;
  $record['customerId']           = CUSTOMER_ID;
	$record['outletId'] 			      = OUTLET_ID;
	$record['companyId'] 		        = COMPANY_ID;

  $insert = $db->AutoExecute('satisfaction', $record, 'INSERT'); 
	 
	if($insert === false){
		echo 'false';
	}else{
    $ops = [
            "title"     => L_NEW_RATING_TITLE,
            "message"   => $customerName . " " . L_CUSTOMER_RATED,
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

//check if ya se califico
$satis = ncmExecute("SELECT transactionId FROM satisfaction WHERE transactionId = ? AND companyId = ? LIMIT 1",[$TRANSACTION_ID,COMPANY_ID]);

$_modules = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
$qtion = L_SA_HOW_WOULD_YOU_RATE;
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
  <title><?=L_SA_QUALIFY_YOUR_EXP_TEXT?></title>

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
 
  <div class="col-xs-12 text-center m-t-lg">
    <a href="#" class="thumb-md"> <img src="/assets/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle"> </a>
    <div class="h3 font-bold m-t-sm">
      <?=COMPANY_NAME?> 
      <?php
      if(OUTLETS_COUNT > 1){
      ?>
        <span class="text-default font-normal">(<?=OUTLET_NAME?>)</span>
      <?php
      }
      ?>
    </div>
    <span class="h2 hidden-xs m-t font-bold text-dark block hideOnSelect" style="display: none;"><?=$customerName?>, <?=$qtion;?></span>
    <span class="h4 visible-xs m-t font-bold text-dark block hideOnSelect" style="display: none;"><?=$customerName?>, <?=$qtion;?></span>
  </div>

  <div class="col-xs-12 wrapper-md b-b hidden-xs">
  </div>

  <div class="col-xs-12 text-center m-t no-padder" id="select" style="display: none;">

    <div class="col-xs-12 no-padder">
      <div class="col-sm-2 hidden-xs"></div>
      <div class="col-sm-8 no-padder">

        <div class="col-xs-4 m-b-sm level pointer wrapper r-24x" data-level="1">
          <img src="/images/badface.png" class="m-t" style="max-width:75%">
          <span class="h2 font-bold block m-t hidden-xs"><?=L_SA_RATE_BAD?></span>
          <span class="h4 font-bold block m-t visible-xs"><?=L_SA_RATE_BAD?></span>
        </div>
        <div class="col-xs-4 m-b-sm level pointer wrapper r-24x" data-level="3">
          <img src="/images/goodface.png" style="max-width:90%">
          <span class="h2 font-bold block m-t hidden-xs"><?=L_SA_RATE_EXCELENT?></span>
          <span class="h4 font-bold block m-t visible-xs"><?=L_SA_RATE_EXCELENT?></span>
        </div>
        <div class="col-xs-4 m-b-sm level pointer wrapper r-24x" data-level="2">
          <img src="/images/mediumface.png" class="m-t" style="max-width:75%">
          <span class="h2 font-bold block m-t hidden-xs"><?=L_SA_RATE_GOOD?></span>
          <span class="h4 font-bold block m-t visible-xs"><?=L_SA_RATE_GOOD?></span>
        </div> </div>
      <div class="col-sm-2 hidden-xs"></div>
    </div>
    

    <div class="col-xs-12 wrapper-lg">
      <div class="col-sm-3 hidden-xs"></div>
      <div class="col-sm-6 no-padder">
        <textarea class="r-24x bg-white b text-lg form-control" placeholder="<?=L_SA_ADD_A_COMMENT_MAX?>" id="comment" style="min-height:100px;"></textarea>
        <div class="text-xs text-right m-r-md"><span class="material-icons">format_italic</span> <span id="remaining" class="font-bold"></span></div>
        <?php
        if(validity(CUSTOMER_ID)){
        ?>
        <div class="col-xs-12 m-t-sm">
          <div class="text-center text-sm text-muted m-b-sm"><?=L_SA_LEAVE_A_COMMENT?></div>
          <div class="col-sm-6 m-b-xs">
            <?php
            if(!$customerPhone){
            ?>
            <input type="tel" class="form-control no-border rounded" id="phone" placeholder="Celular">
            <?php
            }
            ?>
          </div>
          <div class="col-sm-6 m-b-xs">
            <?php
            if(!$customerEmail){
            ?>
            <input type="email" class="form-control no-border rounded" id="email" placeholder="Email">
            <?php
            }
            ?>
          </div>
        </div>
        <?php
        }
        ?>
      </div>
      <div class="col-sm-3 hidden-xs"></div>
    </div>


    <div class="col-xs-12 no-padder">
      <a href="#" class="btn btn-lg btn-info text-u-c font-bold all-shadows btn-rounded" id="sendBtn" disabled><?=L_SA_RATE?></a>
    </div>
  </div>

  <div class="col-xs-12 text-center m-t-lg animated bounceIn" id="success" style="display:none;">
    <i class="icon-check text-info" style="font-size:5em;"></i>
    <div class="block h2 m-t font-bold">
       <?=L_SA_RATING_THANKS?>
    </div>

    <div class="h4 m-t">
      <?=L_SA_FOLLOW_US?>:
      <br><br>
      <?php
      $social     = json_decode($setting['settingSocialMedia'],true);

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

  <div class="col-xs-12 text-center m-t-lg b-t m-b">
    <a href="/?utm_source=ENCOM_online_feedback&utm_medium=ENCOM_footer_icon&
utm_campaign=<?=COMPANY_NAME?>" class="m-t-md block">
      <span class="text-muted"><?=L_POWERED_BY?></span> <br>
      <img src="/images/incomeLogoLgGray.png" width="80">
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

      <?php
      if(!$satis){
        ?>
        $('#select, .hideOnSelect').show();

        $('.level').on('click',function(){
          $('.level').removeClass('dk');
          $(this).addClass('dk');
          $('#sendBtn').removeAttr('disabled');
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

      <?
      }else{
      ?>
          $('#success').show();
      <?php
      }
      ?>
    });
  </script>

</body>
</html>

<?php
dai();
?>
