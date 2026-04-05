<?php
include_once('api_head.php');

$ID 		= validateHttp('ID','post');
$UID 		= validateHttp('UID','post');
$field 		= 'contactId';

if($UID && !$ID){
	$ID 	= $UID;
	$field 	= 'contactUID';
}

if(!$ID){
	jsonDieResult([ 'error'=>'El ID es obligatorio' ], 500);
}

if($ID == 'all'){
	$delete = ncmExecute('DELETE FROM contact WHERE type = 1 AND companyId = ? LIMIT 1000', [COMPANY_ID]); 
}else{
	$delete = ncmExecute('DELETE FROM contact WHERE ' . $field . ' = ? AND companyId = ? LIMIT 1', [ dec($ID), COMPANY_ID ]); 
}

if($delete !== false){
	jsonDieResult([ 'success' => 'Cliente eliminado' ], 200);
}else{
	jsonDieResult([ 'error' => 'No se pudo eliminar el cliente' ], 500);
}

?>