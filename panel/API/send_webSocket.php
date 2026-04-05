<?php
/**
 * Endpoint de publicación de eventos WebSocket.
 *
 * Reemplaza la integración con Pusher por Redis Pub/Sub propio.
 * El servidor Node.js (ws-server/) recibe los eventos y los distribuye
 * a los clientes WebSocket conectados.
 *
 * Parámetros POST:
 *   channel  — nombre del canal (ej: "outlet123-KDS")
 *   event    — nombre del evento (ej: "order", "sale", "update")
 *   message  — payload JSON string o valor simple
 *
 * Compatibilidad: acepta los mismos parámetros que el endpoint Pusher anterior.
 */

include_once('api_head.php');
require_once __DIR__ . '/../includes/ws_publish.php';

$channel = validateHttp('channel', 'post');
$event   = validateHttp('event',   'post');
$message = validateHttp('message', 'post');

if (!$channel || !$event) {
    jsonDieMsg('channel y event son requeridos', 422);
}

// message puede ser JSON string o valor simple
$data = [];
if ($message) {
    $decoded = json_decode($message, true);
    $data = is_array($decoded) ? $decoded : ['message' => $message];
}

wsPublish($channel, $event, $data);

jsonDieResult(['success' => true], 200);
