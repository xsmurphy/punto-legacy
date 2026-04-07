<?php

require_once __DIR__ . '/lib/api_middleware.php';
apiMiddleware();

if (validateHttp('phone', 'post') && validateHttp('country', 'post') && validateHttp('msg', 'post') && validateHttp('secret', 'post') == NCM_SECRET) {

    $COUNTRY = validateHttp('country', 'post');
    $PHONE   = validateHttp('phone', 'post');
    $MSG     = validateHttp('msg', 'post');
    $MEDIA   = validateHttp('media', 'post');
    $CREDIT  = validateHttp('credit', 'post');

    $MSG      = str_replace(['\n', '\r', '<br>', '</br>'], ['', '', '', ''], $MSG);
    $segments = iftn(SMSSegmentsCounter($MSG), 1);

    $PHONE = json_decode(getFileContent(API_URL . '/phonevalidator.php?phone=' . $PHONE . '&country=' . $COUNTRY . '&format=international'), true);

    if ($PHONE['error']) {
        apiOk(['error' => $PHONE['error']], 500);
    }

    if ($CREDIT > $segments) {

        $phone   = ltrim($PHONE['phone'], '+');
        $baseUrl = rtrim(EVOLUTION_API_URL, '/');
        $instance = EVOLUTION_INSTANCE;

        if ($MEDIA) {
            // Enviar mensaje con imagen/media
            $payload = json_encode([
                'number'  => $phone,
                'mediatype' => 'image',
                'mimetype'  => 'image/jpeg',
                'caption'   => $MSG,
                'media'     => $MEDIA,
            ]);
            $endpoint = $baseUrl . '/message/sendMedia/' . $instance;
        } else {
            // Enviar mensaje de texto
            $payload = json_encode([
                'number'  => $phone,
                'text'    => $MSG,
            ]);
            $endpoint = $baseUrl . '/message/sendText/' . $instance;
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'apikey: ' . EVOLUTION_API_KEY,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            apiOk(['error' => 'Evolution API error', 'detail' => $response], 500);
        }

        // Debito la cantidad de segmentos SMS enviados
        $db->Execute('UPDATE company SET smsCredit = smsCredit - ' . $segments . ' WHERE companyId = ?', [COMPANY_ID]);

        if ($CREDIT == 49 || $CREDIT == 39 || $CREDIT == 29 || $CREDIT == 19) {
            $ops = [
                'title'   => 'Te estás quedando sin crédito SMS',
                'message' => 'Te quedan solo ' . $CREDIT . ' mensajes, contactanos y recarga crédito para evitar interrupciones en los envíos.',
                'type'    => 1,
                'company' => COMPANY_ID,
            ];
            insertNotifications($ops);
        } elseif ($CREDIT == 3) {
            $ops = [
                'title'   => 'Te quedaste sin crédito SMS',
                'message' => 'Contactanos y recarga saldo para continuar interactuando con tus clientes.',
                'type'    => 2,
                'company' => COMPANY_ID,
            ];
            insertNotifications($ops);
        }

        apiOk(['sent' => true]);

    } else {
        apiOk(['error' => 'No credit'], 404);
    }
} else {
    apiOk(['error' => 'Phone, country and message are required'], 500);
}
?>
