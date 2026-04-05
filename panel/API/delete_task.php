<?php
include_once('api_head.php');

$ID = validateHttp('ID','post');

if(empty($ID)){
	jsonDieMsg('Missing ID',401,'error');
}

$ID = dec($ID);

$result = ncmDelete('DELETE FROM tasks WHERE ID = ? LIMIT 1', [$ID]);

if($result === false){
	jsonDieMsg($db->ErrorMsg(),401,'error');
}else{
	jsonDieResult(['success' => 'Tarea eliminada']);
}
?>