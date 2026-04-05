<?php
include_once('api_head.php');
$params = $post;
if (!array_key_exists("nro_from", $params) || empty($params["nro_from"])) {
    jsonDieMsg("Necesitas indicar desde que numero quieres enviar", 400);
}
if (!array_key_exists("nro_to", $params) || empty($params['nro_to'])) {
    jsonDieMsg("Necesitas indicar hasta que numero quieres enviar", 400);
}

$registerId = array_key_exists("register_id", $params) ? $params["register_id"]  : "5900";

$limit = 60;
if (isset($params["limit"]) && is_numeric($params["limit"])) {
    $limit = $params["limit"];
}


if (isset($params["fe_type"])) {
    $feType = $params["fe_type"];
    $transactions = ncmExecute("SELECT * FROM transaction where companyId = ? and registerId = ? and transactionType in ($feType) and invoiceNo >= ? and invoiceNo <= ? order by invoiceNo asc limit $limit", [COMPANY_ID, $registerId, $params["nro_from"], $params["nro_to"]],  false, true, false);
} else {
    $transactions = ncmExecute("SELECT * FROM transaction where companyId = ? and registerId = ? and transactionType in (0,3, 6) and invoiceNo >= ? and invoiceNo <= ? order by invoiceNo asc limit $limit", [COMPANY_ID, $registerId, $params["nro_from"], $params["nro_to"]],  false, true, false);
}



