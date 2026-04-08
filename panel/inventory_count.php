<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/".LANGUAGE.".php");
include_once("includes/functions.php");
theErrorHandler();//error handler

accessControl([0],$plansValues[PLAN]['inventory_count']);

$roc            = getROC(1,1);
$limitDetail    = 100;
$offsetDetail   = 0;

if(!$plansValues[PLAN]['inventory_count']){
  header('location:dashboard');
  dai();
}

//Insertar Producto
if(validateHttp('action') == 'insert'){
  if(OUTLET_ID < 2){dai('Seleccione una sucursal');}
  $record     = array();
  $record['inventoryCountName'] = 'Nuevo Conteo';
  $record['inventoryCountDate'] = TODAY;
  $record['userId']             = USER_ID;
  $record['outletId']           = OUTLET_ID;
  $record['companyId']          = COMPANY_ID;


  $insert                     = $db->AutoExecute('inventoryCount', $record, 'INSERT'); 
  $invId                      = $db->Insert_ID();
  if($insert === false){
    echo $db->ErrorMsg();
    echo 'false';
  }else{
    echo 'true|0|'.enc($invId);
  }
  dai();
}

//Editar Conteo
if(validateHttp('action') == 'update' && validateHttp('id','post')){

  $name = iftn($_POST['name'],'Nuevo Conteo');
  $user = iftn($_POST['user'],USER_ID,dec($_POST['user']));
  
  $record = [];
  $record['inventoryCountName']     = $name;
  $record['inventoryCountNote']     = $_POST['note'];
  $record['inventoryCountData']     = json_encode($_POST['category']);
  $record['userId']                 = $user;
  $record['outletId']               = dec($_POST['outlet']);
  
  
  $update = $db->AutoExecute('inventoryCount', $record, 'UPDATE', 'inventoryCountId = '.$db->Prepare(dec($_POST['id']))); 

  if($update === false){
    //echo $db->ErrorMsg();
    echo 'false';
  }else{
    echo 'true|0|'.$_POST['id'];
  }
  dai();
}

