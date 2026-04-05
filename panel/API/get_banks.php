<?php
include_once('api_head.php');

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

        jsonDieResult($arrays, 200);

    }else{
        jsonDieMsg('No se encontraron registros',404);
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
    
        jsonDieResult($arrays, 200);
    }else{
        jsonDieMsg('No se encontraron registros',404);
    }

}
?>