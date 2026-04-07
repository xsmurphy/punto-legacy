<?php
include_once('includes/compression_start.php');
include_once("includes/secure.php");
include_once("includes/db.php");
include_once('includes/simple.config.php');
include_once("includes/config.php");
include_once("languages/" . LANGUAGE . ".php");
include_once("includes/functions.php");
include_once("libraries/countries.php");
theErrorHandler(); //error handler

if (COMPANY_ID != ENCOM_COMPANY_ID) {
  header('location:/login');
  dai();
}

$userData       = ncmExecute('SELECT * FROM contact WHERE companyId = ? AND contactId = ? LIMIT 1', [COMPANY_ID, USER_ID]);
$userJData      = json_decode($userData['data'], true);
$userPermission = $userJData[0]['permissions']['encom'];

$limitDetail    = 200;
$offsetDetail   = 0;
$statuses       = ['Active', 'Pending', 'Deactivate'];

list($calendar, $startDate, $endDate, $lessDays) = datesForGraphs(1);

//Editar empresa
if (validateHttp('action') == 'update' && validateHttp('cid', 'post')) {

  $users    = NULL;
  $pUsers   = validateHttp('users', 'post');
  $pCompId  = dec(validateHttp('cid', 'post'));

  if ($pUsers) {
    $users = [];
    foreach ($pUsers as $value) {
      $users[] = dec($value);
    }
    $users = json_encode($users);
  }

  $companyRecord  = [];
  $settingRecord  = [];
  $userRecord     = [];
  $modulesRecord  = [];
  //
  $companyRecord['companyName']           = validateHttp('storename', 'post');
  $companyRecord['status']         = validateHttp('status', 'post');
  $companyRecord['plan']           = validateHttp('plan', 'post');
  //$companyRecord['balance']        = validateHttp('balance','post');
  $companyRecord['discount']       = validateHttp('discount', 'post');
  $companyRecord['smsCredit']      = validateHttp('smscredit', 'post');
  //$companyRecord['expiresAt']      = validateHttp('expires','post');

  $companyRecord['encomUsers']            = $users;
  $settingInsert                          = $db->AutoExecute('company', $companyRecord, 'UPDATE', 'companyId = ' . $pCompId);

  //
  $settingRecord['settingName']           = validateHttp('storename', 'post');
  $settingRecord['slug']           = validateHttp('storeslug', 'post');
  $settingRecord['settingCountry']        = validateHttp('country', 'post');
  $settingRecord['planExpired']    = iftn(validateHttp('expired', 'post'), NULL);
  $settingRecord['settingPartialBlock']   = iftn(validateHttp('lockPanel', 'post'), NULL);
  $settingRecord['blocked']        = iftn(validateHttp('lockAccount', 'post'), NULL);
  $settingRecord['settingEncomID']        = dec(validateHttp('encomCustomerId', 'post'));
  $settingRecord['settingAutoSMSCredit']  = iftn(validateHttp('autoSMSCredit', 'post'), NULL);

  $modulesRecord['extraUsers']            = validateHttp('extraUsers', 'post');
  $modulesRecord['extraRegisters']        = validateHttp('extraRegisters', 'post');
  $modulesRecord['epos']                  = validateHttp('epos', 'post');

  $exists = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1',[COMPANY_ID]);
  $__modules 				= json_decode($exists['moduleData'],true);
  $__modules 				= is_array($__modules) ? $__modules : [];
  
  if(validateHttp('extraItems', 'post')){

    $__modules["extraItems"] = validateHttp('extraItems', 'post');  

  }else{
    $__modules["extraItems"] = 0;
  }

  $electronicInvoiceStatus = 0;
  if(validateHttp('electronicInvoice', 'post')){
    if(is_numeric(validateHttp('electronicInvoice', 'post'))){
      $electronicInvoiceStatus = validateHttp('electronicInvoice', 'post');
    }
    $__modules["electronicInvoice"]['status'] = (int)$electronicInvoiceStatus;  
  }else {
    $__modules["electronicInvoice"]['status'] = (int)$electronicInvoiceStatus;  
  }

  $modulesRecord['moduleData'] = json_encode($__modules);

  $modulesRecord['eposData']              = json_encode([
    /*'idCommerce'              => validateHttp('idCommerce','post'),
                                                          'idBepsa'                 => validateHttp('idBepsa','post'),
                                                          'hash'                    => validateHttp('hash','post'),*/
    'rate'                    => validateHttp('rate', 'post'),
    'rateDebit'               => validateHttp('rateDebit', 'post'),
    'eposCard'                => validateHttp('eposCard', 'post'),
    'rateOnline'              => validateHttp('rateOnline', 'post'),
    'depositDays'             => validateHttp('depositDays', 'post'),
    'tax'                     => 10, //validateHttp('tax','post'),
    'customerPays'            => validateHttp('customerPays', 'post'),
    'customerRate'            => 6, //validateHttp('customerRate','post'),
    'bankName'                => validateHttp('bankName', 'post'),
    'bankAccount'             => validateHttp('bankAccount', 'post'),
    'bankBeneficiary'         => validateHttp('bankBeneficiary', 'post'),
    'bankBeneficiaryLID'      => validateHttp('bankBeneficiaryLID', 'post'),
    'bankBeneficiaryTypeLID'  => validateHttp('bankBeneficiaryTypeLID', 'post'),
    'confirmClientPaymentLink'  => validateHttp('confirmClientPaymentLink', 'post'),
    'paymentLimitAmount'      => validateHttp('paymentLimitAmount', 'post'),
    'paymentExpiredHoursIn'      => validateHttp('paymentExpiredHoursIn', 'post'),
    'pix'      => validateHttp('pix', 'post'),
    'idPixClient'      => validateHttp('idPixClient', 'post'),
    'keyPixClient'      => validateHttp('keyPixClient', 'post'),
  ]);

  $UID                                    = validateHttp('uid', 'post');

  $modulesInsert  = $db->AutoExecute('module', $modulesRecord, 'UPDATE', 'companyId = ' . $pCompId);
  $settingInsert  = $db->AutoExecute('setting', $settingRecord, 'UPDATE', 'companyId = ' . $pCompId);
  $userInsert     = $db->AutoExecute('contact', $userRecord, 'UPDATE', 'contactId = ' . $UID . ' AND companyId = ' . $pCompId);

  echo 'true|0|' . enc($pCompId);
  updateLastTimeEdit($pCompId);
  dai();
}

//enter company
if (validateHttp('url')) {
  $_SESSION['ncmCache']             = false;
  $sess = getCompanyLoginSession(dec(validateHttp('companyId')), true);

  //echo json_encode($sess);
  //dai();

  header('location:/@#dashboard');
  dai();
}

if (validateHttp('action') == 'editForm') {
  $pId        = validateHttp('id');
  $DpId       = dec($pId);
  $result     = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$DpId]);
  $settings   = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$DpId]);
  $modules    = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$DpId]);
  $user       = ncmExecute('SELECT contactId, contactEmail, contactPhone, contactName FROM contact WHERE role = 1 AND main = \'true\' AND type = 0 AND companyId = ? LIMIT 1', [$DpId]);

  $outlets    = ncmExecute('SELECT COUNT(*) as count FROM outlet WHERE companyId = ?', [$DpId]);
  $registers  = ncmExecute('SELECT COUNT(*) as count FROM register WHERE companyId = ?', [$DpId]);

  $users      = ncmExecute('SELECT COUNT(*) as count FROM contact WHERE type = 0 AND companyId = ?', [$DpId]);
  $customers  = ncmExecute('SELECT COUNT(*) as count FROM contact WHERE type = 1 AND companyId = ?', [$DpId]);

  $plan       = $plansValues[$result['plan']];

  if (!$result) {
    echo '<h1 class="block text-center">Esta empresa ya no existe</h1>';
    dai();
  }

  $cat = $settings['settingCompanyCategoryId'];

  $mainCat = explode('.', $cat); //explodes('.',$cat.'',false,0);
  $mainCat = $mainCat[0];

  $catName = getCompanyCategoryName($companyCategories, $cat);

  $bg = 'gradBgBlue';
  if ($mainCat == '0') {
    $bg = 'gradBgRed';
  } else if ($mainCat == '1') {
    $bg = 'gradBgGreen';
  } else if ($mainCat == '2') {
    $bg = 'gradBgBlue';
  } else if ($mainCat == '3') {
    $bg = 'gradBgOrange';
  } else if ($mainCat == '4') {
    $bg = 'gradBgYellow';
  } else if ($mainCat == '5') {
    $bg = 'gradBgPurple';
  } else if ($mainCat == '6') {
    $bg = 'gradBgGray';
  }
