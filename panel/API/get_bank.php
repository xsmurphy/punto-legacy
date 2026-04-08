<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

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

    apiOk($arrays);
}else{
	apiError('No se encontraron registros', 404);
}

?>