if(validateBool('action') == 'edit' && validateBool('id')){
    $result = ncmExecute('SELECT * FROM inventoryCount WHERE inventoryCountId = ? LIMIT 1', [dec($_GET['id'])]);
    if($result['inventoryCountStatus'] > 1){
      ?>
      <div class="wrapper text-center h2 font-bold">
        <div><i class="material-icons md-68 text-success">done</i></div>
        Conteo finalizado
      </div>
      <div class="col-xs-12 text-center">
        <?=$result['inventoryCountNote']?>
      </div>
      <div class="col-xs-12 wrapper">
        <a href="/screens/inventory_count_list?s=<?=base64_encode(enc($result['inventoryCountId']).','.enc(COMPANY_ID))?>" class="btn btn-info btn-rounded text-u-c font-bold pull-right" target="_blank">Ver Reporte</a>
        <a href="#" class="cancelItemView m-r m-t-sm pull-right">Cerrar</a>
      <?php
        if(ROLE_ID < 2){
        ?>
        <a href="#" class="deleteItem m-r m-t-sm pull-left" data-id="<?=$_GET['id']?>" data-name="<?=$result['inventoryCountName']?>" data-load="?action=delete&id=<?=$_GET['id']?>">
          <span class="text-danger">Eliminar</span>
        </a>
        <?php
        } 
        ?>
        
        </div>
      <?php
      dai();
    }else{
      //$cats   = ncmExecute('SELECT COUNT(taxonomyId) as count FROM taxonomy WHERE taxonomyType = \'category\' AND ' . $SQLcompanyId);
    }
?>
  <div class="col-xs-12 no-padder bg-white">
    <form action="?action=update" method="post" id="editItem" name="editItem">
      <div class="col-xs-12 wrapper text-center">

        <div class="col-xs-12 text-center">
          <span class="text-xs font-bold text-u-c">Nombre del Conteo</span>
          <input type="text" class="form-control text-center maskRequiredText no-border b-b b-light no-bg font-bold" style="font-size:25px; height:55px;" name="name" placeholder="Indique un nombre" value="<?=$result['inventoryCountName']?>" autocomplete="off">
        </div>
        
        <div class="col-sm-6 text-left m-t">
          <span class="text-xs font-bold text-u-c">Sucursal</span>

          <?php echo selectInputOutlet($result['outletId'],false,'m-b chosen-select no-border b-b','outlet',false,false,true); ?>

        </div>
        <div class="col-sm-6 text-left m-t">
          <span class="text-xs font-bold text-u-c">Encargado/a</span>
          <?php echo selectInputUser(USER_ID,false,'m-b chosen-select no-border b-b'); ?>

        </div>
        <div class="col-xs-12 text-left m-t">
          <span class="text-xs font-bold text-u-c">Nota</span>
          <textarea class="form-control" name="note"><?=$result['inventoryCountNote'];?></textarea>
        </div>

        <div class="col-xs-12 m-t-lg text-center">
          <?php
          $startCountURL = '/screens/inventory_count_list?s=' . base64_encode(enc($result['inventoryCountId']).','.enc(COMPANY_ID));
          ?>
          <a href="<?=$startCountURL?>" class="h2 font-bold" target="_blank"><span class="text-info">Iniciar Conteo</span></a>
          <div class="m-t">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&amp;data=<?=$startCountURL?>" width="120">
            <div class="m-t-sm text-muted">Escanee para iniciar el conteo desde <br> su dispositivo móvil</div>
          </div>
        </div>
        
        <div class="col-xs-12 h4 wrapper m-t-sm">Seleccione las categorías que desee inventariar</div>

        <div class="row text-left wrapper" id="catsList">

          <div class="col-xs-12 m-b">
            <?php echo selectInputCategory(json_decode($result['inventoryCountData']),true,'m-b input-lg chosen-select no-border b-b','category',false,'multiple'); ?>
          </div>

        </div>

        <div class="col-sm-6 text-right m-t hidden">
          <span class="block">Conteo a ciegas:</span>
          <?=switchIn('blind',$inventory->fields['inventoryCountBlind'])?>
        </div>


      </div>
      

      <div class="col-xs-12 bg-light lter m-t wrapper">
          <?php
          if(ROLE_ID < 2){
          ?>
          <a href="#" class="pull-left deleteItem m-r m-t" data-load="?action=delete&id=<?=$_GET['id']?>"><span class="text-danger">Eliminar</span></a>
          <?php
          } 
          ?>

          <input class="btn btn-info btn-lg font-bold btn-rounded text-u-c pull-right" type="submit" value="Guardar">
          <a href="#" class="cancelItemView m-r m-t pull-right">Cancelar</a>
          <?php
          if(!$result['inventoryCountStatus']){
            switchIn('status',false);
          }
          ?>
          <input type="hidden" value="<?=$_GET['id']?>" name="id">
      </div>
      
    </form>
  </div>

<?php
  dai();
}

if(validateBool('action') == 'delete' && validateBool('id')){
  $delete = $db->Execute('DELETE FROM inventoryCount WHERE inventoryCountId = ? LIMIT 1', array(dec($_GET['id'])));

  if($delete === false){
    echo 'false';
  }else{ 
    echo 'true';
  }
  dai();
}

