<?php
include_once('includes/top_includes.php');

topHook();
allowUser('settings','view');

$baseUrl        = '/' . basename(__FILE__,'.php');

$setting        = ncmExecute("SELECT * FROM setting WHERE companyId = ? LIMIT 1",[COMPANY_ID],false);
$_cmpSettings   = $setting;
$_fullSettings  = json_decode($_cmpSettings['settingObj'],true);

if(validateHttp('action') == 'update' && validateHttp('type') == 'setting'){

  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  $fullObj                            = json_encode( $_POST );

  $record                             = [];
  $record['settingObj']               = $fullObj;
  //$record['settingName']           = validateHttp('name','post');
  $record['settingAddress']           = validateHttp('address','post');
  $record['settingWebSite']           = validateHttp('website','post');
  $record['settingEmail']             = validateHttp('email','post');
  $record['settingRUC']               = validateHttp('ruc','post');
  $record['settingPhone']             = validateHttp('phone','post');
  $record['settingCity']              = validateHttp('city','post');
  $record['settingCountry']           = validateHttp('country','post');
  $record['settingLanguage']          = iftn(validateHttp('language','post'),'es');
  $record['settingTimeZone']          = validateHttp('timeZone','post');
  $record['settingCurrency']          = validateHttp('currency','post');
  $record['settingAutoPrint']         = validateHttp('autoPrint','post');
  $record['settingShowTax']           = validateHttp('autoTax','post');
  $record['settingTaxName']           = validateHttp('taxName','post');
  $record['settingBillingName']       = validateHttp('billingName','post');
  $record['settingTIN']               = validateHttp('tin','post');
  $record['settingDecimal']           = validateHttp('decimal','post') ? 'yes' : 'no';
  $record['settingSellSoldOut']       = validateHttp('sellsoldout','post') ? 'yes' : 'no';
  $record['settingLockScreen']        = validateHttp('lockscreen','post');
  $record['settingDrawerEmail']       = validateHttp('drawerEmail','post');
  $record['settingDrawerBlind']       = validateHttp('drawerBlind','post');
  $record['settingPaymentMethodId']   = validateHttp('paymentId','post');
  $record['settingForceCreditLine']   = validateHttp('creditLine','post');
  $record['settingDeliveryEmail']     = validateHttp('deliveryEmail','post');
  $record['settingItemSerialized']    = validateHttp('itemSerialized','post');
  $record['settingLoyalty']           = validateHttp('loyaltySwitch','post');
  $record['settingBillDetail']        = validateHttp('billDetail','post');
  $record['settingRemoveTaxes']       = validateHttp('settingRemoveTaxes','post');
   
  $record['settingLoyaltyMin']        = formatNumberToInsertDB(validateHttp('loyaltyMin','post'));
  $record['settingLoyaltyValue']      = formatNumberToInsertDB(validateHttp('loyaltyValue','post'));
  $record['settingloyaltyInsentive']  = formatNumberToInsertDB(validateHttp('loyaltyInsentive','post'));
  $record['settingSocialMedia']       = json_encode(
                                                      [
                                                          'facebook'  => validateHttp('facebook','post'),
                                                          'instagram' => validateHttp('instagram','post'),
                                                          'youtube'   => validateHttp('youtube','post'),
                                                          'twitter'   => validateHttp('twitter','post')
                                                      ]
                                                    );

  $lpromo                             = str_replace(array("\r\n", "\n", "\r"), array("<br>"), validateHttp('loyaltyPromoMsg','post'));
  $lreminder                          = str_replace(array("\r\n", "\n", "\r"), array("<br>"), validateHttp('loyaltyReminderMsg','post'));

  $record['settingLoyaltyEmailPromo']     = $lpromo;
  $record['settingLoyaltyEmailReminder']  = $lreminder;
  $record['settingStoreCredit']           = validateHttp('storeCredit','post');
  $record['settingItemsSaleLimit']        = validateHttp('itemsSaleLimit','post');
  $record['settingStoreTables']           = validateHttp('restaurantTables','post');
  $record['settingStoreCalendar']         = validateHttp('calendar','post');
  $record['settingCompanyCategoryId']     = validateHttp('category','post'); 
  $record['settingThousandSeparator']     = validateHttp('thousandSeparator','post');

  $update = $db->AutoExecute('setting', $record, 'UPDATE', $SQLcompanyId); 
  if($update === false){
    echo $db->ErrorMsg();
  }else{
    $result = ncmExecute('SELECT * FROM setting WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
    $_SESSION['user']['companySettings'] = $result;
    updateLastTimeEdit();
    echo 'true';
  }
  dai();
}

if(validateHttp('action') == 'update' && validateHttp('type') == 'invoicing'){

  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }
  
  $record = [];
  $record['settingBillTemplate']    = $_POST['invoicetemplate'];
  $record['settingTicketFoot']      = $_POST['billbottomtitle'] . '[@]' . $_POST['billbottomsubtitle'];

  $update = ncmUpdate(['records' => $record, 'table' => 'setting', 'where' => $SQLcompanyId]);

  if($update['error'] === false){
    echo 'false';
  }else{
    //addToHistory('Configuracion actualizada', 'config', '0');
    updateLastTimeEdit(COMPANY_ID);
    echo 'true';
  }
  dai();
}

if(validateHttp('action') == 'update' && validateHttp('type') == 'ecommerce'){

  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }
  
  $record = [];
  $record['ecommerceStatus']    = $_POST['estatus'];
  $record['outletId']           = dec($_POST['eoutlet']);
  $record['registerId']         = dec($_POST['eregister']);
  $record['companyId']          = COMPANY_ID;

  if(checkIfExists(COMPANY_ID, 'companyId', 'ecommerce')){
    $action = $db->AutoExecute('ecommerce', $record, 'UPDATE', $SQLcompanyId);   
  }else{
    $action = $db->AutoExecute('ecommerce', $record, 'INSERT'); 
  }

  if($action === false){
    echo 'false';
  }else{
    echo 'true';
    updateLastTimeEdit();
  }
  dai();
}

if(validateHttp('tableExtra')){
  adm(validateHttp('valExtra'),validateHttp('tableExtra'),dec(validateHttp('idExtra')),validateHttp('actionExtra'));
}

if(validateHttp('action') == 'saveTemplate'){
  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  $id                       = $_GET['i'];

  $data                     = $_POST['data'];
  $jdata                    = json_decode($data, true);

  $name                     = iftn($jdata['page_name'],'Nueva Plantilla');

  $record['taxonomyName']   = $name;
  $record['taxonomyExtra']  = $data;
  $record['taxonomyType']   = 'printTemplate';
  $record['companyId']      = COMPANY_ID;

  if(validity($id)){//up[date]
    $sql = $db->AutoExecute('taxonomy', $record, 'UPDATE', 'taxonomyId = ' . dec($id));
  }else{//insert
    $sql = $db->AutoExecute('taxonomy', $record, 'INSERT');
  }

  if($sql === true){
    updateLastTimeEdit(COMPANY_ID);
    dai('true');
  }else{
    dai('false');
  }
}

//eliminar template
if(validateHttp('action') == 'removeTemplate' && validateHttp('id')){
  if(!allowUser('settings','delete',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  $delete = $db->Execute('DELETE FROM taxonomy WHERE taxonomyId = ? AND '.$SQLcompanyId.' LIMIT 1', array(dec($_GET['id']))); 
  if($delete === false){
    updateLastTimeEdit(COMPANY_ID);
    echo 'false';
  }else{
    echo 'true';
  }
  dai();
}

if(validateHttp('action') == 'loadTemplatesList'){
  $templating = ncmExecute("SELECT * FROM taxonomy WHERE taxonomyType = 'printTemplate' AND (companyId = ? OR companyId = 1) ORDER BY taxonomyName ASC",[COMPANY_ID],false,true);
  
  if($templating){
    if($_GET['type']=='select'){
    ?>
      <option>No imprimir</option>
    <?php
    }else{
    ?>
      <li> 
        <a href="#" class="templateSelect" data-id="new"> 
          <span class="text-success">
            <i class="material-icons m-r-sm">add</i> Crear una Nueva
          </span> 
        </a> 
      </li>
    <?php
    }

    while (!$templating->EOF) {
      $templateId = enc($templating->fields['taxonomyId']);

      if($templating->fields['companyId'] == 1){
        $locked     = 'true';
        $templateId = '';
      }else{
        $locked     = 'false';
      }
      
      if($_GET['type'] == 'select'){
    ?>
        <option value="<?=$templateId?>" data-lock="<?=$locked?>" data-json="<?=htmlspecialchars($templating->fields['taxonomyExtra'])?>"><?=$templating->fields['taxonomyName']?></option>
    <?php
      }else{
    ?>
        <li>
          <a href="#" class="templateSelect" data-id="<?=$templateId?>" data-lock="<?=$locked?>" data-name="<?=$templating->fields['taxonomyName']?>">
            <i class="material-icons m-r-sm text-muted">mode_edit</i><?=$templating->fields['taxonomyName']?>
            <div class="loadTemplateData hidden">
              <?=htmlentities($templating->fields['taxonomyExtra'])?>
            </div>
          </a>
        </li>
    <?php
      }

      $templating->MoveNext();
    }

    $templating->Close();
  }else{
    if($_GET['type']=='select'){
      echo '<option>No posee plantillas de impresión</option>';
    }else{
      echo '<li><a href="#" class="">No ha creado plantillas aún</a></li>';
    }
  }

  dai();
}

if(validateHttp('view') == 'registerlist' && validateHttp('outlet')){
    $register = $db->Execute('SELECT registerId, registerName FROM register WHERE outletId = ? AND '.$SQLcompanyId, array(dec($_GET['outlet'])));
  ?>

    <label class="m-t">Caja:</label>
    <select id="register" name="eregister" class="form-control" autocomplete="off">

      <?php 
        while (!$register->EOF) {
        $regId = enc($register->fields['registerId']);
        ?>
      <option value="<?=$regId;?>" <?=($register->fields['registerId'] == dec($_GET['current']))?'selected':'';?>><?=$register->fields['registerName'];?></option>
      <?php 
          $register->MoveNext(); 
        }
        $register->Close();
      ?>
    </select>
  <?php
  dai();
}

if(validateHttp('action') == 'sortCategories'){

  if(validateHttp('update')){
    $data = json_decode( base64_decode( validateHttp('update') ), true );
    
    foreach ($data as $key => $val) {
      if($val['id']){
        $sort = (int)$val['sort'];
        $id   = dec($val['id']);
        ncmUpdate(['records' => ['taxonomyExtra' => $sort], 'table' => 'taxonomy', 'where' => 'taxonomyId = ' . $id . ' AND companyId = ' . COMPANY_ID ]);
      }
    }

    dai();
  }

  $result = ncmExecute("SELECT taxonomyId, taxonomyName, CAST(taxonomyExtra as UNSIGNED) as sort FROM taxonomy WHERE companyId = ? AND taxonomyType = ? ORDER BY sort ASC LIMIT 500",[COMPANY_ID,'category'],false,true);

  $out = [];

  if($result){
    while (!$result->EOF) {
      $out[] =  [
                  'id'    => enc($result->fields['taxonomyId']),
                  'name'  => $result->fields['taxonomyName'],
                  'sort'  => $result->fields['taxonomyExtra'],
                ];
      
      $result->MoveNext();
    }
  }
  header('Content-Type: application/json');
  dai(json_encode($out));
}

if(validateHttp('action') == 'setCurrencies'){

  if(validateHttp('update')){
    $data = json_decode( base64_decode( validateHttp('update') ), true );
    $updt = [];

    foreach ($data as $key => $val) {
      $amount   = floatval($val['value']);
      $currency = preg_replace('/[^a-z]/i','',$val['code']);

      if( is_numeric($currency) || strlen($currency) > 3 ){
        continue;
      }

      if($amount > 0){
        $updt[] = [$currency => $amount];
      } 
    }

    $_fullSettings['currencies'] = $updt;
    $settUpdt = json_encode($_fullSettings);
    ncmUpdate(['records' => ['settingObj' => $settUpdt], 'table' => 'setting', 'where' => 'companyId = ' . COMPANY_ID ]);

    dai();
  }

  $currens = $_fullSettings['currencies'];
  //print_r($currens);
  //dai();

  $out = [];
  foreach ($_COUNTRIES_H as $ccode => $value) {
    $currency = $value['currency']['code'];
    $curcur   = 0;

    if(validity($currens)){
      foreach ($currens as $k => $v) {
        if($v[$currency] > 0){
          $curcur = floatval($v[$currency]);
        }
      }
    }

    if($currency != null && $currency != COUNTRY){
      $out[]  = ['ccode' => $ccode, 'code' => $currency, 'value' => $curcur ];
    }

  }

  
  header('Content-Type: application/json');
  dai(json_encode($out));
}
?>

<style>
  .box {
    position: absolute;
    cursor: move;
    /*height:5mm;*/
    /*word-break: break-all;*/
  }

  .boxOps{
    position: absolute;
    left: 0;
    /*top:8;*/
    width: 180px;
    display: none;
  }

  #wrapit {
    width: 100%;
    height: 360mm;
    /*margin: 0 auto;*/
    padding:21px 0 0 21px;
    background:url("https://panel.encom.app/images/theGrid.png") no-repeat top left;
  }

  #contentIt {
    border:1px dashed #55f;
    position:absolute;/*super important*/
    overflow: auto;
    background-size:100%;
    background-repeat: no-repeat;
    background-position: top left;
  }

  .legalpage{
    width: 215.9mm;
    height: 355.6mm;
  }
  .legalpage-h{
    height: 215.9mm;
    width: 355.6mm;
  }
  .letterpage{
    width: 215.9mm;
    height: 279.4mm;
  }
  .letterpage-h{
    height: 215.9mm;
    width: 279.4mm;
  }
  .a4page{
    width: 210mm;
    height: 297mm;
  }
  .a4page-h{
    height: 210mm;
    width: 297mm;
  }

  .receipt80{
    width: 80mm;
    height: 279mm;
  }
  .receipt76{
    width: 76mm;
    height: 279mm;
  }
  .receipt57{
    width: 57mm;
    height: 279mm;
  }
 
  .guide{
      display: none; 
      position: absolute; 
      left: 0; 
      top: 0; 
  }

  .ui-resizable-helper { 
    border: 1px dashed #55f; 
  }

  #guide-h,#guide2-h,#guide3-h{
      border-top: 1px dashed #55f; 
      width: 100%; 
  }

  #guide-v,#guide2-v,#guide3-v{
      border-left: 1px dashed #55f; 
      height: 100%; 
  }
  div.ui-selected{
    background-color: #fff;
  }
  div.ui-selecting{
    background-color: #fff;
  }
