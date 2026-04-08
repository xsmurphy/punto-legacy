<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$ID 		= validateHttp('ID','post');
$UID 		= validateHttp('UID','post');
$field 		= 'contactId';

if($UID && !$ID){
	$ID 	= $UID;
	$field 	= 'contactId';
}

if(!$ID){
	apiOk([ 'error'=>'El ID es obligatorio' ], 500);
}

if($ID == 'all'){
	$delete = ncmExecute('DELETE FROM contact WHERE type = 1 AND companyId = ? LIMIT 1000', [COMPANY_ID]); 
}else{
	$delete = ncmExecute('DELETE FROM contact WHERE ' . $field . ' = ? AND companyId = ? LIMIT 1', [ dec($ID), COMPANY_ID ]); 
}

if($delete !== false){
	apiOk([ 'success' => 'Cliente eliminado' ]);
}else{
	apiOk([ 'error' => 'No se pudo eliminar el cliente' ], 500);
}

?>