if(validateBool('action') == 'generalTable'){

  $limits = getTableLimits($limitDetail,$offsetDetail);
  if(validateHttp('singleRow')){
    $singleRow = ' AND inventoryCountId = ' . $db->Prepare(dec(validateHttp('singleRow')));
  }

  $result = ncmExecute('SELECT * FROM inventoryCount WHERE companyId = ? ' . $roc . $singleRow . ' ORDER BY inventoryCountDate DESC ' . $limits,[COMPANY_ID],false,true);

  $table              = '';
  $oNames             = getAllOutlets();
  $allUsersArray      = getAllContacts('0');


  $head   =   '<thead>' .
              ' <tr>' .
              '     <th>Nombre</th>' .
              '     <th>Fecha</th>' .
              '     <th>Sucursal</th>' .
              '     <th>Depósito</th>' .
              '     <th>Encargado</th>' .
              '     <th>Modificado</th>' .
              '     <th>Estado</th>' .
              '     <th class="hidden-print">Iniciar</th>' .
              ' </tr>' .
              '</thead>' .
              '<tbody>';

  if($result){  
    while (!$result->EOF) {
      $fields   = $result->fields;
      $userName = iftn($allUsersArray[2][$fields['userId']]['name'],$allUsersArray[1][$fields['userId']]['name']);

      if($fields['inventoryCountStatus'] < 1){
        $status = '<span class="label bg-light">Pendiente</span>';
      }else if($fields['inventoryCountStatus'] == '1'){
        $status = '<span class="label bg-dark lter">Guardado</span>';
      }else{
        $status = '<span class="label bg-success">Finalizado</span>';
      }

      $itemId = enc($fields['inventoryCountId']);

      list($outlet,$location)    = outletOrLocation($fields['outletId']);

      $outletName   = $oNames[$outlet]['name'];
      $locationName = getLocationName($location);

      $url = '<a href="/screens/inventory_count_list?s=' . base64_encode(enc($fields['inventoryCountId']) . ',' . enc(COMPANY_ID)) . '" class="openLink text-md"><i class="material-icons text-info">launch</i></a>';


      $table .= '<tr data-type="loadItem" data-id="'.$itemId.'" data-element="#formItemSlot" data-load="?action=edit&id='.$itemId.'" class="itemsAction pointer">' . 
                ' <td>' . $fields['inventoryCountName'] . ' </td>' .
                ' <td data-order="'.$fields['inventoryCountDate'].'">' . 
                    niceDate($fields['inventoryCountDate'],true) . 
                ' </td>' .
                ' <td>' . 
                    $outletName . 
                ' </td>' .
                ' <td>' . 
                    $locationName . 
                ' </td>' .
                ' <td>' . $userName . '</td>' .
                ' <td>' . 
                    iftn($fields['inventoryCountUpdated'],'<i>Sin modificaciones</i>',niceDate($fields['inventoryCountUpdated'],true)) . 
                ' </td>' .
                ' <td>' . $status . '</td>' .
                ' <td class="hidden-print">' . $url .  '</td>' .
                '</tr>';

      if(validateHttp('part')){
        $table .= '[@]';
      }
      
      $result->MoveNext(); 
    }

  }

  $foot =   '</tbody>' .
            '<tfoot>' .
            ' <tr>' .
            '   <th colspan="8"></th>' .
            ' </tr>' .
            '</tfoot>';

  if(validateHttp('part')){
    dai($table);
  }else{
    $fullTable = $head . $table . $foot;
    $jsonResult['table'] = $fullTable;

    header('Content-Type: application/json'); 
    dai(json_encode($jsonResult));
  }
}

?>
<!DOCTYPE html>
<html class="no-js">
<head>
<!-- meta -->
<meta charset="utf-8">
<meta name="description" content="Flat, Clean, Responsive, admin template built with bootstrap 3">
<meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
<title>Conteo de Stock</title>
<?php
  loadCDNFiles([
            '/assets/vendor/css/select2-4.0.6.min.css',
            '/assets/vendor/css/select2-bootstrap-0.1.0.min.css'
            ],'css');
  ?>