</style>

<div class="col-xs-12 wrapper r-24x bg-info gradBgGray m-b-md">
    <?php 
      $img = companyLogo(150);
    ?>
    <div class="col-sm-2 col-xs-12 no-padder text-center">
      <form method="post" enctype="multipart/form-data" action="upload.php?id=<?=enc(COMPANY_ID);?>">
        <input type="file" name="image" id="image" data-url="upload.php?id=<?=enc(COMPANY_ID);?>" style="display:none"/>
      </form>
    
      <a href="#" class="m-b" id="uploadImgBtn" data-toggle="tooltip" data-placement="bottom" title="Click aquí para añadir o cambiar el logo de su empresa"> 
        <img src="<?=$img?>" class="img-circle itemImg" style="width:60%; max-width: 130px;"> 
      </a>
    </div>
    
    <div class="col-sm-8 col-xs-12 text-white">

      <span class="h1 font-bold m-b">
        <?=$_cmpSettings['settingName']?>
      </span>

      <div class="text-sm font-bold">
        <?php
        foreach($companyCategories as $key => $val){
            foreach($val as $k => $v){
              $selected = '';
              if($_cmpSettings['settingCompanyCategoryId'] === $v){
                echo '<i>'.$k.'</i>';
              }
            }
        }
        ?>
      </div>

      <?=iftn($_cmpSettings['settingAddress'],'Sin dirección')?>
      <p>
        <?=iftn($_cmpSettings['settingCity'],'Ninguna ciudad')?> - <?=iftn($countries[$_cmpSettings['settingCountry']]['name'],'Ningún País')?>
      </p>

      <p>
        <?=iftn($_cmpSettings['settingPhone'],'Sin teléfono')?> - <?=iftn($_cmpSettings['settingEmail'],'Sin email')?>
        <br>
        <?=iftn($_cmpSettings['settingWebSite'],'Sin sitio web')?>
      </p>

    </div>
    <div class="col-sm-2 col-xs-12 no-padder text-right">
      <?php 
      if(isBoss()){ //despliego listado de sucursales solo si soy ROLE 1
      ?>
        <a href="#billing" class="btn btn-block text-u-c font-bold">Estado de cuenta</a>
      <?php
      }
      ?>
    </div>
</div>

