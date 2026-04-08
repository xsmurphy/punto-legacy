<?php
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
topHook();
allowUser('settings','view');

$baseUrl = '/' . basename(__FILE__,'.php');

$limitDetail    = 500;
$offsetDetail   = 0;

//Insertar sucursal
if(validateHttp('action') == 'insert'){

  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  $record = [];
  $record['outletName']       = 'Nueva Sucursal';
  $record['outletAddress']    = '';
  $record['outletPhone']      = '';
  $record['outletEmail']      = '';
  $record['outletStatus']     = 1;
  $record['companyId']        = COMPANY_ID;
  $record['itemsTaxIncluded'] = 1;

  $record['data']             = json_encode($record);

  $insert                     = $db->AutoExecute('outlet', $record, 'INSERT'); 
  $outletId                   = $db->Insert_ID();
  if($insert === false){
    echo $db->ErrorMsg();
    echo 'false';
  }else{
    insertBlankInventoryinOneOutlet($outletId);

    //si se inserta la sucursal agrego un register
    $registerRecord['registerName']   = 'Nueva Caja';
    $registerRecord['registerStatus'] = '1';
    $registerRecord['outletId']       = $outletId;
    $registerRecord['companyId']      = COMPANY_ID;
    
    $registerInsert = $db->AutoExecute('register', $registerRecord, 'INSERT');

    echo 'true|0|'.enc($outletId);
    updateLastTimeEdit();
  }
  dai();
}

//Editar sucursal
if(validateHttp('action') == 'update' && validateHttp('id','post')){

  /*$record                     = [];
  $record['name']             = validateHttp('name','post');
  $record['address']          = validateHttp('address','post');
  $record['phone']            = validateHttp('phone','post');
  $record['email']            = validateHttp('email','post');
  $record['description']      = validateHttp('description','post');
  $record['status']           = validateHttp('status','post');
  $record['tin']              = validateHttp('ruc','post');
  $record['whatsapp']         = validateHttp('whatsApp','post');
  $record['businessHours']    = json_decode(json_encode(validateHttp('businessHours','post')));
  $record['purchaseOrderNo']  = validateHttp('purchaseOrderNo','post');
  $record['online']           = validateHttp('ecom','post');
  $record['coordinates']      = validateHttp('latLng','post');
  $record['taxID']            = validateHttp('tax','post') ? dec(validateHttp('tax','post')) : NULL;

  $jRecord                          = $record;
  $jRecord['outletBusinessHours']   = json_decode(validateHttp('businessHours','post'));
  $record['data']                   = json_encode($jRecord);

  $data   = [
              'api_key'       => API_KEY,
              'company_id'    => enc(COMPANY_ID),
              'ID'            => false,
              'data'          => $record
            ];

  $result = curlContents(API_URL . '/get_orders','POST',$data);*/



  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  if(validateHttp('name','post') == ''){
    $name = 'New Location';
  }else{
    $name = validateHttp('name','post');
  }

  $idD                              = dec(validateHttp('id','post'));
  
  $record = [];
  $record['outletName']             = $name;
  $record['outletAddress']          = validateHttp('address','post');
  $record['outletPhone']            = validateHttp('phone','post');
  $record['outletEmail']            = validateHttp('email','post');
  $record['outletDescription']      = validateHttp('description','post');
  $record['outletStatus']           = validateHttp('status','post');
  $record['outletBillingName']      = validateHttp('billingName','post');
  $record['outletRUC']              = validateHttp('ruc','post');
  $record['outletWhatsApp']         = validateHttp('whatsApp','post');
  $record['outletBusinessHours']    = json_decode(json_encode(validateHttp('businessHours','post')));
  $record['outletPurchaseOrderNo']  = validateHttp('purchaseOrderNo','post');
  $record['outletEcom']             = validateHttp('ecom','post');
  $record['outletLatLng']           = validateHttp('latLng','post');
  $record['taxId']                  = validateHttp('tax','post') ? dec(validateHttp('tax','post')) : NULL;
  $record['itemsTaxIncluded']       = validateHttp('taxIncluded','post') ? 1 : 0;

  $jRecord                          = $record;
  $jRecord['outletBusinessHours']   = json_decode(validateHttp('businessHours','post'));
  $record['data']                   = json_encode($jRecord);

  $update = ncmUpdate(['records' => $record, 'table' => 'outlet', 'where' => 'outletId = ' . $idD . ' AND ' . $SQLcompanyId]);

  if($update['error'] !== false){
    //echo $update['error'];
    echo 'false';
    //print_r($record['data']);
  }else{
    echo 'true|0|' . validateHttp('id','post');
    updateLastTimeEdit();
  }

  dai();
}

