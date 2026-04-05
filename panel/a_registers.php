<?php
include_once('includes/top_includes.php');
topHook();
allowUser('settings', 'view');

$baseUrl = '/' . basename(__FILE__, '.php');

$roc = getROC(1);

//Insertar Producto
if (validateHttp('action') == 'insert') {

  if (!allowUser('settings', 'edit', true)) {
    jsonDieResult(['error' => 'No permissions']);
  }

  if (checkPlanMaxReached('register', ($plansValues[PLAN]['max_registers'] * OUTLETS_COUNT) + $_modules['extraRegisters'], 'registerStatus = 1')) {
    dai('max');
  }

  $registerRecord['registerName']   = 'New Register';
  $registerRecord['registerStatus'] = '1';
  $registerRecord['outletId']       = OUTLET_ID;
  $registerRecord['companyId']      = COMPANY_ID;

  $registerInsert                   = $db->AutoExecute('register', $registerRecord, 'INSERT');
  $registerId                       = $db->Insert_ID();

  if ($registerInsert === false) {
    echo $db->ErrorMsg();
    echo 'false';
  } else {
    echo 'true|0|' . enc($registerId);
    updateLastTimeEdit();
  }

  dai();
}

if (validateHttp('action') == 'duplicate') {

  if (!allowUser('settings', 'edit', true)) {
    jsonDieResult(['error' => 'No permissions']);
  }

  if (checkPlanMaxReached('register', ($plansValues[PLAN]['max_registers'] * OUTLETS_COUNT) + $_modules['extraRegisters'])) {
    jsonDieResult(['error' => 'max_registers']);
  }

  if (!validateHttp('id')) {
    jsonDieResult(['error' => 'missing_id']);
  }

  $from       = dec(validateHttp('id'));
  $record     = [];

  $select     = ncmExecute('SELECT * FROM register WHERE registerId = ? LIMIT 1', [$from]);

  if ($select) {
    $record['registerName']                     = $select['registerName'] . ' [COPIA]';
    $record['registerInvoiceData']              = $select['registerInvoiceData'];
    $record['registerInvoiceAuth']              = $select['registerInvoiceAuth'];
    $record['registerInvoiceAuthExpiration']    = $select['registerInvoiceAuthExpiration'] . ' 23:59:59';
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

    if ($insert !== false) {
      updateLastTimeEdit();
      jsonDieResult(['error' => false, 'id' => $insert]);
    } else {
      jsonDieResult(['error' => 'error_inserting']);
    }
  }

  jsonDieResult(['error' => 'register_not_found']);
}

