<?php

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

$tags       = [];
$where      = validateHttp('where', 'post');
$externalId = validateHttp('ids', 'post');
$title      = iftn(validateHttp('title', 'post'), false);
$message    = validateHttp('message', 'post');
$appLink    = iftn(validateHttp('app_url', 'post'), false);
$webLink    = iftn(validateHttp('web_url', 'post'), false);
$filters    = json_decode(stripslashes(validateHttp('filters', 'post')), true);
$edata      = json_decode(stripslashes(validateHttp('edata', 'post')), true);

if (validateHttp('secret', 'post') == NCM_SECRET && $externalId) {

    if ($filters) {
        foreach ($filters as $value) {
            $tags[] = [
                'field'    => 'tag',
                'key'      => $value['key'],
                'relation' => iftn($value['rel'], '='),
                'value'    => $value['value'],
            ];
        }
    }

    // App IDs y auth tokens desde .env
    // Formato en .env: ONESIGNAL_APP_ID_CAJA, ONESIGNAL_AUTH_CAJA, etc.
    $appId   = $_ENV['ONESIGNAL_APP_ID_CAJA']   ?? '';
    $appAuth = $_ENV['ONESIGNAL_AUTH_CAJA']      ?? '';

    if ($where == 'panel') {
        $appId   = $_ENV['ONESIGNAL_APP_ID_PANEL'] ?? '';
        $appAuth = $_ENV['ONESIGNAL_AUTH_PANEL']   ?? '';
    } elseif ($where == 'ecom') {
        $appId   = $_ENV['ONESIGNAL_APP_ID_ECOM']  ?? '';
        $appAuth = $_ENV['ONESIGNAL_AUTH_ECOM']    ?? '';
    }

    if (!is_array($externalId)) {
        $externalId = [$externalId];
    }

    $data = [
        'app_id'                    => $appId,
        'include_external_user_ids' => $externalId,
        'large_icon'                => '/images/iconincomesm.png',
        'contents'                  => ['en' => $message],
        'headings'                  => ['en' => $title],
        'filters'                   => $tags,
        'web_url'                   => $webLink,
        'app_url'                   => $appLink,
        'data'                      => $edata,
    ];

    $header = [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Basic ' . $appAuth,
    ];

    $push = curlContents('https://onesignal.com/api/v1/notifications', 'POST', json_encode($data), $header);
    $push = json_decode($push, true);

    if ($push['errors']) {
        apiOk(['error' => $push['errors'], 'sent' => $data], 500);
    }

    apiOk(['sent' => $push]);
} else {
    apiOk(['error' => 'Missing Push Data'], 500);
}
?>
