<?php
require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

	function getMarangatu($key){
		//BUSCO EN MARANGATU
		$url            = 'https://marangatu.set.gov.py/eset-restful/contribuyentes/consultar?codigoEstablecimiento=1&ruc=';
		$res            = curlContents($url . $key);
		$data           = json_decode($res,true);
		$tin            = '';
		$name           = '';
		$fullName       = '';
		$fullAddress    = '';
		$phone          = '';
	
		$name           = $data['nombre'];
		$tin            = $data['ruc'] . '-' . $data['dv'];
		$fullName       = $data['nombreFantasia'];
		$fullAddress    = $data['direccion'];
		$phone          = $data['telefono'];
	
		return ['id' => $data['ruc'], 'tin' => $tin, 'name' => $name, 'fullName' => $fullName, 'address' => $fullAddress, 'phone' => $phone];
	}

	$id 		= validateHttp('id');
	$country 	= validateHttp('country');
	$out 		= [];

	if(!$id || !$country){
		apiError('El ID y codigo de pais son obligatorios', 404);
	}

	if($country == 'PY'){

		$id 	= iftn(explodes('-', $id,false,0),$id);

		$urlRUC = 'https://servicios.set.gov.py/eset-publico/contribuyente/estado?ruc=' . $id;
		$urlCI 	= 'http://eas.suace.gov.py/eas_suace/api/eas/cedula/' . $id;
		$urlNcm = '/rucs?s=' . $id . '&c=' . $country;

		//$ruc 	= curlContents($urlRUC);

		$marangatu = getMarangatu($id);

		if(validity($marangatu['name'])){
			$out['name'] 		= $marangatu['name'];
			$out['tin'] 		= $marangatu['tin'];
			$out['id'] 			= $marangatu['id'];
			$out['fullName'] 	= $marangatu['fullName'];
			$out['phone'] 		= $marangatu['phone'];
			$out['bday'] 		= $marangatu['bday'];
			$out['address'] 	= $marangatu['address'];
		}else{

			$db->selectDb('ruc_py');

			$ruc = ncmExecute('SELECT * FROM rucs WHERE ruc = ? LIMIT 1',[$id]);

			if(validity($ruc)){
				$out['name'] 		= $ruc['razon'];
				$out['tin'] 		= $ruc['ruc'] . '-' . $ruc['dv'];
				$out['id'] 			= $ruc['ruc'];
				$out['fullName'] 	= '';
				$out['phone'] 		= '';
				$out['bday'] 		= '';
				$out['address'] 	= '';
			}else{//si no encuentro busco como CI
				$ci 	= curlContents($urlCI);

				if(validity($ci)){
					$ci = json_decode($ci,true);

					if($ci['presente'] == true){
						$data 				= $ci['resultado'];

						$out['name'] 		= $data['nombreCompleto'];
						$out['tin'] 		= $data['cedula'];
						$out['id'] 			= $data['cedula'];
						$out['fullName'] 	= $data['nombreCompleto'];
						$out['phone'] 		= '';
						$out['bday'] 		= '';
						$out['address'] 	= '';
					}else{
						apiError('No se encontraron registros', 404);
					}
				}else{
					apiError('No se encontraron registros', 404);
				}
			}

		}

		apiOk($out);
	}

?>