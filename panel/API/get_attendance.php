<?php
include_once('api_head.php');

$user 			= validateHttp('user','post');
$outlet			= validateHttp('outlet','post');
$start			= validateHttp('from','post');
$end			= validateHttp('to','post');
$limit			= validateHttp('limit','post') ? validateHttp('limit','post') : 100;
$limit 			= intval($limit);

if(!$outlet || !$user || !$start || !$end){
	jsonDieResult(['error' => 'missing data'],404);
}

$outlet = dec($outlet);
$user 	= dec($user);
$out 	= [];
$hourSalary = 0;

$result = ncmExecute(	"SELECT *
                            FROM attendance 
                            WHERE userId  = ?
                            AND outletId  = ?
                            AND companyId = ?
                            AND attendanceCloseDate IS NOT NULL 
                            AND attendanceOpenDate
                            BETWEEN ? 
                            AND ?
                            LIMIT " . $limit,
                            [$user,$outlet,COMPANY_ID,$start,$end],false,true 
                        );

$resultC = ncmExecute("SELECT data FROM contact WHERE contactId = ? LIMIT 1", [$user]);

if($resultC){
	$data 		= json_decode($resultC['data'],true);
	$hourSalary = $data['hourSalary'];
}

if($result){
	while (!$result->EOF) {
		$fields 	= $result->fields;

		/*$timestamp1 = strtotime( $fields['attendanceOpenDate'] );
		$timestamp2 = strtotime( $fields['attendanceCloseDate'] );
		$hour 		= abs($timestamp2 - $timestamp1) / (60 * 60);*/

		$datetime1 	= new DateTime($fields['attendanceOpenDate']);//start time
		$datetime2 	= new DateTime($fields['attendanceCloseDate']);//end time
		$interval 	= $datetime1->diff($datetime2);

		$hour 		= $interval->format('%H');
		$minutes	= $interval->format('%i');

		$out[] 		=	[
							'id' 		=> enc($fields['attendanceId']),
							'in' 		=> $fields['attendanceOpenDate'],
							'out' 		=> $fields['attendanceCloseDate'],
							'duration' 	=> $hour,
							'hours' 	=> $hour,
							'minutes' 	=> $minutes,
							'hourSalary'=> $hourSalary
						];
		

		$result->MoveNext(); 
	}
	$result->Close();
}else{
	$out = ['error' => 'no data'];
}

jsonDieResult($out,200);
?>