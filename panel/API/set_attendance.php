<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

//obtengo IP de cloudflare
$hash 			= validateHttp('token','post');
$user 			= validateHttp('user','post');
$outlet			= validateHttp('outlet','post');

$iHash 			= md5( enc(COMPANY_ID) . $outlet );
$action 		= ['error','not found'];

if(!$hash || !$user){
	apiOk(['error' => 'missing data'], 404);
}

if($hash === $iHash){
	$user 	= dec($user);
	$outlet = dec($outlet);
	$type 	= 'open';

	$result = ncmExecute(	"SELECT *
                            FROM attendance 
                            WHERE userId  = ?
                            AND outletId  = ?
                            AND companyId = ?
                            AND (attendanceCloseDate IS NULL OR attendanceCloseDate < '2000-01-01 00:00:00') 
                            ORDER BY attendanceOpenDate DESC
                            LIMIT 1",
                            [$user,$outlet,COMPANY_ID] 
                        );

	if($result){ //quiere decir que hay una entrada
		$type 								= 'closed';
		$record['attendanceCloseDate']    	= TODAY;
		$record['userId']    				= $user;

		$action = ncmUpdate(['records' => $record, 'table' => 'attendance', 'where' => 'attendanceId = ' . $result['attendanceId']]);

	}else{
		$type 								= 'open';
		$record['attendanceOpenDate']    	= TODAY;
		$record['userId']    				= $user;
		$record['outletId']    				= $outlet;
		$record['companyId']    			= COMPANY_ID;

		$action = ncmInsert(['records' => $record, 'table' => 'attendance']);

		if(!$action){
			$action = ['error' => 'not inserted'];
		}else{
			$action = ['error' => false];
		}
	}

    apiOk(['error' => $action['error'], 'type' => $type]);

}else{
	apiOk(['error'=>'Codigo incorrecto'], 404);
}
?>