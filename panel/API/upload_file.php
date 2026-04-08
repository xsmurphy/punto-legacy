<?php
use Aws\S3\S3Client;

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();
//fala ver como obtengo el contenido del archivo porque AWS pide el contenido por separado

$data 			= validateHttp('data','post');

if(isJson($data)){
	$data = stripslashes($data);
	$data = json_decode($data,true);
}

if($data['secret'] != NCM_SECRET){
	apiOk([ 'error' => 'Acceso denegado' ]);
}

$allowedExt = ['pdf','jpg','png'];

if( $data['ext'] && $data['body'] && $data['action'] ){//lo esencial

	if(!in_array($data['ext'], $allowedExt)){//valido extension
		apiOk([ 'error' => 'Extensión no reconocida' ]);
	}

	$folder 	= 'companyFiles/' . ECOMPANY_ID . '/';
	$ext 		= $data['ext'];
	$bucket 	= 'ncmaspace';
	$fileContent= $data['body'];

	$client = new Aws\S3\S3Client([
	        'version' 		=> 'latest',
	        'region'  		=> 'us-east-1',
	        'endpoint' 		=> '/assets',
	        'credentials' 	=> [
	                'key'    	=> DO_SPACES_ACCESS,
	                'secret' 	=> DO_SPACES_SECRET,
	            ]
	]);

	if($data['action'] == 'add' && $data['sourceId']){//si es agregar valido el sourceId
		$record['filesType'] 	= strip_tags( $data['ext'] );
		$record['sourceId'] 	= dec($data['sourceId']);
		$record['companyId'] 	= COMPANY_ID;
		$insert 				= ncmInsert(['table' => 'files', 'records' => $record]);

		if($insert){
			$fileName = $folder . enc($insert) . '.' . $ext;

			try {
			    // Upload data.
			    $result = $client->putObject([
					'Bucket' => $bucket,
					'Key'    => $fileName,
					'Body'   => $fileContent,
					'ACL'    => $data['private'] ? 'private' : 'public-read'
				]); //'ACL'    => 'private' //para archivos privados

				apiOk([ 'success' => $result['ObjectURL'] ]);
			    
			} catch (S3Exception $e) {
				ncmExecute('DELETE FROM files WHERE filesId = ? AND companyId = ? LIMIT 1',[$insert,COMPANY_ID]);
			    apiOk([ 'error' => $e->getMessage() ]);
			}

		}else{
			apiOk([ 'error' => 'No se pudo guardar el archivo' ]);
		}

	}else if($data['action'] == 'delete'){
		$fileName 	= $folder . $data['name'] . '.' . $ext;

		try {
			$result = $client->deleteObject([
			    'Bucket' => $bucket,
			    'Key'	 => $fileName,
			]);

		    apiOk([ 'success' => true ]);
		} catch (S3Exception $e) {
		    apiOk([ 'error' => $e->getMessage() ]);
		}
	}

}else{
	apiOk(['error'=>"Información faltante","post"=>$data], 500);
}
?>