$result = [];
$data = [];
if (!empty($transactions)) {
    while (!$transactions->EOF) {
        $fields = $transactions->fields;
        // $fields = $transactions;
        $register = ncmExecute("SELECT * FROM register where registerId = '" . $fields["registerId"] . "' limit 1");
        if (!empty($register)) {
            $register = json_decode($register["data"], true);
        }
        $outlet = ncmExecute("SELECT * FROM outlet where outletId = '" . $fields['outletId'] . "' limit 1");
        $customer = ncmExecute("SELECT * FROM contact where contactUID = '" . $fields['customerId'] . "' limit 1");
        $setting = ncmExecute("SELECT * FROM setting where companyId = '" . $fields['companyId'] . "' limit 1");
        $docNo = "";
        $dv = "";
        // jsonDieResult($fields);
        if (!empty($customer) && !empty($customer["contactTIN"]) && strpos($customer["contactTIN"], "-") !== false) {
            $docNo   = explode("-", $customer["contactTIN"])[0];
            $dv      = explode("-", $customer["contactTIN"])[1];
        } else {
            $docNo   = $customer["contactTIN"] ? $customer["contactTIN"] : $customer["contactCI"];
        }
        $dataFE = [];
        $dataFE["timbrado"] = $register["registerInvoiceAuth"];
        //$dataFE["timbrado"] = "16487873";
        $invoicePrefix = explode("-", $register["registerInvoicePrefix"]);
        // jsonDieResult($register);
        $fechaInicioTimbrado = new DateTime($register["registerInvoiceAuthStart"]);
        $fechaInicioTimbrado->setTimezone(new DateTimeZone('America/Asuncion'));
        $dataFE["establecimiento"] = $invoicePrefix[0];
        $dataFE["puntoExpedicion"] = $invoicePrefix[1];
        $dataFE["documentoNro"] = str_pad($fields["invoiceNo"], 7, "0", STR_PAD_LEFT);
        $dataFE["fecIni"] = $fechaInicioTimbrado->format('Y-m-d\TH:i:sP');
        //$dataFE["fecIni"] = "2023-06-21T00:00:00-04:00";
        $dataFE["sucursal"] = $outlet["outletName"];
        $dataFE["operacionMoneda"] = "PYG";
        $dataFE["docNro"] = $docNo ?? "XXXX";
        $dataFE["dv"] = $dv;
        $dataFE["razonSocial"] = $customer["contactName"] ?? "SIN NOMBRE";
        $dataFE["email"] = $customer["contactEmail"];
        $fechaFacturacion = new DateTime($fields["transactionDate"]);
        $fechaFacturacion->setTimezone(new DateTimeZone('America/Asuncion'));
        $dataFE["fecha"] = $fechaFacturacion->format('Y-m-d H:i:s');

        $plazoCredito = null;
        $operacionCreditoTipo = null;
        $type = null;
        $typeDoc = 'FC';
        $notaCreditoDebito = null;

        if ($fields['transactionType'] == 0) { //Factura Contado
            $type = 1;
            $typeDoc = 'FC';
        } else if ($fields['transactionType'] == 3) { //Factura Credito
            $type = 2;
            $operacionCreditoTipo = 1;
            $typeDoc = 'FCR';
            $dueDateStr = $fields['transactionDueDate'];

            $dateStr = $fields['transactionDate'];

            // Convertir la fecha proporcionada en un objeto DateTime
            $fechaProporcionada = new DateTime(str_replace('-', '/', $dueDateStr));

            // Obtener la fecha actual
            $fechaTransaction = new DateTime(str_replace('-', '/', $dateStr));

            // Calcular la diferencia en días
            $intervalo = $fechaTransaction->diff($fechaProporcionada);
            $diasDiferencia = $intervalo->format('%a');

            // Crear una cadena con la cantidad de días y la palabra "días"
            $cadenaDias = $diasDiferencia . ' días';

            // La variable $plazoCredito contendrá la cadena de días
            $plazoCredito = $cadenaDias;
        } else if ($fields['transactionType'] == 6) { //Nota de Crédito
            $typeDoc = 'NCR';
            $notaCreditoDebito['motivoEmision'] = 1;

            // tipo: 1 Electronico, 2 Impreso, 3 Constancia Electrónica
            // tipoDocAsociado: 1 Factura, 3 Nota de Débito, 4 Nota de Remisión
            $dataFE["docAsociados"] = [];
            $dataFE["docAsociados"][] = [
                'tipo' => 1,
                'cdc' => "",
                'tipoDocAsociado' => "1"
            ];
        }

        $dataFE["condicion"] =  $type;
        $dataFE["tiposPagos"] = [];
        $dataFE["operacionTipo"] = $operacionCreditoTipo;
        $dataFE["plazoCredito"] = $plazoCredito;
        $dataFE["notaCreditoDebito"] =  $notaCreditoDebito;

        foreach (json_decode($fields["transactionPaymentType"], true) as $value) {
            // if (!empty($get["debug"])) {
            //     print_r($fields["transactionPaymentType"]);
            //     print_r($value);
            // }


            
            // if ($value["type"] == "cash") {
            //     $dataFE["tiposPagos"][] = [
            //         "tipoPagoCodigo" => 1,
            //         "monto" => $value["price"],
            //     ];
            // }
            if (in_array($value["type"], ['creditcard', 'debitcard'])) {
                $dataFE["tiposPagos"][] = [
                    "tipoPagoCodigo" => 4,
                    "monto" => $value["price"],
                    "tarjeta"  =>   [
                        "denominacionTarjeta" =>  99,
                        "formaProcesamiento"  => 1
                    ]
                ];
            }else if (in_array($value["type"], ['ePOSCard', 'ePOS', 'QRPayment']) || strpos(strtolower($value["name"]), "qr") !== false) {
                $dataFE["tiposPagos"][] = [
                    "tipoPagoCodigo" => 21,
                    "monto" => $value["price"],
                    "tarjeta"  =>   [
                        "denominacionTarjeta" =>  99,
                        "formaProcesamiento"  => 2
                    ]
                ];
            }else{
                $dataFE["tiposPagos"][] = [
                    "tipoPagoCodigo" => 1,
                    "monto" => $value["price"],
                ];
            }
        }

        $dataFE["tiposPagos"] = count($dataFE["tiposPagos"]) !== 0 ? $dataFE["tiposPagos"] : null;

        $dataFE["detalles"] = [];
        foreach (json_decode($fields["transactionDetails"], true) as $value) {
            $afectTributaria = ($value["tax"] > 0) ? 1 : 3; //si el iva es Cero la afectación es 3 (exento) sino es 1(gravIVA)
            $propIva = ($value["tax"] > 0) ? 100 : 0; //si el iva es Cero la proporcíon de iva es 0 (exento) sino es 100(gravIVA)

            if($value["price"] > 0){
                $dataFE["detalles"][] = [
                    "itemCodigo" => (!empty($value["uId"]) ? $value["uId"] : $value["itemId"]) ?? "X",
                    "itemDescripcion" => $value["name"],
                    "cantidad" => $value["count"],
                    "precioUnitario" => $value["price"],
                    "afectacionTributaria" => $afectTributaria,
                    "proporcionIVA" => $propIva,
                    "tasaIVA" => $value["tax"] ?? 0,
                ];
            }
        }
        $dataFE["totalComprobante"] = abs(floatval($fields["transactionTotal"])) - abs(floatval($fields["transactionDiscount"]));
        $feRuc = $setting["settingRUC"];
        list($theSaleType, $docType) = getSaleType($fields['transactionType']);

        $fedata = [
            'ruc'        => $feRuc,
            'type'       => $typeDoc,
            'data'       => $dataFE
        ];

        // error_log('fedata: ');
        // error_log(print_r($fedata, true));
        // if (!empty($get["debug"])) {
        //     print_r("fedata: \n");
        //     print_r($fedata);
        // }
        $result[$register["registerInvoicePrefix"] . $dataFE["documentoNro"]] = [];
        //$result[$register["registerInvoicePrefix"] . $dataFE["documentoNro"]] = $fedata;
        //jsonDieResult($fedata);
        array_push($data, $fedata);
        if (empty($get["debug"])) {
            $feresult = sendFE($fedata, FACTURACION_ELECTRONICA_TOKEN);
            $result[$register["registerInvoicePrefix"] . $dataFE["documentoNro"]] = json_decode($feresult, true);
        }
        $transactions->MoveNext();
        // jsonDieResult(json_decode($feresult,true));
    }
    $transactions->Close();
    if (empty($get["debug"])) {
        jsonDieResult($result);
    } else {
        jsonDieResult($data);
    }
}