//Editar caja
if (validateHttp('action') == 'update' && validateHttp('id', 'post')) {
  if (!allowUser('settings', 'edit', true)) {
    jsonDieResult(['error' => 'No permissions']);
  }

  if (validateHttp('name', 'post') == '') {
    $name = 'Nueva Caja';
  } else {
    $name = validateHttp('name', 'post');
  }

  $auth           = validateHttp('auth', 'post');
  $expiration     = validateHttp('expiration', 'post');
  $prefix         = validateHttp('prefix', 'post');
  $sufix          = validateHttp('sufix', 'post');
  $invoice        = intval(validateHttp('invoice', 'post'));
  $ticket         = intval(validateHttp('ticket', 'post'));
  $return         = intval(validateHttp('return', 'post'));
  $remito         = intval(validateHttp('remito', 'post'));
  $quote          = intval(validateHttp('quote', 'post'));
  $purchaseorder  = intval(validateHttp('purchaseorder', 'post'));
  $order          = intval(validateHttp('order', 'post'));
  $schedule       = intval(validateHttp('schedule', 'post'));
  $zeros          = validateHttp('leadingZero', 'post');

  $authReturn               = validateHttp('authReturn', 'post');
  $expirationReturn         = validateHttp('expirationReturn', 'post');
  $registerReturnNoMax      = intval(validateHttp('registerReturnNoMax', 'post'));
  $registerReturnAuthStart  = validateHttp('registerReturnAuthStart', 'post');
  $prefixReturn             = validateHttp('prefixReturn', 'post');

  $invoiceData = '{"type":"' . validateHttp('type', 'post') . '","orderNo":"' . validateHttp('orderNo', 'post') . '","noPrevious":"' . validateHttp('noPrevious', 'post') . '","expirationDate":"' . validateHttp('expirationDate', 'post') . '","title":"' . validateHttp('title', 'post') . '","message":"' . validateHttp('message', 'post') . '","marginTop":"' . validateHttp('mTop', 'post') . '","marginLeft":"' . validateHttp('mLeft', 'post') . '","marginRight":"' . validateHttp('mRight', 'post') . '"}';

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
  $record['registerInvoiceNoMax']           = intval(validateHttp('registerInvoiceNoMax', 'post'));
  $record['registerInvoiceAuthStart']       = validateHttp('registerInvoiceAuthStart', 'post');
  $record['electronicInvoice']              = validateHttp('electronicInvoice', 'post');
  $record['registerReturnAuth']             = $authReturn;
  $record['registerReturnAuthExpiration']   = $expirationReturn;
  $record['registerReturnNoMax']            = $registerReturnNoMax;
  $record['registerReturnAuthStart']        = $registerReturnAuthStart;
  $record['registerReturnPrefix']           = $prefixReturn;

  if (isset($_POST['status']) && $_POST['status'] == 1) {
    $record['registerStatus']   = 1;
  } else {
    $record['registerStatus']   = 0;
  }

  $record['data']               = json_encode($record);

  $update = ncmUpdate(
    [
      'records'     => $record,
      'table'       => 'register',
      'where'       => 'registerId = ' . dec(validateHttp('id', 'post')) . ' AND companyId = ' . COMPANY_ID
    ]
  );

  if (!$update['error']) {
    echo 'true|0|' . validateHttp('id', 'post');
    updateLastTimeEdit();
  } else {
    //echo $db->ErrorMsg();
    echo 'false';
  }

  dai();
}

