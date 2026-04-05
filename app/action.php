<?php
session_start();
require_once(__DIR__ . '/includes/cors.php');

$GLOBALS['_execution_start'] = microtime(true);

function checkExecTime($reference = false)
{
  $toPrint = ($reference) ? $reference : $_SERVER;

  $executionTimeLast = microtime(true) - $GLOBALS['_execution_start'];

  if ($executionTimeLast >= 1) {
    file_put_contents(
      'cach/mysql_profiling_results.txt',
      $executionTimeLast . ':' . print_r($toPrint, true) . "\n",
      FILE_APPEND
    );
  }
  $GLOBALS['_execution_start'] = microtime(true);
}

require_once('libraries/whoops/autoload.php');
require_once('includes/jwt_middleware.php');

// Decodificar parámetro legacy l= (siempre necesario para extraer la acción)
$decode     = base64_decode($_GET['l'] ?? '');
$get        = json_decode($decode, true) ?? [];
$action     = $get['action']     ?? null;
$companyId  = $get['companyId']  ?? null;
$outletId   = $get['outletId']   ?? null;
$userId     = $get['userId']     ?? null;
$roleId     = $get['roleId']     ?? null;
$registerId = $get['registerId'] ?? null;

if ($action && $companyId && $outletId && $userId && $roleId && $registerId) {
  $rateLimiterId = $registerId;

  include_once('head.php');

  // Autenticación JWT (cookie HttpOnly, header Bearer, o POST _jwt)
  $jwtValid = jwtAuthenticate();

  if ($jwtValid) {
    // Identidad validada server-side — valores del token firmado
    $companyId  = AUTHED_COMPANY_ID;
    $outletId   = AUTHED_OUTLET_ID;
    $userId     = AUTHED_USER_ID;
    $roleId     = AUTHED_ROLE_ID;
    $registerId = AUTHED_REGISTER_ID;
  } else {
    // Ruta legacy: decodificar Hashids del parámetro l=
    header('X-Legacy-Auth: 1'); // monitoreo de adopción JWT
    $companyId  = db_prepare(dec($companyId));
    $outletId   = db_prepare(dec($outletId));
    $userId     = db_prepare(dec($userId));
    $roleId     = db_prepare($roleId);
    $registerId = db_prepare(dec($registerId));
  }

  if (!checkCompanyStatus($companyId)) {
    jsonDieMsg('Company Blocked');
  }

  include_once('data.php');

  if ($action == 'encode') {
    $id = $get['id'];
    if (is_numeric($id)) {
      $id = enc($id);
    }

    jsonDieMsg($id, 200, 'success');
  }

  if ($action == 'clockIn' && $get['o'] && $get['u'] && $get['t']) {
    $data = [
      'api_key'       => API_KEY,
      'company_id'    => enc(COMPANY_ID),
      'outlet'        => $get['o'],
      'user'          => $get['u'],
      'token'         => $get['t']
    ];

    $result = json_decode(curlContents(API_ENCOM_URL . '/set_attendance.php', 'POST', $data));

    jsonDieResult($result, 200);
  }

  if ($action == 'setCurrencies') {
    $currens = $_fullSettings['currencies'] ?? null;
    $out = [];
    foreach ($_COUNTRIES_H as $ccode => $value) {
      $currency = isset($value['currency']['code']) ? $value['currency']['code'] : null;
      $curcur   = 0;

      if (validity($currens)) {
        foreach ($currens as $k => $v) {
          if (isset($v[$currency]) && $v[$currency] > 0) {
            $curcur = floatval($v[$currency]);
          }
        }
      }

      if ($currency != null && $currency != COUNTRY) {
        $out[]  = ['ccode' => $ccode, 'code' => $currency, 'value' => $curcur];
      }
    }

    jsonDieMsg($out, 200, 'success');
  }

  if ($action == 'checkoutScreen' && $get['d']) {
    return false;
    $data = [
      'api_key'       => API_KEY,
      'company_id'    => enc(COMPANY_ID),
      'channel'       => enc(COMPANY_ID) . '-' . enc(REGISTER_ID) . '-register',
      'event'         => 'checkoutScreen',
      'message'       => json_encode($get['d'])
    ];

    $result = json_decode(curlContents(API_ENCOM_URL . '/send_webSocket.php', 'POST', $data));

    jsonDieResult($result, 200);
  }

  if (!empty($action) && (strpos($action, "notifications") !== false)) {
    $actions = explode(",", $action);
    if (!empty($actions) && $actions[0] == "notifications") {
      $type = $actions[1] ?? "notes";
      $data = [
        'api_key'     => API_KEY,
        'company_id'  => enc(COMPANY_ID),
        'user'        => enc(USER_ID),
        'type'        => $type,
        'outlet'      => enc(OUTLET_ID)
      ];
      $result = curlContents(API_ENCOM_URL . '/get_notifications', 'POST', $data);
      //error_log(print_r($result,true));
      if ($result) {
        header('Content-Type: application/json;');
        echo $result;
      }
      dai();
    }
    if ($action == 'notificationsCount') {
      $type = $actions[1] ?? "notes";
      $data = [
        'api_key'     => API_KEY,
        'company_id'  => enc(COMPANY_ID),
        'user'        => enc(USER_ID),
        'type'        => $type,
        'outlet'      => enc(OUTLET_ID)
      ];
      $result = curlContents(API_ENCOM_URL . '/get_notifications_count', 'POST', $data);
      if ($result) {
        header('Content-Type: application/json;');
        echo $result;
      }
      dai();
    }
  }


  if ($action == 'deleteInPrintServer' && $get['id']) {
    $transID  = dec($get['id']);
    $delete   = ncmExecute('DELETE FROM printServer WHERE transactionId = ? AND companyId = ?', [$transID, COMPANY_ID]);

    if ($delete !== false) {
      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'chkDeletedItems' && validateHttp('ids', 'post')) {
    $ids      = validateHttp('ids', 'post');
    $deleted  = [];

    //jsonDieMsg('No removed items',404);

    if (validity($ids)) {
      //SELECT * FROM item WHERE companyId = ? AND itemStatus = 1 AND itemCanSale = 1 AND (outletId = ? OR outletId IS NULL OR outletId = 0)" . $updated_at . " ORDER BY itemDate " . $order . $limit
      $result     = ncmExecute("SELECT itemId, itemId FROM item WHERE companyId = ? AND itemStatus = 1 AND itemCanSale = 1 LIMIT 50000", [COMPANY_ID], false, true, true); //devuelvo object

      if ($result) {
        $result = array_flatten($result);
        foreach ($ids as $i => $id) {
          if (!in_array(dec($id), $result)) {
            //echo dec($id) . ' not in ' . print_r($result);
            if ($id && $id != 'intCred') {
              $deleted[] = $id;
            }
          }
        }
      }
    }

    if (validity($deleted)) {
      jsonDieMsg($deleted, 200, 'success');
    } else {
      jsonDieMsg('No data found', 404);
    }
  }

  if ($action == 'chkDeletedCustomers' && validateHttp('ids', 'post')) {
    $ids      = validateHttp('ids', 'post');
    $deleted  = [];

    //jsonDieMsg('No removed customers',404);

    if (validity($ids)) {

      $result     = ncmExecute("SELECT contactUID, contactUID FROM contact WHERE companyId = ? AND contactStatus > 0 AND type = 1 ORDER BY contactName ASC LIMIT 10000", [COMPANY_ID], false, true, true); //devuelvo object

      if ($result) {
        $result = array_flatten($result);
        foreach ($ids as $i => $id) {
          if (!in_array(dec($id), $result)) {
            if ($id) {
              $deleted[] = $id;
            }
          }
        }
      }
    }

    if (validity($deleted)) {
      jsonDieMsg($deleted, 200, 'success');
    } else {
      jsonDieMsg('No data found', 404);
    }
  }

  if ($action == 'closeTable' && $get['del']) {

    if ($get['kind'] == 'any') { //me baso en su ID
      $id     = db_prepare(dec($get['del']));
      $where  = 'transactionId = ' . $id;
    } else if ($get['kind'] == 'customer') {
      $id     = db_prepare(dec($get['del']));
      $where  = 'customerId = ' . $id;
    } else {
      $id     = db_prepare($get['del']);
      $where  = 'transactionName =  ' . $id;
    }

    //$delete = $db->Execute('DELETE FROM transaction WHERE (transactionType = 11 OR transactionType = 12)' . $where . ' AND outletId = ? AND companyId = ?',[$id,OUTLET_ID,COMPANY_ID]);
    $deleteTable  = $db->Execute('DELETE FROM transaction WHERE transactionType = 11 AND ' . $where . ' AND outletId = ? AND companyId = ?', [OUTLET_ID, COMPANY_ID]);
    $deleteJoined = $db->Execute('DELETE FROM transaction WHERE transactionType = 11 AND outletId = ? AND companyId = ? AND transactionParentId = ?', [OUTLET_ID, COMPANY_ID, $id]);

    $record['transactionStatus']  = 4;
    $record['updated_at']         = TODAY;
    $finishOrders = $db->AutoExecute('transaction', $record, 'UPDATE', $where . ' AND outletId = ' . OUTLET_ID . ' AND companyId = ' . COMPANY_ID . ' AND transactionType = 12');

    jsonDieMsg('true', 200, 'success'); // esto es porque si comparo el if del delete siempre da False
    if ($delete !== true) {
      jsonDieMsg($db->ErrorMsg());
    } else {
      updateLastTimeEdit(COMPANY_ID, 'order');
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'renameTable' && $get['note']) {
    //Elimino la mesa abierta y sus ordenes
    $record['transactionNote'] = strip_tags($get['note']);
    $delete = $db->AutoExecute('transaction', $record, 'UPDATE', 'outletId = ' . OUTLET_ID . ' AND transactionType = 11 AND transactionName = ' . db_prepare($get['t']));

    if ($delete === false) {
      jsonDieMsg();
    } else {
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'joinSpaces' && $get['tFrom'] && $get['tTo']) {
    //Elimino la mesa abierta y sus ordenes
    $tFrom   = intval($get['tFrom']);
    $tTo     = intval($get['tTo']);
    $update  = ncmUpdate(['records' => ['transactionParentId' => $tTo], 'table' => 'transaction', 'where' => 'companyId = ' . COMPANY_ID . ' AND outletId = ' . OUTLET_ID . ' AND transactionType = 11 AND transactionName = ' . $tFrom]);

    if ($update === false) {
      jsonDieMsg();
    }

    $update  = ncmUpdate(['records' => ['transactionName' => $tTo], 'table' => 'transaction', 'where' => 'companyId = ' . COMPANY_ID . ' AND outletId = ' . OUTLET_ID . ' AND transactionType = 12 AND transactionName = ' . $tFrom]);


    if ($update === false) {
      jsonDieMsg();
    } else {
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'moveOrders' && $get['tFrom'] && $get['tTo']) {
    //Elimino la mesa abierta y sus ordenes
    $tFrom    = intval($get['tFrom']);
    $tTo      = intval($get['tTo']);

    $result   = ncmExecute('SELECT * FROM transaction USE INDEX(outletId, transactionType) WHERE outletId = ? AND transactionType = 11 AND transactionName = ? LIMIT 1', [COMPANY_ID, OUTLET_ID, $tFrom]);

    if (!$result) {
      ncmInsert([
        "table"   => "transaction",
        "records" => [
          "transactionDate" => TODAY,
          "transactionName" => $tTo,
          "transactionType" => 11,
          "responsibleId"   => USER_ID,
          "userId"          => USER_ID,
          "outletId"        => OUTLET_ID,
          "registerId"      => REGISTER_ID,
          "companyId"       => COMPANY_ID
        ]
      ]);
    }

    $update   = ncmUpdate(['records' => ['transactionName' => $tTo], 'table' => 'transaction', 'where' => 'outletId = ' . OUTLET_ID . ' AND transactionType = 12 AND transactionName = ' . $tFrom]);

    if ($update === false) {
      jsonDieMsg();
    } else {
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'unReserveTable' && $get['t']) {
    //Elimino la mesa abierta y sus ordenes
    $record['transactionStatus'] = 1;
    $record['updated_at']         = TODAY;
    $delete = $db->AutoExecute('transaction', $record, 'UPDATE', 'outletId = ' . OUTLET_ID . ' AND transactionType = 11 AND transactionName = ' . db_prepare($get['t']));

    if ($delete === false) {
      jsonDieMsg();
    } else {
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'removeItemfromOrder' && $get['id']) {
    $itemId     = $get['id'];
    $oPosition  = $get['oPosition'];
    $autoPrint  = $get['autoPrint'] ?? false;
    $transId    = db_prepare(dec($get['oid']));
    $delete     = false;
    $motive     = $get['motive'] ?? null;

    $result     = ncmExecute(
      'SELECT 
                                transactionDetails
                              FROM
                                transaction 
                              WHERE transactionId = ?
                              AND companyId = ?
                              LIMIT 1',
      [$transId, COMPANY_ID]
    );

    if ($result) {
      $details = json_decode($result['transactionDetails'], true);

      if (counts($details) > 0) {
        $parent = 0;
        foreach ($details as $index => $js) {

          if ($js['itemId'] == $itemId || $js['parent'] === $parent) {
            if ($oPosition != $index) {
              continue;
            }
            if ($js['parent'] > 0) {
              $parent = $js['parent'];
            }
            $details[$index]['status'] = 0;
          }
        }

        $delete = ncmUpdate(['records' => ['transactionDetails' => json_encode($details)], 'table' => 'transaction', 'where' => 'transactionId = ' . $transId]);
      }
    }

    if ($delete === false) {
      jsonDieMsg();
    } else {
      if($autoPrint === true){
        try {
          sendWS([
            'channel'       => enc(OUTLET_ID) . '-register',
            'event'         => 'order',
            'message'       => json_encode(['ID' => enc($transId), 'registerID' => enc(REGISTER_ID), 'autoPrint' => $autoPrint, 'motive' => $motive, 'cancelledId' => $itemId])
          ]);
        } catch (\Throwable $th) {
          error_log("Error al enviar orden de impresion al websocket: \n", 3, './error_log');
          error_log(print_r($th, true), 3, './error_log');
        }
      }      

      updateLastTimeEdit(COMPANY_ID, 'order');
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'processOrderItems' && $get['items']) {
    $update   = false;
    $items    = $get['items'];

    if (count($items)) {
      $final    = [];
      foreach ($items as $key => $value) {
        $orderId    = $value['orderId'];
        $itemId     = $value['itemId'];
        $oPosition  = intval($value['oPosition']);

        $result     = ncmExecute(
          'SELECT 
                                transactionDetails
                              FROM
                                transaction 
                              WHERE transactionId = ?
                              AND companyId = ?
                              LIMIT 1',
          [dec($orderId), COMPANY_ID]
        );
        if ($result) {

          $details    = json_decode($result['transactionDetails'], true);
          $parent     = 0;
          $doit       = false;

          foreach ($details as $index => $js) {

            if ($js['itemId'] == $itemId) {
              if ($oPosition == $index) {
                if ($js['parent'] > 0) {
                  $parent = $js['parent'];
                }

                $doit       = true;
              } else {
                $doit       = false;
              }
            } else if ($js['parent'] === $parent) {
              $doit         = true;
            } else {
              $doit         = false;
            }

            if ($doit) {
              $final[]                      = $details[$index];
            }
          }
        }
      }
      updateLastTimeEdit(COMPANY_ID, 'order');
      jsonDieResult($final, 200);
    }
    jsonDieMsg();
  }

  if ($action == 'processOrderItemsUpdate' && $get['items']) {
    $update   = false;
    $items    = $get['items'];

    if (count($items)) {
      $final    = [];
      foreach ($items as $key => $value) {
        $orderId    = $value['orderId'];
        $itemId     = $value['itemId'];
        $oPosition  = intval($value['oPosition']);

        $result     = ncmExecute(
          'SELECT 
                                transactionDetails
                              FROM
                                transaction 
                              WHERE transactionId = ?
                              AND companyId = ?
                              LIMIT 1',
          [dec($orderId), COMPANY_ID]
        );
        if ($result) {

          $details    = json_decode($result['transactionDetails'], true);
          $parent     = 0;
          $doit       = false;

          foreach ($details as $index => $js) {

            if ($js['itemId'] == $itemId) {
              if ($oPosition == $index) {
                if ($js['parent'] > 0) {
                  $parent = $js['parent'];
                }

                $doit       = true;
              } else {
                $doit       = false;
              }
            } else if ($js['parent'] === $parent) {
              $doit         = true;
            } else {
              $doit         = false;
              $parent       = 0;
            }

            if ($doit) {
              $details[$index]['status']    = 2;
              $details[$index]['oPosition'] = $oPosition;
              $final[]                      = $details[$index];
            }
          }

          $update = ncmUpdate(['records' => ['transactionDetails' => json_encode($details)], 'table' => 'transaction', 'where' => 'transactionId = ' . dec($orderId)]);
        }
      }
    }

    if ($update === false) {
      jsonDieMsg();
    } else {
      updateLastTimeEdit(COMPANY_ID, 'order');
      jsonDieResult($final, 200);
    }
  }

  if ($action == 'moveOrderItems' && $get['items']) {
    $update   = false;
    $items    = $get['items'];
    $from     = $get['from'];
    $to       = $get['to'];

    if (count($items)) {
      $final    = [];
      foreach ($items as $key => $value) {
        $orderId    = $value['orderId'];
        $itemId     = $value['itemId'];
        $oPosition  = intval($value['oPosition']);

        //obtengo los items de las ordenes y cambio su estado a bloqueado

        $result   = ncmExecute(
          'SELECT 
                                transactionDetails
                              FROM
                                transaction 
                              WHERE transactionId = ?
                              AND companyId       = ?
                              AND transactionName = ?
                              LIMIT 1',
          [dec($orderId), COMPANY_ID, $from]
        );
        if ($result) {

          $details  = json_decode($result['transactionDetails'], true);
          $parent   = 0;
          foreach ($details as $index => $js) {

            if ($oPosition == $index) {

              if ($js['itemId'] == $itemId || $js['parent'] === $parent) {
                if ($js['parent'] > 0) {
                  $parent = $js['parent'];
                }
                $details[$index]['status']    = 2;
                $details[$index]['oPosition'] = $oPosition;
                $final[]                      = $details[$index];
              }
            }
          }

          $update = ncmUpdate(['records' => ['transactionDetails' => json_encode($details)], 'table' => 'transaction', 'where' => 'transactionId = ' . dec($orderId)]);
        }
      }
    }

    if ($update === false) {
      jsonDieMsg();
    } else {
      updateLastTimeEdit(COMPANY_ID, 'order');
      jsonDieResult($final, 200);
    }
  }

  if ($action == 'transferOrderToOutlet' && $get['outletFromId'] && $get['outletId']) {
    $dOutletId  = dec($get['outletFromId']);
    $dOrderId   = dec($get['orderId']);

    $result     = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$dOrderId, COMPANY_ID]);
    if ($result) {

      $result     = ncmExecute('SELECT * FROM outlet WHERE outletId = ? AND companyId = ? LIMIT 1', [$dOutletId, COMPANY_ID]);
      if ($result) {
        $update   = ncmUpdate([
          'records' => ['outletId' => $dOutletId],
          'table'   => 'transaction',
          'where'   => 'transactionId = ' . $dOrderId . ' AND companyId = ' . COMPANY_ID
        ]);

        if ($update === false) {
          jsonDieMsg('Could not update');
        } else {
          jsonDieMsg('true', 200, 'success');
        }
      } else {
        jsonDieMsg('Outlet not found');
      }
    } else {
      jsonDieMsg('Item not found');
    }

    jsonDieMsg();
  }

  if ($action == 'deleteItemHistory' && $get['id']) {

    $data   = json_encode(['motive' => markupt2HTML(['text' => $get['motive'], 'type' => 'HtM']), 'user' => enc(USER_ID)]);
    $insert = ncmInsert(['records' => ['itemId' => dec($get['id']), 'date' => TODAY, 'data' => $data, 'companyId' => COMPANY_ID, 'outletId' => OUTLET_ID], 'table' => 'itemDeleted']);

    if ($insert === false) {
      jsonDieMsg();
    } else {
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'verifyQRPaymentCode') {
    $data = [
      'api_key'       => API_KEY,
      'company_id'    => enc(COMPANY_ID),
      'code'          => $get['c']
    ];

    $result = json_decode(curlContents(API_ENCOM_URL . '/get_vpayment_verification.php', 'POST', $data));

    jsonDieResult($result, 200);
  }

  if ($action == 'ePOSAddCardTransaction') {

    $authCode       = $get['authCode'];
    $total          = $get['total'];
    $saleAmount     = $get['sale'];
    $comission      = $get['comission'] ?? NULL;
    $tax            = $get['tax'] ?? NULL;
    $UID            = $get['UID'];
    $nroOperacion   = strtotime(date('Y-m-d H:i:s'));
    $nroOperacion   = substr($nroOperacion, -6);
    $operationNo    = $get['operationNo'] ?? NULL;

    $data           = [
      'api_key'       => API_KEY,
      'company_id'    => enc(COMPANY_ID),
      'outlet'        => enc(OUTLET_ID),
      'user'          => enc(USER_ID),
      'UID'           => $UID,
      'status'        => 'REVIEW',
      'amount'        => $total,
      'saleAmount'    => $saleAmount,
      'comission'     => $comission,
      'tax'           => $tax,
      'source'        => 'POS',
      'data'          => NULL,
      'order'         => $nroOperacion,
      'authCode'      => $authCode,
      'operationNo'   => $operationNo
    ];



    $result         = json_decode(curlContents(API_ENCOM_URL . '/add_vpayment', 'POST', $data));

    jsonDieResult($result, 200);
  }

  if ($action == 'cajaPOSAddCardAndQrTransaction') {

    $authCode       = $get['authCode'];
    $total          = $get['total'];
    $saleAmount     = $get['sale'];
    $comission      = $get['comission'] ?? NULL;
    $tax            = $get['tax'] ?? NULL;
    $UID            = $get['UID'];
    $nroOperacion   = strtotime(date('Y-m-d H:i:s'));
    $nroOperacion   = substr($nroOperacion, -6);
    $operationNo    = $get['operationNo'] ?? NULL;
    $operationData    = $get['operationData'] ? json_encode($get['operationData']) : NULL;

    $data           = [
      'api_key'       => API_KEY,
      'company_id'    => enc(COMPANY_ID),
      'outlet'        => enc(OUTLET_ID),
      'user'          => enc(USER_ID),
      'UID'           => $UID,
      'status'        => 'REVIEW',
      'amount'        => $total,
      'saleAmount'    => $saleAmount,
      'comission'     => $comission,
      'tax'           => $tax,
      'source'        => 'bancardPOS',
      'data'          => $operationData,
      'order'         => $nroOperacion,
      'authCode'      => $authCode,
      'operationNo'   => $operationNo
    ];



    $result         = json_decode(curlContents(API_ENCOM_URL . '/add_vpayment', 'POST', $data));

    jsonDieResult($result, 200);
  }

  if ($action == 'PixAddTransaction') {

    $authCode       = $get['authCode'];
    $total          = $get['total'];
    $saleAmount     = $get['sale'];
    $UID            = $get['UID'];
    $nroOperacion   = strtotime(date('Y-m-d H:i:s'));
    $nroOperacion   = substr($nroOperacion, -6);
    $operationNo    = $get['operationNo'] ?? NULL;
    $operationData    = $get['operationData'] ? json_encode($get['operationData']) : NULL;

    $data           = [
      'api_key'       => API_KEY,
      'company_id'    => enc(COMPANY_ID),
      'outlet'        => enc(OUTLET_ID),
      'user'          => enc(USER_ID),
      'UID'           => $UID,
      'status'        => 'REVIEW',
      'amount'        => $total,
      'saleAmount'    => $saleAmount,
      'comission'     => 0,
      'source'        => 'PIX',
      'data'          => $operationData,
      'order'         => $nroOperacion,
      'authCode'      => $authCode,
      'operationNo'   => $operationNo
    ];
    $result         = json_decode(curlContents(API_ENCOM_URL . '/add_vpayment', 'POST', $data));

    jsonDieResult($result, 200);
  }

  if ($action == 'chkGiftCard') {
    if (!is_numeric($get['id'])) {
      jsonDieMsg('invalid', 200, 'success');
    }

    $id       = intval($get['id']);

    if ($get['id'] < 1) {
      jsonDieMsg('invalid', 200, 'success');
    }

    $record   = ncmExecute('SELECT * FROM giftCardSold WHERE (giftCardSoldCode = ? OR timestamp = ?) AND companyId = ? LIMIT 1', [$id, $id, COMPANY_ID]);
    //$record   = ncmExecute('SELECT * FROM giftCardSold WHERE (giftCardSoldCode = ? OR timestamp = ?) LIMIT 1',array($id,$id));

    if ($record) {

      if ($get['type'] == 'bool') {
        //Si está desabilitada
        if ($record['giftCardSoldStatus'] < 1) {
          jsonDieMsg('deactivated', 200, 'success');
        }
        if (strtotime($record['giftCardSoldExpires']) < strtotime('now')) {
          jsonDieMsg('expired', 200, 'success');
        }
        if ($record['giftCardSoldValue'] < 0.001) {
          jsonDieMsg('used', 200, 'success');
        }
        if ($record['giftCardSoldValue'] < $get['amount']) {
          jsonDieMsg('notenough', 200, 'success');
        }

        jsonDieMsg('true', 200, 'success');
      } else if ($get['type'] == 'data') {

        //aqui armo una visual chusca de la info de la giftcard
        $expired          = false;
        $canceled         = false;
        $customerName     = 'Sin Nombre';
        $beneficiaryName  = 'Sin Beneficiario';
        $outletName       = getCurrentOutletName($record['outletId']);

        if (strtotime($record['giftCardSoldExpires']) < strtotime('now')) {
          $expired        = true;
        }

        if ($record['giftCardSoldStatus'] < 1) {
          $canceled = true;
        }

        $trs  = ncmExecute(
          '  SELECT 
                                customerId,
                                outletId,
                                userId,
                                transactionDate
                              FROM
                                transaction 
                              WHERE transactionId = ?
                              AND companyId = ? LIMIT 1',
          [$record['transactionId'], COMPANY_ID]
        );

        if ($trs['customerId']) {
          $custData     = getCustomerData($trs['customerId'], 'uid');
          $customerName = getCustomerName($custData);
        }

        if ($record['giftCardSoldBeneficiaryId']) {
          $beneData        = getCustomerData($record['giftCardSoldBeneficiaryId'], 'uid');
          $beneficiaryName = getCustomerName($beneData);
        }

        if ($expired === true) {
          $status = 'Vencida';
        } else if ($canceled === true) {
          $status = 'Inhabilitada';
        } else {
          $status = CURRENCY . formatCurrentNumber($record['giftCardSoldValue'], $compDecimal, $compThousand);
        }

        $userName = getValue('contact', 'contactName', 'WHERE contactId = ' . $trs['userId']);
        $country  = getValue('setting', 'settingCountry', "WHERE " . $SQLcompanyId);

        if ($get['json']) {
          $jsonOut = [
            'beneficiaryName' => $beneficiaryName,
            'customerName'    => $customerName,
            'phone'           => getPhoneFormat($beneData['phone'], $country, 'phone_number'),
            'date'            => niceDate($trs['transactionDate'], true),
            'outletName'      => $outletName,
            'userName'        => $userName,
            'giftCardNote'    => isBase64Decode($record['giftCardSoldNote']),
            'amount'          => $status,
            'expires'         => niceDate($record['giftCardSoldExpires']),
            'code'            => $get['id'],
            'lastUsed'        => niceDate($record['giftCardSoldLastUsed']),
            'link'            => 'https://public.encom.app/giftCardRedeem?s=' . base64_encode($record['timestamp'] . ',' . enc(COMPANY_ID))
          ];

          header('Content-Type: application/json');
          echo json_encode($jsonOut);
          dai();
        }
?>
        <div class="gradBgOrange col-xs-12 wrapper text-left">
          <div class="col-sm-6 b-l b-3x b-white wrapper-sm m-b">
            <div class="text-sm">
              Beneficiario:
            </div>
            <div class="h3 font-bold">
              <?= $beneficiaryName ?>
            </div>
            <div class="text-sm">
              <div class="m-b-sm">De <?= $customerName ?></div>
              <div>
                El <?= niceDate($trs['transactionDate'], true) ?>
                <br>
                <strong>Sucursal:</strong> <?= $outletName ?>
                <br>
                <strong>Usuario:</strong> <?= $userName ?>
                <br>
                <strong>Nota:</strong>
                <br>
                <?= $record['giftCardSoldNote'] ?>
              </div>

            </div>
          </div>
          <div class="col-sm-6 text-right no-padder">
            <div class="hidden-xs m-t-lg"></div>
            Saldo:
            <div class="h1 font-bold">
              <?= $status; ?>
            </div>
            <span class="m-t badge bg-light">
              Vence el <?= niceDate($record['giftCardSoldExpires']); ?>
            </span>
            <div class="font-bold text-md">
              Cod. <?= $get['id'] ?>
            </div>
            <div>
              <?php
              if (validity($record['giftCardSoldLastUsed'])) {
                echo 'Usada por última vez el <br>' . niceDate($record['giftCardSoldLastUsed']);
              }
              ?>
            </div>

          </div>
        </div>

<?php

        dai();
      }
    } else {
      jsonDieMsg("Gift Card Not Found", 404);
    }
  }

  if ($action == 'customerNote' && $get['n'] && $get['i']) {
    //Elimino la mesa abierta y sus ordenes
    $record['contactNoteText']  = $get['n'];
    $record['customerId']       = dec($get['i']);
    $record['companyId']        = COMPANY_ID;
    $add = $db->AutoExecute('contactNote', $record, 'INSERT');

    if ($add === false) {
      jsonDieMsg();
    } else {
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'customerAddressAdd' && $get['i']) {
    //Elimino la mesa abierta y sus ordenes
    $lat        = explode(',', $get['latLng'])[0];
    $lng        = explode(',', $get['latLng'])[1];
    $customerId = db_prepare(dec($get['i']));

    $record = [];
    $record['customerAddressName']      = strip_tags($get['name']);
    $record['customerAddressDate']      = TODAY;
    $record['customerAddressText']      = strip_tags($get['address']);
    $record['customerAddressLocation']  = strip_tags($get['location']);
    $record['customerAddressCity']      = strip_tags($get['city']);
    $record['customerAddressDefault']   = 1;

    if ($get['latLng']) {
      $record['customerAddressLat']     = $lat;
      $record['customerAddressLng']     = $lng;
    }

    $record['customerId']       = $customerId;
    $record['companyId']        = COMPANY_ID;

    $add      = ncmInsert(['records' => $record, 'table' => 'customerAddress']);
    $addedId  = $add;

    ncmUpdate(['records' => ['updated_at' => TODAY], 'table' => 'contact', 'where' => 'contactUID = ' . $customerId . ' AND companyId = ' . COMPANY_ID]);

    if ($add === false) {
      jsonDieMsg();
    } else {
      $record   = [];
      $record['customerAddressDefault']  = NULL;
      $update   = ncmUpdate(['records' => $record, 'table' => 'customerAddress', 'where' => 'customerId = ' . $customerId . ' AND customerAddressId NOT IN(' . $addedId . ')']);

      jsonDieMsg('true', 200, 'success');
    }

    dai();
  }

  if ($action == 'customerAddressUpdate' && $get['i'] && $get['id']) {
    //Elimino la mesa abierta y sus ordenes
    $lat        = explode(',', $get['latLng'])[0];
    $lng        = explode(',', $get['latLng'])[1];
    $customerId = db_prepare(dec($get['i']));
    $addressId  = db_prepare(dec($get['id']));

    $record = [];
    $record['customerAddressName']      = strip_tags($get['name']);
    $record['customerAddressText']      = strip_tags($get['address']);
    $record['customerAddressLocation']  = strip_tags($get['location']);
    $record['customerAddressCity']      = strip_tags($get['city']);
    $record['updated_at']               = TODAY;

    if ($get['latLng']) {
      $record['customerAddressLat']     = $lat;
      $record['customerAddressLng']     = $lng;
    }

    $update  = ncmUpdate(['records' => $record, 'table' => 'customerAddress', 'where' => 'customerId = ' . $customerId . ' AND customerAddressId = ' . $addressId]);

    ncmUpdate(['records' => ['updated_at' => TODAY], 'table' => 'contact', 'where' => 'contactUID = ' . $customerId . ' AND companyId = ' . COMPANY_ID]);

    if ($update === false) {
      jsonDieMsg();
    } else {
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'customerAddressDelete' && $get['i'] && $get['id']) {
    $addId  = db_prepare(dec($get['id']));
    $id     = db_prepare(dec($get['i']));

    $delete = $db->Execute('DELETE FROM customerAddress WHERE customerAddressId = ? AND customerId = ? AND companyId = ? LIMIT 1', [$addId, $id, COMPANY_ID]);

    $db->Execute('DELETE FROM toAddress WHERE customerAddressId = ?', [$addId]);

    ncmUpdate(['records' => ['updated_at' => TODAY], 'table' => 'contact', 'where' => 'contactUID = ' . $id . ' AND companyId = ' . COMPANY_ID]);

    if ($delete !== false) {
      updateLastTimeEdit(COMPANY_ID, 'customer');
      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'customerAddressSetDefault' && $get['i'] && $get['id']) {
    $customerId = dec($get['i']);
    $addId      = dec($get['id']);
    $record     = [];
    $record['customerAddressDefault']  = NULL;

    $update     = ncmUpdate(['records' => $record, 'table' => 'customerAddress', 'where' => 'customerId = ' . $customerId . ' AND companyId = ' . COMPANY_ID]);

    $record     = [];
    $record['customerAddressDefault']  = 1;

    $update   = ncmUpdate(['records' => $record, 'table' => 'customerAddress', 'where' => 'customerId = ' . $customerId . ' AND customerAddressId = ' . $addId . ' AND companyId = ' . COMPANY_ID]);

    ncmUpdate(['records' => ['updated_at' => TODAY], 'table' => 'contact', 'where' => 'contactUID = ' . $customerId . ' AND companyId = ' . COMPANY_ID]);

    if ($update['error'] == false) {
      updateLastTimeEdit(COMPANY_ID, 'customer');
      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'scheduleSession' && $get['id'] && $get['f'] && $get['t'] && $get['u']) {
    $transID  = dec($get['id']);
    $from     = $get['f'];
    $to       = $get['t'];
    $client   = dec($get['c']);
    $user     = dec($get['u']);

    //selecciono data de la trans
    //modifico el user ID y pongo el nuevo agendado
    //guardo con un update

    $getTrans = ncmExecute('SELECT transactionDetails FROM transaction WHERE transactionId = ? LIMIT 1', [$transID]);

    if ($getTrans) {
      $transData = json_decode($getTrans['transactionDetails'], true);
      foreach ($transData as $key => $value) {
        $transData[$key]['userId'] = $get['u'];
      }
    }

    $record['userId']             = $user;
    $record['fromDate']           = $from;
    $record['toDate']             = $to;
    $record['transactionDetails'] = json_encode($transData);
    $record['transactionStatus']  = 0;
    $record['outletId']           = OUTLET_ID;
    $record['registerId']         = REGISTER_ID;
    $record['responsibleId']      = USER_ID;

    $update             = ncmUpdate(['records' => $record, 'table' => 'transaction', 'where' => 'transactionId = ' . $transID]);

    //$update             = $db->AutoExecute('transaction', $record, 'UPDATE','transactionId = ' . $transID);

    if ($update['error']) {
      jsonDieMsg();
    } else {
      $contact      = getCustomerData($client, 'uid');
      $contactName  = getCustomerName($contact, 'first');
      $date         = niceDate($from, true, false, false, true);

      if ($contact && validity($contact['email']) && ($from > TODAY_START && $to < TODAY_END)) {
        $contactEmail     = $contact['email'];

        $companyContacts  = [iftn($compEmail), iftn($compPhone)];

        $url              = getShortURL('https://public.encom.app/scheduleConfirm?s=' . base64_encode(enc($transID) . ',' . enc(COMPANY_ID)));

        $subject  = '[' . $compName . '] Confirmación';
        $body     = 'Hola ' . $contactName . ',' .
          '<p>Hemos marcado su asistencia el ' . $date . '. Puede confirmar o cancelar en el siguiente enlace.</p>' .
          makeEmailActionBtn($url, 'Confirmar o Cancelar');

        $meta['subject'] = $subject;
        $meta['to']      = $contactEmail;
        $meta['fromName'] = $compName;
        $meta['data']    = [
          "message"     => $body,
          "companyname" => $compName,
          "companylogo" => $compLogo
        ];

        $sent             = sendEmails($meta);

        //sms
        $msg     = '[' . $compName . '] ' . 'Hola ' . $contactName . ', hemos marcado su asistencia. Puede confirmar o cancelar en: \n' . $url;
        $number  = iftn($contact['phone'], $contact['phone2']);
        sendSMS($number, $msg);
      }

      sendPush([
        "ids"       => enc(COMPANY_ID),
        "message"   => 'Tiene cita con ' . $contactName . ' el ' . $date,
        "title"     => COMPANY_NAME,
        "where"     => 'caja',
        "filters"   =>  [
          [
            "key"   => "userId",
            "value" => enc($user)
          ],
          [
            "key"   => "companyId",
            "value" => enc(COMPANY_ID)
          ]
        ]
      ]);

      jsonDieMsg('true', 200, 'success');
    }

    dai();
  }

  if ($action == 'updateSchedule' && $get['id']) {
    if (!$get['f'] || !$get['ui']) { //hora y usuario son obligatorios
      jsonDieMsg();
    }

    $transId            = db_prepare(dec($get['id']));

    //obtengo duracion de cita
    $result   = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$transId, COMPANY_ID]);
    $diff     = strtotime($result['toDate']) - strtotime($result['fromDate']); //calculo cantidad de horas entre ambas citas

    $oldUser  = enc($result['userId']);
    $newUser  = $get['ui'];

    //le añado nuevo from a la fecha y genero el to sumando las horas de diferencia
    $fromSt   = strtotime(date('Y-m-d ' . $get['f'] . ':i:s', strtotime($result['fromDate'])));
    $toSt     = $fromSt + $diff;

    $from     = date('Y-m-d H:i:s', $fromSt);
    $to       = date('Y-m-d H:i:s', $toSt);

    //listo todos los servicios y cambio los del viejo usuario al nuevo
    $detail   = json_decode($result['transactionDetails'], true);

    foreach ($detail as $key => $value) {
      $detail[$key]['user'] = $newUser;
      /*if($value['user'] == $oldUser){
        $detail[$key]['user'] = $newUser;
      }*/
    }

    /*if(COMPANY_ID == 10){
      echo '-----OLD-----';
      echo '<br>';
      echo $result['transactionDetails'];
      echo '<br>';
      echo json_encode($detail);
    }*/

    $record['userId']               = dec($newUser);
    $record['fromDate']             = $from;
    $record['toDate']               = $to;
    $record['transactionDetails']   = json_encode($detail);

    $update             = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . $transId . ' AND companyId = ' . COMPANY_ID);

    if ($update !== false) {
      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'updateScheduleTo' && $get['id']) {
    if (!$get['t']) { //hora obligatorios
      jsonDieMsg();
    }

    $transId            = db_prepare(dec($get['id']));

    //obtengo duracion de cita
    $result   = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$transId, COMPANY_ID]);
    $toDate   = date('Y-m-d', strtotime($result['fromDate'])); //obtengo la fecha
    $toDate   = $toDate . ' ' . $get['t'];

    $record['toDate']   = $toDate;
    $update             = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . $transId . ' AND companyId = ' . COMPANY_ID);

    if ($update !== false) {
      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'deleteTransaction' && $get['id']) {
    $delete = $db->Execute('DELETE FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [dec($get['id']), COMPANY_ID]);
    if ($delete !== false) {
      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'checkIfUserOccupied' && $get['users']) {
    //obtengo userid from y to
    //consulto solo fechas mayores a hoy
    $users    = $get['users'];
    $from     = $get['from'];
    $to       = $get['to'];
    $dUsers   = [];

    if (!validity($users)) {
      jsonDieMsg('no_users', 404, 'fail');
    }

    $trsIDs = [];
    $result = ncmExecute(
      'SELECT * 
                          FROM transaction 
                          WHERE companyId     = ?
                          AND outletId        = ?
                          AND transactionType = 13 
                          AND transactionStatus NOT IN(6,4) 
                          AND (? <= toDate AND ? >= fromDate) 
                          LIMIT 50',
      [COMPANY_ID, OUTLET_ID, $from, $to]
    );

    if ($result) {

      while (!$result->EOF) {
        $field  = $result->fields;
        $itms   = json_decode($field['transactionDetails'], true);

        if ($field['transactionStatus'] == 7) {
          $itms     = [];
          if (in_array(enc($field['userId']), $users)) {
            $dUsers[] = enc($field['userId']);
          }
        }

        if (validity($itms)) {

          foreach ($itms as $key => $value) {
            $eUser = arrKey($value, 'user', false);

            if (in_array($eUser, $users)) {
              $dUsers[] = $eUser;
            }
          }
        } else {
          $dUsers[] = enc($field['userId']);
        }

        $result->MoveNext();
      }

      if (validity($dUsers)) {
        $dUsers = array_unique($dUsers);
        jsonDieResult($dUsers);
      }
    }

    jsonDieMsg();
  }

  if ($action == 'rejectOrder' && $get['id']) {
    //$delete = $db->Execute('DELETE FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1',[dec($get['id']),COMPANY_ID]);
    $record['transactionStatus']  = 6;
    $record['updated_at']         = TODAY;

    if ($get['motive']) {
      $record['transactionNote']    = strip_tags($get['motive']);
    }

    $delete = $db->AutoExecute('transaction', $record, 'UPDATE', 'transactionId = ' . db_prepare(dec($get['id'])) . ' AND companyId = ' . COMPANY_ID);

    if ($delete !== false) {
      updateLastTimeEdit(COMPANY_ID, 'order');

      sendWS([
        'channel'       => enc(OUTLET_ID) . '-register',
        'event'         => 'order',
        'message'       => json_encode(['ID' => $get['id'], 'registerID' => enc(REGISTER_ID)])
      ]);

      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'acceptOrder' && $get['id']) {

    $orderId                      = db_prepare(dec($get['id']));
    $record['transactionStatus']  = 2;
    $record['updated_at']         = TODAY;

    //$update = $db->AutoExecute('transaction', $record, 'UPDATE','transactionId = ' . $orderId . ' AND companyId = ' . COMPANY_ID );
    $update = ncmUpdate(['records' => $record, 'table' => 'transaction', 'where' => 'transactionId = ' . $orderId . ' AND companyId = ' . COMPANY_ID]);

    if (!$update['error']) {
      updateLastTimeEdit(COMPANY_ID, 'order');

      $result = ncmExecute('SELECT customerId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$orderId, COMPANY_ID]);

      if ($result) {
        //obtengo celular del cliente, si tiene cel le envio un SMS con la confirmacion
        $customer     = getCustomerData($result['customerId'], 'uid');
        $customerId   = enc($result['customerId']);

        sendPush([
          "ids"       => enc(COMPANY_ID),
          "message"   => 'Su orden fue aceptada',
          "title"     => COMPANY_NAME,
          "where"     => 'ecom',
          "filters"   => [
            [
              "key"   => "customerId",
              "value" => $customerId
            ]
          ]
        ]);

        sendWS([
          'channel'       => enc(OUTLET_ID) . '-register',
          'event'         => 'order',
          'message'       => json_encode(['ID' => enc($id), 'registerID' => enc(REGISTER_ID)])
        ]);

        if ($customer) {
          //obtengo order # de la transaccion
          $orderNo    = ncmExecute('SELECT invoiceNo, transactionId FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$orderId, COMPANY_ID]);
          $senderName = getCustomerName($customer, 'first');
          $url        = 'https://public.encom.app/orderView?s=' . base64_encode(enc($orderNo['transactionId']) . ',' . enc(COMPANY_ID));

          $meta['subject'] = '[' . $compName . '] Confirmación de pedido';
          $meta['to']      = $customer['email'];
          $meta['fromName'] = $compName;
          $meta['data']    = [
            "message"     => 'Hola ' . $senderName . ', <p>Su pedido <b>#' . $orderNo['invoiceNo'] . '</b> fue confirmado! <br> Puede ver el estado de su pedido en ' . makeEmailActionBtn($url, 'Ver pedido') . '</p>',
            "companyname" => $compName,
            "companylogo" => $compLogo
          ];

          $sent             = sendEmails($meta);

          //sms
          $msg     = '[' . $compName . '] ' . 'Hola ' . $senderName . ', su pedido #' . $orderNo['invoiceNo'] . ' fue confirmado!: \n' . $url;
          $number  = iftn($customer['phone'], $customer['phone2']);
          sendSMS($number, $msg);
        }
      }

      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'setUserToOrder' && $get['id'] && $get['uid']) {
    $orderId          = db_prepare(dec($get['id']));
    $record['userId'] = dec($get['uid']);

    //$update = $db->AutoExecute('transaction', $record, 'UPDATE','transactionId = ' . $orderId . ' AND transactionType = 12 AND companyId = ' . COMPANY_ID );
    $update = ncmUpdate(['records' => $record, 'table' => 'transaction', 'where' => 'transactionId = ' . $orderId . ' AND transactionType = 12 AND companyId = ' . COMPANY_ID]);

    if ($update['error'] == false) {
      updateLastTimeEdit(COMPANY_ID, 'order');

      $result = ncmExecute('SELECT outletId, registerId, invoiceNo, companyId, transactionDetails FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$orderId, COMPANY_ID]);

      if ($result) {

        $jsonDetails = json_decode($result['transactionDetails'], true);
        foreach ($jsonDetails as $key => $value) {
          $jsonDetails[$key]['user'] = enc($record['userId']);
        }
        $recordTr['transactionDetails'] = json_encode($jsonDetails);

        $update = ncmUpdate(['records' => $recordTr, 'table' => 'transaction', 'where' => 'transactionId = ' . $orderId . ' AND transactionType = 12 AND companyId = ' . COMPANY_ID]);

        $outletId   = enc($result['outletId']);
        $registerId = enc($result['registerId']);
        $orderNo    = $result['invoiceNo'];

        $pushed = sendPush([
          "ids"       => enc(COMPANY_ID),
          "message"   => 'La orden # ' . $orderNo . ' le fue asignada',
          "title"     => COMPANY_NAME,
          "where"     => 'caja',
          "filters"   => [
            [
              "key"   => "userId",
              "value" => $get['uid']
            ],
            [
              "key"   => "isResource",
              "value" => "true"
            ]
          ]
        ]);
      }

      //jsonDieResult(['true' => 'success', 'push' => $pushed], 200);

      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'setUserToSpace' && $get['id']) {
    $spaceNo          = $get['id'];

    $update = ncmUpdate([
      'records' => ['userId' => dec($get['uid'])],
      'table'   => 'transaction',
      'where'   => 'transactionType = 11 AND outletId = ' . OUTLET_ID . ' AND transactionName = ' . $spaceNo
    ]);

    if ($update['error'] == false) {

      $pushed = sendPush([
        "ids"       => enc(COMPANY_ID),
        "message"   => 'El espacio ' . $spaceNo . ' le fue asignado',
        "title"     => COMPANY_NAME,
        "where"     => 'caja',
        "filters"   => [
          [
            "key"   => "userId",
            "value" => $get['uid']
          ],
          [
            "key"   => "isResource",
            "value" => "true"
          ]
        ]
      ]);

      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'unlockCalendar' && $get['lock']) {
    $delete = $db->Execute('DELETE FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [dec($get['lock']), COMPANY_ID]);
    if ($delete !== false) {
      jsonDieMsg('true', 200, 'success');
    } else {
      jsonDieMsg();
    }
  }

  if ($action == 'sale') {
    if (!empty($get['del'])) {
      //$record['transactionType'] = 15;
      //$delete = $db->AutoExecute('transaction', $record, 'UPDATE','transactionId = '.db_prepare(dec($get['del'])));
      $delete = $db->Execute('DELETE FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [dec($get['del']), COMPANY_ID]);
      if ($delete !== false) {
        jsonDieMsg('true', 200, 'success');
      } else {
        jsonDieMsg($db->ErrorMsg());
      }
    } else if (!empty($get['note'])) {

      if (!$get['id'] || !$get['note']) {
        jsonDieMsg();
      }

      $note   = markupt2HTML(['text' => $get['note'], 'type' => 'HtM']);
      $id     = db_prepare(dec($get['id']));
      $update = ncmUpdate(['records' => ['transactionNote' => $note], 'table' => 'transaction', 'where' => 'transactionId = ' . $id . ' AND companyId = ' . COMPANY_ID]);

      if ($update !== false) {
        jsonDieMsg('true', 200, 'success');
      } else {
        jsonDieMsg();
      }
    } else if (!empty($get['status'])) {

      $record = [];
      $status = db_prepare($get['s']);
      $motive = $get['m'] ?? '';
      $id     = db_prepare(dec($get['status']));

      if (isset($get['schedule']) && $get['schedule']) { //si proceso la cita AKA ya se hizo el servicio, flagueo como completado
        if ($status == 6) {
          $record['transactionComplete'] = 1;
          //verifico si existe este agendamiento de una sesion
          $session = ncmExecute('SELECT * FROM transaction WHERE transactionType = 13 AND transactionParentId > 0 AND invoicePrefix IS NOT NULL AND transactionId = ? AND companyId = ?', [$id, COMPANY_ID]);

          if ($session) {
            //si existe obtengo su factura con sus articulos vendidos
            //$soldInSession  = ncmExecute('SELECT a.*, b.* FROM transaction a, itemSold b WHERE a.transactionId = ? AND a.companyId = ? AND a.transactionId = b.transactionId',[$session['transactionParentId'],COMPANY_ID],false,true);
            $soldInSession = json_decode($session['transactionDetails'], true);

            if ($soldInSession) {
              //while (!$soldInSession->EOF) {
              $db->Execute('DELETE FROM comission WHERE transactionId = ?', [$session['transactionId']]);
              foreach ($soldInSession as $key => $fields) {
                $comissionGot   = getItemComsissionTotal(dec($fields['itemId']), $fields['count'], $fields['uniPrice'] ?? 0, true);

                if ($comissionGot) {
                  $recordC                           = [];
                  $recordC['comissionTotal']         = $comissionGot;
                  $recordC['comissionSource']        = 'session';
                  $recordC['userId']                 = $session['userId']; //usuario de la sesion no de la venta
                  $recordC['transactionId']          = $session['transactionId'];
                  $recordC['outletId']               = OUTLET_ID;
                  $recordC['companyId']              = COMPANY_ID;
                  $insertTransaction                 = $db->AutoExecute('comission', $recordC, 'INSERT');
                }
              }
            }
          } else {
            if ($get['uid'] && $id) {
              $db->AutoExecute('toScheduleUID', ['scheduleId' => $id, 'transactionUID' => $get['uid']], 'INSERT');
            }
          }

          //
        } else if ($status == 4 || $status == 5) {

          $hasParent = getValue('transaction', 'transactionParentId', 'WHERE transactionId = ' . $id . ' LIMIT 1');

          if (validity($hasParent)) {
            $status = '0';
            $record['fromDate'] = NULL;
            $record['toDate']   = NULL;
            //elimino la comision asociada a esta sesion
            $db->Execute('DELETE FROM comission WHERE transactionId = ?', [$id]);
          }
        }
      }

      if ($status !== 'reminder') { //si es recordatorio no cambio el estado
        $record['transactionStatus']  = $status;
        $record['updated_at']         = TODAY;

        if ($motive) {
          $record['transactionNote'] = strip_tags($motive);
        }

        $update = ncmUpdate(['records' => $record, 'table' => 'transaction', 'where' => 'transactionId = ' . $id]);

        updateLastTimeEdit(COMPANY_ID, 'calendar');
        updateLastTimeEdit(COMPANY_ID, 'order');

        sendWS([
          'channel'       => enc(OUTLET_ID) . '-KDS',
          'event'         => 'order',
          'message'       => enc($id)
        ]);

        sendWS([
          'channel'       => enc(OUTLET_ID) . '-register',
          'event'         => 'order',
          'message'       => json_encode(['ID' => enc($id), 'registerID' => enc(REGISTER_ID)])
        ]);
      }

      if (isset($get['schedule']) && $get['schedule'] && (in_array($status, [1, 4, 5]) || $status == 'reminder')) {
        $contactId    = getValue('transaction', 'customerId', 'WHERE transactionId = ' . $id . ' LIMIT 1');
        $transData    = ncmExecute('SELECT customerId, userId, transactionDate FROM transaction WHERE transactionId = ? AND companyId = ? LIMIT 1', [$id, COMPANY_ID]);
        $date         = niceDate($transData['transactionDate'], true, false, false, true);
        $contactData  = getCustomerData($transData['customerId'], 'uid');
        $contactEmail = $contactData['email'];
        $contactName  = getCustomerName($contactData, 'first');
        $contactPhone = iftn($contactData['phone'], $contactData['phone2']);
        $smsBody      = '';
        $notifyCustomer = false;

        if ($status == 5) {

          $subject    = '[' . $compName . '] Agendamiento';
          $body       = 'Hola ' . iftn($contactName, '!') . ',' .
            '<p>Lamentamos que no haya podido asistir.</p>' .
            '<p>Si desea volver a re agendar, por favor no dude en contactarnos ' . addWhatsAppLink() . '</p>';

          $smsBody = '[' . $compName . '] ' . $contactName . ', lamentamos que no haya podido asistir, ante cualquier duda por favor pongase en contacto.' . addWhatsAppLink(true);

          $notifyCustomer = true;
        } else if ($status == 4) {
          sendPush([
            "ids"       => enc(COMPANY_ID),
            "message"   => 'Se canceló su cita con ' . $contactName . ' el ' . $date,
            "title"     => COMPANY_NAME,
            "where"     => 'caja',
            "filters"   => [
              [
                "key"   => "userId",
                "value" => enc($transData['userId'])
              ],
              [
                "key"   => "companyId",
                "value" => enc(COMPANY_ID)
              ]
            ]
          ]);

          $notifyCustomer = false;
        } else if ($status == 1) {
          sendPush([
            "ids"       => enc(COMPANY_ID),
            "message"   => 'Se confirmó su cita con ' . $contactName . ' el ' . $date,
            "title"     => COMPANY_NAME,
            "where"     => 'caja',
            "filters"   => [
              [
                "key"   => "userId",
                "value" => enc($transData['userId'])
              ],
              [
                "key"   => "companyId",
                "value" => enc(COMPANY_ID)
              ]
            ]
          ]);

          $notifyCustomer = false;
        } else if ($status === 'reminder') {
          $url              = getShortURL('https://public.encom.app/scheduleConfirm?s=' . base64_encode(enc($id) . ',' . enc(COMPANY_ID)));
          $subject  = '[' . $compName . '] Recordatorio';
          $body     = 'Hola ' . $contactName . ',' .
            '<p>Le contactamos para recordarle su cita, acceda a más detalles en</p>' .
            makeEmailActionBtn($url, 'Detalles');


          //sms
          $smsBody     = '[' . $compName . '] ' . 'Hola ' . $contactName . ', le recordamos su agendamiento. Detalles en: \n' . $url;
          $notifyCustomer = true;
        }

        if ($notifyCustomer) {
          $meta['subject'] = $subject;
          $meta['to']      = $contactEmail;
          $meta['fromName'] = $compName;
          $meta['data']    = [
            "message"     => $body,
            "companyname" => $compName,
            "companylogo" => $compLogo
          ];

          $sent = sendEmails($meta);

          sendSMS($contactPhone, $smsBody, true, true);
        }
      }

      jsonDieMsg('true', 200, 'success');
    } else if (!empty($get['void'])) {
      voidSale($get['void'], urldecode($get['motive']));
    } else {

      /*
        Tipos de Transacciones 
        0 = Venta al contado 
        1 = Compra al contado
        2 = Guardada 
        3 = Venta a crédito
        4 = Compra a crédito
        5 = Pago de ventas a crédito
        6 = Devolución
        7 = Venta anulada
        8 = Venta recursiva
        9 = cotizacion
        10 = Delivery
        11 = open table
        12 = order
        13 = schedule
        */

      $isSchedule     = false;
      $cuid           = isset($get['customerId']) ? $get['customerId'] : false;
      $limit          = iftn($get['limit'] ?? false, 30);
      $datePicked     = $get['date'] ?? false;
      $userLookUp     = '';
      if (validity(isset($get['date']))) {
        $dtPckd       = db_prepare($get['date']);
        $datePicked   = " AND transactionDate BETWEEN '" . $dtPckd . " 00:00:00' AND '" . $dtPckd . " 23:59:59' ";
        $limit        = 1000;
      } else {
        $datePicked   = '';
      }

      $listTitle      = 'Transacciones';

      //no uso IN() porque OR es más rápido segun stackoverflow
      $inT    = ' AND (transactionType = 0 OR transactionType = 2 OR transactionType = 3 OR transactionType = 6 OR transactionType = 7 OR transactionType = 9 OR transactionType = 10) ';

      if (in_array($roleId, ['5', '4'])) {
        $inT            = '2,10';
        $inT            = ' AND (transactionType = 2 OR transactionType = 10) ';
        $userLookUp     = ' AND userId = ' . $userId;
      }

      $tType = $get['f'] ?? '';
      if ($tType) {
        $inT = ' AND transactionType = ' . db_prepare($tType) . ' ';
      }

      if ($tType == '13') {
        $listTitle      = 'Agenda';
        $isSchedule     = true;
        //$inT          = 'transactionType = ' . $tType . ' AND fromDate IS NOT NULL AND toDate IS NOT NULL';
        $inT            = ' AND transactionType = ' . $tType . ' ';
      }

      if ($cuid) {
        $customerLookUp = ' customerId IN (' . dec($cuid) . ')';
      } else {
        $customerLookUp = ' outletId = ' . OUTLET_ID;
      }

      $table =  ' <div class="col-sm-8 wrapper">' .
        '   <div class="h1 font-bold text-left text-dark">' . $listTitle . '</div>' .
        ' </div>' .
        ' <div class="col-sm-4 wrapper" id="saleDatePickerParent">' .
        '   <input type="form" value="' . (isset($get['date']) ? $get['date'] : '') . '" class="form-control datePicker pointer no-border no-bg b-b text-center" readonly>' .
        ' </div>' .
        ' <div class="col-xs-12 m-b-n-lg hidden" id="transactionListInput">' .
        '   <input type="search" class="form-control bg-light lter no-border rounded input-lg datatableFilter" placeholder="Buscar..." value="" autocomplete="off">' .
        ' </div>' .
        ' <table class="table table-hover" id="salesPrimaryTable"> ' .
        '   <thead><tr><td>&nbsp;</td><td>&nbsp;</td></tr></thead>' .
        '   <tbody>';

      $sql = 'SELECT 
                  *
              FROM transaction 
              WHERE
              ' .
        $customerLookUp .
        $userLookUp .
        $inT .
        $datePicked .
        'AND companyId = ' . COMPANY_ID . '
              ORDER BY transactionDate 
              DESC LIMIT ' . $limit;

      $result = ncmExecute($sql, [], false, true);

      if ($result) {

        $whereCustomer  = [];
        $whereTrsId     = [];

        while (!$result->EOF) { //primer loop para generar arrasy para consultas extras
          if ($result->fields['customerId']) {
            $whereCustomer[]  = $result->fields['customerId'];
          }
          $whereTrsId[]     = $result->fields['transactionId'];

          $result->MoveNext();
        }
        $result->MoveFirst();

        //$allCustomers         = validity($whereCustomer,'array') ? getAllContacts(1,' AND contactUID IN ('.implodes(',',$whereCustomer).')') : [];
        $allToPayTransactions = getAllToPayTransactions(' AND transactionParentId IN (' . implodes(',', $whereTrsId) . ')');
        $allPayedTransactions = getAllTransactionPayments(implodes(',', $whereTrsId));

        while (!$result->EOF) {
          $fields   = $result->fields;
          $tTotal   = $fields['transactionTotal'] - $fields['transactionDiscount'];
          $topay    = 0;
          $type     = $fields['transactionType'];
          $status   = $fields['transactionStatus'];
          $isPackage = false;

          //1. Nada (Gris) 2. Orden en espera (Amarillo) 3. Orden en Proceso (Azul) 4. Orden finalizada (Verde) 5. Orden Anulada (Rojo) 6. Otro (Negro)
          $stat = 'b-5x b-l ';
          switch ($status) {
            case '0':
              $caseOColor = 'b-light';
              $stat .= $caseOColor;
              break;
            case '1':
              $stat .= 'b-light';
              break;
            case '2':
              $stat .= 'b-info';
              break;
            case '3':
              $stat .= 'b-primary';
              break;
            case '4':
              $stat .= 'b-success';
              break;
            case '5':
              $stat .= 'b-danger';
              break;
            case '6':
              $stat = 'b-3x b-l b-dark';
              break;
          }

          $typeAttr   = 'reprintSale';
          if ($type == '2' || $type == '9') {
            $typeAttr = 'unsaveSale';
            $topay    = 0;
          } else if ($type == '3') {
            $typeAttr   = 'payCredit';
            $topay      = $tTotal - ($allToPayTransactions[$fields['transactionId']] ?? 0);
          }

          if ($type == '0' || $type == '3') {
            $typeText   = 'text-muted';
          } else if ($type == '2') {
            $typeText   = 'text-muted';
          }

          if ($type == '3') {
            if ($topay > ($tTotal / 2)) {
              $typeText   = 'bg-light text-danger ';
            }
            if ($topay < ($tTotal / 2)) {
              $typeText   = 'bg-light text-warning-dker';
            }
            if ($topay < 1) {
              $typeText   = 'bg-light text-success';
            }

            $typeOfSale = '<span class="label ' . $typeText . ' text-xs">Crédito</span>';
          } else if ($type == '2') {
            $typeOfSale = '<span class="label text-dark b b-light text-xs">Guardado</span>';
          } else if ($type == '6') {
            $typeOfSale = '<span class="label bg-danger lter text-xs">Devolución</span>';
          } else if ($type == '7') {
            $typeOfSale = '<span class="label bg-dark text-xs">Anulado</span>';
          } else if ($type == '9') {
            $typeOfSale = '<span class="label bg-warning dk text-xs">Cotización</span>';
          } else if ($type == '10') {
            $typeOfSale = '<span class="label text-info b b-light text-xs">Envío</span>';
          } else if ($type == '11') {
            $typeOfSale = '<span class="label bg-success text-xs">Orden</span>';
          } else if ($type == '13') {

            $typeOfSale = '<span class="label bg-primary text-xs">Agenda</span>';

            if ($fields['transactionParentId'] > 0 && $fields['invoicePrefix']) {
              $isPackage  = true;
            }

            switch ($status) {
              case '0':
                $caseOColor = 'b-light';

                if ($fields['transactionComplete'] == 1) {
                  $caseOColor = 'b-primary';
                }

                $stat .= $caseOColor;
                break;
              case '1':
                $stat .= 'b-info';
                break;
              case '2':
                $stat .= 'b-warning';
                break;
              case '3':
                $stat .= 'b-success';
                break;
              case '4':
                $stat .= 'b-danger';
                break;
              case '5':
                $stat .= 'b-dark';
                break;
              case '6':
                $stat = ''; //realizado, ya sea session o no
                break;
            }
          } else {
            $typeOfSale = '<span class="label bg-light text-xs">Contado</span>';
          }

          $name     = '';
          if ($fields['transactionName'] != 'Sale' && $fields['transactionName'] != 'Quote' && $fields['transactionName']) {
            $name   = '<span class="text-info">' . $fields['transactionName'] . '</span>';
          }

          $discount   = $fields['transactionDiscount'];
          $total      = $fields['transactionTotal'];

          $cusData = ncmExecute('SELECT contactName,contactUID FROM contact WHERE contactUID = ? AND companyId = ? LIMIT 1', [$fields['customerId'], COMPANY_ID]);
          if (!$cusData) {
            $customerD = 'Sin Nombre';
          } else {
            $customerD = $cusData['contactName'];
          }

          $jsonDetails = $fields['transactionDetails'];
          $jsonPayed   = iftn($allPayedTransactions[$fields['transactionId']] ?? false, '', json_encode($allPayedTransactions[$fields['transactionId']] ?? false));

          if ($jsonPayed) {
            $jsonPayed =  ' <span class="hidden payed' . enc($fields['transactionId']) . '"> ' .
              $jsonPayed .
              '  </span> ';
          }

          $rawDate = $fields['transactionDate'];
          $inTotal = formatCurrentNumber($total - $discount, $dec, $ts);
          $date    = niceDate($rawDate, true);

          if ($isSchedule) {
            list($scDate, $scStart, $scEnd) = dateStartEndTime($fields['fromDate'], $fields['toDate']);
            $timeFrame = $scStart . ' - ' . $scEnd;
            if (!$scStart || !$scEnd) {
              $timeFrame = 'Sin horarios';
              $typeOfSale = '<span class="label bg-primary text-xs">Sin agendar</span>';
            }

            $icon = '&#xe192;';

            if ($status == '6') { //si es una sesion
              $icon = 'done';
            }

            $rawDate = $fields['fromDate'];

            $inTotal = '<i class="material-icons text-primary md-18 m-r-xs">' . $icon . '</i>' . $timeFrame;
            $date    = niceDate($rawDate);
          }

          if ($isPackage) {
            $name           = '<i class="material-icons text-primary">add</i>' . $name;
            $parentPackage  = ncmExecute('SELECT * FROM transaction WHERE transactionId = ? AND companyId = ?', [$fields['transactionParentId'], COMPANY_ID]);
          }

          $table .=   '<tr ' .
            'class="clickeable text-left" ' .
            'data-sort="' . $rawDate . '"' .
            getSalesDataList($fields, $type, $typeAttr, $cusData['contactUID'], $topay) .
            '> ' .
            '<td class="' . $stat . '"> ' .
            '  <span class="block text-ellipsis font-bold text-md">' . $name . ' ' . $customerD . '</span> ' .
            '  <small class="text-muted text-xs">' . $date .
            '   #' . $fields['invoicePrefix'] . $fields['invoiceNo'] . '<span class="font-thin text-success count"></span>' .
            '  </small> ' .

            '  <span class="hidden ' . enc($fields['transactionId']) . '">' .
            $jsonDetails .
            '  </span> ' .
            $jsonPayed .
            '</td> ' .
            '<td class="text-right text-dark text-md">' . $inTotal . '<br>' . $typeOfSale . '</td> ' .
            '</tr>';

          $result->MoveNext();
        }

        $result->Close();

        if (!isset($get['date'])) {
          $foot =   '<tfoot> <tr>' .
            ' <td colspan="2" class="text-center wrapper">' .
            '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-more="' . ($limit + 50) . '" data-type="openTransactions" data-customer-id="' . $cuid . '">Cargar más</span>' .
            ' </td>' .
            '</tr> </tfoot>';
        } else {
          $foot =   '<tfoot> <tr>' .
            ' <td colspan="2" class="text-center wrapper">' .
            '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-type="openTransactions" data-customer-id="' . $cuid . '">Atrás</span>' .
            ' </td>' .
            '</tr> </tfoot>';
        }
      } else {
        $foot =   '<tfoot> <tr>' .
          ' <td colspan="2" class="text-center wrapper">' .
          '   <span class="btn btn-info btn-lg btn-rounded all-shadows clickeable hide-basic font-bold text-u-c" data-type="openTransactions" data-customer-id="' . $cuid . '">Atrás</span>' .
          ' </td>' .
          '</tr> </tfoot>';
      }

      $table .= '</tbody> ' . $foot . ' </table>';

      dai($table);
    }
  }

  if ($action == 'setSession' && $get['id']) {

    $data = [
      'api_key'       => API_KEY,
      'company_id'    => enc(COMPANY_ID),
      'channel'       => enc(COMPANY_ID) . '-' . enc(REGISTER_ID) . '-registerSession',
      'event'         => 'checkSession',
      'message'       => (int) $get['id']
    ];

    curlContents(API_ENCOM_URL . '/send_webSocket.php', 'POST', $data);

    $record['sessionId'] = (int) $get['id'];
    $set = ncmUpdate(['records' => $record, 'table' => 'register', 'where' => "registerId = " . REGISTER_ID . " AND companyId = " . COMPANY_ID]);

    if (!$set['error']) {
      echo $get['id'];
    } else {
      echo false;
    }

    dai();
  }

  if ($action == 'checkSession' && $get['id']) {

    $result = ncmExecute('SELECT sessionId FROM register WHERE registerId = ? AND companyId = ?', [REGISTER_ID, COMPANY_ID]);
    if ($result) {
      if ($result['sessionId'] == $get['id']) {
        echo '1';
      } else {
        echo '0';
      }
    } else {
      echo '1';
    }

    dai();
  }

  if ($action == 'processData') {

    $dataArray      = validateHttp('data', 'post');
    $data           = json_decode($dataArray[0], true);
    $totalAmount    = 0;
    $totalTax       = 0;
    $totalDiscount  = 0;

    if (array_key_exists('transaction', $data)) {
      $data = $data['transaction'];

      if (COMPANY_ID == 10) {
        //jsonDieMsg();
      }

      if ($data['sale'] || $data['type'] == '11' || $data['type'] == '12' || $data['type'] == '13') { //si hay items o si laventa es una mesa abierta

        //Tipos de Transacciones 
        // 0 = Venta al contado 
        // 1 = Compra al contado
        //  2 = Guardada 
        //  3 = Venta a crédito
        //  4 = Compra a crédito
        //  5 = Pago de créditos
        //  6 = Devolución
        //  7 = Venta anulada
        //  8 = Venta recursiva
        //  9 = Presupuesto
        //  10 = Delivery
        //  11 = Abrir mesa
        //  12 = Orden
        //  13 = Agendado

        $saleDetail     = saleArraySanitizer($data['sale']);

        $totalAmount    = $data['subtotal'];
        $totalTax       = $data['tax'];
        $totalDiscount  = $data['discount'];
        $totalUnits     = countUnitSold($saleDetail);

        $client         = is_numeric($data['client']) ? $data['client'] : dec($data['client']);
        $user           = iftn($data['user'], 0, dec($data['user']));

        if ($data['user'] != USER_ID) {
          $user         = dec($data['user']);
          $responsible  = USER_ID;
        } else {
          $user         = USER_ID;
          $responsible  = NULL;
        }

        $db->StartTrans();

        //realizo un check para ver si esta venta ya se añadió. para evitar loops desde la app y que sobrecarguen la DB con duplicados
        $dupli = ncmExecute('SELECT transactionUID FROM transaction WHERE transactionUID = ? LIMIT 1', [$data['uid']]);
        if (validity(is_array($dupli) ? $dupli['transactionUID'] : false)) {
          //@mailSaleBackUp(json_encode($data),COMPANY_ID,OUTLET_ID,$data['date'],$client,USER_ID,REGISTER_ID,'Duplicado Web');
          //$record['transactionType'] = 16;
          jsonDieMsg('Duplicated Entry', 200, 'success');
        }

        //verifico que tipo de parent tiene
        /*if($data['getParentId'] && $data['parentId']){//no tiene parent pero tiene UID
          $missingDad  = ncmExecute('SELECT transactionId FROM transaction WHERE transactionUID = ? AND companyId = ? LIMIT 1',[$data['parentId'],COMPANY_ID]);
          if(!$missingDad){
            jsonDieMsg('Parent Not Found');
          }
        }*/
        //


        //verifico si el parentID es el ID de una transacción, es el UID o es un Array
        $saleParentId   = 0;
        $typeOfParentID = false;
        if ($data['parentId']) {
          if (is_array($data['parentId'])) {
            $saleParentId   = 0;
            $typeOfParentID = 'ARRAY';
          } else {
            if (is_numeric($data['parentId'])) {
              $saleParentId   = $data['parentId'];
              $typeOfParentID = 'UID';
            } else {
              $saleParentId   = dec($data['parentId']);
              $typeOfParentID = 'ID';
            }
          }
        }

        if ($typeOfParentID == 'UID') { //si el parent es UID, busco el id para actualizar la venta añadiendole el parent id (Solo usar para actualizar el parent de la venta)
          $parentIdUID     = $data['parentId'];
          $missingDadUID   = ncmExecute('SELECT transactionId FROM transaction WHERE transactionUID = ? AND companyId = ? LIMIT 1', [$parentIdUID, COMPANY_ID]);
          if ($missingDadUID) {
            $data['parentId'] = enc($missingDadUID['transactionId']);
            $saleParentId   = $missingDadUID['transactionId'];
            $typeOfParentID = 'ID';
          }
        }

        $record                           = [];
        $record['transactionDiscount']    = flipOnReturn($data['type'], $totalDiscount); //total discount in cash
        $record['transactionTax']         = flipOnReturn($data['type'], $totalTax);  //total tax in cash
        $record['transactionTotal']       = flipOnReturn($data['type'], $totalAmount); //total sale amount
        $record['transactionUnitsSold']   = flipOnReturn($data['type'], $totalUnits);

        $record['transactionDetails']     = json_encode($saleDetail);
        $record['transactionPaymentType'] = json_encode($data['payment']);

        $record['transactionParentId']    = $saleParentId;
        $record['transactionType']        = $data['type'];
        $record['transactionComplete']    = ($data['type'] == '3' || $data['type'] == '4' || $data['type'] == '13') ? 0 : 1;

        $record['transactionDate']        = $data['date'];
        $record['transactionDueDate']     = $data['dueDate'] ?? null;
        $record['fromDate']               = iftn(array_key_exists("from", $data) ? $data['from'] : null, null);
        $record['toDate']                 = iftn(array_key_exists("to", $data) ? $data['to'] : null, null);
        $record['transactionName']        = iftn(array_key_exists("ident", $data) ? $data['ident'] : null, null, strip_tags(array_key_exists("ident", $data) ? $data['ident'] : ""));
        $record['transactionNote']        = isset($data['note']) ? strip_tags($data['note']) : null;
        $record['invoiceNo']              = iftn($data['invoiceno'] ?? null, null);
        $record['tags']                   = $data['tags'];
        $record['timestamp']              = $data['timestamp'];
        $record['transactionUID']         = $data['uid'];
        $record['transactionCurrency']    = iftn($data['currency'], null);
        $record['transactionStatus']      = (array_key_exists('status', $data) && $data['status'] > -1) ? $data['status'] : 1;

        $record['customerId']             = $client;
        $record['registerId']             = REGISTER_ID;
        $record['userId']                 = $user;
        $record['responsibleId']          = $responsible;
        $record['outletId']               = iftn(OUTLET_ID, 0);
        $record['companyId']              = iftn(COMPANY_ID, 0);

        $insertTransaction                = $db->AutoExecute('transaction', $record, 'INSERT');
        $transID                          = $db->Insert_ID();
        // print_r($insertTransaction);
        // print_r($transID);
        // die();
        unset($record);
        $records = [];

        if ($insertTransaction === true) {
          list($theSaleType, $docType) = getSaleType($data['type']);

          //guardo el taxObj
          $taxObj = $data['taxObj'] ?? [];
          $taxObj = taxObjSanitizer($taxObj);

          if (validity($taxObj)) {
            ncmInsert(["table" => "toTaxObj", "records" => ["toTaxObjText" => json_encode($taxObj), "transactionId" => $transID, "companyId" => COMPANY_ID]]);
          }

          if($typeOfParentID){
            if (is_array($data['parentId'])) { //si el parent es array inserto en toTransaction
              foreach ($data['parentId'] as $key => $pId) {
                ncmInsert(["table" => "toTransaction", "records" => ["parentId" => dec($pId), "transactionId" => $transID]]);
                ncmInsert(["table" => "toTransaction", "records" => ["parentId" => $transID, "transactionId" => dec($pId)]]); //Inserto el parent de la venta
              }
            } else {
              ncmInsert(["table" => "toTransaction", "records" => ["parentId" => dec($data['parentId']), "transactionId" => $transID]]);
              ncmInsert(["table" => "toTransaction", "records" => ["parentId" => $transID, "transactionId" => dec($data['parentId'])]]); //Inserto el parent de la venta
            }
          }

          //registro address ID
          if (in_array($theSaleType, ['cashsale', 'creditsale', 'order', 'schedule']) && $client && !empty($data['addressId'])) {
            $addressId = dec($data['addressId']);
            ncmInsert(["table" => "toAddress", "records" => ["customerAddressId" => $addressId, "transactionId" => $transID]]);
          }

          //insertot tags
          if (validity($data['tags'])) {
            $tags       = stripslashes($data['tags']);
            $tags       = json_decode($tags, true);
            $tagsAdded  = [];

            if (is_string($tags)) {
              $tags = explode(',', $tags);
            }

            if (is_array($tags)) {
              foreach ($tags as $k => $ttag) {
                if ($k > 20) {
                  break;
                }

                $ttag = intval($ttag);

                if (!in_array($ttag, $tagsAdded)) {
                  ncmInsert(["table" => "toTag", "records" => ["toTagType" => 0, "parentId" => $transID, "tagId" => $ttag]]);
                  $tagsAdded[] = $ttag;
                }
              }
            }
          }


          //inserto payments methods
          /*$payment = paymentMObjSanitizer($data['payment']);
          if($payment){
            $tags = $payment;
            foreach ($tags as $k => $ttag) {
              $extras = json_encode(['name' => $ttag['name'], 'price' => $ttag['price'], 'total' => $ttag['total'], 'extra' => $ttag['extra']]);
              
              ncmInsert(["table" => "toPaymentMethod", "records" => ["toPaymentMethodType" => 0, "parentId" => $transID, "paymentMethodId" => $ttag['type'], "toPaymentMethodExtras" => $extras] ]);
            }
          }*/

          //Inventory discount
          if (in_array($theSaleType, ['cashsale', 'creditsale', 'return'])) { //descuento el inventario

            if (validity($saleDetail)) {
              //verifico si el usuario tiene comision

              foreach ($saleDetail as $i => $sD) {
                if ($sD['type'] != 'discount') {
                  empty($records);

                  if ($sD['itemId']) {
                    $itemId   = dec($sD['itemId']);
                    $itmData  = ncmExecute('SELECT itemType, itemPrice FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$itemId, COMPANY_ID], true);
                  } else {
                    $itemId   = ($sD['type'] == 'inCredit') ? '5' : '0';
                  }

                  $userComission = false;
                  if (validity($sD['user'])) {
                    $userComission = ncmExecute('SELECT contactFixedComission as comission FROM contact WHERE contactId = ? AND companyId = ? AND contactFixedComission > 0 LIMIT 1', [dec($sD['user']), COMPANY_ID]);
                  }

                  //si el item esta dentro de un combo obtengo el precio del item
                  if ($sD['type'] == 'inCombo') {
                    $comissionTotal = $itmData['itemPrice'] * $sD['count'];
                  } else {
                    $comissionTotal = $sD['total'];
                  }

                  if ($userComission) {
                    $comission                  = getUserComissionTotal($comissionTotal, $userComission['comission']);
                  } else {
                    $comission                  = getItemComsissionTotal($itemId, $sD['count'], $comissionTotal);
                  }

                  //Si el item es producción le asigno a $itemSoldCOGS['stockOnHandCOGS'] el costo generado en getProductionCOGS();
                  $itemSoldCOGS                 = [];
                  if ($itmData['itemType'] == 'direct_production') {
                    $itemSoldCOGS['stockOnHandCOGS'] = getProductionCOGS($itemId);
                  } else if (in_array($itmData['itemType'], ['precombo', 'combo'])) {
                    $itemSoldCOGS['stockOnHandCOGS'] = getComboCOGS($itemId);
                  } else {
                    $itemSoldCOGS                    = getItemStock($itemId);
                  }

                  $records['itemSoldTotal']     = flipOnReturn($data['type'], $sD['total']);
                  $records['itemSoldTax']       = flipOnReturn($data['type'], addTax($sD['tax'], $sD['total']));
                  $records['itemSoldDiscount']  = flipOnReturn($data['type'], $sD['totalDiscount']);
                  $records['itemSoldUnits']     = flipOnReturn($data['type'], $sD['count']);
                  $records['itemSoldComission'] = flipOnReturn($data['type'], $comission);
                  $records['itemSoldCOGS']      = flipOnReturn($data['type'], is_array($itemSoldCOGS) ? $itemSoldCOGS['stockOnHandCOGS'] : null);
                  $records['itemSoldParent']    = $sD['parent'];

                  $records['itemId']            = $itemId;
                  $records['itemSoldDate']      = $data['date'];
                  $records['transactionId']     = $transID;
                  $records['userId']            = dec($sD['user']);

                  if ($sD['type'] == 'dynamic') {
                    $records['itemSoldDescription'] = markupt2HTML(['text' => $sD['note'], 'type' => 'HtM']);
                  }

                  $db->AutoExecute('itemSold', $records, 'INSERT');
                  $itemSoldID       = $db->Insert_ID();
                  $compound         = getCompoundsArray($itemId);
                  $units            = $sD['count'];

                  //compounds discount
                  if (validity($compound, 'array') && $sD['type'] != 'combo' && $sD['type'] != 'production') {
                    $allWaste = getAllWasteValue();
                    foreach ($compound as $comr) {
                      $comid    = $comr['compoundId'];
                      $comunits = $comr['toCompoundQty'] * $units;
                      $itmData  = ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$comid, COMPANY_ID]);

                      $wasteP   = $allWaste[$comid] ?? '';
                      if ($wasteP > 0) {
                        $comunits = getNeedWithWaste($comunits, $wasteP);
                      }

                      if ($data['type'] == '6') { //si es una devolución inserto un lote

                        manageStock([
                          'itemId'        => $comid,
                          'outletId'      => OUTLET_ID,
                          'date'          => TODAY,
                          'locationId'    => $itmData['locationId'],
                          'count'         => $comunits,
                          'source'        => 'return',
                          'transactionId' => $transID,
                          'timestamp'     => $data['timestamp']
                        ]);
                      } else { //sino, descuento lo que corresponde
                        $source = 'sale';

                        if ($sD['type'] == 'direct_production') {
                          $source = 'production';
                        }

                        manageStock([
                          'itemId'        => $comid,
                          'outletId'      => OUTLET_ID,
                          'date'          => TODAY,
                          'locationId'    => $itmData['locationId'],
                          'count'         => $comunits,
                          'type'          => '-',
                          'source'        => $source,
                          'transactionId' => $transID,
                          'timestamp'     => $data['timestamp']
                        ]);
                      }
                    }
                  }
                  //compounds discount END

                  $itmData  = ncmExecute('SELECT locationId FROM item WHERE itemId = ? AND companyId = ? LIMIT 1', [$itemId, COMPANY_ID]);

                  if ($data['type'] == '6') { //si es una devolución

                    manageStock([
                      'itemId'    => $itemId,
                      'outletId'  => OUTLET_ID,
                      'date'      => TODAY,
                      'locationId' => $itmData['locationId'],
                      'count'     => $units,
                      'source'    => 'return',
                      'transactionId' => $transID,
                      'timestamp'     => $data['timestamp']
                    ]);
                  } else {
                    manageStock([
                      'itemId'    => $itemId,
                      'outletId'  => OUTLET_ID,
                      'date'      => TODAY,
                      'locationId' => $itmData['locationId'],
                      'count'     => $units,
                      'type'      => '-',
                      'source'    => 'sale',
                      'transactionId' => $transID,
                      'timestamp'     => $data['timestamp']
                    ]);

                    //SESIONES EN CITAS
                    if (validity($client)) { //si hay un cliente
                      $scheduleNo = iftn($data['invoiceno'], 0);
                      if ($sD['itemId']) {

                        $itemId     = dec($sD['itemId']);
                        $sessions   = getValue('item', 'itemSessions', 'WHERE itemId = ' . $itemId . ' AND companyId = ' . COMPANY_ID);
                        $sessions   = $sessions * $sD['count'];

                        if ($sessions > 0) {
                          $i = 0;
                          while ($i < $sessions) {
                            $dataItem               = [];
                            $dataItem['date']       = $data['date'];
                            $dataItem['invoice']    = $i + 1;
                            $dataItem['prefix']     = $data['invoiceno'] . '/';
                            $dataItem['price']      = divider($sD['price'], $sessions, true, 'up');
                            $dataItem['parent']     = $transID;
                            $dataItem['details']    = json_encode([[
                              'itemId'  => $sD['itemId'],
                              'count'   => $sD['count'],
                              'price'   => $sD['price'],
                              'user'    => $sD['user']
                            ]]);
                            $dataItem['customerId'] = $client;
                            $dataItem['registerId'] = REGISTER_ID;
                            $dataItem['userId']     = $user;
                            $dataItem['packageId']  = $itemSoldID;
                            $dataItem['outletId']   = OUTLET_ID;
                            $dataItem['companyId']  = COMPANY_ID;

                            insertEmptySchedule($dataItem);
                            $i++;
                          }
                        }
                      }
                      updateLastTimeEdit(COMPANY_ID, 'calendar');
                    }

                    //

                  }

                  //If giftcard
                  if (validity(array_key_exists("giftcardId", $sD) ? $sD['giftcardId'] : "")) { //si es un giftcard creo un record en giftCardSold table
                    $benef      = $sD['beneficiaryId'];
                    $benefId    = is_numeric($benef) ? $benef : dec($benef);
                    $giftTotal  = $sD['totalGift'] ? $sD['totalGift'] : $sD['total'];

                    $inserted = insertNewGiftCard(
                      $sD['giftcardId'],
                      $giftTotal,
                      date('Y-m-d 01:00:00', strtotime($sD['giftcardExp'])),
                      $transID,
                      $sD['note'],
                      $benefId,
                      $sD['uId'],
                      $sD['giftDate'],
                      $sD['giftcardColor']
                    );

                    if (validity($sD['giftDate']) && validity($sD['beneficiaryId']) && $inserted) {
                      $gfSndDate = explodes(' ', $sD['giftDate'], 0);

                      //E-gift card
                      if (date('Y-m-d') == $gfSndDate) { //si Hoy es igual a la fecha seleccionada para enviar el gift, envio ya nomás

                        $benefData    = getCustomerData($benefId, 'uid');
                        $benefPhone   = iftn($benefData['phone'], $benefData['phone2']);

                        if (validity($benefData['email'], 'email') || validity($benefPhone)) {
                          $senderName   = $compName;
                          $benefName    = '!';

                          if (validity($client)) {
                            $senderData = getCustomerData($client, 'uid');
                            $senderName = getCustomerName($senderData);
                          }

                          $benefName    = getCustomerName($benefData, 'first');

                          //msg
                          $gifUrl       = getShortURL('https://public.encom.app/giftCardRedeem?s=' . base64_encode($sD['uId'] . ',' . enc(COMPANY_ID)));

                          //email
                          $subject  = '[' . $compName . '] Gift Card';
                          $body     = '<p>Hola ' . $benefName . ', <br>' .
                            $senderName . ' le ha enviado una Gift Card' . '</p>' .
                            makeEmailActionBtn($gifUrl, 'Ver Gift Card') .
                            '<p>' . 'Si tiene preguntas o dudas por favor contactenos a ' . $compEmail . '.</p>';
                          //email
                          $meta['subject'] = $subject;
                          $meta['to']      = $benefData['email'];
                          $meta['fromName'] = $compName;
                          $meta['data']    = [
                            "message"     => $body,
                            "companyname" => $compName,
                            "companylogo" => $compLogo
                          ];

                          $sent = sendEmails($meta);

                          $smsBody = '[' . $compName . '] Hola ' . $benefName . ', ' . $senderName . ' le ha enviado una Gift Card. ' . $gifUrl;
                          sendSMS($benefData['phone'], $smsBody);
                        }
                      }
                    }
                  }

                  if (validity($sD['type']) == 'inCredit' && validity($client)) { //si es venta de credito interno y tiene cliente
                    $db->Execute("UPDATE contact SET contactStoreCredit = contactStoreCredit + " . $sD['total'] . ", updated_at = '" . TODAY . "' WHERE contactUID = ?", [$client]);
                    updateLastTimeEdit(COMPANY_ID, 'customer');
                  }
                }
              }
            }
          }
          //Inventory discount END

          //Subscription
          if (in_array($theSaleType, ['creditsale', 'schedule']) && $data['repeat']) {
            //si es schedule agendo varias veces el mismo da
            //si es venta a credito completar datos

            $times  = $data['repeatT'];
            $inD    = 'Y-m-d 00:00:00';
            if ($theSaleType == 'creditsale') {

              $nextRec    = getNextDatePeriod($data['repeatF'], '1', $data['date']);
              $endRec     = getNextDatePeriod($data['repeatF'], $times, $data['date']);
              $transData  = base64_decode(validateHttp('l'));

              $recurring                              = [];
              $recurring['recurringNextDate']         = $nextRec;
              $recurring['recurringEndDate']          = $endRec;
              $recurring['recurringFrecuency']        = $data['repeatF'];
              $recurring['recurringStatus']           = 1;
              $recurring['recurringSaleData']         = json_encode($data);
              $recurring['recurringTransactionData']  = $transData;
              $recurring['companyId']                 = COMPANY_ID;

              $recInsert = $db->AutoExecute('recurring', $recurring, 'INSERT');
            } else if ($theSaleType = 'schedule') {
              $i      = 0;
              $nFrom  = $data['from'];
              $nTo    = $data['to'];

              while ($i < $times) {
                $dataItem               = [];
                $dataItem['date']       = $data['date'];
                $dataItem['from']       = getNextDatePeriod($data['repeatF'], '1', $nFrom, 'Y-m-d H:i:s');
                $dataItem['to']         = getNextDatePeriod($data['repeatF'], '1', $nTo, 'Y-m-d H:i:s');
                $dataItem['invoice']    = $data['invoiceno'] + 1;
                $dataItem['status']     = $data['status'];
                $dataItem['price']      = $totalAmount;
                $dataItem['details']    = json_encode($saleDetail);
                $dataItem['customerId'] = $client;
                $dataItem['registerId'] = REGISTER_ID;
                $dataItem['userId']     = $user;
                $dataItem['outletId']   = OUTLET_ID;
                $dataItem['companyId']  = COMPANY_ID;

                insertEmptySchedule($dataItem);

                $nFrom                  = $dataItem['from'];
                $nTo                    = $dataItem['to'];

                $i++;
              }
            }
          }
          //

          //loyalty
          foreach ($data['payment'] as $payment => $key) {
            if ($key['type'] == 'points') { //se pagó con loyalty
              manageCustomerLoyalty('used', $key['price'], $client, COMPANY_ID);
            } else if ($key['type'] == 'storeCredit') {
              manageCustomerStoreCredit('used', $key['price'], $client, COMPANY_ID);
            } else if ($key['type'] == 'giftcard') {
              //descuento la cantidad utilizada de la giftcard
              manageGiftCard($key['price'], $key['extra']);
            } else {
              //Aqui sumo los loyalty ganados
              if (($data['type'] == '0' || $data['type'] == '5') && $compLoyalty > 0) {
                manageCustomerLoyalty('earned', $key['price'], $client, COMPANY_ID);
              }
            }
          }
          //loyalty end

          $errors             = $db->ErrorMsg();
          $failedTransaction  = $db->HasFailedTrans();
          $db->CompleteTrans();

          if ($failedTransaction) {
            @mailSaleBackUp($transID . ' - ' . $itemSoldID . ' - ' . $errors, COMPANY_ID, OUTLET_ID, $data['date'], $client, USER_ID, REGISTER_ID, 'Web Bad');
            jsonDieMsg($errors);
          } else {
            updateLastTimeEdit(COMPANY_ID, 'item');
          }

          if (!$failedTransaction) {

            //Esto sirve para finalizar una venta a crédito en el caso de que ya se haya pagado en su totalidad
            if ($theSaleType == 'creditpayment' && $typeOfParentID) { //verifico tipo de venta y si tiene Parent ID

              $codDaddy       = false;

              if ($typeOfParentID == 'ID') {
                $codDaddy     = dec($data['parentId']);
              } else if ($typeOfParentID == 'UID') {
                $codDaddy     = $data['parentId'];
                $missingDad   = ncmExecute('SELECT transactionId FROM transaction WHERE transactionUID = ? AND companyId = ? LIMIT 1', [$codDaddy, COMPANY_ID]);
                if ($missingDad) {
                  $codDaddy   = $missingDad['transactionId']; //reasigno el parent y codifico xq luego sera decoded
                  ncmUpdate(['records' => ['transactionParentId' => $codDaddy], 'table' => 'transaction', 'where' => 'transactionId = ' . $transID]);
                }
              }

              if ($codDaddy) {

                $total  = ncmExecute('SELECT transactionTotal as total, transactionDiscount as discount, customerId as customer FROM transaction WHERE transactionId = ? LIMIT 1', [$codDaddy]);
                $paid   = ncmExecute('SELECT SUM(transactionTotal) as paid FROM transaction WHERE transactionParentId = ? GROUP BY transactionParentId', [$codDaddy]);

                if (($total['total'] - $total['discount']) <= $paid['paid']) {
                  $setCompleted = ncmUpdate(['records' => ['transactionComplete' => 1], 'table' => 'transaction', 'where' => 'transactionId = ' . $codDaddy]);
                }

                if (!validity($client)) {
                  $client = $total['customer'];
                }
              }

              try {
                $userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
                $registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
                $companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
                $outletName = getCurrentOutletName(OUTLET_ID);

                $auditoriaData = [
                  'date'        => $data['date'],
                  'user'      => $userName,
                  'module'       => 'CREDITOS',
                  'origin'       => 'CAJA',
                  'company_id'       => COMPANY_ID,
                  'data'       => [
                    'action' => "El usuario $userName agregó un pago desde la caja " . $registerName,
                    'userId' => USER_ID,
                    'userName' => $userName,
                    'operationData' => $data,
                    'registerId' => REGISTER_ID,
                    'registerName' => $registerName,
                    'companyID' => COMPANY_ID,
                    'companyName' => $companyName,
                    'outletId' => OUTLET_ID,
                    'outletName' => $outletName,
                    'timestamp' => $data['timestamp']
                  ]
                ];

                sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
              } catch (\Throwable $th) {
                //throw $th;
                error_log("Error al enviar registro de auditoría de cobro exitoso: \n", 3, './error_log');
                error_log(print_r($th, true), 3, './error_log');
                error_log("data: \n", 3, './error_log');
                error_log(print_r($data, true), 3, './error_log');
              }
            }

            if ($theSaleType == 'order') {

              updateLastTimeEdit(COMPANY_ID, 'order');

              sendWS([
                'channel'       => enc(OUTLET_ID) . '-register',
                'event'         => 'order',
                'message'       => json_encode(['ID' => enc($transID), 'registerID' => enc(REGISTER_ID), 'autoPrint' => $data['autoPrint'] ?? false])
              ]);

              sendWS([
                'channel'       => enc(OUTLET_ID) . '-KDS',
                'event'         => 'order',
                'message'       => enc($transID)
              ]);
            }

            //EMAILS & SMS
            $saleDateOnly = date('Y-m-d', strtotime($data['date']));
            $todayDate    = date('Y-m-d');

            if (validity($client) && ($saleDateOnly == $todayDate)) {

              if (in_array($theSaleType, ['cashsale', 'creditsale', 'quote', 'creditpayment'])) {
                if (!empty($data['dontNotify'])) {
                  $contact    = false;
                } else {
                  $contact    = getCustomerData($client, 'uid');
                }
                if ($contact && (validity($contact['email']) || validity($contact['phone']) || validity($contact['phone2']))) {
                  //datacollect
                  $contactName      = getCustomerName($contact, 'first');
                  $contactEmail     = $contact['email'];
                  $contactPhone     = iftn($contact['phone'], $contact['phone2']);

                  $companyContacts  = [iftn($compEmail), iftn($compPhone)];

                  $sendMail         = true;

                  if ($theSaleType == 'quote') {
                    $userName     = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);

                    $subject      = '[' . $compName . '] ' . L_EMAIL_QUOTE_TITLE;

                    $filename     = $data['timestamp'] . '_' . enc(COMPANY_ID) . '.pdf';
                    $surl         = 'https://public.encom.app/quoteView?s=' . base64_encode(enc($transID) . ',' . enc(COMPANY_ID)); //pdfFile($data['document'],$filename);
                    $url          = getShortURL($surl);
                    $smsBody      = '[' . $compName . '] ' . L_HELLO . ' ' . $contactName . ', ' . L_SMS_QUOTE_BODY . ' ' . $url;
                    $body         = L_HELLO . ' ' . $contactName . ',' .
                      '<p>' . L_EMAIL_QUOTE_BODY . '</p>' .
                      makeEmailActionBtn($url, L_EMAIL_VIEW_QUOTE);
                  } else if (in_array($theSaleType, ['cashsale', 'creditsale'])) {
                    if (!validity($_modules['digitalInvoice'])) {
                      $subject      = '[' . $compName . '] ' . L_EMAIL_DETAILS_TITLE;
                      $surl         = 'https://public.encom.app/receipt?s=' . base64_encode(enc($transID) . ',' . enc(COMPANY_ID));
                      $url          = getShortURL($surl);

                      if (validity($data['electronicInvoicePY'], 'array')) {
                        $url = FACTURACION_ELECTRONICA_URL;
                      };
                      $smsBody      = '[' . $compName . '] ' . L_HELLO . ' ' . $contactName . ', ' . L_SMS_DETAILS_BODY . ' ' . $url; //no uso aqui por el acento
                      $body         = L_HELLO . ' ' . $contactName . ',' .
                        '<p>' . L_EMAIL_DETAILS_BODY . '</p>' .
                        makeEmailActionBtn($url, L_EMAIL_VIEW_DETAILS);
                    } else {

                      if ($theSaleType == 'cashsale') {
                        $L_TITLE    = L_EMAIL_CASHSALE_TITLE;
                        $L_SMSBODY  = L_SMS_CASHSALE_BODY;
                        $L_BODY     = L_EMAIL_CASHSALE_BODY;
                        $L_BTN      = L_EMAIL_VIEW_CASHSALE;
                      } else if ($theSaleType == 'creditsale') {
                        $L_TITLE    = L_EMAIL_INVOICE_TITLE;
                        $L_SMSBODY  = L_SMS_INVOICE_BODY;
                        $L_BODY     = L_EMAIL_INVOICE_BODY;
                        $L_BTN      = L_EMAIL_VIEW_INVOICE;
                      }

                      $subject      = '[' . $compName . '] ' . $L_TITLE;
                      $surl         = 'https://public.encom.app/digitalInvoice?s=' . base64_encode(enc($transID) . ',' . enc(COMPANY_ID)) . '&pdf=1';
                      $url          = getShortURL($surl);

                      $smsBody      = '[' . $compName . '] ' . L_HELLO . ' ' . $contactName . ', ' . $L_SMSBODY . ' ' . $url; //no uso aqui por el acento
                      $body         = L_HELLO . ' ' . $contactName . ',' .
                        '<p>' . $L_BODY . '</p>' .
                        makeEmailActionBtn($url, $L_BTN);
                    }
                  } else if ($theSaleType == 'creditpayment') {
                    $subject      = '[' . $compName . '] ' . L_EMAIL_RECEIPT_TITLE;
                    $url          = getShortURL('https://public.encom.app/receipt?s=' . base64_encode(enc($transID) . ',' . enc(COMPANY_ID)));

                    $smsBody      = '[' . $compName . '] ' . L_HELLO . ' ' . $contactName . ', ' . L_SMS_RECEIPT_BODY . ' ' . $url; //no uso aqui por el acento
                    $body         = L_HELLO . ' ' . $contactName . ',' .
                      '<p>' . L_EMAIL_RECEIPT_BODY . '.</p>' .
                      makeEmailActionBtn($url, L_EMAIL_VIEW_RECEIPT);
                  }

                  $meta['subject'] = $subject;
                  $meta['to']      = $contactEmail;
                  $meta['fromName'] = $compName;
                  $meta['data']    = [
                    "message"     => $body,
                    "companyname" => $compName,
                    "companylogo" => $compLogo
                  ];

                  if ($sendMail) {
                    sendEmails($meta);
                    sendSMS($contactPhone, $smsBody);
                  }
                }
              } else if ($theSaleType == 'schedule') { //envio email de confirmación de cita
                $contact      = getCustomerData($client, 'uid');
                $userResponsable = getCustomerData($user);
                $userResponsableName = getCustomerName($userResponsable, 'first');
                $contactName  = getCustomerName($contact, 'first');
                $date         = niceDate($data['from'], true, false, false, true);

                if ($contact && (validity($contact['email']) || validity($contact['phone']) || validity($contact['phone2'])) && ($data['from'] > TODAY_START && $data['to'] < TODAY_END)) {

                  //datacollect
                  $url              = getShortURL('https://public.encom.app/scheduleConfirm?s=' . base64_encode(enc($transID) . ',' . enc(COMPANY_ID)));

                  $contactEmail     = $contact['email'];

                  $companyContacts  = array(iftn(OUTLET_EMAIL), iftn(OUTLET_PHONE));

                  //email
                  $subject  = '[' . $compName . '] Confirmación';
                  $body     = L_HELLO . ' ' . $contactName . ',' .
                    '<p>Hemos marcado su asistencia el ' . $date . '. Puede confirmar o cancelar en el siguiente enlace.</p>' .
                    makeEmailActionBtn($url, 'Confirmar o Cancelar');

                  $meta['subject'] = $subject;
                  $meta['to']      = $contactEmail;
                  $meta['fromName'] = $compName;
                  $meta['data']    = [
                    "message"     => $body,
                    "companyname" => $compName,
                    "companylogo" => $compLogo
                  ];

                  $sent = sendEmails($meta);

                  //sms
                  $msg     = '[' . $compName . '] ' . 'Hola ' . $contactName . ', hemos marcado su asistencia. Puede confirmar o cancelar en: \n' . $url;
                  $number  = iftn($contact['phone'], $contact['phone2']);
                  sendSMS($number, $msg);
                }

                if ($userResponsable && (validity($userResponsable['email']) || validity($userResponsable['phone']) || validity($userResponsable['phone2'])) && ($data['from'] > TODAY_START && $data['to'] < TODAY_END)) {
                  // $url              = getShortURL('https://public.encom.app/scheduleConfirm?s=' . base64_encode(enc($transID) . ',' . enc(COMPANY_ID)));

                  $userEmail     = $userResponsable['email'];

                  $companyContacts  = array(iftn(OUTLET_EMAIL), iftn(OUTLET_PHONE));

                  //email
                  $subject  = '[' . $compName . '] Confirmación';
                  $body     = L_HELLO . ' ' . $userResponsableName . ',' .
                    '<p>Tiene cita con ' . $contactName . ' el ' . $date . '</p>' .
                    "<p>Notas: " . ($data['note'] ?? "Ninguna") . "</p>";
                  // makeEmailActionBtn($url, 'Confirmar o Cancelar');

                  $meta['subject'] = $subject;
                  $meta['to']      = $userEmail;
                  $meta['fromName'] = $compName;
                  $meta['data']    = [
                    "message"     => $body,
                    "companyname" => $compName,
                    "companylogo" => $compLogo
                  ];

                  $sent = sendEmails($meta);
                }
                //ENVIO PUSH AL PROFESIONAL
                sendPush([
                  "ids"       => enc(COMPANY_ID),
                  "companyId" => enc(COMPANY_ID),
                  "message"   => 'Tiene cita con ' . $contactName . ' el ' . $date,
                  "title"     => COMPANY_NAME,
                  "where"     => 'caja',
                  "filters"   =>  [
                    ["key"   => "userId", "value" => enc($user)], ["key"   => "companyId", "value" => enc(COMPANY_ID)]
                  ]
                ]);
              }
            }

            if (in_array($theSaleType, ['cashsale', 'creditsale', 'return'])) {
              //integración mcal y mariano
              $_modules    = ncmExecute("SELECT * FROM module WHERE companyId = ? LIMIT 1", [COMPANY_ID], true);
              $modusArr    = json_decode($_modules['moduleData'], true);

              if (validity($modusArr['mcal'] ?? '', 'array')) {
                $mcalData = base64_encode(enc(COMPANY_ID) . ',' . enc(OUTLET_ID) . ',0,' . enc($transID));
                $mcalRes  = @file_get_contents('http://panel.encom.app/thirdparty/mcal/mcalSendSales.php?s=' . $mcalData);
              }

              //integración factura electronica PY
              if (validity($data['electronicInvoicePY'], 'array')) {

                if ($data['type'] == 0 || $data['type'] == 3 || $data['type'] == 6) { //Solo envia si es venta contado, venta credito y devolución (Nota de Crédito)
                  $getRuc = ncmExecute('SELECT settingRUC FROM setting WHERE companyId = ? LIMIT 1', [COMPANY_ID]);

                  $typeDoc = 'FC';
                  if ($data['type'] == 0) { //Factura Contado
                    $typeDoc = 'FC';
                  } else if ($data['type'] == 3) { //Factura Credito
                    $typeDoc = 'FCR';
                  } else if ($data['type'] == 6) { // Nota de Credito
                    $typeDoc = 'NCR';
                  }
                  $fedata = [
                    'ruc'        => $getRuc['settingRUC'],
                    'email'      => $data['electronicInvoicePY']['email'],
                    'type'       => $typeDoc,
                    'data'       => $data['electronicInvoicePY']
                  ];

                  $feresult = sendFE($fedata, FACTURACION_ELECTRONICA_TOKEN);
                }
              }

              if ($data['type'] == 0 || $data['type'] == 3 || $data['type'] == 6) { //Solo envia si es venta contado, venta credito y devolución (Nota de Crédito)
                try {
                  $userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
                  $registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
                  $companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
                  $outletName = getCurrentOutletName(OUTLET_ID);
                  $documentType = $data['type'] == 6 ? 'Nota de Crédito' : 'Factura';

                  $auditoriaData = [
                    'date'        => $data['date'],
                    'user'      => $userName,
                    'module'       => 'FACTURACION',
                    'origin'       => 'CAJA',
                    'company_id'       => COMPANY_ID,
                    'data'       => [
                      'action' => "El usuario $userName agregó una $documentType desde la caja " . $registerName,
                      'userId' => USER_ID,
                      'userName' => $userName,
                      'operationData' => $data,
                      'registerId' => REGISTER_ID,
                      'registerName' => $registerName,
                      'companyID' => COMPANY_ID,
                      'companyName' => $companyName,
                      'outletId' => OUTLET_ID,
                      'outletName' => $outletName,
                      'timestamp' => $data['timestamp']
                    ]
                  ];

                  sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
                } catch (\Throwable $th) {
                  //throw $th;
                  error_log("Error al enviar registro de auditoría de registro de $documentType: \n", 3, './error_log');
                  error_log(print_r($th, true), 3, './error_log');
                  error_log("data: \n", 3, './error_log');
                  error_log(print_r($data, true), 3, './error_log');
                }
              }
            }
          }

          jsonDieMsg('true', 200, 'success');
        } else {
          @mailSaleBackUp(json_encode($data) . ' \n--- ' . $db->ErrorMsg(), COMPANY_ID, OUTLET_ID, $data['date'], $client, USER_ID, REGISTER_ID, 'Web Bad');
          jsonDieMsg('Duplicated Entry', 200, 'success');
        }
      }

      jsonDieMsg('Incomple Data', 200, 'success');
    } else if (array_key_exists('backup', $data)) {
      @mailSaleBackUp(json_encode($data), COMPANY_ID, OUTLET_ID, TODAY, '0', USER_ID, REGISTER_ID, 'Backup Sync');
      jsonDieMsg('true', 200, 'success');
    } else if (array_key_exists('newClient', $data)) {
      $customerData = $data['newClient'];

      $record['contactUID']      = $customerData['customerId'];
      $record['contactName']     = preg_replace('/[^A-Za-z0-9._+-]*$/', '', $customerData['name']);
      $record['contactSecondName'] = preg_replace('/[^A-Za-z0-9._+-]*$/', '', $customerData['fullName']);
      $record['contactTIN']      = preg_replace('/[^A-Za-z0-9._+-]*$/', '', $customerData['ruc']);
      $record['contactCI']       = (int)$customerData['ci'];
      $record['contactNote']     = $customerData['description'];
      $record['contactDate']     = $customerData['date'];
      $record['contactBirthDay'] = $customerData['birthday']; //birthday es date, no tiene time
      $record['contactPhone']    = $customerData['phone'];
      $record['contactPhone2']   = $customerData['phone2'];
      $record['contactEmail']    = strtolower(preg_replace('/[^A-Za-z0-9._+-]*$/', '', $customerData['email']));
      $record['userId']          = USER_ID;
      $record['outletId']        = OUTLET_ID;
      $record['companyId']       = COMPANY_ID;
      $record['updated_at']      = TODAY;
      $record['type']            = 1; //customer

      //Si es diplomatico agrego para guardar en el json del campo data en contacts
      if(isset($customerData['diplomatic']) && $customerData['diplomatic'] === 1){
        $record['diplomatic']    = $customerData['diplomatic']; 
        $allData 													=	json_encode($record);
        $record['data'] 									= $allData;
      }

      $insert = $db->AutoExecute('contact', $record, 'INSERT');

      if (validity($customerData['address'])) {
        $recordAdd['customerAddressText']       = $customerData['address'];
        $recordAdd['customerAddressDefault']    = 1;
        $recordAdd['customerAddressLocation']   = $customerData['location'];
        $recordAdd['customerAddressCity']       = $customerData['city'];
        $recordAdd['customerId']                = $customerData['customerId'];
        $recordAdd['companyId']                 = COMPANY_ID;

        if ($customerData['latLng']) {
          $coords = explodes(',', $customerData['latLng']);
          $lat    = $coords[0];
          $lng    = $coords[1];

          $recordAdd['customerAddressLat'] = $lat;
          $recordAdd['customerAddressLng'] = $lng;
        }

        $insertAdd = $db->AutoExecute('customerAddress', $recordAdd, 'INSERT');
      }

      if (isset($update) && $update === false) {
        jsonDieMsg($db->ErrorMsg());
      } else {
        updateLastTimeEdit(COMPANY_ID, 'customer');


        sendWS([
          'channel'       => enc(COMPANY_ID),
          'event'         => 'addCustomers',
          'message'       => json_encode(['ID' => enc($record['contactUID']), 'registerID' => enc(REGISTER_ID)])
        ]);

        try {
          $userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
          $registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
          $companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
          $outletName = getCurrentOutletName(OUTLET_ID);

          $auditoriaData = [
            'date'        => $customerData['date'],
            'user'      => $userName,
            'module'       => 'CLIENTES',
            'origin'       => 'CAJA',
            'company_id'       => COMPANY_ID,
            'data'       => [
              'action' => "El usuario $userName agregó un nuevo cliente (" . $record['contactName'] . ") desde la caja $registerName",
              'userId' => USER_ID,
              'userName' => $userName,
              'operationData' => $customerData,
              'registerId' => REGISTER_ID,
              'registerName' => $registerName,
              'companyID' => COMPANY_ID,
              'companyName' => $companyName,
              'outletId' => OUTLET_ID,
              'outletName' => $outletName,
              'timestamp' => $customerData['timestamp']
            ]
          ];

          sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
        } catch (\Throwable $th) {
          //throw $th;
          error_log("Error al enviar registro de auditoría de nuevo cliente: \n", 3, './error_log');
          error_log(print_r($th, true), 3, './error_log');
          error_log("customerData: \n", 3, './error_log');
          error_log(print_r($customerData, true), 3, './error_log');
        }

        jsonDieMsg('true', 200, 'success');
      }

      dai();
    } else if (array_key_exists('updateClient', $data)) {
      $customerData = $data['updateClient'];
      $id           = $customerData['customerId'];

      $record['contactName']      = preg_replace('/[^A-Za-z0-9._+-]*$/', '', $customerData['name']);
      $record['contactTIN']       = preg_replace('/[^A-Za-z0-9._+-]*$/', '', $customerData['ruc']);
      $record['contactSecondName'] = preg_replace('/[^A-Za-z0-9._+-]*$/', '', $customerData['fullName']);
      $record['contactCI']        = (int)$customerData['ci'];
      $record['contactNote']      = !empty($customerData['description']) ? $customerData['description'] : $customerData['note'];
      $record['contactPhone']     = $customerData['phone'];
      $record['contactPhone2']    = $customerData['phone2'];
      $record['contactEmail']     = strtolower(preg_replace('/[^A-Za-z0-9._+-]*$/', '', $customerData['email']));
      $record['contactBirthDay']  = $customerData['birthday']; //birthday es date, no tiene time
      $record['updated_at']       = TODAY;

      //Si es diplomatico agrego para guardar en el json del campo data en contacts
      if(isset($customerData['diplomatic']) && ($customerData['diplomatic'] === 1 || $customerData['diplomatic'] === 0)){
        $record['diplomatic']    = $customerData['diplomatic']; 
        $allData 													=	json_encode($record);
        $record['data'] 									= $allData;
      }

      if (is_numeric($id)) {
        $id = db_prepare($id);
      } else {
        $id = db_prepare(dec($id));
      }

      $update = ncmUpdate(['records' => $record, 'table' => 'contact', 'where' => "contactUID = '" . $id . "' AND " . $SQLcompanyId]);

      if ($update['error']) {
        $updateError = $update['error'];
      } else {
        $updateError = false;
      }

      if (validity($customerData['address'])) {

        $addressExists = ncmExecute('SELECT customerAddressId FROM customerAddress WHERE customerId = ? AND companyId = ? AND customerAddressDefault = 1 LIMIT 1', [$id, COMPANY_ID]);

        $recordAdd['customerAddressText']       = $customerData['address'];
        $recordAdd['customerAddressDefault']    = 1;
        $recordAdd['customerAddressLocation']   = $customerData['location'];
        $recordAdd['customerAddressCity']       = $customerData['city'];

        if ($customerData['latLng']) {
          $coords = explodes(',', $customerData['latLng']);
          $lat    = $coords[0];
          $lng    = $coords[1];

          $recordAdd['customerAddressLat'] = $lat;
          $recordAdd['customerAddressLng'] = $lng;
        }

        if ($addressExists) { //si tiene una dirección updateo
          $updateAdd = ncmUpdate(['records' => $recordAdd, 'table' => 'customerAddress', 'where' => "customerId = '" . $id . "' AND customerAddressId = " . $addressExists['customerAddressId']]);
        } else { //sino añado

          $recordAdd['customerId']  = $id;
          $recordAdd['companyId']   = COMPANY_ID;

          $updateAdd = ncmInsert(['records' => $recordAdd, 'table' => 'customerAddress']);
          if (!$updateAdd) {
            $updateAddError = true;
          } else {
            $updateAddError = false;
          }
        }

        if ($updateAdd['error']) {
          $updateAddError = $updateAdd['error'];
        } else {
          $updateAddError = false;
        }
      }

      if ($update === false) {
        jsonDieMsg($updateError);
      } else {
        updateLastTimeEdit(COMPANY_ID, 'customer');

        sendWS([
          'channel'       => enc(COMPANY_ID),
          'event'         => 'addCustomers',
          'message'       => json_encode(['ID' => enc($id), 'registerID' => enc(REGISTER_ID)])
        ]);

        try {
          $userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
          $registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
          $companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
          $outletName = getCurrentOutletName(OUTLET_ID);

          $auditoriaData = [
            'date'        => $customerData['date'],
            'user'      => $userName,
            'module'       => 'CLIENTES',
            'origin'       => 'CAJA',
            'company_id'       => COMPANY_ID,
            'data'       => [
              'action' => "El usuario $userName modificó el cliente " . $record['contactName'] . " desde la caja $registerName",
              'userId' => USER_ID,
              'userName' => $userName,
              'operationData' => $customerData,
              'registerId' => REGISTER_ID,
              'registerName' => $registerName,
              'companyID' => COMPANY_ID,
              'companyName' => $companyName,
              'outletId' => OUTLET_ID,
              'outletName' => $outletName,
              'timestamp' => $customerData['timestamp']
            ]
          ];

          sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
        } catch (\Throwable $th) {
          //throw $th;
          error_log("Error al enviar registro de auditoría de modificación de cliente: \n", 3, './error_log');
          error_log(print_r($th, true), 3, './error_log');
          error_log("customerData: \n", 3, './error_log');
          error_log(print_r($customerData, true), 3, './error_log');
        }

        jsonDieMsg('true', 200, 'success');
      }
    } else if (array_key_exists('updateCustomerRecord', $data)) {
      $dataSet    = $data['updateCustomerRecord'];
      $customerId = db_prepare(dec($dataSet['customerId']));
      $list       = $dataSet['data'];

      foreach ($list as $i => $val) {
        $record   = [];
        $decField = db_prepare(dec($val['id']));
        $value    = strip_tags($val['value']);
        $progress = ($val['progress'] > 0) ? true : false;
        $insertIt = true;

        $select   = ncmExecute(
          "SELECT cRecordValueName 
                                FROM cRecordValue 
                                WHERE cRecordFieldId = ? 
                                AND customerId = ? 
                                ORDER BY cRecordValueDate DESC 
                                LIMIT 1",
          [$decField, $customerId]
        );

        if ($select && !$progress) {
          //existe actualizo
          $record['cRecordValueName'] = $value;
          $update = $db->AutoExecute('cRecordValue', $record, 'UPDATE', 'cRecordFieldId = "' . $decField . '" AND customerId = ' . $customerId);
          if ($update === false) {
            jsonDieMsg($db->ErrorMsg());
          }
        } else {
          //no existe o es progreso

          //verifico si es igual al dato anterior para no duplicar
          if ($progress && ($select['cRecordValueName'] == $value)) {
            $insertIt = false;
          }

          $record['cRecordValueName'] = $value;
          $record['cRecordValueDate'] = TODAY;
          $record['cRecordFieldId']   = $decField;
          $record['customerId']       = $customerId;

          if ($insertIt) {
            $insert = $db->AutoExecute('cRecordValue', $record, 'INSERT');
            if ($insert === false) {
              jsonDieMsg($db->ErrorMsg());
            }
          }
        }
      }

      jsonDieMsg('true', 200, 'success');
    } else if (array_key_exists('openCloseDrawer', $data)) {

      //aqui realizo apertura y cierre de caja
      $record   = [];
      $rrecord  = [];
      $drawer   = $data['openCloseDrawer'];

      //verifico si hay una caja abierta

      $drawerSave = ncmExecute("SELECT *
                            FROM drawer 
                            WHERE registerId  = ?
                            AND outletId      = ?
                            AND companyId     = ?
                            AND (drawerCloseDate IS NULL OR drawerCloseDate < '2000-01-01 00:00:00') 
                            ORDER BY drawerOpenDate DESC
                            LIMIT 1", array(REGISTER_ID, OUTLET_ID, COMPANY_ID));

      if ($drawerSave) { //quiere decir que la caja esta abierta, ahora cierro
        if ($drawer['type'] == 'open') { //si la caja esta abierta y estoy queriendo abrir, no hago nada
          jsonDieMsg('Already Open', 200, 'success');
        }

        if (strtotime($drawerSave['drawerOpenDate']) > strtotime($drawer['date'])) { //si la caja esta abierta y estoy queriendo abrir, no hago nada
          jsonDieMsg('Invalid Close Date', 200, 'success');
        }

        //consulto la lista del drawer para enviar
        //{"companyId":"4L0","outletId":"Y0V","userId":"J0OjX","roleId":"1","registerId":"0Ov","load":"loadDrawerList"}

        $record['drawerCloseDate']    = iftn($drawer['date'], TODAY);
        $record['drawerCloseAmount']  = $drawer['amount'];
        $record['drawerUserClose']    = USER_ID;

        $drawerAction                 = $db->AutoExecute('drawer', $record, 'UPDATE', 'drawerId = ' . $drawerSave['drawerId']);

        $etotal                       = CURRENCY . formatCurrentNumber($drawer['amount'], $compDecimal, $compThousand);
        $etitle                       = 'Cierre de Caja';
        $eaction                      = 'Monto total del cierre';
        $edate                        = $drawer['date'];
        $link                         = 'https://public.encom.app/closedRegister?s=' . base64_encode(enc(COMPANY_ID) . ',' . enc($drawerSave['drawerId']));
      } else {
        if ($drawer['type'] == 'close') { //si la caja esta cerrada y estoy queriendo cerrar, no hago nada
          jsonDieMsg('Already Closed', 200, 'success');
        }

        $record['drawerOpenDate']   = iftn($drawer['date'], TODAY);
        $record['drawerOpenAmount'] = $drawer['amount'];
        $record['drawerUserOpen']   = USER_ID;
        $record['drawerUserClose']  = 0;
        $record['drawerUID']        = 0;

        $record['registerId']       = REGISTER_ID;
        $record['outletId']         = OUTLET_ID;
        $record['companyId']        = COMPANY_ID;

        $drawerAction               = $db->AutoExecute('drawer', $record, 'INSERT');

        $etotal                     = CURRENCY . formatCurrentNumber($drawer['amount'], $compDecimal, $compThousand);
        $etitle                     = 'Apertura de Caja';
        $eaction                    = 'Monto de apertura';
        $edate                      = niceDate($drawer['date'], true);
        $link                       = false;
      }

      if ($drawerAction === false) {
        jsonDieMsg($db->ErrorMsg());
      } else {
        $senEmail = getValue('setting', 'settingDrawerEmail', "WHERE " . $SQLcompanyId);

        if (validity($senEmail)) {

          $usersTosend  = ncmExecute('SELECT contactEmail FROM contact WHERE type = 0 AND role = 1 AND companyId = ?', [COMPANY_ID], false, true);

          if ($usersTosend) {
            $meta['subject']  = $etitle;
            $meta['fromName'] = $compName;

            $body     = '<p>' . $etitle . ' por ' . $drawer['user'] . '</p>' .
              '<p>El: <i>' . $edate . '</i><br>' .
              $eaction . ': <strong>' . $etotal . '</strong></p>';

            if ($link) {
              $url  = getShortURL($link);
              $body .=  makeEmailActionBtn($url, 'Ver detalles');
            }

            while (!$usersTosend->EOF) {
              $meta['to']      = $usersTosend->fields['contactEmail'];
              $meta['data']    = [
                "message"     => $body,
                "companyname" => $compName,
                "companylogo" => $compLogo
              ];

              $sent = sendEmails($meta);

              $usersTosend->MoveNext();
            }
          }
        }

        try {
          $userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
          $registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
          $companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
          $outletName = getCurrentOutletName(OUTLET_ID);
          $drawerOpen = $drawer['type'] == 'open' ? 'abrió' : 'cerró';
          $auditoriaData = [
            'date'        => $drawer['date'],
            'user'      => $userName,
            'module'       => 'CAJA',
            'origin'       => 'CAJA',
            'company_id'       => COMPANY_ID,
            'data'       => [
              'action' => "El usuario $userName $drawerOpen la caja $registerName",
              'userId' => USER_ID,
              'userName' => $userName,
              'operationData' => $drawer,
              'registerId' => REGISTER_ID,
              'registerName' => $registerName,
              'companyId' => COMPANY_ID,
              'companyName' => $companyName,
              'outletId' => OUTLET_ID,
              'outletName' => $outletName,
              'timestamp' => $drawer['timestamp']
            ]
          ];
  
          sendAuditoria($auditoriaData, AUDITORIA_TOKEN);
        } catch (\Throwable $th) {
          //throw $th;
          error_log("Error al enviar registro de auditoría de apertura/cierre de caja: \n", 3, './error_log');
          error_log(print_r($th, true), 3, './error_log');
          error_log("drawer: \n", 3, './error_log');
          error_log(print_r($drawer, true), 3, './error_log');
        }

        jsonDieMsg('true', 200, 'success');
      }
    } else if (array_key_exists('updateDocNumber', $data)) {

      $number = $data['updateDocNumber']['number'];
      $type   = $data['updateDocNumber']['type'];

      if (!$number) {
        jsonDieMsg('true', 200, 'success');
      }


      //selecciono settings y obtengo el campo fullSettings y convierto a array json_decode
      //verifico si esta activada la opción blockUsedDocNo
      //si esta activada verifico si el num de factura usado es menor al último


      if ($type == 'ticket') {
        $field = 'registerTicketNumber';
      } else if ($type == 'return') {
        $field = 'registerReturnNumber';
      } else if ($type == 'schedule') {
        $field = 'registerScheduleNumber';
      } else if ($type == 'order') {
        $field = 'registerPedidoNumber';
      } else if ($type == 'quote') {
        $field = 'registerQuoteNumber';
      } else {
        $field = 'registerInvoiceNumber';
      }

      if ($field == 'registerInvoiceNumber') {

        $result = ncmExecute('SELECT invoiceNo FROM transaction WHERE transactionType IN(0,3,7) AND invoiceNo != ? AND registerId = ? AND tags NOT LIKE "%166227%" AND companyId = ? ORDER BY transactionId DESC LIMIT 1', [$number, REGISTER_ID, COMPANY_ID]);
        if ($result) {
          if ($result['invoiceNo'] >= $number) {
            jsonDieMsg('true', 200, 'success');
          }
        }
      }

      if ($field == "registerTicketNumber") {
        $result = ncmExecute('SELECT invoiceNo FROM transaction WHERE transactionType IN(0,3,7) AND invoiceNo != ? AND registerId = ? AND tags LIKE "%166227%" AND companyId = ? ORDER BY transactionId DESC LIMIT 1', [$number, REGISTER_ID, COMPANY_ID]);
        if ($result) {
          if ($result['invoiceNo'] >= $number) {
            jsonDieMsg('true', 200, 'success');
          }
        }
      }

      $record[$field] = $number;
      $invoiceAction = $db->Execute("UPDATE register SET {$field} = '{$number}' WHERE registerId = '" . REGISTER_ID . "' AND companyId = '" . COMPANY_ID . "'", []);
      // $invoiceAction  = $db->AutoExecute('register', $record, 'UPDATE', 'registerId = ' . REGISTER_ID . ' AND companyId = ' . COMPANY_ID);

      if ($invoiceAction === false) {
        jsonDieMsg();
      } else {
        updateLastTimeEdit(COMPANY_ID);
        jsonDieMsg('true', 200, 'success');
      }
    } else if (array_key_exists('clocking', $data)) {

      $record['clockingDate']       = $data['clocking']['date'];
      $record['clockingType']       = $data['clocking']['type'];
      $record['clockingLocation']   = $data['clocking']['location'];

      $clockingAction = $db->AutoExecute('clocking', $record, 'INSERT', 'outletId = ' . OUTLET_ID . ' AND ' . $SQLcompanyId);

      if ($clockingAction === false) {
        jsonDieMsg();
      } else {
        jsonDieMsg('true', 200, 'success');
      }
    } else if (array_key_exists('createItem', $data)) {
      $record = array();

      $record['itemName']     = $data['newitem']['name'];
      $record['companyId']    = COMPANY_ID;
      $record['updated_at']   = TODAY;

      $insert = $db->AutoExecute('item', $record, 'INSERT');
      if ($insert === false) {
        jsonDieMsg();
      } else {
        $itemId = $db->Insert_ID();
        echo enc($itemId);
        updateLastTimeEdit(COMPANY_ID, 'item');
      }

      dai();
    } else if (array_key_exists('hotkeys', $data)) {

      if (empty($data['hotkeys'])) {
        $hk = "[]";
      } else {
        $hk = json_encode($data['hotkeys']);
      }

      $record['registerHotkeys']       = $hk;
      $action = $db->AutoExecute('register', $record, 'UPDATE', 'registerId = ' . REGISTER_ID);

      if ($action === false) {
        jsonDieMsg();
      } else {
        updateLastTimeEdit(COMPANY_ID);
        jsonDieMsg('true', 200, 'success');
      }
    } else if (array_key_exists('printers', $data)) {

      if (empty($data['printers'])) {
        $hk = NULL;
      } else {
        $hk = json_encode($data['printers']);
      }

      $record['registerPrinters']       = $hk;
      $action = $db->AutoExecute('register', $record, 'UPDATE', 'registerId = ' . REGISTER_ID);

      if ($action === false) {
        jsonDieMsg();
      } else {
        updateLastTimeEdit(COMPANY_ID);
        jsonDieMsg('true', 200, 'success');
      }
    } else if (array_key_exists('expense', $data)) {
      //primero verifico que no exista una extracción por el mismo monto a la misma hora
      $exists = ncmExecute('SELECT expensesId FROM expenses WHERE expensesAmount = ? AND expensesDate = ? AND registerId = ? LIMIT 1', [$data['expense']['amount'], $data['expense']['date'], REGISTER_ID]);

      if ($exists) {
        jsonDieMsg('Expense Already Exists', 200, 'success');
      }

      $record['expensesNameId']       = dec('NX'); //id del tipo de gasto
      $record['expensesAmount']       = $data['expense']['amount'];
      $record['expensesDate']         = $data['expense']['date'];
      $record['expensesDescription']  = $data['expense']['note'];
      $record['userId']               = USER_ID;
      $record['registerId']           = REGISTER_ID;
      $record['outletId']             = OUTLET_ID;
      $record['companyId']            = COMPANY_ID;

      $insert = $db->AutoExecute('expenses', $record, 'INSERT');

      if ($insert === false) {
        jsonDieMsg();
      } else {
        jsonDieMsg('true', 200, 'success');
      }
    } else if (array_key_exists('drwrIncome', $data)) {
      //primero verifico que no exista una extracción por el mismo monto a la misma hora
      $amount = floatval($data['drwrIncome']['amount']);
      $exists = ncmExecute('SELECT expensesId FROM expenses WHERE expensesAmount = ? AND expensesDate = ? AND registerId = ? LIMIT 1', [$amount, $data['drwrIncome']['date'], REGISTER_ID]);

      if ($exists) {
        jsonDieMsg('Income Already Exists', 200, 'success');
      }

      $record['expensesNameId']       = dec('NX'); //id del tipo de gasto
      $record['expensesAmount']       = $amount;
      $record['expensesDate']         = $data['drwrIncome']['date'];
      $record['expensesDescription']  = $data['drwrIncome']['note'];
      $record['type']                 = 1;
      $record['userId']               = USER_ID;
      $record['registerId']           = REGISTER_ID;
      $record['outletId']             = OUTLET_ID;
      $record['companyId']            = COMPANY_ID;

      $insert = $db->AutoExecute('expenses', $record, 'INSERT');



      if ($insert === false) {
        jsonDieMsg();
      } else {
        jsonDieMsg('true', 200, 'success');
      }
    } else if (array_key_exists('sendPrintInAuditoria', $data)) {
      $data = $data['sendPrintInAuditoria'];
      $documentType = isset($data['type']) && $data['type'] == 6 ? 'Nota de Crédito' : 'Factura';
      try {
        $userName = getValue('contact', 'contactName', 'WHERE contactId = ' . USER_ID);
        $registerName = getValue('register', 'registerName', 'WHERE registerId = ' . REGISTER_ID);
        $companyName = getValue('setting', 'settingName', 'WHERE companyId = ' . COMPANY_ID);
        $outletName = getCurrentOutletName(OUTLET_ID);
        
        $auditoriaData = [
          'date'        => $data['date'],
          'user'      => $userName,
          'module'       => 'FACTURACION',
          'origin'       => 'CAJA',
          'company_id'       => COMPANY_ID,
          'data'       => [
            'action' => "El usuario $userName imprimió una $documentType desde la caja " . $registerName,
            'userId' => USER_ID,
            'userName' => $userName,
            'operationData' => $data,
            'registerId' => REGISTER_ID,
            'registerName' => $registerName,
            'companyId' => COMPANY_ID,
            'companyName' => $companyName,
            'outletId' => OUTLET_ID,
            'outletName' => $outletName,
            'timestamp' => $data['timestamp']
          ]
        ];
  
        sendAuditoria($auditoriaData, AUDITORIA_TOKEN);

      } catch (\Throwable $th) {
        //throw $th;
        error_log("Error al enviar registro de auditoría de impresión de $documentType: \n", 3, './error_log');
        error_log(print_r($th, true), 3, './error_log');
        error_log("data: \n", 3, './error_log');
        error_log(print_r($data, true), 3, './error_log');
      }

      jsonDieMsg('true', 200, 'success');
      // if ($invoiceAction === false) {
      //   jsonDieMsg();
      // } else {
      //   updateLastTimeEdit(COMPANY_ID);
      //   jsonDieMsg('true', 200, 'success');
      // }


    } else {
      jsonDieMsg('true', 200, 'success');
    }
  }

  if ($action == 'consultStatusElectronicInvoice') {
    $data      = validateHttp('data', 'post');
    $getRuc = ncmExecute('SELECT settingRUC FROM setting WHERE companyId = ? LIMIT 1', [COMPANY_ID]);

    $electronicData = [
      'ruc'        => $getRuc['settingRUC'],
      'type'       => $data['type'] == 0 ? 'FC' : 'FCR',
      'fecha_desde' => $data['fecha_desde'],
      'fecha_hasta' => $data['fecha_hasta'],
      'establecimiento'       => $data['establecimiento'],
      'puntoExpedicion'       => $data['puntoExpedicion'],
      'documentoNro_desde'       => $data['invoiceNum'],
      'documentoNro_hasta'       => $data['invoiceNum'],
    ];

    error_log("electronicData: \n", 3, 'error_log');
    error_log(print_r($electronicData, true), 3, 'error_log');
    error_log("\n", 3, 'error_log');
    //jsonDieMsg('true', 200, 'success');

    $feresult = consultFE($electronicData, FACTURACION_ELECTRONICA_TOKEN);
    $feresult = json_decode($feresult);

    if (empty($feresult->documents)) {
      jsonDieMsg();
    } else {
      die(json_encode($feresult->documents[0]));
    }
  }


  checkExecTime($action);
} else {
  http_response_code(401);
  header('Content-Type: application/json');
  die(json_encode(['error' => 'Missing Data', 'sent' => ['GET' => $get, 'POST' => $post]]));
}
?>