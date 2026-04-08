<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$record 	= [];
$arrays     = [];
$outlet 	= dec( validateHttp('outlet','post') );
$ID 	    = validateHttp('ID','post');

if($ID){

    $ID     = dec( $ID );
    $fields = ncmExecute('SELECT * FROM banks WHERE bankId = ? AND companyId = ? LIMIT 100', [$ID, COMPANY_ID]);

    if($fields){
        $data   = json_decode( $fields['bankData'], true );
    
        $arrays = [
            "ID"        => enc( $fields['bankId'] ),
            "name"      => $data['name'],
            "balance"   => $fields['bankBalance'],
            "outlet"    => enc( $fields['outletId'] )
        ];

        apiOk($arrays);

    }else{
        apiError('No se encontraron registros', 404);
    }

}else{

    $record = ncmExecute('SELECT * FROM banks WHERE companyId = ? LIMIT 100', [COMPANY_ID], false, true);
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

}
?>