if(validateHttp('action') == 'edit' && validateHttp('id')){
    $result   = ncmExecute('SELECT * FROM outlet WHERE outletId = ? AND ' . $SQLcompanyId . ' LIMIT 1', [dec($_GET['id'])]);
    $jResult  = json_decode($result['data'] ?? "", true);

    if(!$result){
      return false;
    }
?>
  <div class="col-xs-12 no-padder bg-white">
    <?php
    $lat = explode(',', $result['outletLatLng'])[0] ?? "";
    $lng = explode(',', $result['outletLatLng'])[1] ?? "";
    ?>
    <div class="row pull-out m-t-n m-b-xs customerInfoMap <?=$lat ? '' : 'hidden'?>" id="ncmGMap">
      <iframe class="mapIframe" width="100%" height="200" frameborder="0" scrolling="no" marginheight="0" marginwidth="0" src="/screens/mapIframe?height=200&lat=<?=$lat?>&lng=<?=$lng?>&theme=light&icon=store&key=0VM_W3i3Uqi5E9P57uSsA_Q2-06swWpChj24kCv9WJ8"></iframe>
    </div>
    <form action="<?=$baseUrl?>?action=update" method="post" id="editItem" name="editItem">
      <div class="col-xs-12 wrapper">
        
        <div class="col-xs-12 m-t m-b text-u-c font-bold text-lg">Perfil de <?=unXss($result['outletName'])?></div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">Nombre de la sucursal</label>
          </div>
          <div class="col-xs-6 no-padder">
            <input type="text" class="form-control no-border b-b" name="name" value="<?=$result['outletName']?>" />
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">Dirección</label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <input type="text" class="form-control no-border b-b" name="address" value="<?=$result['outletAddress']?>" />
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">Razón Social</label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <input type="text" class="form-control no-border b-b" name="billingName" value="<?=$result['outletBillingName']?>" />
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold"><?=TIN_NAME;?></label>
          </div>
          <div class="col-xs-6 no-padder">
            <input type="text" class="form-control no-border b-b" name="ruc" value="<?=$result['outletRUC']?>" />
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">Teléfono</label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <input type="text" class="form-control no-border b-b" name="phone" value="<?=$result['outletPhone']?>" />
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">eMail</label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <input type="text" class="form-control <?=$disabled ?? ""?> no-border b-b" name="email" value="<?=$result['outletEmail'] ?? ""?>"/>
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">WhatsApp <small class="text-normal">(formato internacional)</small></label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <input type="text" class="form-control no-border b-b" name="whatsApp" value="<?=$result['outletWhatsApp']?>" />
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">Coordenadas</label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <input type="text" class="form-control no-border b-b parseUrlToCoors" name="latLng" value="<?=$result['outletLatLng']?>" placeholder="Agregue las coordenadas o link de Google Maps" />
          </div>
        </div>

        <div class="col-xs-12 no-padder m-t m-b" id="outletBusinessHours">
          <label class="text-u-c font-bold">Días y horarios operativos</label>
          <div class="hidden businessHoursConfig">
            <?php
              if($result['outletBusinessHours']){
                echo stripslashes( $result['outletBusinessHours'] );
              }else{
                ?>
                [{"isActive":true,"timeFrom":"09:00","timeTill":"18:00"},{"isActive":true,"timeFrom":"09:00","timeTill":"18:00"},{"isActive":true,"timeFrom":"09:00","timeTill":"18:00"},{"isActive":true,"timeFrom":"09:00","timeTill":"18:00"},{"isActive":true,"timeFrom":"09:00","timeTill":"18:00"},{"isActive":true,"timeFrom":"09:00","timeTill":"12:00"},{"isActive":false,"timeFrom":null,"timeTill":null}]
                <?php
              }
            ?>
          </div>
          <input type="hidden" class="hidden businessHours" name="businessHours">
          <div class="businessHours"></div>
        </div>

        <div class="col-xs-12 m-t m-b text-u-c font-bold text-lg">Configuración</div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">No. de Orden de Compra</label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <input type="number" class="form-control no-border b-b text-right" name="purchaseOrderNo" value="<?=$result['outletPurchaseOrderNo']?>" />
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">Depósitos</label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <?php 
              $dep = ncmExecute("SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = 'location' AND outletId = ? ORDER BY taxonomyName ASC",[$result['outletId']],false,true);

              if(!empty($_GET['debug'])){
                echo 'SELECT taxonomyId, taxonomyName FROM taxonomy WHERE taxonomyType = \'location\' AND outletId = ' . $result['outletId'] . ' ORDER BY taxonomyName ASC';
              }
              ?>
            <select id="location" name="location" data-placeholder="Seleccione" class="form-control search location no-bg no-border b-b b-light" autocomplete="off" data-toggle="tooltip" data-placement="top" title="Crea depósitos para organizar su inventario">
              <?php 
              if($dep){
                while (!$dep->EOF) {
                  $depId = enc($dep->fields['taxonomyId']);
              ?>
                  <option value="<?=$depId;?>"><?=$dep->fields['taxonomyName'];?></option>
              <?php 
                $dep->MoveNext(); 
                }
                $dep->Close();
              }
              ?>
            </select>

            <div class="col-xs-12 no-padder m-t-xs">
              <a href="#" class="addItemPart btn btn-sm bg-light lter" data-table="location" data-outlet="<?=enc($result['outletId'])?>" title="Agregar"><i class="material-icons">add</i></a>
              <a href="#" class="editItemPart btn btn-sm bg-light lter" data-table="location" data-select="location" title="Editar"><i class="material-icons">create</i></a>
              <a href="#" class="deleteItemPart btn btn-sm bg-light lter" data-table="location" data-select="location" title="Remover"><i class="material-icons text-danger">close</i></a>
            </div>   
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">Impuesto a la Venta</label>
          </div>
          <div class="col-xs-6 wrapper-xs">
            <?php 
              echo selectInputTax($result['taxId'],false,'no-bg no-border search b-b b-light m-b',true);
            ?>
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold"><?=TAX_NAME?> incluído</label>
          </div>
          <div class="col-xs-6 wrapper-xs text-right">
            <?=switchIn('taxIncluded',$jResult['itemsTaxIncluded'])?>
          </div>
        </div>

        <div class="col-xs-12 wrapper b-b">
          <div class="col-xs-6 no-padder">
            <label class="m-t-sm text-u-c font-bold">Online</label>
          </div>
          <div class="col-xs-6 wrapper-xs text-right">
            <?=switchIn('ecom',$result['outletEcom'])?>
          </div>
        </div>

      </div>
      
      <div class="col-xs-12 wrapper bg-light lter m-t">
        <?php
        if(dec($_GET['id']) != OUTLET_ID){
        ?>
          <a href="#" class="pull-left deleteItem m-t" data-id="<?=$_GET['id']?>" data-name="<?=$result['outletName']?>" data-load="<?=$baseUrl?>?action=delete&id=<?=$_GET['id']?>"><span class=" text-danger">Eliminar</span></a>
        <?php
        }
        ?>

        <?php
          if($result['outletStatus']){
            $ch = 'checked="checked"';
          }
        ?>

        <div class="form-group pull-left <?=(!SAAS_ADM) ? 'hidden' : '';?>">
            <label>Estado:</label>
            <input type="checkbox" name="status" data-stat="<?=$result['outletStatus']?>" value="1" <?=$ch?> >
        </div>

        <input class="btn btn-info btn-lg btn-rounded text-u-c font-bold pull-right" type="submit" value="Guardar">

        <a href="#" class="cancelItemView m-r-lg m-t pull-right">Cancelar</a>

        <input type="hidden" value="<?=$_GET['id']?>" name="id">
          
      </div>
    </form>
  </div>

<?php
  dai();
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
  if(!allowUser('settings','delete',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  $id     = $db->Prepare(dec(validateHttp('id')));

  if($id != OUTLET_ID){
    $delete = deleteOutlet($id);
    if($delete !== false){
      updateLastTimeEdit();
      dai($delete);
    }else{
      dai('false');
    }
  }else{
    dai('false');
  }
}

if(validateHttp('tableExtra')){

  $locationCallback = function($locationId, $action){
    global $db;
    //CALLBACK LOCATIONS
    if(validateHttp('tableExtra') == 'location' && $locationId){
      if($action == 'add'){
        $db->Execute('UPDATE taxonomy SET outletId = ' . dec(validateHttp('admOutlet')) . ' WHERE taxonomyId = ?',[$locationId]);
      }else if($action == 'delete'){
        $db->Execute('DELETE FROM toLocation WHERE locationId = ?',[$locationId]);
      }
    }
  };

  adm(validateHttp('valExtra'),validateHttp('tableExtra'),dec(validateHttp('idExtra')),validateHttp('actionExtra'),$locationCallback);
}

if(validateHttp('showTable')){
  if(ROLE_ID > 0){
    $limit = ' LIMIT '.$plansValues[PLAN]['max_outlets'];
  }else{
    $limit = '';
  }

  $table = '';
  
  $result = ncmExecute('SELECT * FROM outlet WHERE ' . $SQLcompanyId . $limit,[],false,true);

  if($result){

    $table =  '<thead class="text-u-c">' .
              ' <tr>' .
              '   <th>Nombre</th>' .
              '   <th>Razón Social</th>' .
              '   <th>' . TIN_NAME . '</th>' .
              '   <th>Teléfono</th>' .
              '   <th>Dirección</th>' .
              '   <th>Online</th>' .
              '   <th>Estado</th>' .
              ' </tr>' .
              '</thead>' .
              '<tbody>';
      
    while (!$result->EOF) {
      $fields = $result->fields;
      $status = ($fields['outletStatus'] == 1)?'<span class="label bg-success">Activado</span>':'<span class="label bg-danger">Desactivado</span>';
      $online = '';

      if($fields['outletEcom']){
        $online = '<i class="material-icons text-success">check</i>';
      }

      $itemId = enc($fields['outletId']);

      $table .= '<tr data-id="' . $itemId . '" class="clickrow">' .
                ' <td class="font-bold"> ' . toUTF8($fields['outletName']) . ' </td>' .
                ' <td> ' . toUTF8($fields['outletBillingName']) . ' </td>' .
                ' <td> ' . $fields['outletRUC'] . ' </td>' .
                ' <td> ' . $fields['outletPhone'] . ' </td>' .
                ' <td> ' . toUTF8($fields['outletAddress']) . ' </td>' .
                ' <td class="text-center"> ' . $online . ' </td>' .
                ' <td> ' . $status . '</td>' .
                '</tr>';

      $result->MoveNext(); 
    }
 
    $table .= '</tbody>' .
              '<tfoot><tr><td colspan="7"></td></tr></tfoot>';
    
    $result->Close();
  }

  $jsonResult['table']  = $table;
  
  header('Content-Type: application/json'); 
  dai(json_encode($jsonResult));
}
?>

<div class="col-xs-12 wrapper-sm">
  <a href="#" data-type="loadItem" data-element="#formItemSlot" data-load="<?=$baseUrl?>?action=insertForm" class="itemsAction btn btn-info hidden-print btn-rounded font-bold text-u-c pull-right createItemBtn">Agregar Sucursal</a>
  <span class="m-r m-t-xs pull-left">
    <a href="#registers" class="text-default hidden-print">Cajas Registradoras</a>
  </span>
</div>

<div class="col-xs-12 wrapper">
  <span class="font-bold h1" id="pageTitle">
    Sucursales
  </span>
</div>

<div class="col-xs-12 wrapper panel push-chat-down r-24x table-responsive tableContainer">
  <table class="table hover col-xs-12 no-padder" id="tableOutlets">
    <?=placeHolderLoader('table')?>
  </table>
  
</div>

<script>
  var baseUrl = '<?=$baseUrl?>';

  $(function() {   
    FastClick.attach(document.body);

    var baseUrl = '<?=$baseUrl?>';
    var rawUrl  = baseUrl + "?showTable=true";
    var url     = rawUrl;

    var xhr = ncmHelpers.load({
      url         : url,
      httpType    : 'GET',
      hideLoader  : true,
      type        : 'json',
      success     : function(result){
        var options = {
                "container"   : ".tableContainer",
                "url"         : url,
                "rawUrl"      : rawUrl,
                "iniData"     : result.table,
                "table"       : ".table",
                "sort"        : 0,
                "footerSumCol"  : [],
                "currency"    : "<?=CURRENCY?>",
                "decimal"     : decimal,
                "thousand"    : thousandSeparator,
                "offset"      : <?=$offsetDetail?>,
                "limit"       : <?=$limitDetail?>,
                "noMoreBtn"   : true,
                "tableName"   : 'tableOutlets',
                "fileTitle"   : 'Sucursales',
                "ncmTools"    : {
                                  left    : '',
                                  right   : ''
                                },
                "colsFilter"  : {
                                  name  : 'outlets',
                                  menu  :  []
                                },
                "clickCB"     : function(event,tis){
                    var id  = tis.data('id');
                    var url = baseUrl + '?action=edit&id=' + id
                    loadForm(url,'#modalSmall .modal-content',function(){
                      $('#modalSmall').modal('show');
                    });
                }
        };

        ncmDataTables(options,function(){
          ncmHelpers.onClickWrap('.cancelItemView',function(event,tis){
             $('#modalSmall').modal('hide');
          });

          $('#modalSmall').off('shown.bs.modal').on('shown.bs.modal',function(){
            adm();
            var currBH = JSON.parse( $.trim($('#outletBusinessHours div.businessHoursConfig').text()) );
            var bHours = $("#outletBusinessHours div.businessHours").businessHours({
                checkedColorClass   : 'bg-info lt',
                uncheckedColorClass : 'bg-danger lt',
                operationTime       : currBH,
                weekdays            : ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'],
                postInit            :function(){
                    /*$('.operationTimeFrom, .operationTimeTill').datetimepicker({
                        format: 'HH:mm'
                    });*/
                },
                dayTmpl              :'<div class="dayContainer col-md-3 col-xs-4 m-b wrapper-sm" style="min-height:134px;">' +
                                      ' <div class="weekday font-bold text-u-c"></div>' +
                                      ' <div data-original-title="" class="colorBox m-b-xs r-3x pointer"><input type="checkbox" class="invisible operationState"></div>' +
                                      ' <div class="operationDayTimeContainer">' +
                                      '   <div class="operationTime input-group m-b-xs">' + 
                                      
                                      '     <input type="time" name="startTime" class="mini-time form-control operationTimeFrom">' +
                                      '   </div>' +
                                      '   <div class="operationTime input-group">' +
                                      
                                      '     <input type="time" name="endTime" class="mini-time form-control operationTimeTill">' +
                                      '   </div>' +
                                      ' </div>' + 
                                      '</div>'
            });

            $('.parseUrlToCoors').off('keyup change').on('keyup change',function(e){
              var tis = $(this);
              var url = tis.val();
              if(url){
                var coors = ncmHelpers.coorsParser(url);
                if(coors.lat && coors.lng){
                  tis.val(coors.lat + ',' + coors.lng);
                }
              }
            });

            select2Simple($(".search"));

            submitForm('#addItem,#editItem,#insertItem',function(element,id){              
              loadForm(baseUrl + '?action=edit&id=' + id,'#modalSmall .modal-content',function(){
                $('#modalSmall').modal('hide');
              });
            },false,function(){
              $("#outletBusinessHours input.businessHours").val( JSON.stringify(bHours.serialize()) );
            });
          });

          ncmHelpers.onClickWrap('.deleteItem',function(event,tis){
            var oName   = tis.data('name');
            var oId     = tis.data('id');

            ncmDialogs.confirm('¿Seguro que desea continuar?','TODO lo relacionado a esta sucursal será eliminado del sistema para siempre!','warning',function(a){
              if(a){

                ncmDialogs.confirm('¿Seguro que desea continuar?','Se eliminarán, reportes, transacciones, usuarios, cajas, inventario y más','warning',function(a){
                  if(a){
                    var load = tis.data('load'); 
                    spinner('body', 'show');
                    $.get(load, function(response) {
                      if(response == 'true'){
                        $('#modalSmall').modal('hide');
                        
                        message('Todo lo relacionado a la sucursal fue eliminado','success');
                      }else{
                        message('Error al eliminar','danger');
                      }

                      spinner('body', 'hide');
                    });
                  }
                });
              }
            });
          });
        });
      }
    });

    window.xhrs.push(xhr);

    ncmHelpers.onClickWrap('.createItemBtn',function(event,tis){


      <?php
      if(SAAS_ADM){
      ?>

      ncmDialogs.confirm('¿Desea crear una nueva sucursal?','Implicará el cobro de cada sucursal de forma mensual','question',function(conf){
          if (conf) {
            $.get(baseUrl + '?action=insert',function(response){
              response = response.split('|');
              if(response[0] == 'true'){
                //message('Acción realizada exitosamente','success');
                id = response[2];

                loadForm(baseUrl + '?action=edit&id='+id,'#modalSmall .modal-content',function(){
                  $('#modalSmall').modal('show');
                }); 

              }else if(response[0] == 'false'){
                message('Error al intentar procesar su petición','danger');
              }else if(response[0] == 'max'){
                $('#maxReached').modal();
              }else{
                alert(response[0]);
                return false;
              }
            });
          }
      });

      <?php
      }else{
      ?>

      $('#maxReached').modal();

      <?php
      }
      ?>
      
    });

  });

</script>

<?php
dai();
?>