<?php

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => "http://dev.newton.delivery/api/external/getClients",
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => "",
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => "POST",
  CURLOPT_POSTFIELDS =>"{\n\t\"documentNumber\":\"2323432\"\n}",
  CURLOPT_HTTPHEADER => array(
    "Content-Type: application/json",
    "newtontoken: 75s2fg9cxL5nb"
  ),
));

$response = curl_exec($curl);

curl_close($curl);
echo $response;



die();


$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL             => "http://dev.newton.delivery/api/external/getBranches",
  CURLOPT_RETURNTRANSFER  => true,
  CURLOPT_ENCODING        => "",
  CURLOPT_MAXREDIRS       => 10,
  CURLOPT_TIMEOUT         => 0,
  CURLOPT_FOLLOWLOCATION  => true,
  CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST   => "POST",

  CURLOPT_HTTPHEADER => array(
    "Content-Type: application/json",
    "newtontoken: 75s2fg9cxL5nb",
    "Content-Length: 0"
  ),
));

$response = json_decodecurl_exec($curl);

curl_close($curl);
echo $response;



die();





    $curl = curl_init();

    curl_setopt_array($curl, [
      CURLOPT_URL                       => "http://api.develop.monchis.com.py/integration/branches",
      CURLOPT_RETURNTRANSFER            => true,
      CURLOPT_ENCODING                  => "",
      CURLOPT_MAXREDIRS                 => 10,
      CURLOPT_TIMEOUT                   => 0,
      CURLOPT_FOLLOWLOCATION            => true,
      CURLOPT_HTTP_VERSION              => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST             => "GET",
      CURLOPT_POSTFIELDS                => "{\"token\": \"e8266e19686d26d6a9966d0f59a59e1b82f2453a4ad1f4d05f932c57c0d2bcb9\"}",
      CURLOPT_HTTPHEADER                => ["Content-Type: application/json"],
    ]);

    $company = json_decode(curl_exec($curl),true);

    curl_close($curl);

    echo '<pre>';
    print_r($company);
    echo '</pre>';

    $company = $company['data'];   

    foreach ($company as $key => $data) {
        echo 'Nombre de la empresa: ' . $data['name'] . '<br>';
        echo 'ID: ' . $data['id'] . '<br>';
        echo 'Dirección: ' . $data['full_address'] . '<br><br><br>';
    }
?>