?>
  <div class="col-xs-12 no-padder">
    <form action="main?action=update" method="post" id="editItem" name="newAccount">

      <div class="col-xs-12 wrapper">
        <div class="col-xs-12 text-center m-b">
          <div class="h2 font-bold"><?= unXss($settings['settingName']) ?></div>

          <div class="text-center"><?= ($user['contactEmail'] ? unXss($user['contactEmail']) : unXss($user['contactPhone'])) ?></div>
          <?php
          $moduleData = json_decode($modules['moduleData'], true);
          ?>

          <div class="col-xs-12 no-padder r-24x b text-left m-t">
            <div class="col-sm-6 m-t m-b">
              <strong>Sucursales: </strong> <span class="pull-right"><?= formatQty($outlets['count']); ?></span>
              <br>
              <strong>Cajas: </strong> <span class="pull-right"><?= formatQty($registers['count']); ?> / <?= formatQty(($plan['max_registers'] * $outlets['count']) + $modules['extraRegisters']); ?></span>
              <br>
              <strong>Usuarios: </strong> <span class="pull-right"><?= formatQty($users['count']); ?> / <?= formatQty(($plan['max_users'] * $outlets['count']) + $modules['extraUsers']); ?></span>
              <br>
              <strong>Clientes: </strong> <span class="pull-right"><?= formatQty($customers['count']); ?> / <?= formatQty($plan['max_customers'] * $outlets['count']); ?></span>
            </div>
            <div class="col-sm-6 m-t m-b">
              <strong>Plan: </strong> <span class="pull-right"><?= $plan['name']; ?></span>
              <br>
              <strong>Valor: </strong> <span class="pull-right"><?= formatCurrentNumber($plan['price']); ?></span>
              <br>
              <strong>Descuento: </strong> <span class="pull-right"><?= formatCurrentNumber($result['discount']); ?></span>
              <br>
              <strong>Usuarios Extra: </strong> <span class="pull-right"><?= formatQty($modules['extraUsers']); ?> / <?= formatCurrentNumber($modules['extraUsers'] * 5.00); ?></span>
              <br>
              <strong>Cajas Extra: </strong> <span class="pull-right"><?= formatQty($modules['extraRegisters']); ?> / <?= formatCurrentNumber($modules['extraRegisters'] * 5.00); ?></span>
              <br>
              <strong>Productos Extra: </strong> <span class="pull-right"><?= formatQty($moduleData['extraItems'] ?? 0); ?></span>
              <br>

            </div>
          </div>

          <small class="block text-center hidden bg-light">//activate?i=<?= base64_encode(enc($result['companyId'])) ?>&a=<?= base64_encode($result['accountId']) ?></small>

          <a class="btn btn-rounded btn-default hidden" target="_blank" href="/screens/storyMaker?name=<?= $settings['settingName'] ?>&country=<?= $countries[$settings['settingCountry']]['name'] ?>&color=<?= $bg ?>&cat=<?= $mainCat ?>&catname=<?= $catName ?>&type=story">Historia</a>

          <a class="btn btn-rounded btn-default hidden" target="_blank" href="/screens/storyMaker?name=<?= $settings['settingName'] ?>&country=<?= $countries[$settings['settingCountry']]['name'] ?>&color=<?= $bg ?>&cat=<?= $mainCat ?>&catname=<?= $catName ?>&type=post">Post</a>
        </div>
        <input type="email" name="email" value="fake.field.to.prevent.safari@from-filling.com" tabindex="-1" style="top:-100px; position:absolute;">
        <div class="col-sm-6 col-xs-12">

          <label class="font-bold text-u-c">Nombre</label>
          <input type="text" class="form-control m-b" placeholder="Empresa" value="<?= unXss($settings['settingName']) ?>" name="storename" autocomplete="off" />

          <label class="font-bold text-u-c">Slug</label>
          <input type="text" class="form-control m-b" placeholder="Slug" value="<?= unXss($settings['slug']) ?>" name="storeslug" autocomplete="off" />

          <label class="font-bold text-u-c">Propietario</label>
          <input type="text" class="form-control m-b" placeholder="Propietario" value="<?= unXss($user['contactName']) ?>" name="username" autocomplete="off" />

          <label class="font-bold text-u-c">Plan</label>
          <select name="plan" class="form-control m-b" autocomplete="off">
            <?php
            foreach ($plansValues as $index => $obj) {
              if ($obj['id'] == $result['plan']) {
                $selected = 'selected';
              } else {
                $selected = '';
              }

              $name = $obj['name'];
              if ($name == 'Free') {
                $name = 'Bloqueo de cuenta';
              }

              $name = $name . ' ' . $obj['price'];

              echo '<option value="' . $obj['id'] . '" ' . $selected . '>' . $name . '</option>';
            }
            ?>
          </select>

          <div class="form-group">
            <label class="font-bold text-u-c">Alerta vencimiento</label>
            <input type="checkbox" name="expired" class="m-l m-b pull-right" value="1" <?= ($settings['planExpired']) ? 'checked' : '' ?>>
          </div>

          <div class="form-group">
            <label class="font-bold text-u-c">Bloquear Panel</label>
            <input type="checkbox" name="lockPanel" class="m-l m-b pull-right" value="1" <?= ($settings['settingPartialBlock']) ? 'checked' : '' ?>>
          </div>

          <div class="form-group">
            <label class="font-bold text-u-c">Bloquear toda la cuenta</label>
            <input type="checkbox" name="lockAccount" class="m-l m-b pull-right" value="1" <?= ($settings['blocked']) ? 'checked' : '' ?>>
          </div>

          <div class="form-group">
            <label class="font-bold text-u-c">Recargar SMS</label>
            <input type="checkbox" name="autoSMSCredit" class="m-l m-b pull-right" value="1" <?= ($settings['settingAutoSMSCredit']) ? 'checked' : '' ?>>
          </div>

          <label class="font-bold text-u-c">Crédito SMS</label>
          <input type="text" class="form-control m-b" name="smscredit" value="<?= $result['smsCredit'] ?>" autocomplete="off" />

          <h3 class="font-bold block">ePOS</h3>

          <div class="form-group">
            <label class="font-bold text-u-c">Habilitar ePOS</label>
            <input type="checkbox" name="epos" class="m-l m-b pull-right" value="1" <?= ($modules['epos']) ? 'checked' : '' ?>>
          </div>

          <?php
          $eposData = json_decode($modules['eposData'], true);
          ?>

          <div class="form-group">
            <label class="font-bold text-u-c">POS Físico</label>
            <input type="checkbox" name="eposCard" class="m-l m-b pull-right" value="1" <?= ($eposData['eposCard']) ? 'checked' : '' ?>>
          </div>

          <div class="form-group">
            <label class="font-bold text-u-c">Validacion Electro. Link de Pago</label>
            <input type="checkbox" name="confirmClientPaymentLink" class="m-l m-b pull-right" value="1" <?= ($eposData['confirmClientPaymentLink']) ? 'checked' : '' ?>>
          </div>

          <div class="form-group">
            <label class="font-bold text-u-c">Habilitar PIX</label>
            <input type="checkbox" name="pix" class="m-l m-b pull-right" value="1" <?= ($eposData['pix']) ? 'checked' : '' ?>>
          </div>

          <div class="col-xs-12 no-padder">

            <label class="font-bold text-u-c">% Comisión T. débito</label>
            <input type="text" class="form-control m-b" name="rateDebit" value="<?= ($eposData['rateDebit']) ? $eposData['rateDebit'] : 3.5 ?>" autocomplete="off" />

            <label class="font-bold text-u-c">% Comisión T. crédito</label>
            <input type="text" class="form-control m-b" name="rate" value="<?= ($eposData['rate']) ? $eposData['rate'] : 5.5 ?>" autocomplete="off" />

            <label class="font-bold text-u-c">% Comisión online</label>
            <input type="text" class="form-control m-b" name="rateOnline" value="<?= ($eposData['rateOnline']) ? $eposData['rateOnline'] : 5.5 ?>" autocomplete="off" />

            <label class="font-bold text-u-c">Días para desembolso (Crédito)</label>
            <input type="text" class="form-control m-b" name="depositDays" value="<?= ($eposData['depositDays'] !== null) ? $eposData['depositDays'] : 2 ?>" autocomplete="off" />

            <!-- <label class="font-bold text-u-c">ID de Cliente PIX</label>
            <input type="text" class="form-control m-b" name="idPixClient" value="<?= ($eposData['idPixClient'] !== null) ? $eposData['idPixClient'] : '' ?>" autocomplete="off" />

            <label class="font-bold text-u-c">Clave de Cliente PIX</label>
            <input type="text" class="form-control m-b" name="keyPixClient" value="<?= ($eposData['keyPixClient'] !== null) ? $eposData['keyPixClient'] : '' ?>" autocomplete="off" /> -->

            <label class="font-bold text-u-c hidden">Comisión al cliente</label>
            <input type="checkbox" name="customerPays" class="m-l m-b pull-right hidden" value="1" <?= ($eposData['customerPays']) ? 'checked' : '' ?>>

          </div>

          <div class="col-xs-12 no-padder m-t hidden">
            <div class="col-xs-12 no-padder h4 font-bold m-b">Dinelco</div>

            <label class="font-bold text-u-c">ID Commerce</label>
            <input type="text" class="form-control m-b" name="idCommerce" value="<?= $eposData['idCommerce'] ?>" autocomplete="off" />

            <label class="font-bold text-u-c">ID</label>
            <input type="text" class="form-control m-b" name="idBepsa" value="<?= $eposData['idBepsa'] ?>" autocomplete="off" />

            <label class="font-bold text-u-c">Hash</label>
            <input type="text" class="form-control m-b" name="hash" value="<?= $eposData['hash'] ?>" autocomplete="off" />
          </div>

        </div>

        <div class="col-sm-6 col-xs-12">
          <div class="form-group">
            <label class="font-bold text-u-c">País</label>
            <select name="country" class="form-control m-b" autocomplete="off">
              <?php
              foreach ($countries as $key => $val) {
                $selected = '';
                if ($settings['settingCountry'] == $key) {
                  $selected = 'selected';
                }
                echo '<option value="' . $key . '" ' . $selected . '>' . $val['name'] . '</option>';
              }
              ?>
            </select>
          </div>

          <div class="form-group">
            <label class="font-bold text-u-c">Estado</label>
            <select name="status" class="form-control m-b" autocomplete="off">
              <?php
              foreach ($statuses as $key) {
                if ($key == $result['status']) {
                  $selected = 'selected';
                } else {
                  $selected = '';
                }

                echo '<option value="' . $key . '" ' . $selected . '>' . $key . '</option>';
              }
              ?>
            </select>
          </div>

          <!-- <label class="font-bold text-u-c">Usuarios asignados a la cuenta</label>
          <?php
          //echo selectInputUser(json_decode($result['encomUsers']), true, 'm-b chosen-select no-border b-b', 'users', false, 'multiple');
          ?> -->

          <!-- <div class="form-group m-t">
            <label class="font-bold text-u-c">Asociar cliente en ENCOM</label>
            <select name="encomCustomerId" class="form-control m-b chosen-select" autocomplete="off">
              <option value="">Sin Seleccionar</option>
              <?php
              // $ncmCusId     = ncmExecute('SELECT contactName, contactSecondName,contactId FROM contact WHERE companyId IN(15,4456,4457) AND type = 1');

              // ncmWhile($ncmCusId, function ($result, $vars) {
              //   $settings           = $vars[0];
              //   $selected           = '';
              //   if ($settings['settingEncomID'] == $result['contactId']) {
              //     $selected = 'selected';
              //   }

              //   echo '<option value="' . enc($result['contactId']) . '" ' . $selected . '>' . $result['contactName'] . ' (' . $result['contactSecondName'] . ')' . '</option>';
              // }, [$settings]);
              ?>
            </select>
          </div> -->

          <label class="font-bold text-u-c">Usuarios Extra</label>
          <input type="text" class="form-control m-b text-right" name="extraUsers" value="<?= $modules['extraUsers'] ?>" autocomplete="off" />

          <label class="font-bold text-u-c">Cajas Extra</label>
          <input type="text" class="form-control m-b text-right" name="extraRegisters" value="<?= $modules['extraRegisters'] ?>" autocomplete="off" />

          <label class="font-bold text-u-c">Productos Extra</label>
          <input type="text" class="form-control m-b text-right" name="extraItems" value="<?= $moduleData['extraItems'] ?? 0 ?>" autocomplete="off" />

          <?php
            $moduleData = json_decode($modules['moduleData'], true);
            $electronicInvoice = '';
            if (isset($moduleData['electronicInvoice']) && ($moduleData['electronicInvoice']['status'] === 1)) {
              $electronicInvoice = 'checked';
            }
            ?>
          <div class="form-group">
            <label class="font-bold text-u-c">Habilitar Factura Electronica</label>
            <input type="checkbox" name="electronicInvoice" class="m-l m-b pull-right" value="1" <?= $electronicInvoice ?>>
          </div>

          <div class="col-xs-12 no-padder m-t-lg">

            <h3 class="font-bold block">ePOS Cuenta</h3>

            <label class="font-bold text-u-c">Banco</label>
            <input type="text" class="form-control m-b" name="bankName" value="<?= $eposData['bankName'] ?>" autocomplete="off" />

            <label class="font-bold text-u-c">Nro. de cuenta</label>
            <input type="text" class="form-control m-b" name="bankAccount" value="<?= $eposData['bankAccount'] ?>" autocomplete="off" />

            <label class="font-bold text-u-c">Beneficiario</label>
            <input type="text" class="form-control m-b" name="bankBeneficiary" value="<?= $eposData['bankBeneficiary'] ?>" autocomplete="off" />

            <label class="font-bold text-u-c">Tipo de Documento</label>
            <input type="text" class="form-control m-b" name="bankBeneficiaryTypeLID" value="<?= $eposData['bankBeneficiaryTypeLID'] ? $eposData['bankBeneficiaryTypeLID'] : 'CI' ?>" autocomplete="off" />

            <label class="font-bold text-u-c">Nro. de Documento</label>
            <input type="text" class="form-control m-b" name="bankBeneficiaryLID" value="<?= $eposData['bankBeneficiaryLID'] ?>" autocomplete="off" />
            <?php
            $ecomData = json_decode($modules['ecom_data'], true);
            $ecomCard = '';
            if (isset($ecomData['payments']) && ($ecomData['payments'] === 'cc' ||  $ecomData['payments'] === 'ccc')) {
              $ecomCard = 'checked';
            }
            ?>
            <div class="form-group">
              <label class="font-bold text-u-c">Pago Online en Ecommerce</label>
              <input type="checkbox" name="payments" disabled class="m-l m-b pull-right" value="1" <?= $ecomCard ?>>
            </div>

            <div class="form-group">
              <label class="font-bold text-u-c">Monto máximo de link de pago</label>
              <input type="text" class="form-control m-b" name="paymentLimitAmount" value="<?= ($eposData['paymentLimitAmount']) ? $eposData['paymentLimitAmount'] : 0 ?>" autocomplete="off" />
            </div>
            <div class="form-group">
              <label class="font-bold text-u-c">Vencimiento en Horas del link de pago</label>
              <input type="text" class="form-control m-b" name="paymentExpiredHoursIn" value="<?= ($eposData['paymentExpiredHoursIn']) ? $eposData['paymentExpiredHoursIn'] : 24 ?>" autocomplete="off" />
            </div>
          </div>

        </div>
      </div>

      <div class="col-xs-12 wrapper bg-light lter m-t">
        <a href="#" id="deleteAccount" class="pull-left m-t" data-id="<?= enc($settings['companyId']); ?>" data-load="?action=delete&id=<?= $pId ?>"><span class="text-danger">Eliminar</span></a>

        <input class="btn btn-info text-u-c btn-lg font-bold btn-rounded pull-right" type="submit" value="Guardar">
        <a href="#" class="cancelItemView m-r m-t pull-right">Cancelar</a>
        <input type="hidden" value="<?= enc($user['contactId']) ?>" name="uid">
        <input type="hidden" value="<?= enc($result['companyId']) ?>" name="cid">
      </div>
    </form>
  </div>
<?php
  dai();
}

