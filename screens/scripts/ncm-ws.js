/**
 * NcmWS — cliente WebSocket compatible con la API de Pusher.
 *
 * Reemplaza `new Pusher(key, opts)` con `new NcmWS(wsUrl)`.
 * Los handlers existentes (channel.bind / channel.unbind) no cambian.
 *
 * Uso:
 *   var pusher  = new NcmWS(WS_URL);
 *   var channel = pusher.subscribe('outlet123-KDS');
 *   channel.bind('order', function(data) { ... });
 *
 * Reconexión automática con backoff exponencial (máx 30s).
 * Heartbeat ping cada 25s para mantener la conexión viva.
 */
(function(global) {
  'use strict';

  var PING_INTERVAL   = 25000;
  var RECONNECT_BASE  = 1000;
  var RECONNECT_MAX   = 30000;

  // ── NcmChannel ──────────────────────────────────────────────────────────────
  function NcmChannel(name, ws) {
    this.name     = name;
    this._ws      = ws;
    this._handlers = {};
  }

  NcmChannel.prototype.bind = function(event, fn) {
    if (!this._handlers[event]) {
      this._handlers[event] = [];
    }
    this._handlers[event].push(fn);
    return this; // encadenable
  };

  NcmChannel.prototype.unbind = function(event) {
    if (event) {
      delete this._handlers[event];
    } else {
      this._handlers = {};
    }
    return this;
  };

  NcmChannel.prototype._dispatch = function(event, data) {
    var fns = this._handlers[event];
    if (!fns) return;
    for (var i = 0; i < fns.length; i++) {
      try { fns[i](data); } catch(e) { console.error('[NcmWS] handler error:', e); }
    }
  };

  // ── NcmWS ───────────────────────────────────────────────────────────────────
  function NcmWS(url) {
    this._url        = url;
    this._channels   = {};   // name → NcmChannel
    this._ws         = null;
    this._pingTimer  = null;
    this._reconnectDelay = RECONNECT_BASE;
    this._closed     = false;
    this._connect();
  }

  NcmWS.prototype._connect = function() {
    var self = this;
    var ws   = new WebSocket(this._url);
    this._ws = ws;

    ws.onopen = function() {
      console.log('[NcmWS] conectado a', self._url);
      self._reconnectDelay = RECONNECT_BASE;

      // Re-suscribir canales activos (reconexión)
      Object.keys(self._channels).forEach(function(name) {
        self._send({ action: 'subscribe', channel: name });
      });

      // Heartbeat
      self._pingTimer = setInterval(function() {
        if (ws.readyState === WebSocket.OPEN) {
          self._send({ action: 'ping' });
        }
      }, PING_INTERVAL);
    };

    ws.onmessage = function(e) {
      var msg;
      try { msg = JSON.parse(e.data); } catch(err) { return; }

      if (msg.event === 'pong' || msg.event === 'subscribed') return;

      var ch = self._channels[msg.channel];
      if (ch) ch._dispatch(msg.event, msg.data);
    };

    ws.onclose = function() {
      clearInterval(self._pingTimer);
      if (self._closed) return;
      console.log('[NcmWS] desconectado — reconectando en', self._reconnectDelay + 'ms');
      setTimeout(function() { self._connect(); }, self._reconnectDelay);
      self._reconnectDelay = Math.min(self._reconnectDelay * 2, RECONNECT_MAX);
    };

    ws.onerror = function(e) {
      console.warn('[NcmWS] error de conexión');
    };
  };

  NcmWS.prototype._send = function(obj) {
    if (this._ws && this._ws.readyState === WebSocket.OPEN) {
      this._ws.send(JSON.stringify(obj));
    }
  };

  NcmWS.prototype.subscribe = function(channelName) {
    if (!this._channels[channelName]) {
      this._channels[channelName] = new NcmChannel(channelName, this);
    }
    this._send({ action: 'subscribe', channel: channelName });
    return this._channels[channelName];
  };

  NcmWS.prototype.unsubscribe = function(channelName) {
    delete this._channels[channelName];
    this._send({ action: 'unsubscribe', channel: channelName });
  };

  NcmWS.prototype.disconnect = function() {
    this._closed = true;
    clearInterval(this._pingTimer);
    if (this._ws) this._ws.close();
  };

  global.NcmWS = NcmWS;

})(typeof window !== 'undefined' ? window : global);
