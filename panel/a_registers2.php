<?php
require_once('libraries/whoops/autoload.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("libraries/hashid.php");
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
include_once("libraries/parsecsv.lib.php");
topHook();
allowUser('settings','view');

$baseUrl = '/' . basename(__FILE__,'.php');

$roc = getROC(1);

//Insertar Producto
if(validateHttp('action') == 'insert'){
  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  if(checkPlanMaxReached('register', ($plansValues[PLAN]['max_registers'] * OUTLETS_COUNT) + $_modules['extraRegisters'] )){
      dai('max');
  }

  $invoiceData = '{"type":"0","orderNo":"0","noPrevious":"00-00","expirationDate":"","title":"Gracias por su compra","message":"","marginTop":"0","marginLeft":"0","marginRight":"0"}';

  $registerRecord['registerName']   = 'New Register';
  $registerRecord['registerStatus'] = '1';
  $registerRecord['outletId']       = OUTLET_ID;
  $registerRecord['companyId']      = COMPANY_ID;
  
  $registerInsert = $db->AutoExecute('register', $registerRecord, 'INSERT');
  $registerId = $db->Insert_ID();

  if($registerInsert === false){
    echo $db->ErrorMsg();
    
    echo 'false';
  }else{
    echo 'true|0|'.enc($registerId);
    updateLastTimeEdit();
  }
  dai();
}

if(validateHttp('action') == 'duplicate'){

  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  if(checkPlanMaxReached('register', ($plansValues[PLAN]['max_registers'] * OUTLETS_COUNT) + $_modules['extraRegisters'] )){
    jsonDieResult(['error'=>'max_registers']);
  }

  if(!validateHttp('id')){
    jsonDieResult(['error'=>'missing_id']); 
  }

  $from       = dec( validateHttp('id') );
  $record     = [];

  $select     = ncmExecute('SELECT * FROM register WHERE registerId = ? LIMIT 1',[$from]);

  if($select){
    $record['registerName']                     = $select['registerName'] . ' copia';
    $record['registerInvoiceData']              = $select['registerInvoiceData'];
    $record['registerInvoiceAuth']              = $select['registerInvoiceAuth'];
    $record['registerInvoiceAuthExpiration']    = $select['registerInvoiceAuthExpiration'];
    $record['registerInvoicePrefix']            = $select['registerInvoicePrefix'];
    $record['registerInvoiceSufix']             = $select['registerInvoiceSufix'];
    $record['registerInvoiceNumber']            = $select['registerInvoiceNumber'];
    $record['registerRemitoNumber']             = $select['registerRemitoNumber'];
    $record['registerQuoteNumber']              = $select['registerQuoteNumber'];
    $record['registerReturnNumber']             = $select['registerReturnNumber'];
    $record['registerTicketNumber']             = $select['registerTicketNumber'];
    $record['registerOrderNumber']              = $select['registerOrderNumber'];
    $record['registerPedidoNumber']             = $select['registerPedidoNumber'];
    $record['registerBoletaNumber']             = $select['registerBoletaNumber'];
    $record['registerScheduleNumber']           = $select['registerScheduleNumber'];
    $record['registerDocsLeadingZeros']         = $select['registerDocsLeadingZeros'];
    $record['registerStatus']                   = $select['registerStatus'];
    $record['registerHotkeys']                  = $select['registerHotkeys'];
    $record['registerPrinters']                 = $select['registerPrinters'];
    $record['outletId']                         = $select['outletId'];
    $record['companyId']                        = $select['companyId'];
    $record['sessionId']                        = $select['sessionId'];
    
    $insert   = ncmInsert(['records' => $record, 'table' => 'register']);

    if($insert !== false){
      updateLastTimeEdit();
      jsonDieResult(['error' => false, 'id' => $insert]);
    }else{
      jsonDieResult(['error'=>'error_inserting']);
    }
  }

  jsonDieResult(['error'=>'register_not_found']);
}

//Editar caja
if(validateHttp('action') == 'update' && validateHttp('id','post')){
  if(!allowUser('settings','edit',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  if(validateHttp('name','post') == ''){
    $name = 'Nueva Caja';
  }else{
    $name = validateHttp('name','post');
  }

  $auth           = validateHttp('auth','post');
  $expiration     = validateHttp('expiration','post');
  $prefix         = validateHttp('prefix','post');
  $sufix          = validateHttp('sufix','post');
  $invoice        = validateHttp('invoice','post');
  $ticket         = validateHttp('ticket','post');
  $return         = validateHttp('return','post');
  $remito         = validateHttp('remito','post');
  $quote          = validateHttp('quote','post');
  $purchaseorder  = validateHttp('purchaseorder','post');
  $order          = validateHttp('order','post');
  $schedule       = validateHttp('schedule','post');
  $zeros          = validateHttp('leadingZero','post');

  $invoiceData = '{"type":"'.validateHttp('type','post').'","orderNo":"'.validateHttp('orderNo','post').'","noPrevious":"'.validateHttp('noPrevious','post').'","expirationDate":"'.validateHttp('expirationDate','post').'","title":"'.validateHttp('title','post').'","message":"'.validateHttp('message','post').'","marginTop":"'.validateHttp('mTop','post').'","marginLeft":"'.validateHttp('mLeft','post').'","marginRight":"'.validateHttp('mRight','post').'"}';

  $record                                   = [];
  $record['registerName']                   = $name;

  $record['registerInvoiceAuth']            = $auth;
  $record['registerInvoiceAuthExpiration']  = $expiration;
  $record['registerInvoicePrefix']          = $prefix;
  $record['registerInvoiceNumber']          = $invoice;
  $record['registerInvoiceSufix']           = $sufix;
  $record['registerTicketNumber']           = $ticket;
  $record['registerReturnNumber']           = $return;
  $record['registerRemitoNumber']           = $remito;
  $record['registerQuoteNumber']            = $quote;
  $record['registerOrderNumber']            = $purchaseorder;
  $record['registerPedidoNumber']           = $order;
  $record['registerScheduleNumber']         = $schedule;
  $record['registerDocsLeadingZeros']       = $zeros;  

  if(isset($_POST['status'])){
    $record['registerStatus']   = validateHttp('status','post');
  }
  
  $update = $db->AutoExecute('register', $record, 'UPDATE', 'registerId = ' . dec( validateHttp('id','post') )); 

  if($update === false){
    //echo $db->ErrorMsg();
    echo 'false';
  }else{
    echo 'true|0|' . validateHttp('id','post');
    updateLastTimeEdit();
  }

  dai();
}

if( validateHttp('action') == 'edit' && validateHttp('id') ){
  $result = ncmExecute( "SELECT * FROM register WHERE registerId = ? AND companyId = ? LIMIT 1", [ dec(validateHttp('id')), COMPANY_ID ] );
?>
  <div class="col-xs-12 no-padder bg-white">
    <form action="<?=$baseUrl?>?action=update" method="post" id="editItem" name="editItem">
      <div class="col-xs-12 wrapper text-center">

        <?php 
          $img = companyLogo();
        ?>

        <div class="col-xs-12">
          <a href="#" class="thumb-md" id="uploadImgBtn"> <img src="<?=$img?>" class="img-circle itemImg"> </a>
          <input type="text" class="form-control text-center  no-border b-b b-light no-bg font-bold" style="font-size:25px; height:55px;" name="name" placeholder="Nombre de la Caja" value="<?=$result['registerName']?>" autocomplete="off">
        </div>
        
        <div class="col-sm-12 text-left">
            
            <div class="font-bold m-t text-md">Facturas</div>

            <div class="col-xs-12 no-padder m-b">
              <div class="col-sm-6 no-padder" style="padding-right:15px!important;">
                <label>No. de Timbrado o Autorización:</label>
                <input type="number" min="0" step="1" class="form-control text-right maskInteger m-r" name="auth" value="<?=$result['registerInvoiceAuth']?>" />
              </div>
              <div class="col-sm-6 no-padder">
                <label>Vencimiento:</label>
                <input type="text" class="form-control datepicker" name="expiration" value="<?=$result['registerInvoiceAuthExpiration']?>" />
              </div>
            </div>

            <div class="col-xs-12 no-padder m-b-md">
              <div class="col-sm-3 no-padder">
                <label>Prefijo:</label>
                <input type="text" class="form-control" name="prefix" value="<?=$result['registerInvoicePrefix']?>" />
              </div>
              
              <div class="col-xs-3">
                <label>Cant. de dígitos:</label>
                <select name="leadingZero" class="form-control" autocomplete="off">
                  <?php
                  for($i=0;$i<11;$i++){
                    $selected = '';
                    if($i == $result['registerDocsLeadingZeros']){
                      $selected = 'selected';
                    }
                    echo '<option value="'.$i.'" '.$selected.'>'.$i.'</option>';
                  }
                  ?>
                </select>
              </div>
              <div class="col-xs-3">
                <label>No. de Factura:</label>
                <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="invoice" value="<?=$result['registerInvoiceNumber']?>" />
              </div>
              <div class="col-sm-3 no-padder">
                <label>Sufijo:</label>
                <input type="text" class="form-control" name="sufix" value="<?=$result['registerInvoiceSufix']?>" />
              </div>
            </div>

            <div class="font-bold m-t text-md">Otros documentos</div>
            <div class="col-xs-12 no-padder m-b">
                <div class="col-sm-4 no-padder">
                  <label>No. de Recibo:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="ticket" value="<?=$result['registerTicketNumber']?>" />
                </div>
                <div class="col-sm-4">
                  <label>Nota de Crédito:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="return" value="<?=$result['registerReturnNumber']?>" />
                </div>
                <div class="col-sm-4 no-padder">
                  <label>No. de Remito o Remisión:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="remito" value="<?=$result['registerRemitoNumber']?>" />
                </div>
            </div>

            <div class="col-xs-12 no-padder m-b">
                <div class="col-sm-4 no-padder">
                  <label>No. de Cotización:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="quote" value="<?=$result['registerQuoteNumber']?>" />
                </div>
                <div class="col-sm-4">
                  <label>No. de Orden de Compra:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="purchaseorder" value="<?=$result['registerOrderNumber']?>" />
                </div>
                <div class="col-sm-4 no-padder">
                  <label>No. de Pedido:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="order" value="<?=$result['registerPedidoNumber']?>" />
                </div>
            </div>

            <div class="col-xs-12 no-padder m-b">
                <div class="col-sm-4 no-padder">
                  <label>No. de Cita:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="schedule" value="<?=$result['registerScheduleNumber']?>" />
                </div>
                <div class="col-sm-4">
                  <!--<label>No. de Orden de Compra:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="purchaseorder" value="<?=$result['registerOrderNumber']?>" />-->
                </div>
                <div class="col-sm-4 no-padder">
                  <!--<label>No. de Pedido:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="order" value="<?=$result['registerPedidoNumber']?>" />-->
                </div>
            </div>
           
            
        </div>
      </div>
      

      <div class="col-xs-12 wrapper bg-light lter m-t">
          <?php
            if(ROLE_ID == 0){

                if($result['registerStatus'] == 1){
                  $ch = 'checked';
                }
          ?>
          <div class="col-sm-6">

              <div class="form-group">
                  <label>Estado:</label>
                  <input type="checkbox" name="status" value="1" <?=$ch?>>
              </div>

          </div>
          <?php
              }
          ?>
          <input class="btn btn-info pull-right btn-rounded btn-lg font-bold text-u-c" type="submit" value="Guardar">
          <a href="#" class="pull-left deleteItem m-t" data-id="<?=$_GET['id']?>" data-load="<?=$baseUrl?>?action=delete&id=<?=$_GET['id']?>"><span class="text-danger">Eliminar</span></a>
          <a href="#" class="pull-left duplicateItem m-t m-l" data-id="<?=$_GET['id']?>" data-load="<?=$baseUrl?>?action=duplicate&id=<?=$_GET['id']?>">Duplicar</a>
          <a href="#" class="cancelItemView m-r-lg m-t pull-right">Cancelar</a>
          <input type="hidden" value="<?=$_GET['id']?>" name="id">
          
      </div>
      
    </form>
  </div>
  <script>
    $('.datepicker').datetimepicker({
      format: 'YYYY-MM-DD'
    });
  </script>

  <?php
  dai();
}

if(validateHttp('action') == 'delete' && validateHttp('id')){
  if(!allowUser('settings','delete',true)){
    jsonDieResult(['error'=>'No permissions']);
  }

  $delete = ncmExecute( 'DELETE FROM register WHERE registerId = ? AND ' . $SQLcompanyId . ' LIMIT 1', [ dec($_GET['id']) ] ); 
  if($delete === false){
    echo 'false';
  }else{
    echo 'true';
    updateLastTimeEdit();
  }
  dai();
}

if(validateHttp('list')){
  $totalRegisters = ($plansValues[PLAN]['max_registers'] * OUTLETS_COUNT) + $_modules['extraRegisters'];
  $limit          = ' LIMIT ' . $totalRegisters;
  
  $result = ncmExecute("SELECT * FROM register WHERE  registerId > 0" . $roc . $limit,[],false,true);

  $table = '<thead class="text-u-c">
              <tr>
                <th>Nombre</th>
                <th>Creado el</th>
                <th class="text-center">No. de Timbrado o Autorización</th>
                <th>Prefijo</th>
                <th class="text-center">No. de Factura</th>
                <th>Sufijo</th>
              </tr>
            </thead>
            <tbody>';
    
  if($result){
    while (!$result->EOF) {
      $fields = $result->fields;
      $status = ($fields['registerStatus'] == '1') ? '<i class="material-icons">check_circle</i>' : '<i class="material-icons text-danger">error</i>';

      $itemId = enc($fields['registerId']);

      $table .= '<tr data-type="loadItem" data-id="'.$itemId.'" data-element="#formItemSlot" data-load="' . $baseUrl . '?action=edit&id='.$itemId.'" class="clickrow">' .
                ' <td class="font-bold"> ' . $fields['registerName'] . ' </td>' .
                ' <td>' . niceDate($fields['registerCreationDate']) . '</td>' .
                ' <td class="text-right">' . $fields['registerInvoiceAuth'] . '</td>' .
                ' <td>' . $fields['registerInvoicePrefix'] . '</td>' .
                ' <td class="text-right">' . leadingZeros($fields['registerInvoiceNumber'], $fields['registerDocsLeadingZeros']) . '</td>' .
                ' <td>' . $fields['registerInvoiceSufix'] . '</td>' .
                '</tr>';
      
      $result->MoveNext(); 
    }
    
    $table .= '</tbody>' .
              '<tfoot><tr><td colspan="6"></td></tr></tfoot>';

    $result->Close();
  }
  
  dai($table);
}

?>

  <div class="col-xs-12 wrapper-sm">
    <a href="javascript:;" data-type="loadItem" data-element="#formItemSlot" data-load="<?=$baseUrl?>?action=insertForm" class="itemsAction btn btn-info font-bold text-u-c rounded pull-right createItemBtn hidden-print">Agregar Caja</a>
    <span class="m-t-xs">
      <a href="#outlets" class="block m-t-xs text-default hidden-print">Sucursales</a>
    </span>
  </div>

  <div class="col-xs-12 wrapper">
    <span class="font-bold h2" id="pageTitle">
      Cajas registradoras: 
      <?php
       $currentOutlet = getCurrentOutletName();
       echo ($currentOutlet == 'None') ? 'Todas' : $currentOutlet;
      ?>
    </span>
  </div>

  <div class="col-xs-12 panel wrapper r-24x push-chat-down">

    <table class="table hover col-xs-12 no-padder">
      <?=placeHolderLoader('table');?>
    </table>
  </div>

  <script>
    $(function() {   
      var baseUrl = '<?=$baseUrl?>';

      FastClick.attach(document.body);

      var info1 = {
                    "container"   : ".tableContainer",
                    "url"         : baseUrl + "?list=true",
                    "table"       : ".table",
                    "sort"        : 0
                  };

      manageTable(info1);

      onClickWrap('.createItemBtn',function(event,tis){

        $.get(baseUrl + '?action=insert',function(response){
          response = response.split('|');
          if(response[0] == 'true'){
            //message('Acción realizada exitosamente','success');
            id = response[2];

             manageTable(info1);

            loadForm(baseUrl + '?action=edit&id='+id,'#modalSmall .modal-content',function(){
              $('#modalSmall').modal('show');
            });

          }else if(response[0] == 'false'){
            message('Error al intentar procesar su petición','danger');
          }else if(response[0] == 'max'){
            ncmDialogs.confirm('Ha alcanzado el límite','Contáctenos y le asistiremos para incrementar','warning');
          }else{
            alert(response[0]);
            return false;
          }
        });
      });

      onClickWrap('.clickrow',function(event,tis){
        checkIfAdmin();        
        var load = tis.data('load');
        loadForm(load,'#modalSmall .modal-content',function(){
          $('#modalSmall').modal('show');
        });
      });

      onClickWrap('.cancelItemView',function(event,tis){
        $('#modalSmall').modal('hide');
      });

      $('#modalSmall').off('shown.bs.modal').on('shown.bs.modal',function(){

        submitForm('#addItem,#editItem,#insertItem', (element,id) => {
          manageTable(info1);
          loadForm(baseUrl + '?action=edit&id='+id,'#modalSmall .modal-content',function(){
            $('#modalSmall').modal('show');
          });
        });

        onClickWrap('.deleteItem',(event,tis) => {
          var load = tis.data('load'); 
          $.get(load, function(response) {
            if(response == 'true'){
              
              $('#modalSmall').modal('hide');
               manageTable(info1);
              
              message('Caja eliminada','success');
            }else{
              message('Error al eliminar','danger');
            }
          });  
        });

        onClickWrap('.duplicateItem',(event,tis) => {
          var load = tis.data('load'); 
          $.get(load, (response) => {
            if(!response.error){
              
              $('#modalSmall').modal('hide');
               manageTable(info1);
              
              message('Caja duplicada','success');
            }else{
              message('Error al duplicar','danger');
            }
          });  
        });

      });


    });


    var checkIfAdmin = function(){
      var state = $('.role').val();
      if(state == 1){
        $(".pass").prop('disabled', false).val('');
        //$('.pass').removeClass('disabled').val('');
      }else{
        $(".pass").prop('disabled', true).val('');
        //$('.pass').addClass('disabled').val('');
      }
    };
    </script>

<?php
dai();
?>