<?php
include_once('includes/top_includes.php');

topHook();
allowUser('items','view');

$baseUrl        = '/' . basename(__FILE__,'.php');

$roc            = getROC(1,1);
$limitDetail    = 100;
$offsetDetail   = 0;

//Insertar Producto
if(validateHttp('action') == 'insert'){
  if(!allowUser('items','edit',true)){
      jsonDieResult(['error'=>'No permissions']);
  }

  if(OUTLET_ID < 2){ dai('Seleccione una sucursal'); }

  $data   = [
              'cogs'    => false,
              'blind'   => false,
              'note'    => '',
              'name'    => 'Nuevo Conteo'
            ];
  
  $record                       = [];
  $record['inventoryCountName'] = 'Nuevo Conteo';
  $record['inventoryCountDate'] = TODAY;
  $record['data']               = json_encode($data);
  $record['userId']             = USER_ID;
  $record['outletId']           = OUTLET_ID;
  $record['companyId']          = COMPANY_ID;

  $insert                       = $db->AutoExecute('inventoryCount', $record, 'INSERT'); 
  $invId                        = $db->Insert_ID();

  if($insert === false){
    echo $db->ErrorMsg();
    echo 'false';
  }else{
    echo 'true|0|' . enc($invId);
  }

  dai();
}

//Editar Conteo
if(validateHttp('action') == 'update' && validateHttp('id','post')){

  if(!allowUser('items','edit',true)){
      jsonDieResult(['error'=>'No permissions']);
  }

  $name   = iftn( validateHttp('name','post'), 'Nuevo Conteo');
  $user   = iftn( validateHttp('user','post'), USER_ID, dec( validateHttp('user','post') ) );
  $dID    = dec( validateHttp('id','post') );

  $data   = [
              'cogs'    => iftn( validateHttp('cogs','post') ),
              'blind'   => iftn( validateHttp('blind','post') ),
              'note'    => validateHttp('note','post'),
              'name'    => $name
            ];

  //$countData  = ['categories' => validateHttp('category','post'), 'config' => $countConfig];
  
  $record                           = [];
  $record['inventoryCountName']     = $name;
  $record['inventoryCountNote']     = validateHttp('note','post');
  $record['inventoryCountData']     = json_encode( validateHttp('category','post') );

  $record['inventoryCountBlind']    = validateHttp('blind','post');
  $record['data']                   = json_encode($data);
  
  $record['userId']                 = $user;
  $record['outletId']               = dec( validateHttp('outlet','post') );
  
  $update = ncmUpdate(['records' => $record, 'table' => 'inventoryCount', 'where' => 'inventoryCountId = ' . $dID]);

  if($update['error']){
    //echo $db->ErrorMsg();
    echo 'false';
  }else{
    echo 'true|0|' . validateHttp('id','post');
  }

  dai();

}