<section id="templateBuilderToolsCanvas" class="col-xs-12 no-padder m-b-lg push-chat-down">

  <section class="col-xs-12 no-padder">
    <ul class="nav nav-tabs padder hidden-print wrap-l-md">
        <li class="active">
            <a href="#general" data-toggle="tab">
              <span class="hidden-xs">Perfil</span>
              <span class="material-icons visible-xs">list_alt</span>
            </a>
        </li>
        <li class="" id="cobrosTab">
            <a href="#app" data-toggle="tab">
              <span class="hidden-xs">Visualización y parámetros</span>
              <span class="material-icons visible-xs">settings</span>
            </a>
        </li>
        <li>
          <a href="#outlets" class="navigate">
            <span class="hidden-xs">Editar sucursales</span>
            <span class="material-icons visible-xs">store</span>
          </a>
        </li>
        <li class="" id="quotesTab">
            <a href="#printTemplates" data-toggle="tab" id="printTemplatesBtn">
              <span class="hidden-xs">Plantillas de Impresión</span>
              <span class="material-icons visible-xs">print</span>
            </a>
        </li>
    </ul>

    <section class="panel r-24x">
      <div class="panel-body table-responsive">
    
        <form action="<?=$baseUrl?>?action=update&type=setting" method="POST" id="editSetting" name="editSetting">
          <div class="tab-content bg-white">
              
            <div class="tab-pane active wrapper" id="general">
              <div class="h2 font-bold m-b-lg"><div class="text-sm font-default">Perfil de la</div>Empresa</div>

                <div class="row">

                  <div class="col-sm-6">
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Dirección Física</label>
                      <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="address" value="<?=$_cmpSettings['settingAddress']?>" autocomplete="off" />
                    </div>
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Email de la empresa</label>
                      <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="email" value="<?=$_cmpSettings['settingEmail']?>" autocomplete="off" />
                    </div>
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Nombre, Razón Social o Razón Comercial</label>
                      <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="billingName" value="<?=$_cmpSettings['settingBillingName']?>" autocomplete="off" />
                    </div>
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold"><?=TIN_NAME?></label>
                      <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="ruc" value="<?=$_cmpSettings['settingRUC']?>" autocomplete="off" />
                    </div>
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Información Extra (actividad comercial y datos tributarios)</label>
                      <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="billDetail" value="<?=iftn($_cmpSettings['settingBillDetail'])?>" autocomplete="off" />
                    </div>
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Sitio Web</label>
                      <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="website" value="<?=$_cmpSettings['settingWebSite']?>" autocomplete="off" />
                    </div>

                    <div class="col-xs-12 no-padder"> 
                      <?php
                      $social = json_decode($_cmpSettings['settingSocialMedia'],true);
                      ?>
                      <div class="col-xs-6">
                          <div class="input-group m-b"> 
                              <span class="input-group-addon"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/facebook.svg" height="15" class="img-circle"></span> 
                              <input type="text" class="form-control" placeholder="@perfil" value="<?=$social['facebook']?>" name="facebook"> 
                          </div> 
                      </div>
                      <div class="col-xs-6">
                          <div class="input-group m-b"> 
                              <span class="input-group-addon"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/instagram.svg" height="15"></span> 
                              <input type="text" class="form-control" placeholder="@perfil"  value="<?=$social['instagram']?>" name="instagram"> 
                          </div> 
                      </div>
                      <div class="col-xs-6">
                          <div class="input-group m-b"> 
                              <span class="input-group-addon"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/youtube.svg" height="15"></span> 
                              <input type="text" class="form-control" placeholder="@perfil" value="<?=$social['youtube']?>" name="youtube"> 
                          </div> 
                      </div>
                      <div class="col-xs-6">
                          <div class="input-group m-b"> 
                              <span class="input-group-addon"><img src="https://cdnjs.cloudflare.com/ajax/libs/simple-icons/3.0.1/twitter.svg" height="15"></span> 
                              <input type="text" class="form-control" placeholder="@perfil" value="<?=$social['twitter']?>" name="twitter"> 
                          </div> 
                      </div>                      
                   </div>

                    
                  </div>


                  <div class="col-sm-6">

                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Categoría</label>
                      <select name="category" class="form-control no-bg no-border b-b b-light" autocomplete="off" placeholder="">
                        <?php
                          foreach($companyCategories as $key => $val){
                              echo '<optgroup label="'.$key.'">';
                              foreach($val as $k => $v){
                                $selected = '';
                                if($_cmpSettings['settingCompanyCategoryId'] === $v){$selected = 'selected="selected"';}
                                echo '<option value="'.$v.'" '.$selected.'>'.$k.'</option>';
                              }
                              echo '</optgroup">';
                          }
                          ?>
                      </select>
                    </div>


                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Teléfonos</label>
                      <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="phone" value="<?=$_cmpSettings['settingPhone']?>"  autocomplete="off"/>
                    </div>
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Ciudad</label>
                      <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="city" value="<?=$_cmpSettings['settingCity']?>"  autocomplete="off"/>
                    </div>
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">País</label>
                      <select name="country" class="form-control no-bg no-border b-b b-light" autocomplete="off">
                          <?php
                          foreach($countries as $key => $val){
                              $selected = '';
                              if($_cmpSettings['settingCountry'] == $key){$selected = 'selected';}
                              echo '<option value="'.$key.'" '.$selected.'>'.$val['name'].'</option>';
                          }
                          ?>
                      </select>
                    </div>
                    <div class="form-group">
                      <label class="text-xs text-u-c font-bold">Idioma</label>
                      <select name="language" class="form-control no-bg no-border b-b b-light" autocomplete="off" disabled="disabled">
                        <?php
                          foreach($languages as $key => $value){
                              $selected = '';
                              //if($_cmpSettings['settingLanguage'] == $value){$selected = 'selected';}
                              echo '<option value="'.$value.'" '.$selected.'>'.$key.'</option>';
                          }
                        ?>
                      </select>
                    </div>
                    <div class="form-group">     
                      <label class="text-xs text-u-c font-bold">Zona Horaria</label>               
                      
                        <?php
                          $currentTimeZ = $_cmpSettings['settingTimeZone'];
                          $timezones = array();
                          foreach ($regions as $name => $mask)
                          {
                              $zones = DateTimeZone::listIdentifiers($mask);
                              foreach($zones as $timezone)
                              {
                              // Lets sample the time there right now
                              $time = new DateTime(NULL, new DateTimeZone($timezone));

                              // Us dumb Americans can't handle millitary time
                              $ampm = $time->format('H') > 12 ? ' ('. $time->format('g:i a'). ')' : '';

                              // Remove region name and add a sample time
                              $timezones[$name][$timezone] = substr($timezone, strlen($name) + 1) . ' - ' . $time->format('H:i') . $ampm;
                            }
                          }

                          ?>

                          <select name="timeZone" class="form-control no-bg no-border b-b b-light"  autocomplete="off">
                          <?php
                            foreach($timezones as $region => $list)
                            {
                              echo '<optgroup label="' . $region . '">' . "\n";
                              foreach($list as $timezone => $name)
                              {
                                $selected = '';
                                if($currentTimeZ == $timezone){$selected = 'selected';}
                                //echo $currentTimeZ.' '.$timezone;
                                echo '<option value="' . $timezone . '" '.$selected.'>' . $name . '</option>' . "\n";
                              }
                              echo '<optgroup>' . "\n";
                            }
                            echo '</select>';
                          ?>
                        </select>
                    </div>

                    <div class="col-xs-12">
                      <div class="onesignal-customlink-container"></div>
                    </div>

                  </div>
                  
                </div>
            </div>

            <div class="tab-pane wrapper" id="app">
              
                <div class="row clear">
                  <div class="h1 font-bold m-b-lg m-l"><div class="text-sm font-default">Visualización y</div> parámetros</div>
                  
                  <div class="col-xs-12 m-b m-t-md font-bold text-u-c">

                    <div class="col-xs-12 h4 m-b font-bold text-u-c">
                      General
                      <a href="#" class="btn clicker pull-right" data-type="toggle" data-target="#settingsGeneralsBlock"><i class="material-icons">expand_more</i></a>
                    </div>

                    <div class="col-xs-12 no-padder animated fadeIn speed-4x" id="settingsGeneralsBlock">

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Utilizar decimales <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Active esta opción si la moneda de su país utiliza decimales">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <?=switchIn('decimal',(($_cmpSettings['settingDecimal']=='yes')?true:false))?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Ignorar ventas internas en reportes <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Excluye de los reportes las ventas etiquetadas como Internas.">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          echo switchIn('ignoreInternal',(validity($_fullSettings['ignoreInternal']) ? true : false));
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Forzar conteo de stock a ciegas <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          echo switchIn('stockCountBlind',(validity($_fullSettings['stockCountBlind']) ? true : false));
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Moneda</div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="currency" value="<?=$_cmpSettings['settingCurrency']?>"  autocomplete="off"/>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Separador de miles</div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <select name="thousandSeparator" class="form-control no-bg no-border b-b b-light" autocomplete="off">
                            <?php
                              foreach($thousandSeparator as $key => $value){
                                  $selected = '';
                                  if($_cmpSettings['settingThousandSeparator'] == $value){$selected = 'selected';}
                                  echo '<option value="'.$value.'" '.$selected.'>'.$key.'</option>';
                              }
                              ?>
                          </select>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Nombre del Impuesto (IVA, VAT, TAX, etc)</div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="taxName" value="<?=$_cmpSettings['settingTaxName']?>"  autocomplete="off"/>
                        </div>
                      </div>
                    
                      <div class="col-xs-12 wrapper b-b">
                        <?php 
                        $tax = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "tax" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC',[],false,true);
                        ?>
                        <div class="col-xs-12 no-padder m-t-xs m-b-xs">
                          <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Valores de impuestos Ej: 5%, 10%, 12.5%, 21%</div>
                          <div class="col-sm-4 col-xs-12 no-padder text-right">
                            <select id="taxAdd" name="tax" tabindex="1" data-placeholder="Seleccione" class="form-control tax no-bg no-border b-b b-light" autocomplete="off">
                              <?php 
                              if($tax){
                                while (!$tax->EOF) {
                                  $taxId = enc($tax->fields['taxonomyId']);
                              ?>
                              <option value="<?=$taxId;?>"><?=$tax->fields['taxonomyName'];?></option>
                              <?php 
                                    $tax->MoveNext(); 
                                  }
                                  $tax->Close();
                                }
                              ?>
                            </select>

                            <div class="col-xs-12 no-padder m-t-xs">
                              <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="tax" data-valtype="num" title="Agregar"><i class="material-icons">add</i></a>
                              <a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="tax" data-valtype="num" data-select="taxAdd" title="Editar"><i class="material-icons">create</i></a>
                              <a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="tax" data-valtype="num" data-select="taxAdd" title="Remover"><i class="material-icons text-danger">close</i></a>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Nombre del código de identificación tributaria: (RUT,TIN,RUC,CUIT,etc)</div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="tin" value="<?=$_cmpSettings['settingTIN']?>"  autocomplete="off"/>
                        </div>
                      </div>   

                      <div class="col-xs-12 wrapper b-b">
                        <?php 
                        $tC = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "transactionCategory" AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC',[],false,true);
                        ?>
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Plan de cuentas (Egresos) <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Cree métodos de pagos personalizados que se ajusten a sus necesidades">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <select id="transactionCategory" name="transactionCategory" tabindex="1" data-placeholder="Seleccione" class="form-control transactionCategory no-bg no-border b-b b-light" autocomplete="off">
                            
                            <?php 
                            if($tC){
                              while (!$tC->EOF) {
                                $tCId = enc($tC->fields['taxonomyId']);
                            ?>
                                <option value="<?=$tCId;?>"><?=$tC->fields['taxonomyName'];?></option>
                            <?php 
                              $tC->MoveNext(); 
                              }
                              $tC->Close();
                            }
                            ?>
                          </select>

                          <div class="col-xs-12 no-padder m-t-xs">
                            <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="transactionCategory" title="Agregar"><i class="material-icons">add</i></a>
                            <a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="transactionCategory" data-select="transactionCategory" title="Editar"><i class="material-icons">create</i></a>
                            <a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="transactionCategory" data-select="transactionCategory" title="Remover"><i class="material-icons text-danger">close</i></a>
                          </div>         
                        </div>                        
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Categorías de Artículos</div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <?php 
                          echo selectInputCategory(false,false,'category no-bg no-border b-b b-light searchSimple needsclick','category',true);
                          ?>
                          <div class="col-xs-12 no-padder m-t-xs">
                            <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="category" title="Crear"  data-toggle="tooltip" data-placement="top"><i class="material-icons">add</i></a>
                            <a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Renombrar"  data-toggle="tooltip" data-placement="top"><i class="material-icons">create</i></a>
                            <a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="category" data-select="category" title="Remover"  data-toggle="tooltip" data-placement="top"><i class="material-icons text-danger">close</i></a>
                            <a href="#" class="sortCategoriesBtn btn btn-sm bg-light lter" title="Ordenar Categorías"  data-toggle="tooltip" data-placement="top"><i class="material-icons">sort</i></a>
                          </div>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Monedas <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Configurar Monedas">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">                        
                          <a href="#" class="setCurrenciesBtn btn btn-block btn-rounded bg-light lter text-u-c font-bold">Configurar</a>
                        </div>
                      </div>

                    </div>

                    <div class="col-xs-12 h4 m-b m-t-lg font-bold text-u-c">
                      Caja
                      <a href="#" class="btn clicker pull-right" data-type="toggle" data-target="#settingsRegisterBlock"><i class="material-icons">expand_more</i></a>
                    </div>

                    <div class="col-xs-12 no-padder animated fadeIn speed-4x" id="settingsRegisterBlock">

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Vender artículos fuera de stock <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Habilita la posibilidad de vender artículos que se encuentren fuera de stock">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <?=switchIn('sellsoldout',( ($_cmpSettings['settingSellSoldOut']=='yes') ? true : false ))?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Agrupar artículos <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Si se activa, al seleccionar varias veces un mismo artículo en la caja registradora, este sumará sus unidades en vez de añadirse como un artículo nuevo en la lista">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <?=switchIn('itemSerialized',(($_cmpSettings['settingItemSerialized']>0)?true:false))?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Apertura y cierre de cajas por email <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Envía un email notificando la apertura y cierre de caja">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                        echo switchIn('drawerEmail',(($_cmpSettings['settingDrawerEmail']>0)?true:false));
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Control de caja a ciegas <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Habilita o inhabilita la posibilidad de visualizar montos y métodos de pago en el control de caja. Útil para que los cajeros no sepan cuanto se vendió, los métodos de pago ni con cuanto dinero se realizó la apertura de caja">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          
                           echo switchIn('drawerBlind',(($_cmpSettings['settingDrawerBlind']>0)?true:false));
                          
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Eliminar impuestos <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Permite eliminar los impuestos de la venta"></i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          
                            echo switchIn('settingRemoveTaxes',(($_cmpSettings['settingRemoveTaxes']>0)?true:false));
                          
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Identificadores de métodos de pago <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Al momento de realizar una venta, permitirá ingresar un ID de los siguiente métodos de pago: T. de Crédito, T. de Débito y Cheque. Útil para poder asociar cheques o comprobantes de tarjetas con las ventas en el sistema">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          
                            echo switchIn('paymentId',(($_cmpSettings['settingPaymentMethodId']>0)?true:false));
                          
                        ?>
                          
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Líneas de crédito <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Esta opción permite habilitar una linea de crédito para cada cliente, si su línea de crédito está en cero no podrá realizar ventas a crédito a este cliente.">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          echo switchIn('creditLine',(validity($_cmpSettings['settingForceCreditLine']) ? true : false));
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Crédito Interno <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Habilita la opción de vender crédito a tus clientes, especialmente útil cuando dan pagos por adelantado">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          echo switchIn('storeCredit',(validity($_cmpSettings['settingStoreCredit']) ? true : false));
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Bloquear numeraciones repetidas <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Activa esta opción para que el sistema verifique si el número de documento actual ya fue utilizado anteriormente.">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          echo switchIn('blockUsedDocNo',(validity($_fullSettings['blockUsedDocNo']) ? true : false));
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Enviar comprobantes al cliente automáticamente <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Si activa esta opción el sistema enviará automáticamente comprobantes, recibos, cotizaciones, etc. a sus clientes si tienen añadido un email o nro. de celular.">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          echo switchIn('autoSendDocs',(validity($_fullSettings['autoSendDocs']) ? true : false));
                        ?>
                        </div>
                      </div>

                      <?php
                      if(COUNTRY == 'PY'){
                      ?>
                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Decreto 3881 <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Régimen especial para Gastronomía, alojamiento en hoteles, turismo y otros.">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          echo switchIn('taxPy',(validity($_fullSettings['taxPy']) ? true : false));
                        ?>
                        </div>
                      </div>
                      <?php
                      }
                      ?>

                      <div class="col-xs-12 wrapper b-b hidden">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Registrar artículos eliminados <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Los artículos eliminados en una venta quedarán registrados en el panel con su justificación">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                        <?php
                          echo switchIn('deletedItemsHistory',(validity($_fullSettings['deletedItemsHistory']) ? true : false));
                        ?>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Límite de artículos por venta</div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <input type="text" class="form-control no-bg no-border b-b b-light" placeholder="" name="itemsSaleLimit" value="<?=$_cmpSettings['settingItemsSaleLimit']?>"  autocomplete="off"/>
                        </div>
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <?php 
                        $tag = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "tag" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC',[],false,true);
                        ?>
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Etiquetas <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Útiles para enriquecer las ventas con información específica, ej. Delivery, pickup, cambio, devolución, etc">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <select id="tagAdd" name="tag" tabindex="1" data-placeholder="Seleccione" class="form-control tag no-bg no-border b-b b-light" autocomplete="off">
                            <?php 
                            if($tag){
                            while (!$tag->EOF) {
                              $tagId = enc($tag->fields['taxonomyId']);
                            ?>
                            <option value="<?=$tagId;?>"><?=$tag->fields['taxonomyName'];?></option>
                            <?php 
                              $tag->MoveNext(); 
                              }
                              $tag->Close();
                            }
                            ?>
                          </select>
                          <div class="col-xs-12 no-padder m-t-xs">
                            <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="tag" title="Agregar"><i class="material-icons">add</i></a>
                            <a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="tag" data-select="tagAdd" title="Editar"><i class="material-icons">create</i></a>
                            <a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="tag" data-select="tagAdd" title="Remover"><i class="material-icons text-danger">close</i></a>
                          </div>
                        </div>

                      </div>
                     
                      <div class="col-xs-12 wrapper b-b">
                        <?php 
                        $pM = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "paymentMethod" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC',[],false,true);
                        
                        ?>
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Métodos de Pago <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Cree métodos de pagos personalizados que se ajusten a sus necesidades">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <select id="paymentMAdd" name="paymentMethod" tabindex="1" data-placeholder="Seleccione" class="form-control paymentMethod no-bg no-border b-b b-light" autocomplete="off">
                            
                            <?php 
                            if($pM){
                              while (!$pM->EOF) {
                                $pMId = enc($pM->fields['taxonomyId']);
                            ?>
                            <option value="<?=$pMId;?>"><?=$pM->fields['taxonomyName'];?></option>
                            <?php 
                                 $pM->MoveNext(); 
                                }
                                $pM->Close();
                              }
                            ?>
                          </select>

                          <div class="col-xs-12 no-padder m-t-xs">
                            <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="paymentMethod" title="Agregar"><i class="material-icons">add</i></a>
                            <a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="paymentMethod" data-select="paymentMAdd" title="Editar"><i class="material-icons">create</i></a>
                            <a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="paymentMethod" data-select="paymentMAdd" title="Remover"><i class="material-icons text-danger">close</i></a>
                          </div>
                        </div>                        
                      </div>

                      <div class="col-xs-12 wrapper b-b">
                        <?php 
                        $pM = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "bankName" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC',[],false,true);
                        ?>
                        <div class="col-sm-8 col-xs-12 no-padder m-t-sm">Bancos (Recepción de cheques) <i class="material-icons text-muted m-l-xs pointer md-16" data-toggle="tooltip" data-placement="top" title="Cree métodos de pagos personalizados que se ajusten a sus necesidades">help_outline</i></div>
                        <div class="col-sm-4 col-xs-12 no-padder text-right">
                          <select id="bankCAdd" name="bankName" tabindex="1" data-placeholder="Seleccione" class="form-control bankName no-bg no-border b-b b-light" autocomplete="off">
                            
                            <?php 
                            if($pM){
                              while (!$pM->EOF) {
                                $pMId = enc($pM->fields['taxonomyId']);
                            ?>
                            <option value="<?=$pMId;?>"><?=$pM->fields['taxonomyName'];?></option>
                            <?php 
                                 $pM->MoveNext(); 
                                }
                                $pM->Close();
                              }
                            ?>
                          </select>

                          <div class="col-xs-12 no-padder m-t-xs">
                            <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="bankName" title="Agregar"><i class="material-icons">add</i></a>
                            <a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="bankName" data-select="bankCAdd" title="Editar"><i class="material-icons">create</i></a>
                            <a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="bankName" data-select="bankCAdd" title="Remover"><i class="material-icons text-danger">close</i></a>
                          </div>         
                        </div>                      
                      </div>

                    </div>

                  </div>

                </div>  
            </div>

            <div class="tab-pane no-padder col-xs-12" id="printTemplates">
              <div class="col-xs-12 m-b-md">

                <div class="col-sm-2 no-padder">
                  <div class="btn-group">
                    <button class="btn b bg-white btn-block btn-rounded text-u-c font-bold dropdown-toggle" data-toggle="dropdown"><span class="m-r-sm">Plantillas</span><span class="caret"></span></button>
                    <ul class="dropdown-menu" id="loadTemplatesList"></ul>
                  </div>
                </div>

                <div class="col-sm-4">
                  <input type="text" id="templateName" class="form-control text-left no-border b-b b-light no-bg font-bold" name="name" placeholder="Nombre de la Plantilla" value="" autocomplete="off">
                </div>

                <div class="col-sm-6 text-left no-padder">
                  <a id="viewTemplate" class="btn m-r" href="#">Probar</a>
                  <a id="" class="btn btn-info btn-rounded saveTemplate text-u-c font-bold" href="#">Guardar Plantilla</a>
                  <a data-dupli="true" class="btn btn-default saveTemplate hidden" id="duplicateTemplate" href="#">Duplicar Plantilla</a>
                  <input type="hidden" value="" id="templateId">

                  <a id="deleteTemplate" class="m-t pull-right hidden" href="#"><span class="text-danger">Eliminar Plantilla</span></a>
                </div>

              </div>
              
              <div id="templateBuilderTools" class="col-sm-2 col-xs-12 no-padder">

                  <div class="col-xs-12 wrap-l-n wrap-r">
                    <section class="panel panel-default">
                      <header class="panel-heading bg-light no-border text-u-c">
                        Herramientas 
                        <ul class="nav nav-pills pull-right">  <li><a href="#" data-type="toggle" class="clicker" data-target="#collapseOtros"><i class="material-icons">keyboard_arrow_down</i></a></li> </ul>
                      </header>
                      <div class="list-group no-radius alt collapse" id="collapseOtros">
                        <a class="addField list-group-item" data-type="custom" data-default="" href="#" data-toggle="tooltip" data-placement="right" title="Puede ingresar un texto personalizado, especialmente útil para títulos">Texto Personalizado</a>
                        <a class="addField list-group-item receiptableN" data-type="hor_line" data-default="" href="#" data-toggle="tooltip" data-placement="right" title="Inserte una línea horizontal">Línea Horizontal</a>

                        <span class="list-group-item">
                          <div class="btn-group">
                            <button class="btn btn-default dropdown-toggle" data-toggle="dropdown"><span class="m-r-sm" id="pageSizeTitle">Formato: A4 <span class="text-muted text-sm">(Vertical)</span></span><span class="caret"></span></button>
                            <ul class="dropdown-menu">
                              <li class="pFormat" data-size="a4page"><a href="#"><b>A4</b> <span class="pull-right text-muted"><i class="material-icons">screen_rotation</i></span></a></li>
                              <li class="pFormat" data-size="legalpage"><a href="#"><b>Legal</b> <span class="pull-right text-muted hidden"><i class="material-icons">screen_rotation</i></a></li>
                              <li class="pFormat" data-size="letterpage"><a href="#"><b>Carta</b> <span class="pull-right text-muted hidden"><i class="material-icons">screen_rotation</i></a></li>
                              <li class="pFormat" data-size="receipt80"><a href="#"><b>Roll 80mm</b></a></li>
                              <li class="pFormat" data-size="receipt76"><a href="#"><b>Roll 76mm</b></a></li>
                              <li class="pFormat" data-size="receipt57"><a href="#"><b>Roll 57mm</b></a></li>
                            </ul>
                          </div>
                        </span>
                        <span class="list-group-item">
                          <div class="btn-group hiddenTicket">
                            <button class="btn btn-default dropdown-toggle" data-toggle="dropdown">Fuente <span class="caret"></span></button>
                            <ul class="dropdown-menu">
                              <li class="pFamily" data-size="Arial"><a href="#!"><span style="font-family: Arial;">Arial</span></a></li>
                              <li class="pFamily" data-size="Courier New"><a href="#!"><span style="font-family: Courier New;">Courier New</span></a></li>
                              <li class="pFamily" data-size="Times New Roman"><a href="#!"><span style="font-family: Times New Roman;">Times New Roman</span></a></li>
                              <li class="pFamily" data-size="Comic Sans MS"><a href="#!"><span style="font-family: Comic Sans MS;">Comic Sans MS</span></a></li>
                              <li class="pFamily" data-size="Trebuchet MS"><a href="#!"><span style="font-family: Trebuchet MS;">Trebuchet MS</span></a></li>
                              <li class="pFamily" data-size="Verdana"><a href="#!"><span style="font-family: Verdana;">Verdana</span></a></li>
                              <li class="pFamily" data-size="dotmatrix"><a href="#!"><span style="font-family: dotmatrix;">Dot Matrix</span></a></li>
                              <li class="pFamily" data-size="FakeReceipt-Regular"><a href="#!"><span style="font-family: 'fakereceipt', 'dotmatrix', monospace;">Fake Receipt</span></a></li>
                            </ul>
                          </div>
                          <div class="btn-group">
                            <button class="btn btn-default dropdown-toggle" data-toggle="dropdown">Tamaño <span class="caret"></span></button>
                            <ul class="dropdown-menu">
                              <li class="pSize" data-size="4pt"><a href="#!"><span style="font-size: 4pt;">4pt</span></a></li>
                              <li class="pSize" data-size="5pt"><a href="#!"><span style="font-size: 5pt;">5pt</span></a></li>
                              <li class="pSize" data-size="6pt"><a href="#!"><span style="font-size: 6pt;">6pt</span></a></li>
                              <li class="pSize" data-size="7pt"><a href="#!"><span style="font-size: 7pt;">7pt</span></a></li>
                              <li class="pSize" data-size="8pt"><a href="#!"><span style="font-size: 8pt;">8pt</span></a></li>
                              <li class="pSize" data-size="10pt"><a href="#!"><span style="font-size: 10pt;">10pt</span></a></li>
                              <li class="pSize hiddenTicket" data-size="12pt"><a href="#!"><span style="font-size: 12pt;">12pt</span></a></li>
                              <li class="pSize hiddenTicket" data-size="14pt"><a href="#!"><span style="font-size: 14pt;">14pt</span></a></li>
                              <li class="pSize hiddenTicket" data-size="16pt"><a href="#!"><span style="font-size: 16pt;">16pt</span></a></li>
                              <li class="pSize hiddenTicket" data-size="18pt"><a href="#!"><span style="font-size: 18pt;">18pt</span></a></li>
                              <li class="pSize hiddenTicket" data-size="20pt"><a href="#!"><span style="font-size: 20pt;">20pt</span></a></li>
                              <li class="pSize hiddenTicket" data-size="24pt"><a href="#!"><span style="font-size: 24pt;">24pt</span></a></li>
                              <li class="pSize hiddenTicket" data-size="30pt"><a href="#!"><span style="font-size: 30pt;">30pt</span></a></li>
                            </ul>
                          </div>
                          <a href="#!" class="btn btn-default pUpper" data-size="uppercase"><i class="material-icons">format_size</i></a>
                        </span>

                        <textarea id="jsonTemplateField" class="form-control"></textarea>
                        
                        <div id="preview" class="hidden"></div>

                        <span class="list-group-item hidden">
                          <div class="input-group m-b"> <input type="text" id="ticketLeftMargin" class="form-control maskInteger receiptableY" placeholder="Margen izquierdo"> <span class="input-group-addon">mm</span> </div>
                        </span>
                        
                      </div>
                    </section>

                    <section class="panel panel-default">
                      <header class="panel-heading bg-light no-border text-u-c">
                        Empresa 
                        <ul class="nav nav-pills pull-right">  <li><a href="#!" data-type="toggle" class="clicker" data-target="#collapseEmpresa"><i class="material-icons">keyboard_arrow_down</i></a></li> </ul>
                      </header>
                      <div class="list-group no-radius alt collapse" id="collapseEmpresa">
                        <a class="addField list-group-item" data-type="company_logo" data-default="<?=imageToBase64(companyLogo(150))?>" href="#">Logo</a>
                        <a class="addField list-group-item" data-type="company_logo" data-default="<?=imageToBase64(companyLogo(150,true))?>" href="#">Logo (B&W)</a>
                        <a class="addField list-group-item" data-type="company_name" data-default="<?=$_cmpSettings['settingName']?>" href="#">Nombre</a>
                        <a class="addField list-group-item" data-type="company_billing_name" data-default="<?=$_cmpSettings['settingBillingName']?>" href="#">Razón Social</a>
                        <a class="addField list-group-item" data-type="company_tin" data-default="<?=$_cmpSettings['settingRUC']?>" href="#"><?=TIN_NAME?> de la empresa</a>
                        <a class="addField list-group-item" data-type="company_address" data-default="<?=$_cmpSettings['settingAddress']?>" href="#">Dirección</a>
                        <a class="addField list-group-item" data-type="company_email" data-default="<?=$_cmpSettings['settingEmail']?>" href="#">Email</a>
                        <a class="addField list-group-item" data-type="company_website" data-default="<?=$_cmpSettings['settingWebSite']?>" href="#">Sitio Web</a>

                        <a class="addField list-group-item" data-type="outlet_name" data-default="Nombre de Sucursal" href="#">Nombre de Sucursal</a>
                        <a class="addField list-group-item" data-type="outlet_address" data-default="Dirección de Sucursal" href="#">Dirección de Sucursal</a>
                        <a class="addField list-group-item" data-type="outlet_phone" data-default="Teléfono de Sucursal" href="#">Teléfono de Sucursal</a>

                        <a class="addField list-group-item" data-type="register_name" data-default="Caja Registradora" href="#">Caja Registradora</a>
                        <a class="addField list-group-item" data-type="printer_name" data-default="Impresora" href="#">Impresora</a>

                        <a class="addField list-group-item" data-type="auth_number" data-default="######" href="#">No. Timbrado o Autorización</a>
                        <a class="addField list-group-item" data-type="auth_start_date" data-default="####-##-##" href="#">Inicio de Timbrado</a>
                        <a class="addField list-group-item" data-type="auth_expiration" data-default="####-##-##" href="#">Fin de Timbrado</a>

                        <a class="addField list-group-item" data-type="user_name" data-default="Usuario" href="#">Usuario</a>
                      </div>
                    </section>

                    <section class="panel panel-default">
                      <header class="panel-heading bg-light no-border text-u-c">
                          Cliente
                        <ul class="nav nav-pills pull-right">  <li><a href="#!" data-type="toggle" class="clicker" data-target="#collapseCliente"><i class="material-icons">keyboard_arrow_down</i></a></li> </ul>
                      </header>
                      <div class="list-group no-radius alt collapse" id="collapseCliente">
                        <a class="addField list-group-item" data-type="customer_name" data-default="Razón Social" href="#">Razón Social</a>
                        <a class="addField list-group-item" data-type="customer_full_name" data-default="Nombre y Apellido" href="#">Nombre y Apellido</a>
                        <a class="addField list-group-item" data-type="customer_tin" data-default="<?=TIN_NAME?>" href="#"><?=TIN_NAME?></a>
                        <a class="addField list-group-item" data-type="customer_ci" data-default="#######" href="#">Doc. de Identidad</a>
                        <a class="addField list-group-item" data-type="customer_address" data-default="Dirección 1 del cliente" href="#">Dirección 1</a>
                        <a class="addField list-group-item" data-type="customer_address_2" data-default="Dirección 2 del cliente" href="#">Dirección 2</a>
                        <a class="addField list-group-item" data-type="customer_location" data-default="Localidad" href="#">Localidad</a>
                        <a class="addField list-group-item" data-type="customer_city" data-default="Ciudad" href="#">Ciudad</a>
                        <a class="addField list-group-item" data-type="customer_country" data-default="País" href="#">País</a>
                        <a class="addField list-group-item" data-type="customer_phone" data-default="Teléfono 1 del Cliente" href="#">Teléfono 1</a>
                        <a class="addField list-group-item" data-type="customer_phone_2" data-default="Teléfono 2 del Cliente" href="#">Teléfono 2</a>
                        <a class="addField list-group-item" data-type="customer_note" data-default="Nota del Cliente" href="#">Nota</a>
                        <a class="addField list-group-item" data-type="customer_loyalty" data-default="Loyalty Acumulado" href="#">Loyalty</a>
                        <a class="addField list-group-item" data-type="table_number" data-default="Mesa: ###" href="#">Nro. de Mesa</a>
                      </div>
                    </section>

                    <section class="panel panel-default">
                      <header class="panel-heading bg-light no-border text-u-c">
                          Transacción
                        <ul class="nav nav-pills pull-right">  <li><a href="#!" data-type="toggle" class="clicker" data-target="#collapseTransaccion"><i class="material-icons">keyboard_arrow_down</i></a></li> </ul>
                      </header>
                      <div class="list-group no-radius alt collapse" id="collapseTransaccion">
                        <a class="addField list-group-item" data-type="document_number" data-default="######" href="#">Documento No.</a>
                        <a class="addField list-group-item" data-type="document_prefix" data-default="######" href="#">Prefijo</a>
                        <a class="addField list-group-item" data-type="document_sufix" data-default="######" href="#">Sufijo</a>
                        <a class="addField list-group-item" data-type="document_type" data-default="Tipo de Documento" href="#">Tipo de Documento</a>
                        <a class="addField list-group-item" data-type="date" data-default="<?=TODAY?>" href="#">Fecha y Hora</a>
                        <a class="addField list-group-item" data-type="duedate" data-default="<?=TODAY?>" href="#">Fecha de Vencimiento</a>
                        <a class="addField list-group-item" data-type="discount" data-default="Descuento" href="#">Descuento</a>
                        <a class="addField list-group-item" data-type="subtotal" data-default="Subtotal" href="#">Subtotal</a>
                        <a class="addField list-group-item" data-type="tax_total" data-default="Total <?=TAX_NAME?>" href="#">Total <?=TAX_NAME?></a>
                        <a class="addField list-group-item" data-type="total" data-default="Total" href="#">Total</a>
                        <a class="addField list-group-item" data-type="nums_to_words" data-default="Total en letras" href="#">Total en letras</a>
                        <a class="addField list-group-item" data-type="sale_type" data-default="Contado/Crédito" href="#">Contado/Crédito texto</a>
                        <a class="addField list-group-item" data-type="sale_type_contado" data-default="✕" href="#">✕ Venta al Contado</a>
                        <a class="addField list-group-item" data-type="sale_type_credit" data-default="✕" href="#">✕ Venta a Crédito</a>
                        <a class="addField list-group-item" data-type="payment_methods" data-default="Métodos de pago" href="#">Métodos de pago</a>
                        <a class="addField list-group-item" data-type="tags" data-default="Etiquetas" href="#">Etiquetas</a>
                        <a class="addField list-group-item" data-type="note" data-default="Nota" href="#">Nota</a>
                        <a class="addField list-group-item" data-type="transaction_id" data-default="ID" href="#">ID de transacción</a>
                        <a class="addField list-group-item hidden" data-type="transaction_id_barcode" data-default="#######" href="#">Código de Barras</a>
                        <?php 
                        $tax = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "tax" AND '.$SQLcompanyId.' ORDER BY taxonomyName ASC',[],false,true);
                        
                        if($tax){
                          while (!$tax->EOF) {
                            $taxId = enc($tax->fields['taxonomyId']);
                        ?>
                          <a class="addField list-group-item" data-type="tax_single" data-default="Total <?=TAX_NAME?> <?=$tax->fields['taxonomyName'];?>%" href="#">Total <?=TAX_NAME?> <?=$tax->fields['taxonomyName'];?>%</a>

                        <?php 
                              $tax->MoveNext(); 
                            }
                            $tax->Close();
                          }
                        ?>
                      </div>
                    </section>

                    <section class="panel panel-default">
                      <header class="panel-heading bg-light no-border text-u-c">
                          Artículos
                        <ul class="nav nav-pills pull-right">  <li><a href="#!" data-type="toggle" class="clicker" data-target="#collapseProductos"><i class="material-icons">keyboard_arrow_down</i></a></li> </ul>
                      </header>
                      <div class="list-group no-radius alt collapse" id="collapseProductos">
                        <a class="addField list-group-item hidden receiptableY" data-type="item_receipt" data-default="Listado de Venta" href="#">Listado de Venta</a>
                        <a class="addField list-group-item hidden receiptableY" data-type="item_receipt_5" data-default="Listado de Venta 2" href="#">List. de Venta 2</a>
                        <a class="addField list-group-item hidden receiptableY" data-type="item_receipt_4" data-default="Listado de Venta Sin <?=TAX_NAME?>" href="#">List. de Venta sin <?=TAX_NAME?></a>

                        <a class="addField list-group-item hidden receiptableY" data-type="item_receipt_2" data-default="Listado sin Precios" href="#">Listado sin Precios</a>
                        <a class="addField list-group-item hidden receiptableY" data-type="item_receipt_3" data-default="Listado Simple" href="#">Listado Simple</a>
                        <a class="addField list-group-item receiptableN" data-type="item_units" data-default="#####" href="#">Cantidad</a>
                        <a class="addField list-group-item receiptableN" data-type="item" data-default="Producto" href="#">Articulo</a>
                        <a class="addField list-group-item receiptableN" data-type="item_id" data-default="Código Interno" href="#">Código Interno</a>
                        <a class="addField list-group-item receiptableN" data-type="item_note" data-default="Nota del Artículo" href="#">Nota</a>
                        
                        <a class="addField list-group-item receiptableN" data-type="item_uid" data-default="SKU" href="#">SKU</a>
                        <a class="addField list-group-item receiptableN" data-type="item_tags" data-default="Etiquetas" href="#">Etiquetas</a>
                        <a class="addField list-group-item receiptableN" data-type="item_tax" data-default="<?=TAX_NAME?> %" href="#"><?=TAX_NAME?> %</a>
                        <a class="addField list-group-item receiptableN" data-type="item_taxAmount" data-default="<?=TAX_NAME?>" href="#"><?=TAX_NAME?></a>
                        <a class="addField list-group-item receiptableN" data-type="item_discount" data-default="##.###" href="#">Descuento</a>
                        <a class="addField list-group-item receiptableN" data-type="item_price" data-default="Precio" href="#">Precio</a>
                        <a class="addField list-group-item receiptableN" data-type="item_uni_price" data-default="Precio de lista" href="#">Precio de lista</a>
                        <a class="addField list-group-item receiptableN" data-type="item_price_notax" data-default="Precio sin <?=TAX_NAME?>" href="#">Precio sin <?=TAX_NAME?></a>
                        <a class="addField list-group-item receiptableN" data-type="item_total" data-default="Total" href="#">Total</a>
                        <?php 

                        $tax = ncmExecute('SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = "tax" AND ' . $SQLcompanyId . ' ORDER BY taxonomyName ASC',[],false,true);
                        
                        if($tax){
                          while (!$tax->EOF) {
                            $taxId = enc($tax->fields['taxonomyId']);
                        ?>
                          <a class="addField list-group-item receiptableN" data-type="item_taxAmount_single" data-default="{{TAX_SINGLE_<?=($tax->fields['taxonomyName'] ? $tax->fields['taxonomyName'] : '0');?>%}}" href="#">Artículo <?=$_cmpSettings['settingTaxName']?> <?=($tax->fields['taxonomyName'] ? $tax->fields['taxonomyName'] : '0');?>%</a>

                          <a class="addField list-group-item" data-type="item_subtotal" data-default="{{TAX_SUBTOTAL_<?=($tax->fields['taxonomyName'] ? $tax->fields['taxonomyName'] : '0');?>%}}" href="#">Suma <?=$_cmpSettings['settingTaxName']?> <?=($tax->fields['taxonomyName'] ? $tax->fields['taxonomyName'] : '0');?>%</a>
                        <?php 
                              $tax->MoveNext(); 
                            }
                            $tax->Close();
                          }
                        ?>
                      </div>
                    </section>
                  </div>
              </div>
              
              <div class="col-xs-12 col-sm-10 no-padder">
                <div id="wrapit" class="">
                  <div id="contentIt" class="a4page" style="">
                  </div>
                </div>
              

                <input type="hidden" id="pageType" value="a4page">
                <div id="my_mm" style="height:1mm;display:none"></div>
                <div id="guide-h" class="guide"></div>
                <div id="guide-v" class="guide"></div>
                <div id="guide2-h" class="guide"></div>
                <div id="guide2-v" class="guide"></div>
                <div id="guide3-h" class="guide"></div>
                <div id="guide3-v" class="guide"></div>
              </div>

            </div>

            <div class="wrapper bg-white" id="saveSettings">
                <input type="submit" class="btn btn-info btn-lg btn-rounded text-u-c all-shadows font-bold" value="Guardar Cambios" >
            </div>

          </div>
        </form>

      </div>
    </section>
  </section>
