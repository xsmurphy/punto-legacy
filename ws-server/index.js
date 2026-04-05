/**
 * Punto POS — WebSocket Server
 *
 * Arquitectura:
 *   PHP (panel/app) → Redis Pub/Sub → este servidor → clientes WebSocket
 *
 * Canales usados (mismo esquema que Pusher actual):
 *   {outletId}-KDS          → Kitchen Display System
 *   {outletId}-register     → POS register updates
 *   {companyId}-{regId}-register     → register session events
 *   {companyId}-{regId}-registerSession
 *   {companyId}             → company-wide events
 *
 * Protocolo cliente → servidor:
 *   { "action": "subscribe",   "channel": "<nombre>" }
 *   { "action": "unsubscribe", "channel": "<nombre>" }
 *
 * Protocolo servidor → cliente:
 *   { "event": "<nombre>", "channel": "<nombre>", "data": { ... } }
 *   { "event": "error",    "message": "..." }
 *   { "event": "pong" }
 */

'use strict';

const WebSocket = require('ws');
const Redis     = require('ioredis');

// ── Configuración ─────────────────────────────────────────────────────────────
const PORT        = parseInt(process.env.WS_PORT  ?? '6001', 10);
const REDIS_URL   = process.env.REDIS_URL ?? 'redis://127.0.0.1:6379';
const REDIS_PREFIX = 'punto:channel:';        // prefijo para todos los canales
const PING_INTERVAL_MS = 30_000;              // heartbeat cada 30s
const MAX_CHANNELS_PER_CLIENT = 20;

// ── Redis ─────────────────────────────────────────────────────────────────────
const publisher  = new Redis(REDIS_URL, { lazyConnect: true });
const subscriber = new Redis(REDIS_URL, { lazyConnect: true });

// canal → Set<WebSocket>
const channelClients = new Map();

// ws → Set<string> (canales suscritos por este cliente)
const clientChannels = new WeakMap();

subscriber.on('message', (redisChannel, raw) => {
  const channelName = redisChannel.slice(REDIS_PREFIX.length);
  const clients = channelClients.get(channelName);
  if (!clients || clients.size === 0) return;

  let payload;
  try {
    payload = JSON.parse(raw);
  } catch {
    console.warn(`[ws] mensaje no-JSON en canal ${channelName}`);
    return;
  }

  const outgoing = JSON.stringify({
    event:   payload.event   ?? 'message',
    channel: channelName,
    data:    payload.data    ?? payload,
  });

  clients.forEach(ws => {
    if (ws.readyState === WebSocket.OPEN) {
      ws.send(outgoing);
    }
  });
});

subscriber.on('error', err => console.error('[redis subscriber]', err.message));
publisher.on('error',  err => console.error('[redis publisher]',  err.message));

// ── WebSocket server ──────────────────────────────────────────────────────────
const wss = new WebSocket.Server({ port: PORT });

wss.on('listening', () => {
  console.log(`[ws] servidor escuchando en ws://0.0.0.0:${PORT}`);
});

wss.on('connection', (ws, req) => {
  const ip = req.headers['x-forwarded-for'] ?? req.socket.remoteAddress;
  console.log(`[ws] cliente conectado desde ${ip}  (total: ${wss.clients.size})`);

  clientChannels.set(ws, new Set());
  ws.isAlive = true;

  ws.on('pong', () => { ws.isAlive = true; });

  ws.on('message', raw => {
    let msg;
    try {
      msg = JSON.parse(raw);
    } catch {
      ws.send(JSON.stringify({ event: 'error', message: 'Mensaje inválido — se esperaba JSON' }));
      return;
    }

    switch (msg.action) {
      case 'subscribe':
        handleSubscribe(ws, msg.channel);
        break;
      case 'unsubscribe':
        handleUnsubscribe(ws, msg.channel);
        break;
      case 'ping':
        ws.send(JSON.stringify({ event: 'pong' }));
        break;
      default:
        ws.send(JSON.stringify({ event: 'error', message: `Acción desconocida: ${msg.action}` }));
    }
  });

  ws.on('close', () => {
    cleanup(ws);
    console.log(`[ws] cliente desconectado  (total: ${wss.clients.size})`);
  });

  ws.on('error', err => {
    console.error(`[ws] error de cliente: ${err.message}`);
  });
});

// ── Heartbeat ─────────────────────────────────────────────────────────────────
const heartbeat = setInterval(() => {
  wss.clients.forEach(ws => {
    if (!ws.isAlive) {
      cleanup(ws);
      return ws.terminate();
    }
    ws.isAlive = false;
    ws.ping();
  });
}, PING_INTERVAL_MS);

wss.on('close', () => clearInterval(heartbeat));

// ── Helpers ───────────────────────────────────────────────────────────────────
function handleSubscribe(ws, channel) {
  if (!channel || typeof channel !== 'string') {
    ws.send(JSON.stringify({ event: 'error', message: 'canal inválido' }));
    return;
  }

  const subs = clientChannels.get(ws) ?? new Set();

  if (subs.size >= MAX_CHANNELS_PER_CLIENT) {
    ws.send(JSON.stringify({ event: 'error', message: 'límite de canales alcanzado' }));
    return;
  }

  if (subs.has(channel)) return; // ya suscrito

  subs.add(channel);
  clientChannels.set(ws, subs);

  if (!channelClients.has(channel)) {
    channelClients.set(channel, new Set());
    // Suscribir a Redis solo cuando hay al menos un cliente
    subscriber.subscribe(REDIS_PREFIX + channel, err => {
      if (err) console.error(`[redis] error al suscribirse a ${channel}:`, err.message);
      else console.log(`[redis] suscrito al canal: ${channel}`);
    });
  }

  channelClients.get(channel).add(ws);

  ws.send(JSON.stringify({ event: 'subscribed', channel }));
}

function handleUnsubscribe(ws, channel) {
  if (!channel) return;

  const subs = clientChannels.get(ws);
  if (!subs) return;

  subs.delete(channel);

  const clients = channelClients.get(channel);
  if (clients) {
    clients.delete(ws);
    if (clients.size === 0) {
      channelClients.delete(channel);
      // Desuscribir de Redis cuando no quedan clientes
      subscriber.unsubscribe(REDIS_PREFIX + channel);
      console.log(`[redis] desuscrito del canal: ${channel}`);
    }
  }
}

function cleanup(ws) {
  const subs = clientChannels.get(ws);
  if (!subs) return;

  subs.forEach(channel => {
    const clients = channelClients.get(channel);
    if (clients) {
      clients.delete(ws);
      if (clients.size === 0) {
        channelClients.delete(channel);
        subscriber.unsubscribe(REDIS_PREFIX + channel);
      }
    }
  });
}

// ── Graceful shutdown ─────────────────────────────────────────────────────────
process.on('SIGTERM', shutdown);
process.on('SIGINT',  shutdown);

function shutdown() {
  console.log('[ws] apagando servidor...');
  clearInterval(heartbeat);
  wss.close(() => {
    subscriber.quit();
    publisher.quit();
    process.exit(0);
  });
}

// ── Conectar Redis ────────────────────────────────────────────────────────────
Promise.all([subscriber.connect(), publisher.connect()])
  .then(() => console.log('[redis] conectado'))
  .catch(err => {
    console.error('[redis] error de conexión:', err.message);
    process.exit(1);
  });