if (validateHttp('action') == 'edit' && validateHttp('id')) {
  $result   = ncmExecute("SELECT * FROM register WHERE registerId = ? AND companyId = ? LIMIT 1", [dec(validateHttp('id')), COMPANY_ID]);
  $jResult  = json_decode($result['data'], true);

  $chElectronic = 0;

  if (isset($result['data']) && !empty($result['data'])) {
    if (!empty($jResult['electronicInvoice']) && $jResult['electronicInvoice'] == 1) {
      $chElectronic = 'checked';
    }
  }

?>
  <div class="col-xs-12 no-padder bg-white">
    <form action="<?= $baseUrl ?>?action=update" method="post" id="editItem" name="editItem">
      <div class="col-xs-12 wrapper text-center">

        <?php
        $img = companyLogo();
        $disabled = '';
        if ($result['registerStatus'] < 1) {
          $disabled = 'disabled';

          echo '<div class="col-xs-12 wrapper bg-danger lter text-center font-bold r-3x">Inhabilitado</div>';
        }

        ?>

        <div class="col-xs-12">
          <a href="#" class="thumb-md" id="uploadImgBtn"> <img src="<?= $img ?>" class="img-circle itemImg"> </a>
          <input type="text" class="form-control text-center  no-border b-b b-light no-bg font-bold" style="font-size:25px; height:55px;" name="name" placeholder="Nombre de la Caja" value="<?= $result['registerName'] ?>" autocomplete="off">
        </div>

        <div class="col-sm-12 text-left no-padder">

          <div class="font-bold m-t text-md">Facturas</div>

          <div class="col-xs-12 no-padder m-b">
            <div class="col-sm-6 no-padder" style="padding-right:15px!important;">
              <label>No. de Timbrado o Autorización:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger m-r" name="auth" value="<?= $result['registerInvoiceAuth'] ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-6 no-padder">
              <label>Vencimiento:</label>
              <input type="text" class="form-control datepicker" name="expiration" value="<?= $result['registerInvoiceAuthExpiration'] ?>" <?= $disabled ?> />
            </div>
          </div>

          <div class="col-xs-12 no-padder m-b">
            <div class="col-sm-6 no-padder" style="padding-right:15px!important;">
              <label>Numeración máxima:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger m-r" name="registerInvoiceNoMax" value="<?= $jResult['registerInvoiceNoMax'] ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-6 no-padder">
              <label>Inicio:</label>
              <input type="text" class="form-control datepicker" name="registerInvoiceAuthStart" value="<?= $jResult['registerInvoiceAuthStart'] ?>" <?= $disabled ?> />
            </div>
          </div>

          <div class="col-xs-12 no-padder m-b-md">
            <div class="col-sm-3 no-padder">
              <label>Prefijo:</label>
              <input type="text" class="form-control" name="prefix" value="<?= $result['registerInvoicePrefix'] ?>" <?= $disabled ?> />
            </div>

            <div class="col-xs-3">
              <label>Cant. de dígitos:</label>
              <select name="leadingZero" class="form-control" autocomplete="off" <?= $disabled ?>>
                <?php
                for ($i = 0; $i < 11; $i++) {
                  $selected = '';
                  if ($i == $result['registerDocsLeadingZeros']) {
                    $selected = 'selected';
                  }
                  echo '<option value="' . $i . '" ' . $selected . '>' . $i . '</option>';
                }
                ?>
              </select>
            </div>
            <div class="col-xs-3">
              <label>No. de Factura:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="invoice" value="<?= $result['registerInvoiceNumber'] ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-3 no-padder">
              <label>Sufijo:</label>
              <input type="text" class="form-control" name="sufix" value="<?= $result['registerInvoiceSufix'] ?>" <?= $disabled ?> />
            </div>
          </div>

          <div class="font-bold m-t text-md">Nota de Crédito</div>

          <div class="col-xs-12 no-padder m-b">
            <div class="col-sm-6 no-padder" style="padding-right:15px!important;">
              <label>No. de Timbrado o Autorización:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger m-r" name="authReturn" value="<?= $jResult['registerReturnAuth'] ?? '' ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-6 no-padder">
              <label>Vencimiento:</label>
              <input type="text" class="form-control datepicker" name="expirationReturn" value="<?= $jResult['registerReturnAuthExpiration'] ?? '' ?>" <?= $disabled ?> />
            </div>
          </div>

          <div class="col-xs-12 no-padder m-b">
            <div class="col-sm-6 no-padder" style="padding-right:15px!important;">
              <label>Numeración máxima:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger m-r" name="registerReturnNoMax" value="<?= $jResult['registerReturnNoMax'] ?? '' ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-6 no-padder">
              <label>Inicio:</label>
              <input type="text" class="form-control datepicker" name="registerReturnAuthStart" value="<?= $jResult['registerReturnAuthStart'] ?? '' ?>" <?= $disabled ?> />
            </div>
          </div>

          <div class="col-xs-12 no-padder m-b">
            <div class="col-sm-6 no-padder" style="padding-right:15px!important;">
              <label>Prefijo:</label>
              <input type="text" class="form-control" name="prefixReturn" value="<?= $jResult['registerReturnPrefix'] ?? '' ?>" <?= $disabled ?> />
            </div>

            <div class="col-sm-6 no-padder">
              <label>No. de Nota de Crédito:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="return" value="<?= $result['registerReturnNumber'] ?? '' ?>" <?= $disabled ?> />
            </div>
          </div>

          <div class="font-bold m-t text-md">Otros documentos</div>
          <div class="col-xs-12 no-padder m-b">
            <div class="col-sm-4 no-padder">
              <label>No. de Recibo:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="ticket" value="<?= $result['registerTicketNumber'] ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-4">
              <label>No. de Remito o Remisión:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="remito" value="<?= $result['registerRemitoNumber'] ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-4 no-padder">
              <label>No. de Cita:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="schedule" value="<?= $result['registerScheduleNumber'] ?>" <?= $disabled ?> />
            </div>
          </div>

          <div class="col-xs-12 no-padder m-b">
            <div class="col-sm-4 no-padder">
              <label>No. de Cotización:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="quote" value="<?= $result['registerQuoteNumber'] ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-4">
              <label>No. de Orden de Compra:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="purchaseorder" value="<?= $result['registerOrderNumber'] ?>" <?= $disabled ?> />
            </div>
            <div class="col-sm-4 no-padder">
              <label>No. de Pedido:</label>
              <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="order" value="<?= $result['registerPedidoNumber'] ?>" <?= $disabled ?> />
            </div>
          </div>

          <div class="col-xs-12 no-padder m-b">
            <div class="col-sm-4 no-padder">

              <?php
              //if(ROLE_ID == 0){

              if ($result['registerStatus'] == 1) {
                $ch = 'checked';
              }
              ?>
              <label>Estado:</label><br>
              <input type="checkbox" name="status" value="1" <?= $ch ?>>
              <?php
              //}
              ?>
              <!--<label>No. de Orden de Compra:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="purchaseorder" value="<?= $result['registerOrderNumber'] ?>" />-->
            </div>

            <div class="col-sm-4">
              <div class="form-group">
                <label class="block">Factura Electrónica:</label>
                <input type="checkbox" name="electronicInvoice" value="1" <?= $chElectronic ?>>
              </div>
            </div>
            <!-- <div class="col-sm-4 no-padder">
              <label>No. de Pedido:</label>
                  <input type="number" min="0" step="1" class="form-control text-right maskInteger" name="order" value="<?= $result['registerPedidoNumber'] ?>"
            </div> -->
          </div>

        </div>
      </div>


      <div class="col-xs-12 wrapper bg-light lter m-t">
        <input class="btn btn-info pull-right btn-rounded btn-lg font-bold text-u-c" type="submit" value="Guardar">
        <!--<a href="#" class="pull-left deleteItem m-t" data-id="<?= $_GET['id'] ?>" data-load="<?= $baseUrl ?>?action=delete&id=<?= $_GET['id'] ?>"><span class="text-danger">Eliminar</span></a>-->
        <a href="#" class="pull-left duplicateItem m-t m-l" data-id="<?= $_GET['id'] ?>" data-load="<?= $baseUrl ?>?action=duplicate&id=<?= $_GET['id'] ?>">Duplicar</a>
        <a href="#" class="cancelItemView m-r-lg m-t pull-right">Cancelar</a>
        <input type="hidden" value="<?= $_GET['id'] ?>" name="id">

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

if (validateHttp('action') == 'delete' && validateHttp('id')) {
  if (!allowUser('settings', 'delete', true)) {
    jsonDieResult(['error' => 'No permissions']);
  }

  $delete = ncmExecute('DELETE FROM register WHERE registerId = ? AND ' . $SQLcompanyId . ' LIMIT 1', [dec($_GET['id'])]);
  if ($delete === false) {
    echo 'false';
  } else {
    echo 'true';
    updateLastTimeEdit();
  }
  dai();
}

if (validateHttp('list')) {
  $totalRegisters = ($plansValues[PLAN]['max_registers'] * OUTLETS_COUNT) + $_modules['extraRegisters'];
  $limit          = ''; //' LIMIT ' . $totalRegisters;

  $result = ncmExecute("SELECT * FROM register WHERE  registerId > 0" . $roc . $limit, [], false, true);

  $table = '<thead class="text-u-c">
              <tr>
                <th>Nombre</th>
                <th>Creado el</th>
                <th class="text-center">No. de Timbrado o Autorización</th>
                <th>Prefijo</th>
                <th class="text-center">No. de Factura</th>
                <th>Sufijo</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>';

  if ($result) {
    while (!$result->EOF) {
      $fields = $result->fields;
      $status = ($fields['registerStatus'] == '1') ? '<i class="material-icons text-success">check</i>' : '<i class="material-icons text-danger">close</i>';

      $itemId = enc($fields['registerId']);

      $table .= '<tr data-type="loadItem" data-id="' . $itemId . '" data-element="#formItemSlot" data-load="' . $baseUrl . '?action=edit&id=' . $itemId . '" class="clickrow">' .
        ' <td class="font-bold"> ' . $fields['registerName'] . ' </td>' .
        ' <td>' . niceDate($fields['registerCreationDate']) . '</td>' .
        ' <td class="text-right">' . $fields['registerInvoiceAuth'] . '</td>' .
        ' <td>' . $fields['registerInvoicePrefix'] . '</td>' .
        ' <td class="text-right">' . leadingZeros($fields['registerInvoiceNumber'], $fields['registerDocsLeadingZeros']) . '</td>' .
        ' <td>' . $fields['registerInvoiceSufix'] . '</td>' .
        ' <td>' . $status . '</td>' .
        '</tr>';

      $result->MoveNext();
    }

    $table .= '</tbody>' .
      '<tfoot><tr><td colspan="7"></td></tr></tfoot>';

    $result->Close();
  }

  dai($table);
}

?>

<div class="col-xs-12 wrapper-sm">
  <a href="javascript:;" data-type="loadItem" data-element="#formItemSlot" data-load="<?= $baseUrl ?>?action=insertForm" class="itemsAction btn btn-info font-bold text-u-c rounded pull-right createItemBtn hidden-print">Agregar Caja</a>
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

<div class="col-xs-12 panel wrapper r-24x push-chat-down tableContainer">

  <table class="table hover col-xs-12 no-padder" id="registersTable">
    <?= placeHolderLoader('table'); ?>
  </table>

</div>

<script>
  $(function() {
    var baseUrl = '<?= $baseUrl ?>';

    FastClick.attach(document.body);

    var info1 = {
      "container": ".tableContainer",
      "url": baseUrl + "?list=true",
      "table": "#registersTable",
      "sort": 0
    };

    manageTable(info1);

    onClickWrap('.createItemBtn', function(event, tis) {

      $.get(baseUrl + '?action=insert', function(response) {
        response = response.split('|');
        if (response[0] == 'true') {
          //message('Acción realizada exitosamente','success');
          id = response[2];

          manageTable(info1);

          loadForm(baseUrl + '?action=edit&id=' + id, '#modalSmall .modal-content', function() {
            $('#modalSmall').modal('show');
          });

        } else if (response[0] == 'false') {
          message('Error al intentar procesar su petición', 'danger');
        } else if (response[0] == 'max') {
          ncmDialogs.confirm('Ha alcanzado el límite', 'Contáctenos y le asistiremos para incrementar', 'warning');
        } else {
          alert(response[0]);
          return false;
        }
      });
    });

    onClickWrap('#registersTable .clickrow', function(event, tis) {
      checkIfAdmin();
      var load = tis.data('load');
      loadForm(load, '#modalSmall .modal-content', function() {
        $('#modalSmall').modal('show');
      });
    });

    onClickWrap('.cancelItemView', function(event, tis) {
      $('#modalSmall').modal('hide');
    });

    $('#modalSmall').off('shown.bs.modal').on('shown.bs.modal', function() {

      submitForm('#addItem,#editItem,#insertItem', (element, id) => {
        manageTable(info1);
        $('#modalSmall').modal('hide');
      });

      onClickWrap('.deleteItem', (event, tis) => {
        var load = tis.data('load');
        $.get(load, function(response) {
          if (!response.error) {

            $('#modalSmall').modal('hide');
            manageTable(info1);

            message('Caja eliminada', 'success');
          } else {
            message('Error al eliminar', 'danger');
          }
        });
      });

      onClickWrap('.duplicateItem', (event, tis) => {
        var load = tis.data('load');
        $.get(load, (response) => {
          if (!response.error) {

            $('#modalSmall').modal('hide');
            manageTable(info1);

            message('Caja duplicada', 'success');
          } else {
            message('Error al duplicar', 'danger');
          }
        });
      });

    });

  });


  var checkIfAdmin = function() {
    var state = $('.role').val();
    if (state == 1) {
      $(".pass").prop('disabled', false).val('');
      //$('.pass').removeClass('disabled').val('');
    } else {
      $(".pass").prop('disabled', true).val('');
      //$('.pass').addClass('disabled').val('');
    }
  };
</script>

<?php
dai();
?>