if (validateHttp('action') == 'delete' && validateHttp('id')) {
  $errors     = '';
  $db->debug  = false;
  $db->StartTrans();

  if (validateHttp('decoded') == 'si') {
    $id         = validateBool('id');
  } else {
    $id         = dec(validateBool('id'));
  }

  //dai('Decoded Id: ' . $id . ' Coded: ' . validateHttp('id'));

  $outlets    = ncmExecute('SELECT STRING_AGG(outletId::text, \',\') as oids FROM outlet WHERE companyId = ?', [$id]);
  $errors .= '1. ' . $db->ErrorMsg() . '\n';

  $db->Execute('DELETE FROM accountCategory WHERE companyId = ?', [$id]);
  $errors .= '2. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM comission WHERE companyId = ?', [$id]);
  $errors .= '3. ' . $db->ErrorMsg() . '\n';
  //--company elimino mas adelante
  $db->Execute('DELETE FROM companyHours WHERE companyId = ?', [$id]);
  $errors .= '4. ' . $db->ErrorMsg() . '\n';
  //--contacts elimino mas adelante
  $db->Execute('DELETE FROM cpayments WHERE companyId = ?', [$id]);
  $errors .= '5. ' . $db->ErrorMsg() . '\n';

  $db->Execute('DELETE FROM drawer WHERE companyId = ?', [$id]);
  $errors .= '6. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM expenses WHERE companyId = ?', [$id]);
  $errors .= '7. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM inventory WHERE companyId = ?', [$id]);
  $errors .= '8. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM register WHERE companyId = ?', [$id]);
  $errors .= '9. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM reminder WHERE companyId = ?', [$id]);
  $errors .= '10. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM taxonomy WHERE companyId = ?', [$id]);
  $errors .= '11. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM stock WHERE companyId = ?', [$id]);
  $errors .= '12. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM stockTrigger WHERE outletId IN (?)', [$outlets['oids']]);
  $errors .= '13. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM satisfaction WHERE companyId = ?', [$id]);
  $errors .= '14. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM production WHERE companyId = ?', [$id]);
  $errors .= '15. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM notify WHERE companyId = ?', [$id]);
  $errors .= '16. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM giftCardSold WHERE companyId = ?', [$id]);
  $errors .= '17. ' . $db->ErrorMsg() . '\n';

  $allOutlet = ncmExecute('SELECT * FROM outlet WHERE companyId = ?', [$id], false, true);
  $errors .= '18. ' . $db->ErrorMsg() . '\n';

  if ($allOutlet) {
    while (!$allOutlet->EOF) {
      $db->Execute('DELETE FROM toItemLocation WHERE outletId = ?', [$allOutlet->fields['outletId']]);
      $errors .= '19. ' . $db->ErrorMsg() . '\n';
      $allOutlet->MoveNext();
    }
  }

  $db->Execute('DELETE FROM contact WHERE companyId = ? AND type IN (0,2)', array($_GET['id']));
  $errors .= '20. ' . $db->ErrorMsg() . '\n'; //no borro los cliente solo users y suppliers para poder crear un mailing list
  $db->Execute('DELETE FROM contact WHERE companyId = ? AND type = 1 AND contactEmail = ""', array($_GET['id']));
  $errors .= '21. ' . $db->ErrorMsg() . '\n'; //elimino los clientes que no tienen email

  $result = ncmExecute('SELECT STRING_AGG(transactionId::text, \',\') as ids FROM transaction WHERE companyId = ?', [$id]);
  $errors .= '22. ' . $db->ErrorMsg() . '\n';

  if ($result && counts($result['ids']) > 0) {
    $db->Execute('DELETE FROM itemSold WHERE transactionId IN(' . $result['ids'] . ')');
    $errors .= '23. ' . $db->ErrorMsg() . '\n';
    $db->Execute('DELETE FROM transaction WHERE companyId = ?', [$id]);
    $errors .= '24. ' . $db->ErrorMsg() . '\n';
  }

  $db->Execute('DELETE FROM outlet WHERE companyId = ?', [$id]);
  $errors .= '25. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM item WHERE companyId = ?', [$id]);
  $errors .= '26. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM stock WHERE companyId = ?', [$id]);
  $errors .= '27. ' . $db->ErrorMsg() . '\n';

  //selecciono todas las sucursales y voy eliminando una a una
  //elimino todos los contactos
  //elimino todos los items

  $result = ncmExecute('SELECT itemId FROM item WHERE companyId = ?', [$id], false, true);
  $errors .= '28. ' . $db->ErrorMsg() . '\n';
  $items  = [];

  if ($result) {

    while (!$result->EOF) {
      $items[] = $result->fields['itemId'];
      $result->MoveNext();
    }
    $result->Close();

    if (validity($items, 'array')) {
      $ids = implodes(',', $items);
      deleteItemBulk($ids, $id);
    }
  }

  $db->Execute('DELETE FROM contact WHERE companyId = ?', [$id]);
  $errors .= '29. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM company WHERE companyId = ?', [$id]);
  $errors .= '30. ' . $db->ErrorMsg() . '\n';
  $db->Execute('DELETE FROM company WHERE companyId = ?', [$id]);
  $errors .= '31. ' . $db->ErrorMsg() . '\n';


  $failedTransaction  = $db->HasFailedTrans();
  $db->CompleteTrans();

  if ($failedTransaction) {
    echo 'Errores encontrados: ' . $errors;
  } else {
    echo 'true';
  }
  dai();
  //poner id de empresa al inicio de cada imagen para poder identificar y eliminar posteriormente
}

if (validateHttp('action') == 'showTable') {

  function getLastInvoiceDate($ID)
  {
    return false;
    $select = ncmExecute('SELECT transactionDate FROM transaction WHERE companyId = ? ORDER BY transactionDate DESC LIMIT 1', [$ID], true);
    return $select['transactionDate'];
  }

  if (!$userPermission['companyList']) {
    echo 'No permissions';
    die();
  }

  $c          = 0;
  $table      = '';
  $limits     = getTableLimits($limitDetail, $offsetDetail);

  $result     = ncmExecute('SELECT * FROM company ORDER BY createdAt DESC ' . $limits, [], false, true);

  $head = '<thead class="text-u-c">
            <tr>
              <th>#</th>
              <th>ID</th>
              <th>NCM ID</th>
              <th>Plan</th>
              <th>Estado de Cuenta</th>
              <th>Valor $</th>
              <th>Sucursales</th>
              <th>Nombre</th>
              <th>Propietario</th>
              <th>Telefono</th>
              <th>Email</th>
              <th>Fecha de registro</th>
              <th>Último uso</th>
              <th>País</th>
              <th>Categoría</th>
              <th>ePOS</th>
              <th>Ecommerce</th>
            </tr>
          </thead>
          <tbody>';

  if ($result) {
    $onlyAsigned = (ROLE_ID == 7) ? true : false;
    while (!$result->EOF) {

      $fields     = $result->fields;
      $companyId  = $fields['companyId'];
      $encCompId  = enc($companyId);

      $compLast     = $fields['customersLastUpdate']; //getLastInvoiceDate($companyId);//strtotime($fields['companyLastUpdate']);
      $compLastTime = strtotime($compLast);

      $proceed    = false;
      $eUsers     = ($fields['encomUsers']) ? json_decode($fields['encomUsers']) : [];
      $plan       = $plansValues[$fields['plan']];
      $isActive   = 'inactivo';

      $compStats  = 'b-l b-light b-4x';
      if ($compLastTime > strtotime("-7 day")) {
        $compStats  = 'b-l b-success b-4x';

        if (!in_array($plan['name'], ['Trial', 'Free'])) {
          $isActive   = 'activo';
        }
      } else if ($compLastTime > strtotime("-30 day") || $fields['companyLastUpdate'] == "") {
        $compStats = 'b-l b-warning b-4x';
      }

      if ($fields['status'] == 'Active') {
        $status = 'bg-success';
      } else if ($fields['status'] == 'Pending') {
        $status = 'bg-warning';
      } else {
        $status = 'bg-danger';
      }


      $planName = $plan['name'];

      if ($onlyAsigned) {
        if (in_array(USER_ID, $eUsers)) {
          $proceed = true;
        } else {
          $proceed = false;
        }
      } else {
        $proceed = true;
      }

      $hasePOS    = '<span class="badge bg-danger lter">Inhabilitado</span>';
      $hasEcommerce    = '<span class="badge bg-danger lter">Inhabilitado</span>';

      if ($proceed) {
        $user     = ncmExecute('SELECT * FROM contact WHERE companyId = ? AND main = \'true\' AND type = 0 LIMIT 1', [$companyId]);
        $setting  = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
        $_modules = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
        $_outlets = ncmExecute('SELECT COUNT(*) as count FROM outlet WHERE companyId = ? LIMIT 1000', [$companyId]);

        $accountBlocked = '<span class="badge bg-success lter">Activado</span>';

        if ($setting['blocked'] == 1) {
          $accountBlocked =  '<span class="badge bg-danger lter">Bloqueado</span>';
        } 

        if ($_modules['epos']) {
          $hasePOS    = '<span class="badge bg-success lter">Activado</span>';
        }

        if ($_modules['ecom']) {
          $hasEcommerce    = '<span class="badge bg-success lter">Activado</span>';
        }
        //obener fecha de primera factura

        $companyEdit    = '?action=editForm&id=' . $encCompId;
        $companyAccess  = '   <a class="loadDash font-bold text-' . (($setting['planExpired']) ? 'danger' : 'default') . '" href="?url=true&companyId=' . $encCompId . '">' . $setting['settingName'] . '</a>';

        if (!$userPermission['companyListAccess']) {
          $companyAccess = '<span class="font-bold text-' . (($setting['planExpired']) ? 'danger' : 'default') . '">' . $setting['settingName'] . '</span>';
        }

        if (!$userPermission['companyEdit']) {
          $companyEdit  = '';
        }

        $NCMID = ($setting['settingEncomID'] > 0) ? enc($setting['settingEncomID']) : '';

        $table .= '<tr data-id="' . $encCompId . '" data-id-n="' . $companyId . '"  data-element="#formItemSlot" data-load="' . $companyEdit . '" class="openTr ' . $compStats . '">' .
          ' <td data-filter="' . $companyId . ' ' . $encCompId . '">' . $companyId . '</td>' .
          ' <td data-filter="estado:' . $isActive . '"> <span class="badge ' . $status . ' lter" >' . $c . '</span> </td>' .
          ' <td data-filter="' . $NCMID . '">' . $NCMID . '</td>' .
          ' <td class="selectIt" data-id="' . $encCompId . '"> <span class="label bg-dark lter">' . $planName . '</span></td>' .
          ' <td> ' . $accountBlocked . ' </td>' .
          ' <td> ' . $plan['price'] * $_outlets['count'] . ' </td>' .
          ' <td> ' . $_outlets['count'] . ' </td>' .
          ' <td> ' . $companyAccess . ' </td>' .
          ' <td> ' . $user['contactName'] . ' </td>' .
          ' <td> ' . $user['contactPhone'] . ' </td>' .
          ' <td> ' . $user['contactEmail'] . ' </td>' .
          ' <td data-order="' . $fields['createdAt'] . '"> ' . $fields['createdAt'] . ' </td>' .
          ' <td data-order="' . $compLast . '"> ' . $compLast . ' </td>' .
          ' <td> ' . $countries[$setting['settingCountry']]['name'] . ' </td>' .
          ' <td> ' . getCompanyCategoryName($companyCategories, $setting['settingCompanyCategoryId']) . ' </td>' .
          ' <td> ' . $hasePOS . ' </td>' .
          ' <td> ' . $hasEcommerce . ' </td>' .
          '</tr>';

        if (validateHttp('part') && !validateHttp('singleRow')) {
          $table .= '[@]';
        }
      }

      $result->MoveNext();
      $c++;
    }
  }

  $foot .= '</tbody>' .
    '<tfoot>' .
    '  <tr>' .
    '    <th colspan="14"></th>' .
    '  </tr>' .
    '</tfoot>';

  if (validateHttp('part')) {
    dai($table);
  } else {
    $fullTable            = $head . $table . $foot;
    $jsonResult['table']  = $fullTable;

    header('Content-Type: application/json');
    dai(json_encode($jsonResult));
  }
}

if (validateHttp('action') == 'depositStateePOS') {
  $data   = validateHttp('data', 'post');
  $result = ['success' => false];

  if (validity($data)) {

    $datas = [];
    foreach ($data as $key => $value) {
      $datas[] = dec($value);
    }

    //verifico su estado, si es pending paso a received si no marco como deposited
    foreach ($datas as $key => $id) {
      $status = ncmExecute('SELECT * FROM vPayments WHERE ID = ? LIMIT 1', [$id]);
      if ($status) {
        if (in_array($status['status'], ['APPROVED'])) { //si esta pendiente paso a banco
          $update = ncmUpdate(['records' => ['status' => 'RECEIVED'], 'table' => 'vPayments', 'where' => 'ID = ' . $id]);
        } else if ($status['status'] == 'RECEIVED') {
          $update = ncmUpdate(['records' => ['deposited' => 1], 'table' => 'vPayments', 'where' => 'ID = ' . $id . " AND status = 'RECEIVED'"]);
        }
      }
    }

    if (!$update['error']) {
      $result = ['success' => true];
    }
  }

  header('Content-Type: application/json');
  dai(json_encode($result));
}