if(validateBool('action') == 'edit' && validateBool('id')){
  $ID       = validateBool('id');
  $dID      = dec( $ID );

  $result   = ncmExecute('SELECT * FROM inventoryCount WHERE inventoryCountId = ? LIMIT 1', [$dID]);
  $allData  = json_decode($result['data'],true);

  $name     = $allData['name'] ? $allData['name'] : $result['inventoryCountName'];
  $note     = $allData['note'] ? $allData['note'] : $result['inventoryCountNote'];
  $cogs     = $allData['cogs'] ? $allData['cogs'] : false;
  $blind    = $allData['blind'] ? $allData['blind'] : $result['inventoryCountBlind'];

  if($result['inventoryCountStatus'] > 1){
    ?>
    <div class="bg-white col-xs-12">
      <div class="wrapper text-center h2 font-bold">
        <div><i class="material-icons md-68 text-success">done</i></div>
        Conteo finalizado
      </div>
      <div class="col-xs-12 text-center">
        <?=$note?>
      </div>
      <div class="col-xs-12 wrapper">
        <a href="/screens/inventoryCount?s=<?=base64_encode(enc($result['inventoryCountId']).','.enc(COMPANY_ID))?>" class="btn btn-info btn-rounded text-u-c font-bold pull-right" target="_blank">Ver Reporte</a>
        <a href="#" class="cancelItemView m-r m-t-sm pull-right">Cerrar</a>
      
        <a href="#" class="deleteItem m-r m-t-sm pull-left" data-id="<?=$_GET['id']?>" data-name="<?=$name?>" data-load="<?=$baseUrl?>?action=delete&id=<?=$_GET['id']?>">
          <span class="text-danger">Eliminar</span>
        </a>
        
        
      </div>
    </div>
    <?php
    dai();
  }else{
    //$cats   = ncmExecute('SELECT COUNT(taxonomyId) as count FROM taxonomy WHERE taxonomyType = \'category\' AND ' . $SQLcompanyId);
  }

?>
  <div class="col-xs-12 no-padder bg-white">
    <form action="<?=$baseUrl;?>?action=update" method="post" id="editItem" name="editItem">
      <div class="col-xs-12 wrapper text-center">

        <div class="col-xs-12 text-center">
          <span class="text-xs font-bold text-u-c">Nombre del Conteo</span>
          <input type="text" class="form-control text-center maskRequiredText no-border b-b b-light no-bg font-bold" style="font-size:25px; height:55px;" name="name" placeholder="Indique un nombre" value="<?=$name?>" autocomplete="off">
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
          <textarea class="form-control" name="note"><?=$note;?></textarea>
        </div>

        <div class="col-xs-12 m-t-lg text-center">
          <?php
          $startCountURL = '/screens/inventoryCount?s=' . base64_encode(enc($result['inventoryCountId']).','.enc(COMPANY_ID));
          ?>
          <a href="<?=$startCountURL?>" class="h2 font-bold" target="_blank"><span class="text-info">Iniciar Conteo</span></a>
          <div class="m-t">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&amp;data=<?=$startCountURL?>" width="120">
            <div class="m-t-sm text-muted">Escanee para iniciar el conteo desde <br> su dispositivo móvil</div>
          </div>
        </div>
        
        <div class="col-xs-12 h4 wrapper m-t-sm">Seleccione las categorías que desee inventariar</div>

      <div class="col-xs-12 m-t no-padder">
        <?php echo selectInputCategory(json_decode($result['inventoryCountData']),true,'m-b input-lg chosen-select no-border b-b','category',false,'multiple'); ?>
      </div>

        <?php
        if(isset($_fullSettings['stockCountBlind']) && $_fullSettings['stockCountBlind']){
        ?>
          <input type="hidden" name="blind" value="1">
        <?php
        }else{
        ?>
          <div class="col-sm-6 col-xs-12 text-left m-t">
            <div class="text-xs font-bold text-u-c">Conteo a ciegas</div>
            <?=switchIn('blind',$blind ? true : false)?>
          </div>
        <?php
        }
        ?>

        <div class="col-sm-6 col-xs-12 text-left m-t">
          <div class="text-xs font-bold text-u-c">Listar precio de costo</div>
          <?=switchIn('cogs', $cogs)?>
        </div>
        
        <div class="col-sm-6 col-xs-12 text-right m-t hidden">
          <div class="text-xs font-bold text-u-c">Permitir afectar inventario</div>
          <?=switchIn('changeStock',(isset($result['inventoryChangeStock']) && $result['inventoryChangeStock']) ? true : false)?>
        </div>

      </div>
      

      <div class="col-xs-12 bg-light lter m-t wrapper">
          
          <a href="#" class="pull-left deleteItem m-r m-t" data-load="<?=$baseUrl?>?action=delete&id=<?=$_GET['id']?>"><span class="text-danger">Eliminar</span></a>
          

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
  if(!allowUser('items','delete',true)){
      jsonDieResult(['error'=>'No permissions']);
  }

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
  $singleRow = "";
  if(validateHttp('singleRow')){
    $singleRow = ' AND inventoryCountId = ' . $db->Prepare(dec(validateHttp('singleRow')));
  }

  $result = ncmExecute('SELECT * FROM inventoryCount WHERE companyId = ? ' . $roc . $singleRow . ' ORDER BY inventoryCountId DESC ' . $limits,[COMPANY_ID],false,true);

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

      $url = '<a href="/screens/inventoryCount?s=' . base64_encode(enc($fields['inventoryCountId']) . ',' . enc(COMPANY_ID)) . '" class="openLink text-md"><i class="material-icons text-info">launch</i></a>';


      $table .= '<tr data-type="loadItem" data-id="'.$itemId.'" data-element="#formItemSlot" data-load="' . $baseUrl . '?action=edit&id='.$itemId.'" class="clickrow pointer">' . 
                ' <td>' . toUTF8($fields['inventoryCountName']) . ' </td>' .
                ' <td data-order="'.$fields['inventoryCountDate'].'">' . 
                    niceDate($fields['inventoryCountDate'],true) . 
                ' </td>' .
                ' <td>' . 
                  toUTF8($outletName) . 
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

  <div class="col-xs-12 wrapper">
    <?=headerPrint();?>
    <span class="font-bold h1" id="pageTitle">
      Conteo de Stock
    </span>
    <a href="#" data-type="loadItem" data-element="#formItemSlot" data-load="<?=$baseUrl?>?action=insertForm" class="itemsAction btn btn-info btn-rounded text-u-c font-bold pull-right createItemBtn hidden-print">Nuevo Conteo</a>
  </div>

  <div class="col-xs-12 table-responsive wrapper bg-white panel r-24x push-chat-down tableContainer">
      <table class="table table1 table-hover col-xs-12 no-padder" id="tableTransactions"><?=placeHolderLoader('table')?></table>
  </div>

  <script>

    $(() => {
        var baseUrl   = '<?=$baseUrl;?>';
        var url       = baseUrl + "?action=generalTable";
        var rawUrl    = url;

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

            onClickWrap('.deleteItem',function(event,tis){
                var url   = tis.attr('data-load');
                var $row  = $('.editting');
                var conf  = confirm("Seguro que desea continuar?");

                if(conf == true) { 

                  $.get(url, function(response) {
                    if(response == 'true'){
                      message('Eliminado','success');
                    }else{
                      message('Error al eliminar','danger');
                    }
                  });

                  $('#modalSmall').modal('hide');
                  oTable.row($row).remove().draw();

                }
            });

            onClickWrap('.createItemBtn',function(event,tis){
                $.get(baseUrl + '?action=insert',function(response){
                  resp = response.split('|');
                  if(resp[0] == 'true'){
                    id = resp[2];

                    $.get(tableOps.url + '&part=1&singleRow=' + id,function(data){
                      var $tRow = $('tr[data-id="' + id + '"]');
                      if($tRow.length > 0){
                        oTable.row($tRow).remove().draw();
                      }
                      $tRow.addClass('editting');
                      oTable.row.add($(data)).draw();
                    });

                    loadForm(baseUrl + '?action=edit&id='+id,'#modalSmall .modal-content',function(){
                      $('#modalSmall').modal('show');
                    }); 

                  }else if(resp[0] == 'false'){
                    message('Error al intentar procesar su petición','danger');
                  }else if(resp[0] == 'max'){
                    $('#maxReached').modal('show');
                  }else if(response.error){
                    alert(response.error);
                  }else{
                    alert(resp[0]);
                    return false;
                  }
                });
            });

            $('#modalSmall').off('shown.bs.modal').on('shown.bs.modal', function() {

                $('select.chosen-select').select2({
                  placeholder   : "Seleccione",
                  theme         : "bootstrap",
                  language      : 'es'
                }).on('select2:select select2:unselect', function (e) {
                });

                switchit((tis) => {
                  
                },true);

                submitForm('#addItem,#editItem,#insertItem',function(element,id){
                  $('#modalSmall').modal('hide');
                  $.get(tableOps.url + '&part=1&singleRow=' + id,function(data){
                    var $tRow = $('.editting');
                    if($tRow.length > 0){
                      oTable.row($tRow).remove();
                      oTable.row.add($(data));
                      oTable.draw();
                    }
                  });
                });

            });

            onClickWrap('.openLink',function(event,tis){
                var url = tis.attr('href');
                window.open(url,'_blank');
            });

            onClickWrap('.clickrow',function(event,tis){
                $('.editting').removeClass('editting');
                if(!tis.hasClass('disabled')){
                  var load = tis.data('load');
                  tis.addClass('editting');
                  loadForm(load,'#modalSmall .modal-content',function(){
                    $('#modalSmall').modal('show');

                  });
                }
            });

            onClickWrap('.cancelItemView',function(event,tis){
                $('#modalSmall').modal('hide');
                tis.removeClass('editting');
            });
        };

    });

  </script>
  
<?php
include_once('includes/compression_end.php');
dai();
?>