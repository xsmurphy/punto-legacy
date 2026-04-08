<?php

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

//obtengo IP de cloudflare
$ip 			= validateHttp('ip');//GET
$lang 			= validateHttp('lang','post');//post

$cloudFlareIP 	= ($ip) ? $ip : $_SERVER["HTTP_CF_CONNECTING_IP"];
$array 			= json_decode( curlContents('http://api.ipapi.com/' . $cloudFlareIP . '?access_key=' . IPAPI_KEY . '&format=1','GET'), true );

if($array['success'] == false){
    $array          = json_decode( curlContents('https://extreme-ip-lookup.com/json/','GET'), true );

    $array['country_code']  = $array['countryCode'];
    $array['latitude']      = $array['lat'];
    $array['longitude']     = $array['lon'];
}

if($lang){
	$array 		= $array["location"]["languages"][0];
}

header('Content-Type: application/json');
http_response_code(200);
echo json_encode($array);

dai();



/*
{
    "ip": "181.238.169.32",
    "type": "ipv4",
    "continent_code": "SA",
    "continent_name": "South America",
    "country_code": "PY",
    "country_name": "Paraguay",
    "region_code": "ASU",
    "region_name": "Asunción",
    "city": "Asunción",
    "zip": "1409",
    "latitude": -25.282199859619140625,
    "longitude": -57.635101318359375,
    "location": {
        "geoname_id": 3439389,
        "capital": "Asunción",
        "languages": [
            {
                "code": "es",
                "name": "Spanish",
                "native": "Español"
            },
            {
                "code": "gn",
                "name": "Guarani",
                "native": "Avañe'ẽ"
            }
        ],
        "country_flag": "http://assets.ipapi.com/flags/py.svg",
        "country_flag_emoji": "🇵🇾",
        "country_flag_emoji_unicode": "U+1F1F5 U+1F1FE",
        "calling_code": "595",
        "is_eu": false
    }
}
*/
?>

