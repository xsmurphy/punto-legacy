<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$record 	= [];
$ID         = validateHttp('ID','post');
    
if(!validity($ID)){
    apiOk(['error' => 'Ingrese un ID válido'], 403);
}

$ID     = dec($ID);
$result = ncmExecute('SELECT * FROM banks WHERE bankId = ? AND companyId = ? LIMIT 1', [$ID, COMPANY_ID]);

if($result){
    $delete = ncmExecute('DELETE FROM banks WHERE bankId = ? AND companyId = ? LIMIT 1', [$ID, COMPANY_ID]);

    if(!$delete){
        apiOk(['error' => 'No se pudo actualizar']);
    }else{
        apiOk(['success' => 'Banco actualizado']);
    }
}else{
	apiOk(['error' => 'No se encontraron datos', 'failed' => $result], 404);
}

?>