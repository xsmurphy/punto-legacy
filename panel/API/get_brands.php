<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
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

	apiOk($arrays);

} else {
	apiNotFound();
}
