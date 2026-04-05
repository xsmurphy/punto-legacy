<?php
include_once('api_head.php');

$record 	= [];
$arrays     = [];
$outlet 	= dec( validateHttp('outlet','post') );

$record = ncmExecute('SELECT * FROM banks WHERE outletId = ? AND companyId = ? LIMIT 100', [$outlet, COMPANY_ID],false,true);

if($record){
    while (!$record->EOF) {
        $fields = $record->fields;
        $data   = json_decode( $fields['bankData'], true );

        $arrays[] = [
            "ID"        => enc( $fields['bankId'] ),
            "name"      => $data['name'],
            "balance"   => $fields['bankBalance'],
            "outlet"    => enc( $fields['outletId'] )
        ];

        $record->MoveNext();
    }

    jsonDieResult($arrays, 200);
}else{
	jsonDieMsg('No se encontraron registros',404);
}

?>