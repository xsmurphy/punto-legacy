<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$ID = validateHttp('ID','post');

if(empty($ID)){
	apiError('Missing ID', 401);
}

$ID = dec($ID);

$result = ncmDelete('DELETE FROM tasks WHERE ID = ? LIMIT 1', [$ID]);

if($result === false){
	apiError($db->ErrorMsg(), 401);
}else{
	apiOk(['success' => 'Tarea eliminada']);
}
?>