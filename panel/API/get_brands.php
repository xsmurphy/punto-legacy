<?php
include_once('api_head.php');
$cache 				= validateHttp('cache','post') ? validateHttp('cache','post') : false;
$result = ncmExecute("SELECT taxonomyId,taxonomyName FROM taxonomy WHERE taxonomyType = 'brand' AND companyId = ?",[COMPANY_ID],$cache,true);
$arrays 		= [];

if($result){
	while (!$result->EOF) {

		$arrays[] = [
						"ID" 	=> enc($result->fields['taxonomyId']),
						"name" 	=> unXss($result->fields['taxonomyName'])
					];

	    $result->MoveNext(); 
	}
	$result->Close();

	jsonDieResult($arrays,200);
	
}else{
	jsonDieMsg('No se encontraron registros',404);
}


?>