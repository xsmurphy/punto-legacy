<?php
include_once('sa_head.php');

$data = explodes(',', base64_decode($_GET['s']));

define('COMPANY_ID', dec($data[0]));
define('CUSTOMER_ID', dec($data[1]));
define('OUTLETS_COUNT', 0);


$setting = ncmExecute("SELECT * FROM company WHERE companyId = ?",[COMPANY_ID]);

define('THOUSAND_SEPARATOR', $setting['settingThousandSeparator']);
define('DECIMAL', $setting['settingDecimal']);
define('CURRENCY', $setting['settingCurrency']);
define('TIMEZONE', $setting['settingTimeZone']);
define('COMPANY_NAME', toUTF8($setting['settingName']));

date_default_timezone_set(TIMEZONE);

define('TODAY', date('Y-m-d H:i:s'));

if(validateHttp('action')){
  $getNotes = ncmExecute('SELECT * FROM contactNote WHERE customerId = ? AND companyId = ? ORDER BY contactNoteDate DESC',[CUSTOMER_ID,COMPANY_ID],false,true);

  $contact      = getContactData(CUSTOMER_ID,'uid');
  $customerName = getCustomerName($contact);

  if($getNotes){
    
    ?>
    
    <div class="col-xs-12 wrapper bg-light lter"> 
      <div class="m-b-lg text-center">
        <a href="#" class="thumb-md animated fadeInDown"> 
          <img src="/assets/150-150/0/<?=enc(COMPANY_ID)?>.jpg" class="img-circle companyImg"> 
        </a>
      </div>
      <div class="text-center">Notas de</div> 
      <div class="col-xs-12 h1 font-bold text-center text-dark m-b"><?=$customerName?></div>
      
      <div class="text-left" style="display: block; position: relative;margin: 0 auto; max-width: 700px;">
        <div class="col-xs-12 wrapper text-right">
          <a href="#" class="btn btn-info rounded font-bold text-u-c clickeable hidden-print" data-name="<?=$customerName?>" data-id="<?=enc(CUSTOMER_ID)?>" data-type="customerNoteAdd">Crear nota</a>
        </div>
        <?php
        

          if($getNotes){
            while (!$getNotes->EOF) {
              $noFields = $getNotes->fields;
              $noteId   = enc($noFields['contactNoteId']);      
              $noteTxt  = toUTF8( strip_tags( isBase64Decode( $noFields['contactNoteText'] ) ) );

              $noteTxt = markupt2HTML(['text' => $noteTxt, 'type' => 'MtH']);
              
              $noteDate = niceDate($noFields['contactNoteDate']);
        ?>
              <div class="col-xs-12 wrapper r-3x b m-b bg-white pagebreak">
                <div class="col-xs-12 no-padder">
                  <span class="pull-left text-u-c font-bold text-sm"><?=$noteDate?></span>
                  <a href="#" class="btn btn-default rounded font-bold text-u-c pull-right clickeable hidden-print" data-element="#note<?=$noteId?>" data-type="customerNotePrint">Imprimir</a>
                </div>
                <div class="col-xs-12 m-t-sm text-md no-padder" id="note<?=$noteId?>">
                  <?=$noteTxt?>
                </div>
              </div>

      <?php
              $getNotes->MoveNext(); 
            }
          }
        ?>

        <div class="col-xs-12 wrapper text-center hidden-print">
          <a href="/screens/customerNotes?s=<?=$_GET['s']?>" class="nativeA" target="_blank">Ver versión independiente</a>
        </div>


      </div>
      
    </div>
    <?php

  }else{
    ?>
    <div class="text-center col-xs-12 wrapper noDataMessage">
      <img src="/assets/images/emptystate7.png" height="130" class="m-b-sm">
      <h1 class="font-bold m-t-n">No posee notas</h1>
      <div class="text-center m-t">
        <a href="#" class="btn btn-lg btn-info rounded font-bold text-u-c clickeable hidden-print" data-name="<?=$customerName?>" data-id="<?=enc(CUSTOMER_ID)?>" data-type="customerNoteAdd">Crear nota</a>
      </div>
    </div>
    <?php
  }

  dai();
}
?>
<!DOCTYPE html>
<html class="no-js">
<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Progreso</title>

  <?php
  loadCDNFiles(array(),'css');
  ?>
</head>
<body class="bg-light lter">
<div id="results" class="col-xs-12 no-padder"></div>

<div class="hidden-print text-center">
  <a href="/?utm_source=ENCOM_customer_progress&utm_medium=ENCOM_footer_icon&
  utm_campaign=<?=COMPANY_NAME?>" class="m-t-lg m-b-lg text-center block">
    <span class="text-muted">Usamos</span> <br>
    <img src="/images/incomeLogoLgGray.png" width="80">
  </a>
</div>

<script type="text/javascript">
  var noSessionCheck  = true;
  window.standAlone   = true;
</script>
<?php
loadCDNFiles(array('/assets/vendor/js/Chart-2.9.4.min.js'),'js');
?>
<script>
    $(document).ready(function(){
      $.get('?action=1&s=<?=$_GET['s']?>',function(result){
        if(result){
          $('#results').html(result);
          $('[data-toggle="tooltip"]').tooltip();
        }
      });
      
    });
  </script>

</body>
</html>
<?php
dai();
?>