</head>
<body class="bg-white">
  <?=menuFrame('top',true);?>
  <div class="col-xs-12 wrapper">
    <?=headerPrint();?>
    <span class="font-bold h2">
      Conteo de Stock
    </span>
    <a href="#" data-type="loadItem" data-element="#formItemSlot" data-load="?action=insertForm" class="itemsAction btn btn-info btn-rounded text-u-c font-bold pull-right createItemBtn hidden-print">Nuevo Conteo</a>
  </div>


  <div class="col-xs-12 table-responsive wrapper bg-white panel r-3x push-chat-down tableContainer">
      <table class="table table1 table-hover col-xs-12 no-padder" id="tableTransactions"><?=placeHolderLoader('table')?></table>
  </div>

  <?=menuFrame('bottom');?>

  <div class="modal fade" id="modalView" role="dialog">
    <div class="modal-dialog">
      <div class="modal-content r-3x clear bg-white no-border all-shadows">
        <div class="modal-body wrapper">
          
        </div>
      </div>
    </div>
  </div>

  <?php
  footerInjector();
  loadCDNFiles([
            '/assets/vendor/js/select2-4.1.0.min.js',
            '/assets/vendor/js/select2-i18n-es.min.js'
            ],'js');
  ?>

  <script>

    $(function() {

      var url     = "?action=generalTable";
      var rawUrl  = url;

      $.get(url,function(result){

        var options = {
                "container"   : ".tableContainer",
                "url"         : url,
                "rawUrl"      : rawUrl,
                "iniData"     : result.table,
                "table"       : ".table1",
                "sort"        : 1,
                "currency"    : "<?=CURRENCY?>",
                "decimal"     : decimal,
                "thousand"    : thousandSeparator,
                "offset"      : <?=$offsetDetail?>,
                "limit"       : <?=$limitDetail?>,
                "ncmTools"    : {
                          left  : '<a href="#" class="btn btn-default exportTable" data-table="tableTransactions" data-name="Conteos">Exportar Listado</a>',
                          right   : ''
                          }
        };

        manageTableLoad(options,function(oTable){
          loadTheTable(options,oTable);
        });
      });

      var loadTheTable = function(tableOps,oTable){
          window.tableOps = tableOps;
          window.oTable   = oTable;
          submitForm('#addItem,#editItem,#insertItem',function(element,id){
            $('#modalView').modal('hide');
            $.get(tableOps.url + '&part=1&singleRow=' + id,function(data){
              var $tRow = $('.editting');
              if($tRow.length > 0){
                oTable.row($tRow).remove();
                oTable.row.add($(data));
                oTable.draw();
              }
            });
          });

          onClickWrap('.deleteItem',function(event,tis){
            var url   = tis.data('load');
            var $row  = $('.editting');

            confirmation("Seguro que desea continuar?",function(conf){
              if(conf == true) { 

                $.get(url, function(response) {
                  if(response == 'true'){
                    message('Eliminado','success');
                  }else{
                    message('Error al eliminar','danger');
                  }
                });

                $('#modalView').modal('hide');
                oTable.row($row).remove().draw();

              }
            });

          },false,true);

          onClickWrap('.createItemBtn',function(event,tis){
            $.get('/inventory_count?action=insert',function(response){
              response = response.split('|');
              if(response[0] == 'true'){
                id = response[2];

                $.get(tableOps.url + '&part=1&singleRow=' + id,function(data){
                  var $tRow = $('tr[data-id="' + id + '"]');
                  if($tRow.length > 0){
                    oTable.row($tRow).remove().draw();
                  }
                  $tRow.addClass('editting');
                  oTable.row.add($(data)).draw();
                });

                loadForm('?action=edit&id='+id,'#modalView .modal-content',function(){
                  $('#modalView').modal('show');
                }); 

              }else if(response[0] == 'false'){
                message('Error al intentar procesar su petición','danger');
              }else if(response[0] == 'max'){
                $('#maxReached').modal('show');
              }else{
                alert(response[0]);
                return false;
              }
            });
          },false,true);

          $('#modalView').on('shown.bs.modal', function() {
            select2Simple($('select.chosen-select'),$('#modalView'));
            $('select.chosen-select').on('select2:select', function(e){
              var elm = e.params.data.element;
              $elm    = $(elm);
              $t      = $(this);
              $t.append($elm);
              $t.trigger('change.select2');
            });
            $('.select2-selection__choice__remove').click(function(){
              $('select.chosen-select').trigger('change.select2');
            });
          });

          onClickWrap('.openLink',function(event,tis){
            var url = tis.attr('href');
            window.open(url,'_blank');
          },false,true);

          onClickWrap('.table tbody tr',function(event,tis){
            $('.editting').removeClass('editting');
            if(!tis.hasClass('disabled')){
              var load = tis.attr('data-load');
              tis.addClass('editting');
              loadForm(load,'#modalView .modal-content',function(){
                $('#modalView').modal('show');

              });
            }
          },false,true);

          onClickWrap('.cancelItemView',function(event,tis){
             $('#modalView').modal('hide');
             tis.removeClass('editting');
          },false,true);
      };

    });

  </script>
  
</body>
</html>
<?php
include_once('includes/compression_end.php');
dai();
?>