</section>
 
<script>
var baseUrl   = "<?=$baseUrl?>";
var tin_name  = '<?=TIN_NAME?>';

<?php
//if($_GET['update']){
  //ob_start();
?>
  var ncmMaths  = {
    parseFloatSafe : function(val){
      return parseFloat(val);
    }
  };

  $(() => {
    adm();

    submitForm('#editSetting',function(){

      masksCurrency($('.maskInteger'),thousandSeparator,'no');

      $('[data-toggle="tooltip"]').tooltip({
          tooltipClass:"bg-dark"
      });

      /*$.post("settings", function(data){
        if(data == 'true'){
          location.reload();
        }
          //var mainDiv = $(".loadPage", data); // finds <div id='mainDiv'>...</div>
          //thalog(mainDiv);
          //$(".loadPage section").html(mainDiv);

        }, "html");*/
    });

    ncmHelpers.onClickWrap('.toggleTools',function(event,tis){
      $('#templateBuilderTools, #templateBuilderToolsSM').toggle();
      $('#templateBuilderToolsCanvas').toggleClass('col-sm-10 col-sm-11');
    });

    $('#outlet').off('change').on('change',function(){
      $('#register').load('?view=registerlist&outlet=' + $(this).val());
    });

    ncmHelpers.onClickWrap('#uploadImgBtn',function(event,tis){
      $('#image').trigger('click');
    });

    ncmHelpers.onClickWrap('a.sortCategoriesBtn',function(event,tis){
      $.get(baseUrl + '?action=sortCategories',function(data){
        ncmListOrdering('sortCategoriesTable',$('#modalTiny .modal-content'),data);
        $('#modalTiny').modal('show');
        $("#sortCategoriesTable").on( "sortstop", function( event, ui ) {
          var update = [];
          $('table#sortCategoriesTable tbody tr').each(function(i,val){
            var tis   = $(this);
            var id    = tis.data('id');
            var pos   = i;
            update.push({id : id, sort : pos});
            ncmDialogs.toast('Guardado','success');
          });
          var send = btoa(JSON.stringify(update));
          $.get(baseUrl + '?action=sortCategories&update=' + send);
        });
      });
    });

    ncmHelpers.onClickWrap('a.setCurrenciesBtn',function(event,tis){
      $.get(baseUrl + '?action=setCurrencies',function(data){
        var table     = '<div class="col-xs-12 wrapper panel m-n" id="setCurrenciesList">' +
                        ' <div class="col-xs-12 text-center text-u-c font-bold m-b">Monedas</div>' + 
                        ' <table class="table bg-white m-n">' +
                        '   <tbody>';
        var flagsCDN  = 'https://cdnjs.cloudflare.com/ajax/libs/flag-icon-css/3.4.3/flags/1x1/';

        $.each(data,function(i,val){
          table +=  '<tr>' +
                    '   <td class="font-bold">' +
                    '     <div class="m-t-xs">' +
                    '       <img src="' + flagsCDN + val.ccode.toLowerCase() + '.svg" class="m-r-sm" width="20">' + val.code + 
                    '     </div>' +
                    '   </td>' +
                    '   <td>' +
                    '     <input class="form-control text-right" data-code="' + val.code + '" value="' + val.value + '">';

          if(val.value > 0){
            table +='     <div class="text-xs text-right currencyExp' + val.code + '">1 ' + currency + ' = ' + val.value + ' ' + val.code + '</div>';
          }
            table +='   </td>' +
                    '</tr>';
        });

        table       += '    </tbody>' +
                       '  </table>' +
                       '</div>';

        $('#modalTiny').modal('show');
        $('#modalTiny .modal-content').html(table);

        $('#setCurrenciesList input').off('change').on('change',function(){

          var allCur = [];

          $('#setCurrenciesList input').each(function(){
            var tis     = $(this);
            var value   = tis.val();
            var code    = tis.data('code');
            if(value > 0){
              allCur.push({'code' : code, 'value' : value});
            }
          });

          var send      = btoa( JSON.stringify(allCur) );
          $.get(baseUrl + '?action=setCurrencies&update=' + send,function(){
            ncmDialogs.toast('Guardado','success');
          });

        });

        $('#setCurrenciesList input').off('keyup').on('keyup',function(){
          var tis     = $(this);
          var value   = tis.val();
          var code    = tis.data('code');

          $('.currencyExp' + code).text('1 ' + window.currency + ' = ' + value + ' ' + code);
        });

        

      });
    });

    logoUpload();
    switchit(function(tis){
      if(tis.find('input').hasClass('loyaltySwitchClass')){
        $('.loyaltyConf').toggleClass('hidden');
      }
    },true);

    $('[data-toggle="tooltip"]').tooltip({
        tooltipClass:"bg-dark"
    });
    
    var opts = {
      readAsDefault: 'DataURL',
      dragClass : 'dker',
      on: {
        beforestart: function(){

        },
          load: function(e, file) {

            console.log(e.target.result);

            $('#contentIt').css({"background-image":"url(" + e.target.result + ")","background-position":"left top","background-repeat":"no-repeat","background-size":"100%"});
          }
      }
    };

    $('#contentIt').fileReaderJS(opts);

    var templateStarted = false;
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
      if($(this).attr('id') == 'printTemplatesBtn'){
        if(!templateStarted){
          spinner('body', 'show');
          templateBuilder.init();
          templateStarted = true;
        }
      }else if($(this).attr('id') == 'toolsTab'){
        masksCurrency($('.maskCurrency'),thousandSeparator,decimal);
        $('#loyaltyMin').on('formatted',function(){
          var valor = $(this).val();
          $('.currentLoyaltyMin').text(valor);
        });
        $('#loyaltyValue').on('formatted',function(){
          var valor = $(this).val();
          $('.currentLoyaltyVal').text(valor);
        });
      }
    });

    window.mm         = $('#my_mm').height();
    window.minHeight  = window.mm * 5;

  });

  //
  window.fontFamily = 'Arial';
  window.fontSize   = '8pt';
  window.fontCase   = 'none';
  window.receipt_left_margin = '7';
  window.isTicketConfig = false;

  var templateBuilder = {
    init: function(){
      templateBuilder.loadTemplatesList();
      spinner('body', 'hide');
    },
    buildJsonResult : function(tis, toSave){
        var arr     = templateBuilder.getPosition('.box');

        var dupli   = (tis)   ? tis.data('dupli') : false;
        var name    = $('input#templateName').val();
        name        = (dupli) ? name + ' [copia]' : name;
        var saveIt  = JSON.stringify({
                                      page_size           : window.paperSize,
                                      page_size_name      : $('#pageSizeTitle').html(),
                                      page_name           : name,
                                      page_font_family    : window.fontFamily,
                                      page_font_size      : window.fontSize,
                                      receipt_left_margin : window.receipt_left_margin,
                                      page_font_case      : window.fontCase,
                                      mm                  : ducumentPrintBuilder.findMm(),
                                      data                : arr
                                    });

        if(!toSave){
          $('textarea#jsonTemplateField').val($.trim( saveIt ));
        }

        return dupli;
    },
    loadTemplatesList: function(callback){
      spinner('#loadTemplatesList', 'show');
      $.get(baseUrl + '?action=loadTemplatesList',function(result){
        $('#loadTemplatesList').html(result);
        $('#contentIt').html('');
        $('#templateName').val('');
        $('#templateId').val('');
        $('#deleteTemplate, #duplicateTemplate').data('id','').addClass('hidden');
        templateBuilder.manageFields('.box');
        templateBuilder.events();
        templateBuilder.setPage();
        spinner('#loadTemplatesList', 'hide');
        callback && callback();
      });
    },
    manageFields: function(classs){
      $('#contentIt').selectable({
        start : () => {
          $(classs).resizable('disable');
        },
        stop  : () => {
          $(classs).resizable('enable');
        }
      });
      $(classs).resizable({
        handles     : window.isTicketConfig?'s':'se',
        helper      : "ui-resizable-helper",
        minHeight   : window.minHeight,
        autoHide    : true,
        grid        : [(mm),(mm)],
        start       : () => {
          templateBuilder.saveAlert(true);
        },
        stop        : () => {
          templateBuilder.saveAlert(true);
        }
      }).draggable({
        containment : '#contentIt',
        grid        : [(mm),(mm)],
        scroll      : true,
        delay       : 300,
        start       : function(event, ui){
          $('#guide-h,#guide-v,#guide2-h,#guide2-v,#guide3-h,#guide3-v').show();
          $(this).addClass('all-shadows').css('opacity','0.7').removeClass('bounceIn').find('.boxOps').hide();
          templateBuilder.saveAlert(true);

          if ($(this).hasClass("ui-selected")) {
            posTopArray   = [];
            posLeftArray  = [];
            $(".ui-selected").each(function (i) {
                posTopArray[i] = parseInt($(this).css('top'));
                posLeftArray[i] = parseInt($(this).css('left'));
            });
            begintop = $(this).offset().top;
            beginleft = $(this).offset().left;
          }
        },
        drag        : function(event, ui){
          var padd    = 21;
          var tTop    = $(this).position().top+padd;
          var tLeft   = $(this).position().left+padd;
          var oHeight = $(this).outerHeight();
          var oWidth  = $(this).outerWidth();

          $('#guide-h').css({'top'    : tTop});
          $('#guide-v').css({'left'   : tLeft});
          $('#guide2-h').css({'top'   : tTop+oHeight});
          $('#guide2-v').css({'left'  : tLeft+oWidth});
          $('#guide3-h').css({'top'   : (tTop+(oHeight/2))});
          $('#guide3-v').css({'left'  : (tLeft+(oWidth/2))});

          if ($(this).hasClass("ui-selected")) {
            var topdiff = $(this).offset().top - begintop;
            var leftdiff = $(this).offset().left - beginleft;
            $(".ui-selected").each(function (i) {
                $(this).css('top', posTopArray[i] + topdiff);
                $(this).css('left', posLeftArray[i] + leftdiff);
            });
          }

          templateBuilder.buildJsonResult();

        },
        stop        : function(){
          $('#guide-h,#guide-v,#guide2-h,#guide2-v,#guide3-h,#guide3-v').hide();
          $(this).removeClass('all-shadows').css('opacity','1').find('.boxOps').show();
          if(window.isTicketConfig){
            templateBuilder.setBoxContainerWidth();
          }

          $(".ui-selected").removeClass('lter');
          templateBuilder.buildJsonResult();
        }
      }).hover(function(){
        var newZindex = templateBuilder.getHighestZIndex()+1;
        $(this).css('z-index',newZindex).find('.boxOps').show();
        if(window.isTicketConfig){
          templateBuilder.setBoxContainerWidth();
        }
        if(!$(this).hasClass('ui-selected ui-selecting')){
          $(this).addClass('lter');
        }
      },function(){
        $(this).removeClass('lter').find('.boxOps').hide();
      });
    },
    boxTemplate: function(style,type,url,cont,align,size,family,bold,loading,textwrap){
      var rBCStyle = 'padding:0 20px 0 0;';
      if(type == 'company_logo'){
        url     = iftn(url,cont);
        cont    = '<img src="'+url+'" width="100%" height="100%">';
        style   = iftn(style,'width:20mm;height:20mm;');
      }else if(type == 'custom' && !loading){
        var custom = prompt("Ingrese un texto", "");
        if(custom) {
          cont = custom.replace("/\n","<br />");
          console.log(cont,custom);
        }else{
            return false;
        }
        console.log(cont,custom);
      }else if(type == 'hor_line'){
        cont    = '<div style="border-top:1px solid #c9d0d7; width:100%; min-width:40px;"></div>';
        rBCStyle = 'padding:1px 0;';
      }else if(type == 'ver_line'){
        cont    = '<div style="border-top:1px solid #000; width:100%;"></div>';
      }

      if(textwrap == 'wrap'){
        rBCStyle += 'text-overflow: clip!important; white-space: nowrap!important;overflow: hidden!important';
        var wrapIcon = 'wrap_text';
      }else{
        rBCStyle += 'text-overflow: none!important;white-space: wrap!important;overflow: none!important';
        var wrapIcon = 'short_text';
      }

      var out = '<div class="box bg-light lter text-dark r-2x b b-light animated bounceIn" style="'+style+'" data-type="'+type+'" data-url="'+url+'" data-textalign="'+align+'" data-fontsize="'+size+'" data-fontfamily="'+family+'" data-bold="'+bold+'" data-textwrap="'+textwrap+'">'+
              ' <div class="realBlockContent" style="'+rBCStyle+'">'+cont+'</div>'+
              ' <span class="boxOps col-xs-12 bg-dark lter wrapper-sm r-3x text-center animated fadeIn animatedx4">'+
              '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default m-b-xs boxRemove">'+
              '     <i class="material-icons text-danger">delete_outline</i>'+
              '   </a>';
              if(type != 'company_logo' || type != 'hor_line' || type != 'ver_line'){
                out +=  '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxFont m-b-xs hiddenTicket">'+
                        '     <i class="material-icons">format_size</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxFamily m-b-xs hiddenTicket">'+
                        '     <i class="material-icons">text_format</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxBold m-b-xs hiddenTicket">'+
                        '     <i class="material-icons">format_bold</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxAlign m-b-xs">'+
                        '     <i class="material-icons">format_align_'+align+'</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxClone m-b-xs">'+
                        '     <i class="material-icons">content_copy</i>'+
                        '   </a>'+
                        '   <a href="#" class="btn btn-rounded btn-sm btn-icon btn-default boxWrapText">'+
                        '     <i class="material-icons">'+wrapIcon+'</i>'+
                        '   </a>';
              }
      out +=  ' </span>'+
            '</div>';
      return out;
    },
    events: function(){
      onClickWrap('#viewTemplate',function(e,tis){
        var receiptConf = {};
        receiptConf.isTicket  = false;
        receiptConf.isHTML    = false;
        receiptConf.chars     = 0;
        receiptConf.space     = ' ';
        receiptConf.EOL       = '<br>';
        
        if(window.paperSize == 'receipt57' || window.paperSize == 'receipt76' || window.paperSize == 'receipt80'){
          receiptConf.isTicket  = window.paperSize;
          receiptConf.isHTML    = true;
          receiptConf.space     = ' ';

          if(window.paperSize == 'receipt57'){
            receiptConf.chars     = 35;
          }else if(window.paperSize == 'receipt76'){
            receiptConf.chars     = 42;
          }else{
            receiptConf.chars     = 50;
          }
        }
        
        ducumentPrintBuilder.config.TINname         = tin_name;
        ducumentPrintBuilder.config.NoCustomerName  = 'Sin nombre';
        ducumentPrintBuilder.config.NoCustomerTIN   = 'xxxxxx';
        var rows = ducumentPrintBuilder.build(templateBuilder.getPosition('.box'),false,receiptConf,true);

        if(!receiptConf.isTicket){
          var html = '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> @page{size:auto;margin:0;padding:0;border:0;}*{padding: 0; margin: 0;border:0;font-family:'+window.fontFamily+'!important;font-size:'+window.fontSize+'!important; color: black;}</style></head><body>' +rows+ '</body></html>';
        }else{
          if(receiptConf.isHTML){
            var loadFonts       =   '@font-face {' +
                                      ' font-family: "dotmatrix";' +
                                      ' src: local("Dot Matrix"), local("dotmatrix"), url("' + window.masterUrl + 'fonts/dotmatrix.ttf") format("truetype");' +
                                    '}' +
                                    '@font-face {' +
                                    '   font-family: "fakereceipt";' +
                                    '   src: local("FakeReceipt-Regular"),local("fakereceipt"), url(https://ncmaspace.nyc3.cdn.digitaloceanspaces.com/app/fonts/fakereceipt.woff) format("woff"), url(' + window.masterUrl + 'fonts/fakereceipt.ttf) format("truetype");' +
                                    '}';
            var html = '<html><head><meta charset="utf-8"> <style type="text/css" media="print"> ' + loadFonts + '@page{size:auto;margin:0;padding:0;border:0;}pre,*{padding: 0; margin: 0;border:0;font-family:' + window.fontFamily + '!important; font-size:'+window.fontSize+'!important; text-transform: uppercase!important;}</style></head><body><pre style="margin-left:'+window.receipt_left_margin+'mm;">' +rows+ '</pre></body></html>';
          }else{
            var html = rows;
          }
        }

        templateBuilder.printAction(html);

      });

      $('#ticketLeftMargin').keyup(function(){
        window.receipt_left_margin = parseFloat($(this).val());
      });

      $('#templateName').on('change',() => {
        templateBuilder.saveAlert(true);
      });

      $('textarea#jsonTemplateField').off('change paste').on('change paste',() => {
        //templateBuilder.saveAlert(true);

        var data = $.parseJSON( $('textarea#jsonTemplateField').val() );
        templateBuilder.buildTemplateView(data);
        console.log('textarea changed',data);
      });
      
      onClickWrap('.saveTemplate',function(e,tis){

        var dupli   = templateBuilder.buildJsonResult(tis, true);

        var saveIt  = $('textarea#jsonTemplateField').val();
        var name    = $('input#templateName').val();

        var id      = (!dupli) ? $('#templateId').val() : '';
        id          = iftn(id,'','&i=' + id);

        spinner('body', 'show');
        var post    = $.post(baseUrl + '?action=saveTemplate' + id, { data: saveIt, name: name  } );

        post.done((result) => {
          if(result == 'true'){
            ncmDialogs.toast('Plantilla guardada','success');
            $('#contentIt').html('');
            templateBuilder.loadTemplatesList(function(){
              templateBuilder.setPage(false,false);
              templateBuilder.saveAlert(false);
            });
          }else{
            ncmDialogs.toast('No se pudo guardar','danger');
          }
          spinner('body', 'hide');
        }).fail(() => {
          ncmDialogs.toast('No se pudo guardar','danger');
          spinner('body', 'hide');
        });
      });

      window.paperSize = (!window.paperSize)?'a4page':window.paperSize;
      onClickWrap('.pFormat',function(e,tis){
        var size  = tis.data('size');
        var title = 'Formato: '+tis.find('b').text();
        $('.pFormat span').addClass('hidden');
        
        if(window.paperSize == size && (size != 'receipt80' || size != 'receipt76' || size != 'receipt57')){
          size = size+'-h';
          title += ' <span class="text-muted text-sm">(Horizontal)</span>';
        }else{
          tis.find('span').removeClass('hidden');
          title += ' <span class="text-muted text-sm">(Vertical)</span>';
        }
        templateBuilder.saveAlert(true);
        templateBuilder.setPage(size,title);
      });

      onClickWrap('.addField',function(e,tis){
        window.scrollTo(0,0);
        var cont    = tis.data('default');
        var type    = tis.data('type');
        var style   = 'padding:0; min-height:'+window.minHeight+'px;';
        var url     = '';

        var toAppend = templateBuilder.boxTemplate(style,type,url,cont,'left','inherit','inherit','normal',false,'cut');

        $('#contentIt').append(toAppend);
        templateBuilder.manageFields('.box');
        templateBuilder.events();
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxRemove',function(e,tis){
        tis.closest('.box').draggable().remove();
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxFont',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('fontsize');
        var base    = 'inherit';
        var arr     = [
                        base,
                        '10pt',
                        '12pt',
                        '14pt',
                        '16pt',
                        '18pt',
                        '20pt',
                        '24pt',
                        '30pt',
                        '8pt'
                      ];

        $.each(arr,function(i,ar){
          var a = (i<1)?i:(i-1);
          if(data == arr[a]){
            ar = (i+1 == arr.length)?base:ar;
            el.css({'font-size':ar}).data('fontsize',ar);
          }
        });
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.pSize',function(e,tis){
        var size        = tis.data('size');
        window.fontSize = size;
        $('#contentIt').css('font-size',window.fontSize);
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.pUpper',function(e,tis){
        var size        = tis.data('size');
        window.fontCase = size;
        $('#contentIt').css('text-transform',window.fontCase);
        templateBuilder.saveAlert(true);
        if(size == 'uppercase'){
          tis.data('size','none');
        }else{
          tis.data('size','uppercase');
        }
      });

      onClickWrap('.pFamily',function(e,tis){
        var size          = tis.data('size');
        window.fontFamily = size;
        $('#contentIt').css('font-family',window.fontFamily);
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxAlign',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('textalign');

        if(data == 'left'){//si ya tiene el primer tamaño
          el.css({'text-align':'center'}).data('textalign','center');
          tis.find('i').text('format_align_center');
        }else if(data == 'center'){
          el.css({'text-align':'right'}).data('textalign','right');
          tis.find('i').text('format_align_right');
        }else{
          el.css({'text-align':'left'}).data('textalign','left');
          tis.find('i').text('format_align_left');
        }
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxBold',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('bold');

        if(data == 'normal'){//si ya tiene el primer tamaño
          el.css({'font-weight':'bold'}).data('bold','bold');
        }else{
          el.css({'font-weight':'normal'}).data('bold','normal');
        }
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxFamily',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('fontfamily');
        var base    = 'inherit';
        var arr     = [
                        base,
                        'Courier New',
                        'Arial',
                        'Times New Roman',
                        'Comic Sans MS',
                        'Trebuchet MS',
                        'Verdana'
                      ];

        $.each(arr,function(i,ar){
          var a = (i<1)?i:(i-1);
          if(data == arr[a]){
            ar = (i+1 == arr.length)?base:ar;
            el.css({'font-family':ar}).data('fontfamily',ar);
          }
        });

        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxClone',function(e,tis){
        var el      = tis.closest('.box');

        var toAppend = el.clone();
        $('#contentIt').append(toAppend);

        templateBuilder.manageFields('.box');
        templateBuilder.events();
        templateBuilder.saveAlert(true);
      });

      onClickWrap('.boxWrapText',function(e,tis){
        var el      = tis.closest('.box');
        var data    = el.data('textwrap');
        var ele     = el.find('.realBlockContent');

        if(data == 'cut'){//si ya tiene el primer tamaño
          var wrapObj     = {"text-overflow": "clip","white-space": "nowrap","overflow": "hidden"};
          var warpType    = 'wrap';
           var warpIcon   = 'wrap_text';
        }else{
          var wrapObj     = {"text-overflow": "none","white-space": "wrap","overflow": "none"};
          var warpType    = 'cut';
          var warpIcon    = 'short_text';
        }

        ele.attr('style','');
        tis.find('i').text(warpIcon);
        ele.css(wrapObj);
        ele.css('padding','0px 20px 0px 0px');
        el.data('textwrap',warpType);

        ele.show();
        templateBuilder.saveAlert(true);
      });

      onClickWrap('#deleteTemplate',function(e,tis){
        var id    = tis.data('id');

        confirmation('¿Realmente desea remover del grupo?', function (e) {
          if (e === true) {
            $.get(baseUrl + '?action=removeTemplate&id='+id,function(result){
              if(result == 'true'){
                ncmDialogs.toast('Plantilla eliminada','success');
                templateBuilder.loadTemplatesList();
              }else{
                ncmDialogs.toast('No se pudo eliminar la plantilla','danger');
              }
            });
            templateBuilder.saveAlert(false);
          }
        });

      });

      onClickWrap('.templateSelect',function(e,tis){
        var id        = tis.data('id');
        spinner('body', 'show');
        if(id == 'new'){
          templateBuilder.loadTemplatesList(function(){
            templateBuilder.setPage(false,false);
            templateBuilder.saveAlert(true);

            spinner('body', 'hide');
            $('textarea#jsonTemplateField').val('');
          });
          $('#deleteTemplate, .saveTemplate').show();
          return false;
        }else if(id == '1'){
          $('#deleteTemplate, .saveTemplate').hide();
        }

        var data      =  tis.find('div.loadTemplateData').text();
        $('textarea#jsonTemplateField').val( $.trim( data ) );
        data          = $('textarea#jsonTemplateField').val();

        if($.type( data ) === "string") { 
          data = $.parseJSON(data);
        }

        data.id   = id;
        data.name = tis.data('name');

        templateBuilder.buildTemplateView(data);        
      });
    },
    buildTemplateView : (data) => {
        console.log('buildTemplateView');
        var id        = data.id;
        var name      = data.name;
        var size      = data.page_size;
        var sizeName  = data.page_size_name;
        var font      = data.page_font_family;
        var fontSize  = data.page_font_size;
        var fontCase  = data.page_font_case;
        var fLeftM    = data.receipt_left_margin;
        var out       = '';

        $('#templateName').val(name);
        $('#templateId').val(id);
        $('#deleteTemplate, #duplicateTemplate').data('id',id).removeClass('hidden');
        
        //$('#contentIt').html(out);
        $.each(data.data, function(i, v){
          out += templateBuilder.boxTemplate('top:' + v.top + 'px;left:' + v.left + 'px;width:'+v.width+'px;height:'+v.height+'px;min-height:'+window.minHeight+'px;position:absolute;z-index:'+i+';font-size:'+v.size+';font-family:'+v.family+';text-align:'+v.align+';font-weight:'+v.bold+';',v.type,v.url,v.text,v.align,v.size,v.family,v.bold,true,v.textwrap);
        });

        $('#contentIt').html(out);
        templateBuilder.setPage(size,sizeName,font,fontSize,fontCase,fLeftM);
        templateBuilder.manageFields('.box');
        templateBuilder.events();
        templateBuilder.saveAlert(false);
        spinner('body', 'hide');
    },
    setPage: function(size,sizeName,font,fSize,fCase,fLeftM){
      var size      = (!size)?'a4page':size;
      var sizeName  = (!size)?'Formato: A4 (Vertical)':sizeName;
      var font      = (!font)?window.fontFamily:font;
      font          = (window.isTicketConfig)?"'fakereceipt', 'dotmatrix', monospace;":font;
      var fSize     = (!fSize)?window.fontSize:fSize;
      fSize         = (window.isTicketConfig)?'8pt':fSize;
      var fCase     = (!fCase)?window.fontCase:fCase;
      var fLeftM    = (!fLeftM)?window.receipt_left_margin:fLeftM;

      $('#contentIt').removeClass('a4page legalpage letterpage a4page-h legalpage-h letterpage-h receipt80 receipt76 receipt57').addClass(size).css({'font-family':font,'font-size':fSize});
      $('#pageSizeTitle').html(sizeName);
      $('#pageType').val(size);

      if(size == 'receipt80' || size == 'receipt76' || size == 'receipt57'){
        $('.receiptableN').addClass('hidden');
        $('.receiptableY').removeClass('hidden');
        templateBuilder.setBoxContainerWidth();
      }else{
        $('.receiptableY').addClass('hidden');
        $('.receiptableN').removeClass('hidden');
        window.isTicketConfig = false;
      }

      window.paperSize  = size;
      window.fontFamily = font;
      window.fontSize   = fSize;
      window.fontCase   = fCase;
      window.receipt_left_margin = fLeftM;
      $('.pUpper').data('size',fCase);
      $('#ticketLeftMargin').val(window.receipt_left_margin);
    },
    getPosition: function(classe) {
      var arr = [];
      $(classe).each(function() {
        var tis = $(this);
        arr.push({
          width   : tis.outerWidth(),
          height  : tis.outerHeight(),
          left    : tis.position().left,
          top     : tis.position().top,
          text    : tis.find('div.realBlockContent').html(),
          type    : tis.data('type'),
          url     : tis.data('url'),
          size    : tis.data('fontsize'),
          align   : tis.data('textalign'),
          family  : tis.data('fontfamily'),
          textwrap  : tis.data('textwrap'),
          bold    : tis.data('bold')
        });
      });

      arr.sort(function(a, b) {
        return a.top - b.top;
      });

      return arr;
    },
    setBoxContainerWidth: function(){
      var rowWidth = $('#contentIt').width();
      $('.box').css({left:0,width:rowWidth});
      $('.hiddenTicket').hide();
      window.isTicketConfig = true;
    },
    printAction: function(body, callback) {
      $("#printarea").remove();
      $('body').append('<div id="printarea" style="display:none;"></div>');
      var success = function() {
        $("#printarea").remove();
        callback && callback(true);
      };
      var failure = function() {
        $("#printarea").remove();
        callback && callback(false);
      };
      setTimeout(function() {
        $("#printarea").print({
          append: body,
          deferred: $.Deferred().done(success, failure)
        });
      }, 50);
    },
    getHighestZIndex: function(){
      var index_highest = 0;
      // more effective to have a class for the div you want to search
      $(".box").each(function() {
          // always use a radix when using parseInt
          var index_current = parseInt($(this).css("zIndex"), 10);
          if (index_current > index_highest) {
              index_highest = index_current;
          }
      });
      return index_highest;
    },
    saveAlert: function(noti){
      if(noti){
        window.onbeforeunload = function(){return true;};
      }else{
        window.onbeforeunload = null;
      }

      templateBuilder.buildJsonResult();
      ncmDialogs.toast('Actualizado','success');
    }
  };

  var htmlToPlain = {//este code usare para build el ticket template
    spaceW: 7.98,
    spaceH: 15,
    plainBuild: function(arr){
      htmlToPlain.sortOn(arr,"left");
      htmlToPlain.sortOn(arr,"top");
      var out = [];
      var prevTop = 0;
      var prevLeft = 0;
      $(arr).each(function(i,v) {
        var left    = Math.round(v.left/htmlToPlain.spaceW);
        var top     = Math.round(v.top/htmlToPlain.spaceH);
        var width   = Math.round(v.width/htmlToPlain.spaceW);
        var height  = Math.round(v.height/htmlToPlain.spaceH);
        var text    = v.text;
        
        var newtop = top - prevTop;
        if (top == prevTop) {
          var newleft = left - prevLeft;
        } else {
          var newleft = left;
        }

        out += htmlToPlain.buildIt(newleft, newtop, width, text, height);

        prevTop = top;
        prevLeft = left + width;      
      });
      return out;
    },
    plainbuildIt: function(left, top, width, text, height) {
      var lef   = '';
      var to    = '';
      var widt  = '';
      var txt   = text.split('');
      var txto  = '';
      var lbkd  = 1;
      for (var i = 0; i < left; i++) {
        lef += ' ';
      }
      for (var i = 0; i < top; i++) {
        to += '\n';
      }
      var lt = 0;
      for (var i = 0; i < txt.length; i++) {
        if(lt==width){
          if(lbkd<height){
            txto += '\n'+lef;
            lbkd++;
            lt = 0;
            i--;
          }else{
            break;
          }
        }else{
          txto += txt[i];
          lt++;
        }
      }
      return to + lef + txto;
    },
    sortOn: function(arr,key) {
      arr.sort(function(a, b) {
        if(a[key] < b[key]){
          return -1;
        }else if(a[key] > b[key]){
          return 1;
        }
        return 0;
      });
    }
  }

<?php
  //$script = ob_gets_contents();
  //minifyJS([$script => 'scripts' . $baseUrl . '.js']);
//}
?>
</script>
<!--<script src="scripts<?=$baseUrl?>.js?<?=date('d.i')?>"></script>-->

<?php
include_once('includes/compression_end.php');
dai();
?>