if (validateHttp('action') == 'changeStateePOS') {

  try {
    $data         = validateHttp('data', 'post');
    $result       = ['success' => false, 'errors' => 'invalid file', 'data' => $data];
    $typeSearch   = ['DEBITO', 'CREDITO', 'BILLETERA', 'OTRO'];
    $typeReplace  = ['TD', 'TC', 'DC', 'DC'];
    $allErrors    = [];
    $allSuccess   = [];
    $type         = isset($value['tipo']) ? strtoupper($value['tipo']) : '';

    if (validity($data)) {

      foreach ($data as $key => $value) {

        $processor = '';
        $opNo       = (int)$value['operacion'] ?? '';
        $authNo     = $value['autorizacion'] . '';
        $amount     = isset($value['Importe']) ? (float)$value['Importe'] : (float)$value['monto'];
        $brand      = $value['marca'];
        $rate       = 0;
        $source     = $value['procesadora2'] ?? '';
        $authNoUpdate     = $value['autorizacion2'] . '';
        $amount2     = (float)$value['monto2'];

        if ($value['Nro. transaccion']) { //Bancard 
          $brand = 'Bancard';
          $opNo       = (int)$value['Nro. transaccion'];
          $authNo     = $value['Codigo autorizacion'] . '';
          $type       = strtoupper($value['Tipo de tarjeta']);
          $source     = 'bancardPOS';
        } else if ($value['Boleta N°']) { //Bepsa
          $brand = 'Bepsa';
          $opNo       = (int)$value['Boleta N°'];
          $authNo     = $value['Cód. de Autorización'] . '';
          $type       = strtoupper($value['Tipo de Tarjeta']);
          $source     = 'dinelcoPOS';
        }

        $accountType = str_replace($typeSearch, $typeReplace, $type);
        $records    = [];
        $status     = 'RECEIVED';
        $payout     = $value['acreditar'];
        $tax        = (float)$value['iva'];
        $comission  = (float)$value['comision'];

        if ($value['estado'] == 'rechazado') {
          $status = 'DENIED';
        }

        //si se envia autorizacion2 actualizo tambien el codigo de autorización
        if (!empty($authNoUpdate)) {
          $records['authCode'] = $authNoUpdate;
        }

        if (validity($opNo)) {

          if (validity($authNo) && validity($brand) && validity($value['Estado']) && in_array($value['Estado'], ['A', 'Aprobado'])) {
            $records['operationNo']   = $opNo;

            $records['data']          = json_encode(['account_type' => $accountType, 'brand' => $brand]);

            $records['source']        = $source;

            $where                    = "authCode = '" . $authNo . "'" . " AND status = 'REVIEW'" . " AND ROUND(amount, 0) = '" . $amount . "'";

            $resultPayment = ncmExecute('SELECT companyId, amount, payoutAmount, comission, tax, source FROM vPayments WHERE authCode = ? AND status = ? AND ROUND(amount, 0) = ? LIMIT 1', [$authNo, 'REVIEW', $amount]);

            if ($resultPayment) {
              $companyId = $resultPayment['companyId'];
              $_modules   = ncmExecute('SELECT eposData FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
              $ePOSData   = json_decode($_modules['eposData'], true);

              if (!empty($resultPayment['source']) && in_array($resultPayment['source'], ['bancardQROnline', 'dinelcoVPOS', 'bancardAutoDebit', 'bancardVPOS'])) { //si es pago online
                $rate = (float)$ePOSData['rateOnline'];
                $records['source'] = $resultPayment['source'];
              } else {
                if (!empty($accountType) && $accountType == 'TC') {
                  $rate   = (float)$ePOSData['rate'];
                } else if (!empty($accountType) && in_array($accountType, ['DC', 'TD'])) {
                  $rate   = (float)$ePOSData['rateDebit'];
                }
              }

              if (!empty($rate) && $rate > 0) {

                //si se envia monto2 actualizo tambien el monto de la operación
                if (!empty($amount2) && $amount2 > 0) {
                  $amount = $amount2;
                }

                $records['amount']        = $amount;
                $records['status']        = $status;

                if (!empty($resultPayment['amount']) && $resultPayment['amount'] > 0 && (empty($resultPayment['payoutAmount']) || $resultPayment['payoutAmount'] == 0) && !empty($resultPayment['comission']) && $resultPayment['comission'] > 0 && !empty($resultPayment['tax']) && $resultPayment['tax'] > 0) {
                  $payout                   = (float)$resultPayment['amount'] - (float)$resultPayment['comission'] - (float)$resultPayment['tax'];
                  $records['payoutAmount']  = $payout;
                } else if ((empty($resultPayment['comission']) || $resultPayment['comission'] == 0) && (empty($resultPayment['tax']) || $resultPayment['tax'] == 0)) {
                  $comission                = $amount * ((float)$rate / 100);
                  $records['comission']     = $comission;
                  $tax                      = $comission * (10 / 100);
                  $records['tax']           = $tax;
                  $payout                   = $amount - $comission - $tax;
                  $records['payoutAmount']  = $payout;
                }
              }
            }
          } else if (validity($authNo)) { //es POS fisico dinelco
            if (validity($payout)) {
              $records['payoutAmount']  = $payout;
              $records['operationNo']   = $opNo;
              $where                    = "authCode = '" . $authNo . "'" . " AND status = 'REVIEW'" . " AND ROUND(amount, 0) = '" . $amount . "'";

              //si se envia monto2 actualizo tambien el monto de la operación
              if (!empty($amount2) && $amount2 > 0) {
                $amount = $amount2;
              }

              if ($amount) {
                $records['amount']        = $amount;
              }

              if ($comission) {
                $records['comission']     = $comission;
              }

              if ($tax) {
                $records['tax']           = $tax;
              }

              if ($source) {
                $records['source']        = $source;
              }

              $records['status']        = $status;
              $records['data']          = json_encode(['account_type' => $accountType, 'brand' => $brand]);
            }
          } else if (!validity($brand)) {
            if (validity($payout)) {
              $records['payoutAmount']  = $payout;
            }

            if (validity($tax)) {
              $records['tax']           = $tax;
            }

            if (validity($comission)) {
              $records['comission']     = $comission;
            }


            if (validity($source)) {
              $records['source']     = $source;
            }

            $records['status']          = $status;
            $where                      = 'operationNo = ' . $opNo . " AND status = 'REVIEW'" . " AND ROUND(amount, 0) = '" . $amount . "'";

            //si se envia monto2 actualizo tambien el monto de la operación
            if (!empty($amount2) && $amount2 > 0) {
              $$records['amount'] = $amount2;
            }
          }
        } else {
          continue;
        }

        // print_r("\n value: \n");
        // print_r([$value]);

        // print_r("\n records: \n");
        // print_r([$records]);

        // print_r("\n where: \n");
        // print_r($where);
        $update = ncmUpdate(['records' => $records, 'table' => 'vPayments', 'where' => $where]);

        if ($update['error']) {
          $allErrors[]  = ['codID'   => ($authNo ? $authNo : $opNo), 'error' => $update['error']];
          $result       = ['success'  => false, 'errors' => $allErrors];
          break;
        } else {
          $allSuccess[] = ['codID' => ($authNo ? $authNo : $opNo)];
        }
      }

      if (!validity($allErrors)) {
        $result = ['success' => true, 'all' => $allSuccess];
      } else {
        $result = ['success' => false, 'errors' => $allErrors];
      }
    }

    header('Content-Type: application/json');
    dai(json_encode($result));
  } catch (Exception $e) {
    echo $e->getMessage();
    die();
  }
}

if (validateHttp('action') == 'deleteePOSRecord' && validateHttp('ID')) {
  if (!$userPermission['eposDeleteRecord']) {
    $result = ['error' => 'no_permissions'];
  } else {
    $ID     = dec(validateHttp('ID'));

    $update = ncmUpdate(['records' => ['status' => 'DENIED'], 'table' => 'vPayments', 'where' => 'ID = ' . $ID]);

    //echo 'DELETE FROM vPayments WHERE ID = ' . $ID . ' LIMIT 1';
    //$result = ncmExecute('DELETE FROM vPayments WHERE ID = ? LIMIT 1', [$ID]);

    if (validity($update['error'])) {
      $result = ['error' => $db->ErrorMsg()];
    } else {
      $result = ['success' => true, 'error' => false];
    }
  }

  header('Content-Type: application/json');
  dai(json_encode($result));
}

if (validateHttp('action') == 'listePOS') {
  ini_set('memory_limit', '128M');
  if (!$userPermission['eposPayout']) {
    echo 'No permissions';
    die();
  }
  try {
    $byMonth    = validateHttp('byMonth');
    $date       = validateHttp('date') ? validateHttp('date') . ' 00:00:00' : TODAY;

    if ($byMonth == 'true') {
      $dateTmp = $date;
      $date       = date('m', strtotime($dateTmp));
      $year       = date('Y', strtotime($dateTmp));
      $result     = ncmExecute('SELECT * FROM vPayments WHERE status IN(?,?,?) AND MONTH(payoutDate) = ? AND YEAR(payoutDate) = ?  ORDER BY payoutDate ASC', ['APPROVED', 'RECEIVED', 'REVIEW', $date, $year], false, true);
    } else {
      $result     = ncmExecute('SELECT * FROM vPayments WHERE status IN(?,?,?) AND payoutDate = ? ORDER BY payoutDate ASC', ['APPROVED', 'RECEIVED', 'REVIEW', $date], false, true);
    }

    $table      = '';

    $head       = '<thead class="text-u-c">
                  <tr>
                    <th>Comercio</th>
                    <th>Sucursal</th>
                    <th>Fecha de Operación</th>
                    <th>Fecha de Pago</th>
                    <th>Días</th>
                    <th>Cod. Autorización</th>
                    <th>Nro. Operación</th>
                    <th>Procesadora</th>
                    <th>% Comisión</th>
                    <th>Paga Comisión</th>
                    <th>Medio</th>
                    <th>Total de la venta</th>
                    <th>Comisión</th>
                    <th>IVA</th>
                    <th>Acreditar</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                  </tr>
                </thead>
                <tbody>';

    if ($result) {

      while (!$result->EOF) {

        $fields     = $result->fields;
        $companyId  = $fields['companyId'];
        $encCompId  = enc($companyId);

        $_settings  = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
        $_outlets   = ncmExecute('SELECT * FROM outlet WHERE outletId = ? LIMIT 1', [$fields['outletId']]);
        $_modules   = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
        $ePOSData   = json_decode($_modules['eposData'], true);
        if (empty($fields['data'])) {
          $fields['data'] = "";
        }
        $ePOSPayData = @json_decode($fields['data'], true);
        if (!empty($fields['data']) && empty($ePOSPayData)) {
          $ePOSPayData = toUTF8($fields['data']);
          $ePOSPayData = @json_decode($fields['data'], true);
        }
        if ((json_last_error() != JSON_ERROR_NONE) && !empty($fields['data'])) {
          switch (json_last_error()) {
            case JSON_ERROR_NONE:
              echo ' - No errors';
              break;
            case JSON_ERROR_DEPTH:
              echo ' - Maximum stack depth exceeded';
              break;
            case JSON_ERROR_STATE_MISMATCH:
              echo ' - Underflow or the modes mismatch';
              break;
            case JSON_ERROR_CTRL_CHAR:
              echo ' - Unexpected control character found';
              break;
            case JSON_ERROR_SYNTAX:
              echo ' - Syntax error, malformed JSON';
              break;
            case JSON_ERROR_UTF8:
              echo ' - Malformed UTF-8 characters, possibly incorrectly encoded';
              break;
            default:
              echo ' - Unknown error';
              break;
          }
          var_dump($fields['data']);
          die();
        }
        $medio      = '-';
        $rate       = 0;
        $allowDelete = false;

        if ($ePOSPayData['account_type']) {

          if (!empty($ePOSPayData['account_type']) && $ePOSPayData['account_type'] == 'TC') {
            $medio  = 'T. Crédito';
            $rate   = $ePOSData['rate'];
          } else if (!empty($ePOSPayData['account_type']) && in_array($ePOSPayData['account_type'], ['DC', 'TD'])) {
            $medio  = 'T. Débito';
            $rate   = $ePOSData['rateDebit'];
          }
        } else if (!empty($ePOSPayData['brand'])) {
          $medio = $ePOSPayData['brand'];
        }

        if (in_array($fields['source'], ['bancardQROnline', 'dinelcoVPOS', 'bancardAutoDebit', 'bancardVPOS'])) {
          $rate       = $ePOSData['rateOnline'];
        }

        $comissionPays  = 'Comercio';
        $status         = '<span class="badge bg-light lter">Pendiente</span>';

        if ($ePOSData['customerPays']) {
          $comissionPays = 'Cliente';
        }

        if ($fields['status'] == 'RECEIVED') {
          $status        = '<span class="badge bg-info lter">En Banco</span>';
        }

        if ($fields['status'] == 'REVIEW') {
          $status        = '<span class="badge bg-light lter">Revisión</span>';
          $allowDelete   = true;
        }

        if ($fields['deposited']) {
          $status        = '<span class="badge bg-success lter">Acreditado</span>';
        }

        $depositDays     = !empty($ePOSData['depositDays']) ? $ePOSData['depositDays'] : '0';

        $table .= '<tr class="clickrow openTrePOS" data-load="' . base64_encode($_modules['eposData']) . '" data-id="' . enc($fields['ID']) . '">' .
          ' <td class="font-bold">' . $_settings['settingName'] . '</td>' .
          ' <td>' . $_outlets['outletName'] . '</td>' .
          ' <td>' . $fields['date'] . '</td>' .
          ' <td>' . date('Y-m-d', strtotime($fields['payoutDate'])) . '</td>' .
          ' <td>' . $depositDays . '</td>' .
          ' <td>' . $fields['authCode'] . '</td>' .
          ' <td>' . $fields['operationNo'] . '</td>' .
          ' <td>' . $fields['source'] . '</td>' .
          ' <td>' . $rate . '%</td>' .
          ' <td>' . $comissionPays . '</td>' .
          ' <td>' . $medio . '</td>' .
          ' <td data-format="money" data-order="' . $fields['amount'] . '" class="text-right">' . formatCurrentNumber($fields['amount']) . '</td>' .
          ' <td data-format="money" data-order="' . $fields['comission'] . '" class="text-right">' . formatCurrentNumber($fields['comission']) . '</td>' .
          ' <td data-format="money" data-order="' . $fields['tax'] . '" class="text-right">' . formatCurrentNumber($fields['tax']) . '</td>' .
          ' <td data-format="money" data-order="' . $fields['payoutAmount'] . '" class="text-right">' . formatCurrentNumber($fields['payoutAmount']) . '</td>' .
          ' <td>' . $status . '</td>' .
          ' <td>' . ($allowDelete ? '<a href="#" class="deleteePOSRecord" data-id="' . enc($fields['ID']) . '"><i class="material-icons text-danger">close</i></a>' : '...') . '</td>' .
          '</tr>';

        $result->MoveNext();
      }
    } else {
      //$table .= '<tr> <td class="h3 font-bold text-center" colspan="16"> No hay transacciones para hoy </td> </tr>';
      $table .= '  <tr>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '    <th></th>' .
        '  </tr>';
    }


    $foot .= '</tbody>' .
      '<tfoot>' .
      '  <tr>' .
      '    <th colspan="11">TOTAL</th>' .
      '    <th class="text-right"></th>' .
      '    <th class="text-right"></th>' .
      '    <th class="text-right"></th>' .
      '    <th class="text-right"></th>' .
      '    <th></th>' .
      '    <th></th>' .
      '  </tr>' .
      '</tfoot>';

    $jsonResult['table']  = $head . $table . $foot;
    header('Content-Type: application/json');
    echo json_encode($jsonResult);
    if (json_last_error_msg() != "No error") {
      echo json_last_error_msg();
    }
    die();
  } catch (Exception $e) {
    echo $e->getMessage();
    die();
  }
}

if (validateHttp('action') == 'listePOSTable') {
  ini_set('memory_limit', '128M');
  if (!$userPermission['eposPayout']) {
    echo 'No permissions';
    die();
  }
  try {
    $c          = 0;
    $table      = '';
    $limits     = getTableLimits($limitDetail, $offsetDetail);

    $result     = ncmExecute('SELECT c.*, m.epos, m.eposData, s.planExpired, s.settingName, s.settingEncomID, s.settingCountry, s.settingCompanyCategoryId
                              FROM company c JOIN module m ON c.companyId = m.companyId
                              LEFT JOIN setting s ON c.companyId = s.companyId 
                              WHERE m.epos = true OR JSON_EXTRACT(m.eposData,"$.eposCard") = "1" 
                              ORDER BY c.createdAt DESC ' . $limits, [], false, true);

    $head = '<thead class="text-u-c">
            <tr>
              <th>#</th>
              <th>ID</th>
              <th>NCM ID</th>
              <th>Plan</th>
              <th>Valor $</th>
              <th>Nombre</th>
              <th>Propietario</th>
              <th>Telefono</th>
              <th>Email</th>
              <th>Fecha de registro</th>
              <th>Último uso</th>
              <th>País</th>
              <th>Categoría</th>
              <th>ePOS</th>
              <th>POS FISICO</th>
              <th>% Comisión T. Débito</th>
              <th>% Comisión T. Crédito</th>
              <th>% Comisión Online</th>
              <th>Días para desembolso (Crédito)</th>
              <th>Última transacción ePOS</th>
              <th>Banco</th>
              <th>Nro. de Cuenta</th>
              <th>Beneficiario</th>
              <th>Tipo de Documento</th>
              <th>Nro. de Documento</th>
            </tr>
          </thead>
          <tbody>';

    if ($result) {
      $onlyAsigned = (ROLE_ID == 7) ? true : false;
      while (!$result->EOF) {

        $fields     = $result->fields;
        $companyId  = $fields['companyId'];
        $encCompId  = enc($companyId);

        $compLast     = $fields['customersLastUpdate']; //getLastInvoiceDate($companyId);//strtotime($fields['companyLastUpdate']);
        $compLastTime = strtotime($compLast);

        $proceed    = false;
        $eUsers     = ($fields['encomUsers']) ? json_decode($fields['encomUsers']) : [];
        $plan       = $plansValues[$fields['plan']];
        $isActive   = 'inactivo';

        $compStats  = 'b-l b-light b-4x';
        if ($compLastTime > strtotime("-7 day")) {
          $compStats  = 'b-l b-success b-4x';

          if (!in_array($plan['name'], ['Trial', 'Free'])) {
            $isActive   = 'activo';
          }
        } else if ($compLastTime > strtotime("-30 day") || $fields['companyLastUpdate'] == "") {
          $compStats = 'b-l b-warning b-4x';
        }

        if ($fields['status'] == 'Active') {
          $status = 'bg-success';
        } else if ($fields['status'] == 'Pending') {
          $status = 'bg-warning';
        } else {
          $status = 'bg-danger';
        }


        $planName = $plan['name'];

        if ($onlyAsigned) {
          if (in_array(USER_ID, $eUsers)) {
            $proceed = true;
          } else {
            $proceed = false;
          }
        } else {
          $proceed = true;
        }

        $hasePOS    = '<span class="badge bg-danger lter">Inhabilitado</span>';
        $hasEposCard    = '<span class="badge bg-danger lter">Inhabilitado</span>';

        if ($proceed) {
          $user     = ncmExecute('SELECT * FROM contact WHERE companyId = ? AND main = \'true\' AND type = 0 LIMIT 1', [$companyId]);
          //$setting  = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
          //$_modules = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
          $_outlets = ncmExecute('SELECT COUNT(*) as count FROM outlet WHERE companyId = ? LIMIT 1000', [$companyId]);
          $ePOSData   = json_decode($fields['eposData'], true);
          $lastDate = ncmExecute('SELECT date FROM vPayments WHERE companyId = ? order by date desc LIMIT 1', [$companyId]);
          $lastDate = $lastDate['date'];

          $rate = $ePOSData['rate'];
          $rateDebit = $ePOSData['rateDebit'];
          $rateOnline = $ePOSData['rateOnline'];
          $depositDays = $ePOSData['depositDays'];
          $bankName = $ePOSData['bankName'];
          $bankAccount = $ePOSData['bankAccount'];
          $bankBeneficiary = $ePOSData['bankBeneficiary'];
          $bankBeneficiaryTypeLID = $ePOSData['bankBeneficiaryTypeLID'];
          $bankBeneficiaryLID = $ePOSData['bankBeneficiaryLID'];
          $eposCard = $ePOSData['eposCard'];


          if ($fields['epos']) {
            $hasePOS    = '<span class="badge bg-success lter">Activado</span>';
          }
          if (!empty($eposCard) && $eposCard == 1) {
            $hasEposCard  = '<span class="badge bg-success lter">Activado</span>';
          }
          //obener fecha de primera factura

          $companyEdit    = '?action=editForm&id=' . $encCompId;
          $companyAccess  = '   <a class="loadDash font-bold text-' . (($fields['planExpired']) ? 'danger' : 'default') . '" href="?url=true&companyId=' . $encCompId . '">' . $fields['settingName'] . '</a>';

          if (!$userPermission['companyListAccess']) {
            $companyAccess = '<span class="font-bold text-' . (($fields['planExpired']) ? 'danger' : 'default') . '">' . $fields['settingName'] . '</span>';
          }

          if (!$userPermission['companyEdit']) {
            $companyEdit  = '';
          }

          $NCMID = ($fields['settingEncomID'] > 0) ? enc($fields['settingEncomID']) : '';

          $table .= '<tr data-id="' . $encCompId . '" data-id-n="' . $companyId . '"  data-element="#formItemSlot" data-load="' . $companyEdit . '" class="openTr ' . $compStats . '">' .
            ' <td data-filter="' . $companyId . ' ' . $encCompId . '">' . $companyId . '</td>' .
            ' <td data-filter="estado:' . $isActive . '"> <span class="badge ' . $status . ' lter" >' . $c . '</span> </td>' .
            ' <td data-filter="' . $NCMID . '">' . $NCMID . '</td>' .
            ' <td class="selectIt" data-id="' . $encCompId . '"> <span class="label bg-dark lter">' . $planName . '</span></td>' .
            ' <td> ' . $plan['price'] * $_outlets['count'] . ' </td>' .
            ' <td> ' . $companyAccess . ' </td>' .
            ' <td> ' . $user['contactName'] . ' </td>' .
            ' <td> ' . $user['contactPhone'] . ' </td>' .
            ' <td> ' . $user['contactEmail'] . ' </td>' .
            ' <td data-order="' . $fields['createdAt'] . '"> ' . $fields['createdAt'] . ' </td>' .
            ' <td data-order="' . $compLast . '"> ' . $compLast . ' </td>' .
            ' <td> ' . $countries[$fields['settingCountry']]['name'] . ' </td>' .
            ' <td> ' . getCompanyCategoryName($companyCategories, $fields['settingCompanyCategoryId']) . ' </td>' .
            ' <td> ' . $hasePOS . ' </td>' .
            ' <td> ' . $hasEposCard . ' </td>' .
            ' <td> ' . $rateDebit . ' </td>' .
            ' <td> ' . $rate . ' </td>' .
            ' <td> ' . $rateOnline . ' </td>' .
            ' <td> ' . $depositDays . ' </td>' .
            ' <td> ' . $lastDate . ' </td>' .
            ' <td> ' . $bankName . ' </td>' .
            ' <td> ' . $bankAccount . ' </td>' .
            ' <td> ' . $bankBeneficiary . ' </td>' .
            ' <td> ' . $bankBeneficiaryTypeLID . ' </td>' .
            ' <td> ' . $bankBeneficiaryLID . ' </td>' .
            '</tr>';

          if (validateHttp('part') && !validateHttp('singleRow')) {
            $table .= '[@]';
          }
        }

        $result->MoveNext();
        $c++;
      }
    }

    $foot .= '</tbody>' .
      '<tfoot>' .
      '  <tr>' .
      '    <th colspan="14"></th>' .
      '  </tr>' .
      '</tfoot>';

    if (validateHttp('part')) {
      dai($table);
    } else {
      $fullTable            = $head . $table . $foot;
      $jsonResult['table']  = $fullTable;

      header('Content-Type: application/json');
      dai(json_encode($jsonResult));
    }
  } catch (Exception $e) {
    echo $e->getMessage();
    die();
  }
}

if (validateHttp('action') == 'listeTransactionMonthPOSTable') {
  ini_set('memory_limit', '128M');
  if (!$userPermission['eposPayoutMonth']) {
    echo 'No permissions';
    die();
  }
  try {
    $c          = 0;
    $table      = '';
    $limits     = getTableLimits($limitDetail, $offsetDetail);

    $resultVPay     = ncmExecute('SELECT companyId, MONTH(date) AS month, YEAR(date) AS year, SUM(amount) AS totalAmount
                              FROM vPayments
                              WHERE date >= CURRENT_DATE - INTERVAL \'12 months\'
                              GROUP BY companyId, YEAR(date), MONTH(date)
                              ORDER BY companyId DESC, year DESC, month DESC' . $limits, [], false, true);

    $head = '<thead class="text-u-c">
            <tr>
              <th>#</th>
              <th>ID</th>
              <th>Plan</th>
              <th>Estado de Cuenta</th>
              <th>Valor $</th>
              <th>Sucursales</th>
              <th>Nombre</th>
              <th>Propietario</th>
              <th>Telefono</th>
              <th>Email</th>
              <th>Fecha de registro</th>
              <th>País</th>
              <th>Ciudad</th>
              <th>Categoría</th>
              <th>ePOS</th>
              <th>POS Fisico</th>
              <th>Mes</th>
              <th>Año</th>
              <th>Monto</th>
            </tr>
          </thead>
          <tbody>';

    $dataArray = array();

    if ($resultVPay) {
      while (!$resultVPay->EOF) {
        array_push($dataArray, $resultVPay->fields);
        $resultVPay->MoveNext();
      }
    }

    // Extraer todos los companyId del array $dataArray
    $companyIds = array_column($dataArray, 'companyId');

    // Eliminar duplicados convirtiendo el array a un conjunto
    $uniqueCompanyIds = array_unique($companyIds);

    // Verificar si hay algún companyId antes de construir la cláusula IN
    if (!empty($uniqueCompanyIds)) {
      $inClause = implode(',', $uniqueCompanyIds);

      $missingResults     = ncmExecute('SELECT companyId 
                                  FROM company 
                                  WHERE (epos = true OR JSON_EXTRACT(eposData,"$.eposCard") = "1") 
                                  AND companyId NOT IN (' . $inClause . ')' . $limits, [], false, true);

      // Obtener el mes actual
      $monthCurrent = date("n");

      // Obtener el año actual
      $yearCurrent = date("Y");

      if ($missingResults) {
        while (!$missingResults->EOF) {
          $fields     = $missingResults->fields;
          $resultData = array();
          $resultData['companyId'] = $fields['companyId'];
          $resultData['month'] = $monthCurrent;
          $resultData['year'] = $yearCurrent;
          $resultData['totalAmount'] = 0;
          //Agregra al array principal las empresas con pos habilitados pero sin movimientos
          array_push($dataArray, $resultData);
          $missingResults->MoveNext();
        }
      }
    }

    if (!empty($dataArray)) {
      $onlyAsigned = (ROLE_ID == 7) ? true : false;
      while ($c < count($dataArray)) {
        $fields     = $dataArray[$c];
        $companyId  = $fields['companyId'];
        $company  = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
        $encCompId  = enc($companyId);
        $compLast     = $company['customersLastUpdate'];
        $compLastTime = strtotime($compLast);

        $proceed    = false;
        $eUsers     = ($company['encomUsers']) ? json_decode($company['encomUsers']) : [];
        $plan       = $plansValues[$company['plan']];
        $isActive   = 'inactivo';

        $compStats  = 'b-l b-light b-4x';
        if ($compLastTime > strtotime("-7 day")) {
          $compStats  = 'b-l b-success b-4x';

          if (!in_array($plan['name'], ['Trial', 'Free'])) {
            $isActive   = 'activo';
          }
        } else if ($compLastTime > strtotime("-30 day") || $company['companyLastUpdate'] == "") {
          $compStats = 'b-l b-warning b-4x';
        }

        if ($company['status'] == 'Active') {
          $status = 'bg-success';
        } else if ($company['status'] == 'Pending') {
          $status = 'bg-warning';
        } else {
          $status = 'bg-danger';
        }

        $planName = $plan['name'];

        if ($onlyAsigned) {
          if (in_array(USER_ID, $eUsers)) {
            $proceed = true;
          } else {
            $proceed = false;
          }
        } else {
          $proceed = true;
        }

        $hasePOS    = '<span class="badge bg-danger lter">Inhabilitado</span>';
        $hasEposCard    = '<span class="badge bg-danger lter">Inhabilitado</span>';

        if ($proceed) {
          $user     = ncmExecute('SELECT * FROM contact WHERE companyId = ? AND main = \'true\' AND type = 0 LIMIT 1', [$companyId]);
          $setting  = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
          $_modules = ncmExecute('SELECT * FROM company WHERE companyId = ? LIMIT 1', [$companyId]);
          $_outlets = ncmExecute('SELECT COUNT(*) as count FROM outlet WHERE companyId = ? LIMIT 1000', [$companyId]);
          $ePOSData   = json_decode($_modules['eposData'], true);
          $eposCard = $ePOSData['eposCard'];

          $accountBlocked = '<span class="badge bg-success lter">Activado</span>';

          if ($setting['blocked'] == 1) {
            $accountBlocked =  '<span class="badge bg-danger lter">Bloqueado</span>';
          } 

          if ($_modules['epos']) {
            $hasePOS    = '<span class="badge bg-success lter">Activado</span>';
          }
          if (!empty($eposCard) && $eposCard == 1) {
            $hasEposCard  = '<span class="badge bg-success lter">Activado</span>';
          }

          $companyEdit    = '?action=editForm&id=' . $encCompId;
          $companyAccess  = '   <a class="loadDash font-bold text-' . (($setting['planExpired']) ? 'danger' : 'default') . '" href="?url=true&companyId=' . $encCompId . '">' . $setting['settingName'] . '</a>';

          if (!$userPermission['companyListAccess']) {
            $companyAccess = '<span class="font-bold text-' . (($setting['planExpired']) ? 'danger' : 'default') . '">' . $setting['settingName'] . '</span>';
          }

          if (!$userPermission['companyEdit']) {
            $companyEdit  = '';
          }

          $NCMID = ($setting['settingEncomID'] > 0) ? enc($setting['settingEncomID']) : '';

          $table .= '<tr data-id="' . $encCompId . '" data-id-n="' . $companyId . '"  data-element="#formItemSlot" data-load="' . $companyEdit . '" class="openTr ' . $compStats . '">' .
            ' <td data-filter="' . $companyId . ' ' . $encCompId . '">' . $companyId . '</td>' .
            ' <td data-filter="estado:' . $isActive . '"> <span class="badge ' . $status . ' lter" >' . $c . '</span> </td>' .
            ' <td class="selectIt" data-id="' . $encCompId . '"> <span class="label bg-dark lter">' . $planName . '</span></td>' .
            ' <td> ' . $accountBlocked . ' </td>' .
            ' <td> ' . $plan['price'] * $_outlets['count'] . ' </td>' .
            ' <td> ' . $_outlets['count'] . ' </td>' .
            ' <td> ' . $companyAccess . ' </td>' .
            ' <td> ' . $user['contactName'] . ' </td>' .
            ' <td> ' . $user['contactPhone'] . ' </td>' .
            ' <td> ' . $user['contactEmail'] . ' </td>' .
            ' <td data-order="' . $company['createdAt'] . '"> ' . $company['createdAt'] . ' </td>' .
            ' <td> ' . $countries[$setting['settingCountry']]['name'] . ' </td>' .
            ' <td> ' . $setting['settingCity'] . ' </td>' .
            ' <td> ' . getCompanyCategoryName($companyCategories, $setting['settingCompanyCategoryId']) . ' </td>' .
            ' <td> ' . $hasePOS . ' </td>' .
            ' <td> ' . $hasEposCard . ' </td>' .
            ' <td> ' . $meses[$fields['month'] - 1] . ' </td>' .
            ' <td> ' . $fields['year'] . ' </td>' .
            ' <td> ' . formatCurrentNumber($fields['totalAmount']) . ' </td>' .
            '</tr>';

          if (validateHttp('part') && !validateHttp('singleRow')) {
            $table .= '[@]';
          }
        }

        $result->MoveNext();
        $c++;
      }
    }

    $foot .= '</tbody>' .
      '<tfoot>' .
      '  <tr>' .
      '    <th colspan="14"></th>' .
      '  </tr>' .
      '</tfoot>';

    if (validateHttp('part')) {
      dai($table);
    } else {
      $fullTable            = $head . $table . $foot;
      $jsonResult['table']  = $fullTable;

      header('Content-Type: application/json');
      dai(json_encode($jsonResult));
    }
  } catch (Exception $e) {
    echo $e->getMessage();
    die();
  }
}

if (validateHttp('action') == 'getOnRisk') {

  $inactive   = 0;
  $risk       = 0;
  $active     = 0;

  $result     = ncmExecute('SELECT * FROM company ORDER BY createdAt DESC', [], false, true);

  while (!$result->EOF) {
    $fields     = $result->fields;
    $companyId  = $fields['companyId'];
    $encCompId  = enc($companyId);
    $compLast   = strtotime($fields['companyLastUpdate']);

    if ($compLast > strtotime("-7 day")) {
      $active++;
    } else if ($compLast > strtotime("-15 day") || $fields['companyLastUpdate'] == "") {
      $risk++;
    } else {
      $inactive++;
    }

    $result->MoveNext();
  }

  header('Content-Type: application/json');
  dai(json_encode(['inactive' => formatCurrentNumber($inactive, false, false, 0), 'risk' => formatCurrentNumber($risk, false, false, 0), 'active' => formatCurrentNumber($active, false, false, 0)]));
}

if (validateHttp('action') == 'countries') {

  $result     = ncmExecute('SELECT * FROM company', [], false, true);
  $list       = [];

  while (!$result->EOF) {
    $fields     = $result->fields;
    $country    = $countries[$fields['settingCountry']]['name'];

    if ($list[$country]) {
      $list[$country] += 1;
    } else {
      $list[$country] = 1;
    }

    $result->MoveNext();
  }

  asort($list);

  $list = array_reverse($list);

  header('Content-Type: application/json');
  dai(json_encode($list));
}

if (validateHttp('action') == 'soldByCompanyPM') {
  ini_set('display_errors', 1);
  ini_set('display_startup_errors', 1);
  error_reporting(E_ALL);

  //obtengo empresas (nombre, categoria)
  //obtengo transacciones de ultimos 12 meses, sumo y agrupo por mes (total efe, total TC, TD, QR, Pya y monchis, otros)
  //

  $dateStart  = '2022-01-01 00:00:00';
  $dateEnd    = '2022-12-30 23:59:59';
  $transLimit = 5000;
  $compLimit  = 350;

  $companies    = ncmExecute(
    'SELECT a.*, b.* 
                              FROM company a, setting b 
                              WHERE 
                                a.companyLastUpdate   > ?
                                AND a.companyId       = b.companyId 
                                AND b.settingCountry  = ? 
                              LIMIT ?',
    [$dateStart, 'PY', $compLimit],
    false,
    true
  );
  $companyData  = [];
  $compIDs      = [];
  $group        = ['cash' => 0, 'creditcard' => 0, 'debitcard' => 0, 'other' => 0];

  if ($companies) {
    while (!$companies->EOF) {
      $cfields     = $companies->fields;
      $compID      = $cfields['companyId'];
      $compIDs[]   = $compID;

      $companyData[$compID]['payment']['cash']        = 0;
      $companyData[$compID]['payment']['creditcard']  = 0;
      $companyData[$compID]['payment']['debitcard']   = 0;
      $companyData[$compID]['payment']['other']       = 0;

      $companyData[$compID]['name']           = $cfields['settingName'];
      $companyData[$compID]['category']       = getCompanyCategoryName($companyCategories, $cfields['settingCompanyCategoryId']);
      $companyData[$compID]['from']           = $dateStart;
      $companyData[$compID]['to']             = $dateEnd;


      $transactionR    = ncmExecute(
        ' SELECT transactionPaymentType
                                      FROM transaction 
                                      WHERE companyId = ? 
                                        AND transactionType IN(?) 
                                        AND transactionDate BETWEEN ? AND ? 
                                      LIMIT ?',
        [$compID, '0,5', $dateStart, $dateEnd, $transLimit],
        true,
        true
      );

      if ($transactionR) {

        while (!$transactionR->EOF) {
          $tfields                  = $transactionR->fields;
          $payments                 = json_decode($tfields['transactionPaymentType'], true);

          if (is_array($payments)) {
            foreach ($payments as $key => $payment) {
              if (array_key_exists($payment['type'], $group)) {
                $companyData[$compID]['payment'][$payment['type']] += intval($payment['price']);
              } else {
                $companyData[$compID]['payment']['other']          += intval($payment['price']);
              }
            }
          }

          $transactionR->MoveNext();
        }
      }


      $companies->MoveNext();
    }
  }



  echo '<pre>';
  echo json_encode($companyData);
  echo '</pre>';

  die();
}

?>
<!DOCTYPE html>
<html class="no-js">

<head>
  <!-- meta -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, user-scalable=1, initial-scale=1, maximum-scale=1">
  <title>Listado de Clientes | ENCOM</title>
  <script>
    var noSessionCheck = false;
  </script>
  <?php
  loadCDNFiles([
    'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.1/css/select2.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/select2-bootstrap-theme/0.1.0-beta.10/select2-bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/3.1.4/css/bootstrap-datetimepicker.css'
  ], 'css');

  function modBlock($ops)
  {
    $ops += ['color' => 'info', 'id' => ''];
    $out =  '<div class="col-md-4 col-sm-6 col-xs-12 wrapper">' .
      ' <div class="col-xs-12 no-padder r-24x clear bg-white" style="min-height:180px;">' .
      '   <div class="text-left wrapper col-xs-12 b-b">' .
      '     <div class="m-t h1 font-bold col-xs-12 no-padder text-dark">' .
      $ops['title'] .
      '     </div>' .
      '   </div>' .
      '   <div class="col-md-9 col-sm-12 wrapper">' .
      $ops['description'] .
      '   </div>' .
      '   <div class="col-md-3 col-sm-12 wrapper text-right">' .
      '     <a href="' . $ops['url'] . '" target="_blank" class="btn btn-rounded btn-' . ($ops['color'] ? $ops['color'] : 'info') . '" id="' . ($ops['id'] ? $ops['id'] : '') . '"><i class="material-icons">call_made</i></a>' .
      '   </div>' .
      ' </div>' .
      '</div>';

    return $out;
  }
  ?>
</head>

<body>

  <section class="col-xs-12 wrapper">
    <div class="col-xs-12 m-b">

      <img src="/images/iconincomesm.png" class="m-r-xs m-t-n" width="40">
      <span class="h1 text-dark font-bold">
        <?= USER_NAME ?> - Panel Corporativo
      </span>
      <a href="/logout" class="block m-t-sm"><span class="text-danger font-bold">Cerrar Sessión</span></a>

    </div>

    <div class="col-xs-12 wrapper bg-light dk r-3x">

      <a href="#" class="btn btn-default btn-rounded font-bold btnSection" data-section="#sections">
        Accesos
      </a>
      <a href="#" class="btn btn-default btn-rounded font-bold btnSection" data-section="#analisis">
        Análisis
      </a>
      <a href="#" class="btn btn-default btn-rounded font-bold btnSection" data-section="#list">
        Listado
      </a>
      <a href="#" class="btn btn-default btn-rounded font-bold btnSection" data-section="#ePOS">
        ePOS
      </a>
      <a href="#" class="btn btn-default btn-rounded font-bold btnSection" data-section="#listEPOS">
        Listado ePOS
      </a>
      <a href="#" class="btn btn-default btn-rounded font-bold btnSection" data-section="#transactionMonthEPOS">
        Transaccion por Mes en ePos
      </a>

    </div>

    <div class="col-xs-12 wrapper" id="sections">

      <?= modBlock(['title' => 'eMail', 'description'        => 'Ingresa a tu email corporativo', 'url'   => '/:2096']); ?>
      <?= modBlock(['title' => 'Bitrix24 CRM', 'description' => 'Ingresa a tu cuenta del CRM', 'url'      => 'https://encom.bitrix24.com/']); ?>
      <?= modBlock(['title' => 'Intercom', 'description'     => 'Ingresa al chat de soporte', 'url'       => 'https://app.intercom.com/']); ?>
      <?= modBlock(['title' => 'Tutoriales', 'description'   => 'Editar y añadir tutoriales', 'url'       => 'https://www.gitbook.com/']); ?>
      <?= modBlock(['title' => 'Novedades', 'description'    => 'Añade novedades de la plataforma', 'url' => 'https://headwayapp.co/encom-changelog']); ?>
      <?= modBlock(['title' => 'Marangatu', 'description'    => 'Ingresa a tu email corporativo', 'url' => 'https://marangatu.set.gov.py/eset/login']); ?>
      <?= modBlock(['title' => 'CPanel', 'description'       => 'Ingresa a tu email corporativo', 'url' => '/:2083']); ?>
      <?= modBlock(['title' => 'WHM', 'description'          => 'Ingresa a tu email corporativo', 'url' => '/:2087']); ?>

      <?php
      if (ROLE_ID <= 1) {
      ?>

        <?= modBlock(['title' => 'Panel de ENCOM', 'description' => 'Ir al panel de control de ENCOM', 'url' => '/@#dashboard']); ?>
        <?= modBlock(['title' => 'Nuke', 'description' => 'Eliminar seleccionados', 'url' => '#', 'id' => 'nuke', 'color' => 'danger']); ?>

      <?php
      }
      ?>
    </div>

    <div class="col-xs-12 no-padder m-t-lg hidden" id="analisis">

      <div class="col-xs-12 col-sm-6 wrapper bg-white r-3x tableCountries">
        <div class="col-xs-12 h2 font-bold m-b text-dark">
          Cuentas por país
        </div>
        <table class="table">
          <tbody>

          </tbody>
        </table>
      </div>

      <div class="col-xs-12 col-sm-6 h2 font-bold m-b text-dark">
        <div class="col-xs-12 h3 font-bold m-b">Estado de las cuentas</div>
        <div class="col-xs-12 col-sm-4 text-center inactive">
          <span class="h1 font-bold">...</span>
          <div class="font-normal text-sm">Inactivos</div>
        </div>

        <div class="col-xs-12 col-sm-4 text-center risk">
          <span class="h1 font-bold">...</span>
          <div class="font-normal text-sm">En Riesgo</div>
        </div>

        <div class="col-xs-12 col-sm-4 text-center active">
          <span class="h1 font-bold">...</span>
          <div class="font-normal text-sm">Activos</div>
        </div>
      </div>
    </div>

    <div class="col-xs-12 no-padder hidden" id="list">
      <div class="col-xs-12 h2 font-bold m-t-lg m-b text-dark">
        Listado de empresas asignadas
      </div>

      <div class="col-xs-12 wrapper bg-white panel r-24x tableContainer push-chat-down table-responsive">
        <table class="table hover col-xs-12 no-padder" id="tableCompanies">
          <?= placeHolderLoader('table') ?>
        </table>
      </div>

    </div>

    <div class="col-xs-12 no-padder hidden" id="ePOS">
      <div class="col-xs-12 h2 font-bold m-t-lg m-b text-dark">
        <div class="col-lg-8 col-md-6 col-sm-4">
          Transacciones ePOS
        </div>

        <div class="col-lg-2 col-md-3 col-sm-4 text-right">
          <label class="font-bold text-u-c text-sm">Por mes</label>
          <input type="checkbox" id="ePOSmonthly" class="" value="1">
        </div>
        <div class="col-lg-2 col-md-3 col-sm-4">
          <input type="text" class="form-control rounded text-center" id="ePOSDate" />
        </div>
      </div>

      <div class="col-xs-12 wrapper bg-white panel r-24x ePOSTableContainer push-chat-down table-responsive">
        <table class="table hover col-xs-12 no-padder" id="tableePOS"> <?= placeHolderLoader('table') ?> </table>
      </div>

    </div>

    <div class="col-xs-12 no-padder hidden" id="listEPOS">
      <div class="col-xs-12 h2 font-bold m-t-lg m-b text-dark">
        Listado de empresas asignadas con ePOS
      </div>

      <div class="col-xs-12 wrapper bg-white panel r-24x ePOSListTableContainer push-chat-down table-responsive">
        <table class="table hover col-xs-12 no-padder" id="tableCompaniesEpos"> <?= placeHolderLoader('table') ?> </table>
      </div>

    </div>

    <div class="col-xs-12 no-padder hidden" id="transactionMonthEPOS">
      <div class="col-xs-12 h2 font-bold m-t-lg m-b text-dark">
        Listado de transacciones por mes de empresas con ePOS
      </div>

      <div class="col-xs-12 wrapper bg-white panel r-24x ePOSTransactionMonthTableContainer push-chat-down table-responsive">
        <table class="table hover col-xs-12 no-padder" id="tableTransactionMonthEpos"> <?= placeHolderLoader('table') ?> </table>
      </div>

    </div>

    <div class="col-xs-12 wrapper m-b hidden">
      <textarea class="form-control" style="width:100%; height:300px;">

            <?php
            /*  $country = '';
              if($_GET['country']){
                $country = " AND s.settingCountry = '".$db->Prepare($_GET['country'])."'";
              }
              $sql = "SELECT c.contactEmail as email, c.companyId as id FROM contact c, setting s WHERE c.companyId = s.companyId AND c.role = 1 AND c.type = 0 AND c.contactEmail != ''".$country;
              $result = $db->Execute($sql);
              $a = array();
            while (!$result->EOF) {
              if($result->fields['email'] != ''){
                echo $result->fields['email']."\n";
                $a[$result->fields['id']] = $result->fields['email'];
              }
              $result->MoveNext(); 
            }*/
            ?>
      </textarea>
    </div>

    <div class="col-xs-12 wrapper m-b hidden">
      <textarea class="form-control" style="width:100%; height:300px;">
        <?php
        /* $companyInfo = getAllCompanies();
        foreach($companyInfo as $cdata){
          echo $cdata['name'].',';
          echo $a[$cdata['id']].',';
        }*/
        ?>
      </textarea>
    </div>

    <div class="col-xs-12 wrapper hidden">
      <table class="table">
        <?php

        /*$pais = $db->Execute('SELECT settingCountry,COUNT(settingId) as count FROM company GROUP BY settingCountry ORDER BY count DESC');
        while (!$pais->EOF) {
          echo '<tr>';
          $cn = $countries[$pais->fields['settingCountry']]['name'];
          $count = $pais->fields['count'];
          if($cn != '' && $count > 4){
            echo '<td>' . $cn . '</td><td>' . $count . '</td>';
          }
          echo '<tr>';
          
          $pais->MoveNext(); 
        }*/
        ?>
      </table>
    </div>

  </section>

  <div class="modal fade" tabindex="-1" id="modalView" role="dialog">
    <div class="modal-dialog">
      <div class="modal-content bg-white no-padder clear col-xs-12 r-24x all-shadows no-border">
        <div class="modal-body">

        </div>
      </div>
    </div>
  </div>



  <?php
  footerInjector();
  /*loadCDNFiles([
                'https://cdn.jsdelivr.net/simplestorage/0.2.1/simpleStorage.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.1/js/select2.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.8/js/i18n/es.js',
                'https://cdnjs.cloudflare.com/ajax/libs/limonte-sweetalert2/7.33.1/sweetalert2.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/3.1.4/js/bootstrap-datetimepicker.min.js'
              ],'js');*/
  ?>

  <script type="text/javascript" src="/screens/scripts/ncm-ws.js"></script>
  <script>var WS_URL = '<?= WS_URL ?>';</script>
  <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/push.js/1.0.8/push.min.js"></script>

  <script src="/scripts/initials.js?<?= date('d.H') ?>"></script>
  <script src="/scripts/tdp.js?<?= date('d.H') ?>"></script>
  <script src="/scripts/ncm.js?<?= date('d.h.s') ?>"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/3.1.4/js/bootstrap-datetimepicker.min.js"></script>


  <script>
    $(function() {
      FastClick.attach(document.body);
      ncmUI.setDarkMode.autoSelected();

      var offset = <?= $offsetDetail ?>;
      var detail = <?= $limitDetail ?>;
      var currency = '<?= CURRENCY ?>';
      var decimal = '<?= DECIMAL ?>';
      var url = "?action=showTable";
      var companyID = '<?= enc(COMPANY_ID) ?>';

      $.get(url, (result) => {

        var options = {
          "container": ".tableContainer",
          "url": url,
          "iniData": result.table,
          "table": "#tableCompanies",
          "sort": 9,
          "currency": currency,
          "decimal": decimal,
          "offset": offset,
          "limit": detail,
          "nolimit": true,
          "noMoreBtn": false,
          "tableName": 'tableCompanies',
          "fileTitle": 'Listado de empresas',
          "ncmTools": {
            left: '',
            right: ''
          },
          "colsFilter": {
            name: 'contactsListingMain4',
            menu: [{
                "index": 0,
                "name": "Nro.",
                "visible": false
              },
              {
                "index": 1,
                "name": "ID",
                "visible": false
              },
              {
                "index": 2,
                "name": "NCM ID",
                "visible": false
              },
              {
                "index": 3,
                "name": 'Plan',
                "visible": true
              },
              {
                "index": 4,
                "name": 'Estado de Cuenta',
                "visible": false
              },
              {
                "index": 5,
                "name": 'Valor',
                "visible": false
              },
              {
                "index": 6,
                "name": 'Sucursales',
                "visible": false
              },
              {
                "index": 7,
                "name": 'Nombre',
                "visible": true
              },
              {
                "index": 8,
                "name": 'Propietario',
                "visible": true
              },
              {
                "index": 9,
                "name": 'Telefono',
                "visible": false
              },
              {
                "index": 10,
                "name": 'Email',
                "visible": false
              },
              {
                "index": 11,
                "name": 'Fecha de registro',
                "visible": true
              },
              {
                "index": 12,
                "name": 'Último uso',
                "visible": false
              },
              {
                "index": 13,
                "name": 'País',
                "visible": false
              },
              {
                "index": 14,
                "name": 'Categoría',
                "visible": false
              },
              {
                "index": 15,
                "name": 'ePOS',
                "visible": true
              },
              {
                "index": 16,
                "name": 'Ecommerce',
                "visible": false
              },
            ]
          },
          "clickCB": (event, tis) => {
            var load = tis.data('load');
          }
        };

        ncmDataTables(options, (oTable) => {
          //loadTheTable(options,oTable);
        });

      });

      onClickWrap('.loadDash', function(event, tis) {
        var url = tis.attr('href');
        window.location = url;
      });

      $.get("?action=getOnRisk", function(result) {
        $('.inactive span').text(result.inactive);
        $('.risk span').text(result.risk);
        $('.active span').text(result.active);
      });

      $.get("?action=countries", function(result) {
        var list = '';
        $.each(result, function(name, count) {
          list += '<tr><td>' + name + '</td><td>' + count + '</td></tr>';
        });

        $('div.tableCountries table tbody').html(list);
      });

      $('#ePOSDate').datetimepicker({
        format: 'YYYY-MM-DD'
      });

      var ePOSListLoaded = false;
      window.ePOSListDT = false;
      var loadePOSTable = (date) => {

        if (!date) {
          date = moment().format('YYYY-MM-DD');
        }

        $('#ePOSDate').val(date);

        var byMonthChk = $("#ePOSmonthly").is(':checked');
        var urlePOS = "?action=listePOS&date=" + date + "&byMonth=" + byMonthChk;

        $.get(urlePOS, (resultePOS) => {

          if (window.ePOSListDT) {
            window.ePOSListDT.clear().destroy();
            $("#tableePOS").empty();
            window.ePOSListDT = false;
          }

          var options = {
            "container": ".ePOSTableContainer",
            "url": urlePOS,
            "iniData": resultePOS.table,
            "table": "#tableePOS",
            "sort": 3,
            "footerSumCol": [11, 12, 13, 14],
            "currency": 'Gs.',
            "decimal": decimal,
            "offset": offset,
            "limit": detail,
            "nolimit": false,
            "noMoreBtn": false,
            "tableName": 'tableePOS',
            "fileTitle": 'Listado de Pagos Online',
            "ncmTools": {
              left: '',
              right: '<a href="#" class="btn btn-sm btn-rounded b b-light text-u-c font-bold text-white pull-right ePOSDeposit">Acreditar</a>'
            },
            "colsFilter": {
              name: 'ePOSList6',
              menu: [{
                  "index": 0,
                  "name": 'Comercio',
                  "visible": true
                },
                {
                  "index": 1,
                  "name": 'Sucursal',
                  "visible": false
                },
                {
                  "index": 2,
                  "name": 'Fecha de Operación',
                  "visible": false
                },
                {
                  "index": 3,
                  "name": 'Fecha de Pago',
                  "visible": true
                },
                {
                  "index": 4,
                  "name": 'Días',
                  "visible": false
                },
                {
                  "index": 5,
                  "name": 'Cod. Autorización',
                  "visible": true
                },
                {
                  "index": 6,
                  "name": 'Nro. Operación',
                  "visible": true
                },
                {
                  "index": 7,
                  "name": 'Procesadora',
                  "visible": true
                },
                {
                  "index": 8,
                  "name": '% Comisión',
                  "visible": false
                },
                {
                  "index": 9,
                  "name": 'Paga Comisión',
                  "visible": false
                },
                {
                  "index": 10,
                  "name": 'Medio',
                  "visible": true
                },
                {
                  "index": 11,
                  "name": 'Total de la venta',
                  "visible": false
                },
                {
                  "index": 12,
                  "name": 'Comisión',
                  "visible": true
                },
                {
                  "index": 13,
                  "name": 'IVA',
                  "visible": false
                },
                {
                  "index": 14,
                  "name": 'Acreditar',
                  "visible": true
                },
                {
                  "index": 15,
                  "name": 'Estado',
                  "visible": true
                },
                {
                  "index": 16,
                  "name": 'Acciones',
                  "visible": false
                }
              ]
            },
            "clickCB": (event, tis) => {
              var load = tis.data('load');
            }
          };

          ncmDataTables(options, (oTable) => {
            window.ePOSListDT = oTable;
          });

          var prevDate = $('input#ePOSDate').val();
          $('input#ePOSDate').off('dp.change').on('dp.change', () => {
            var newDate = $('input#ePOSDate').val();

            if (newDate != prevDate) {
              loadePOSTable($('#ePOSDate').val());
            }
          });

          $('#ePOSmonthly').off('change').on('change', () => {
            loadePOSTable($('#ePOSDate').val());
          });

          onClickWrap('.openTrePOS', function(event, tis) {

            var data = JSON.parse(atob(tis.data('load')));

            var table = '<div class="col-xs-12 h2 m-b wrapper text-center font-bold">Datos para transferencia</div><table class="table"><tbody>' +
              ' <tr>' +
              '   <td class="font-bold">Banco</td>' +
              '   <td>' + data.bankName + '</td>' +
              '   <td></td>' +
              ' </tr>' +
              ' <tr>' +
              '   <td class="font-bold">Nro. de Cuenta</td>' +
              '   <td>' + data.bankAccount + '</td>' +
              '   <td> <a href="#" class="btn btn-xs copyTxt" data-text="' + data.bankAccount + '"> <span class="material-icons md-14">content_copy</span> </a> </td>' +
              ' </tr>' +
              ' <tr>' +
              '   <td class="font-bold">Beneficiario</td>' +
              '   <td>' + data.bankBeneficiary + '</td>' +
              '   <td> <a href="#" class="btn btn-xs copyTxt" data-text="' + data.bankBeneficiary + '"> <span class="material-icons md-14">content_copy</span> </a> </td>' +
              ' </tr>' +
              ' <tr>' +
              '   <td class="font-bold">Tipo doc.</td>' +
              '   <td>' + data.bankBeneficiaryTypeLID + '</td>' +
              '   <td></td>' +
              ' </tr>' +
              ' <tr>' +
              '   <td  class="font-bold">Nro de documento</td>' +
              '   <td>' + data.bankBeneficiaryLID + '</td>' +
              '   <td> <a href="#" class="btn btn-xs copyTxt" data-text="' + data.bankBeneficiaryLID + '"> <span class="material-icons md-14">content_copy</span> </a> </td>' +
              ' </tr>' +
              '</tbody> </table>';

            $('#modalView .modal-content').html(table);
            $('#modalView').modal('show');
          });

          onClickWrap('.copyTxt', function(event, tis) {
            var text = tis.data('text');
            navigator.clipboard.writeText(text).then(() => {
              ncmDialogs.toast('Copiado', 'success');
            }, () => {
              ncmDialogs.toast('No se pudo copiar', 'danger');
            });
          });

          onClickWrap('.deleteePOSRecord', function(event, tis) {
            var ID = tis.data('id');

            confirmation('Realmente quiere eliminar?', function(e) {
              if (e) {
                $.ajax({
                  url: './main?action=deleteePOSRecord&ID=' + ID,
                  type: "GET",
                  success: function(result) {
                    if (result.success) {
                      message('Eliminado', 'success');
                      loadePOSTable($('input#ePOSDate').val());
                      spinner('body', 'hide');
                    } else {
                      message('Error al eliminar', 'danger');
                    }
                  }
                });
              }

            });
          });

          onClickWrap('.ePOSDeposit', function(event, tis) {
            var arrSend = [];
            var tis = '';
            var id = '';

            confirmation('Realmente quiere acreditar?', function(e) {
              if (e) {

                spinner('body', 'show');
                $('table#tableePOS tbody tr.selected').each(function(i, val) {
                  tis = $(this);
                  id = tis.data('id');
                  arrSend.push(id);
                });

                $.ajax({
                  url: './main?action=depositStateePOS',
                  type: "POST",
                  data: {
                    "data": arrSend
                  },
                  success: function(result) {
                    if (result.success) {
                      message('Modificado', 'success');
                      loadePOSTable($('input#ePOSDate').val());
                      spinner('body', 'hide');
                    }
                  }
                });

              }

            });
          });


        });

      };

      window.ePOSCompaniesListDT = false;
      var loadListePOSTable = () => {

        if (window.ePOSCompaniesListDT) {
          ePOSCompaniesListDT.clear().destroy();
          $("#tableCompaniesEpos").empty();
          ePOSCompaniesListDT = false;
        }

        var urlePOSList = "?action=listePOSTable";

        $.get(urlePOSList, (resultePOSCompanyList) => {

          var options = {
            "container": ".ePOSListTableContainer",
            "url": urlePOSList,
            "iniData": resultePOSCompanyList.table,
            "table": "#tableCompaniesEpos",
            "sort": 9,
            "currency": currency,
            "decimal": decimal,
            "offset": offset,
            "limit": detail,
            "nolimit": false,
            "noMoreBtn": false,
            "tableName": 'tableCompaniesEpos',
            "fileTitle": 'Listado de empresas con ePOS',
            "ncmTools": {
              left: '',
              right: ''
            },
            "colsFilter": {
              name: 'contactsListingMain5',
              menu: [{
                  "index": 0,
                  "name": "Nro.",
                  "visible": false
                },
                {
                  "index": 1,
                  "name": "ID",
                  "visible": false
                },
                {
                  "index": 2,
                  "name": "NCM ID",
                  "visible": false
                },
                {
                  "index": 3,
                  "name": 'Plan',
                  "visible": false
                },
                {
                  "index": 4,
                  "name": 'Valor',
                  "visible": false
                },
                {
                  "index": 5,
                  "name": 'Nombre',
                  "visible": true
                },
                {
                  "index": 6,
                  "name": 'Propietario',
                  "visible": true
                },
                {
                  "index": 7,
                  "name": 'Telefono',
                  "visible": false
                },
                {
                  "index": 8,
                  "name": 'Email',
                  "visible": false
                },
                {
                  "index": 9,
                  "name": 'Fecha de registro',
                  "visible": false
                },
                {
                  "index": 10,
                  "name": 'Último uso',
                  "visible": false
                },
                {
                  "index": 11,
                  "name": 'País',
                  "visible": false
                },
                {
                  "index": 12,
                  "name": 'Categoría',
                  "visible": false
                },
                {
                  "index": 13,
                  "name": 'ePOS',
                  "visible": true
                },
                {
                  "index": 14,
                  "name": 'POS Físico',
                  "visible": true
                },
                {
                  "index": 15,
                  "name": '% Comisión T.C.',
                  "visible": true
                },
                {
                  "index": 16,
                  "name": '% Comisión T.D.',
                  "visible": true
                },
                {
                  "index": 17,
                  "name": '% Comisión Online',
                  "visible": true
                },
                {
                  "index": 18,
                  "name": 'Días desembolso',
                  "visible": true
                },
                {
                  "index": 19,
                  "name": 'Última transacción ePOS',
                  "visible": true
                },
                {
                  "index": 20,
                  "name": 'Banco',
                  "visible": true
                },
                {
                  "index": 21,
                  "name": 'Nro. de Cuenta',
                  "visible": true
                },
                {
                  "index": 22,
                  "name": 'Beneficiario',
                  "visible": true
                },
                {
                  "index": 23,
                  "name": 'Tipo de Documento',
                  "visible": true
                },
                {
                  "index": 24,
                  "name": 'Nro. de Documento',
                  "visible": true
                }
              ]
            },
            "clickCB": (event, tis) => {
              var load = tis.data('load');
            }
          };

          ncmDataTables(options, (oTable) => {
            window.ePOSCompaniesListDT = oTable;
          });
        });
      };

      window.ePOSTransactionMonthListDT = false;
      var loadListeTransactionPOSTable = () => {

        if (window.ePOSTransactionMonthListDT) {
          ePOSTransactionMonthListDT.clear().destroy();
          $("#tableTransactionMonthEpos").empty();
          ePOSTransactionMonthListDT = false;
        }

        var urlePOSTransactionMonth = "?action=listeTransactionMonthPOSTable";

        $.get(urlePOSTransactionMonth, (resultePOSCTransactionMonth) => {

          var options = {
            "container": ".ePOSTransactionMonthTableContainer",
            "url": urlePOSTransactionMonth,
            "iniData": resultePOSCTransactionMonth.table,
            "table": "#tableTransactionMonthEpos",
            "sort": 9,
            "currency": currency,
            "decimal": decimal,
            "offset": offset,
            "limit": detail,
            "nolimit": false,
            "noMoreBtn": false,
            "tableName": 'tableTransactionMonthEpos',
            "fileTitle": 'Listado de empresas con ePOS',
            "ncmTools": {
              left: '',
              right: ''
            },
            "colsFilter": {
              name: 'contactsListingMain6',
              menu: [{
                  "index": 0,
                  "name": "Nro.",
                  "visible": false
                },
                {
                  "index": 1,
                  "name": "ID",
                  "visible": true
                },
                {
                  "index": 2,
                  "name": 'Plan',
                  "visible": true
                },
                {
                  "index": 3,
                  "name": 'Estado de Cuenta',
                  "visible": true
                },
                {
                  "index": 4,
                  "name": 'Valor',
                  "visible": true
                },
                {
                  "index": 5,
                  "name": 'Sucursales',
                  "visible": true
                },
                {
                  "index": 6,
                  "name": 'Nombre',
                  "visible": true
                },
                {
                  "index": 7,
                  "name": 'Propietario',
                  "visible": true
                },
                {
                  "index": 8,
                  "name": 'Telefono',
                  "visible": true
                },
                {
                  "index": 9,
                  "name": 'Email',
                  "visible": false
                },
                {
                  "index": 10,
                  "name": 'Fecha de registro',
                  "visible": true
                },
                {
                  "index": 11,
                  "name": 'País',
                  "visible": true
                },
                {
                  "index": 12,
                  "name": 'Ciudad',
                  "visible": true
                },
                {
                  "index": 13,
                  "name": 'Categoría',
                  "visible": true
                },
                {
                  "index": 14,
                  "name": 'ePOS',
                  "visible": true
                },
                {
                  "index": 15,
                  "name": 'POS Físico',
                  "visible": true
                },
                {
                  "index": 16,
                  "name": 'Mes',
                  "visible": true
                },
                {
                  "index": 17,
                  "name": 'Año',
                  "visible": true
                },
                {
                  "index": 18,
                  "name": 'Monto',
                  "visible": true
                }
              ]
            },
            "clickCB": (event, tis) => {
              var load = tis.data('load');
            }
          };

          ncmDataTables(options, (oTable) => {
            window.ePOSTransactionMonthListDT = oTable;
          });
        });
      };

      var opts = {
        readAsDefault: 'ArrayBuffer',
        dragClass: 'dker',
        on: {
          beforestart: () => {
            spinner('body', 'show');
          },
          load: function(e, file) {
            var result = new Uint8Array(e.target.result);
            var xlsread = XLSX.read(result, {
              type: 'array'
            });

            //var sheetIs   = xlsread.Sheets.Sheet1 ? xlsread.Sheets.Sheet1 : xlsread.Sheets.Hoja1;
            // Obtener la primera hoja automáticamente
            var firstSheetName = xlsread.SheetNames[0];
            var sheetIs = xlsread.Sheets[firstSheetName];

            var xlsjson = XLSX.utils.sheet_to_json(sheetIs);

            var arrSend = [];
            console.log('JSON file firstSheetName', firstSheetName);
            console.log('JSON file sheetIs', sheetIs);
            console.log('JSON file xlsjson', xlsjson);

            //return false;

            /*$.each(xlsjson, function(i, val){
              arrSend.push(val.COMPROBANTE);
            });*/

            $.ajax({
              url: './main?action=changeStateePOS',
              type: "POST",
              data: {
                "data": xlsjson
              },
              success: function(result) {
                if (result.success) {
                  message('Archivo subido', 'success');
                  loadePOSTable($('input#ePOSDate').val());
                  spinner('body', 'hide');
                } else {
                  message('Error al subir el archivo', 'danger');
                  spinner('body', 'hide');
                }
              },
              fail: () => {
                message('Error al subir el archivo', 'danger');
              }
            });
          }
        }
      };

      $(".ePOSTableContainer").fileReaderJS(opts);



      <?php
      if (ROLE_ID <= 1) {
      ?>

        onClickWrap('.btnSection', function(event, tis) {
          var section = tis.data('section');
          $('#list, #analisis, #sections, #ePOS, #listEPOS, #transactionMonthEPOS').addClass('hidden');
          $(section).removeClass('hidden');
          if (section == '#ePOS') {
            loadePOSTable();
          } else if (section == '#listEPOS') {
            loadListePOSTable();
          } else if (section == '#transactionMonthEPOS') {
            loadListeTransactionPOSTable();
          }
        });

        onClickWrap('.createItemBtn', function(event, tis) {
          loadForm('?action=insertForm<?= ($_GET['iam']) ? '&iam=true' : '' ?>', '#modalView .modal-content', function() {
            $('#modalView').modal('show');
          });
        });

        onClickWrap('.openTr', function(event, tis) {
          //$('.openTr').on('click', function(event,tis){
          var load = tis.attr('data-load');
          $('tr.active').removeClass('active');
          tis.addClass('active');
          loadForm(load, '#modalView .modal-content', function() {
            $('#modalView').modal('show');
          });
        });

        onClickWrap('.cancelItemView', function(event, tis) {
          $('#modalView').modal('hide');
        });

        onClickWrap('.selectIt', function(event, tis) {
          tis.closest('tr').toggleClass('bg-dark');
          tis.toggleClass('selectedIt');
        });

        onClickWrap('#nuke', function(event, tis) {
          var str = "Desea eliminar por completo del sistema a estas empresas?";
          if (confirm(str) == true) {

            $('.selectedIt').each(function() {
              var tis = $(this);
              var id = tis.data('id');

              if (id) {
                $.get('main?action=delete&id=' + id + '<?= ($_GET['iam']) ? '&iam=true' : '' ?>', function(result) {
                  if (result == 'true') {
                    tis.closest('tr').remove();
                    message('Empresa Eliminada', 'success');
                  } else {
                    message('No se pudo elminar la empresa', 'danger');
                    alert(result);
                  }
                });
              }
            });

          }
        });

        onClickWrap('#deleteAccount', function(event, tis) {

          var str = "Desea eliminar por completo del sistema a esta empresa?";
          var id = tis.attr('data-id');

          if (confirm(str) == true) {
            $('#modalView').modal('hide');
            $('tr.active').remove();
            $.get('main?action=delete&id=' + id + '<?= ($_GET['iam']) ? '&iam=true' : '' ?>', function(result) {
              if (result == 'true') {
                manageTable(info1);
                message('Empresa Eliminada', 'success');
              } else {
                message('No se pudo elminar la empresa', 'danger');
                alert(result);
              }
            });
          }
        });

        $('#modalView').off('shown.bs.modal').on('shown.bs.modal', function() {
          select2Simple($('select.chosen-select'), $('#modalView'));

          $('select.chosen-select').on('select2:select', function(e) {
            var elm = e.params.data.element;
            $elm = $(elm);
            $t = $(this);
            $t.append($elm);
            $t.trigger('change.select2');
          });

          $('.select2-selection__choice__remove').click(function() {
            $('select.chosen-select').trigger('change.select2');
          });

          submitForm('#addItem,#editItem,#insertItem', function(element, id) {
            loadForm('?action=editForm&id=' + id + '<?= ($_GET['iam']) ? '&iam=true' : '' ?>', '#modalView .modal-content', function() {
              $('#modalView').modal('hide');
            });
          });

        });

      <?php
      }
      ?>

      var pusher = new NcmWS(WS_URL);

      var channel = pusher.subscribe('ncm-ePOS');
      channel.bind('payoutNow', function(result) {
        var data = JSON.parse(result.message);
        Push.create(data.title, {
          body: data.msg,
          icon: '/images/iconincomesm.png',
          timeout: 30 * 60000,
          onClick: () => {
            window.location = '/main';
          }
        });
      });

      Push.Permission.request(() => {}, () => {});



    });
  </script>
</body>

</html>
<?php
include_once('includes/compression_end.php');
dai();
?>
