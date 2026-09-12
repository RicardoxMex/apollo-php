/**
 * Apollo Realtime JS SDK — cliente WebSocket.
 *
 *   const socket = new RealtimeClient({
 *       url: 'ws://localhost:8080',
 *       token: jwt,                  // opcional; se envía como ?token= en el handshake
 *       key: 'app_apollo',           // app_key público (nunca el secreto)
 *       auth: (channel) => fetch('/v1/realtime/auth', {...}).then(r => r.json()),
 *   });
 *
 *   // API top-level (spec §9)
 *   socket.on('notification', (data) => { /* … *\/ });
 *   socket.send({ type: 'ping' });
 *
 *   // API channel-based (BC) — para canales public/private/presence
 *   socket.channel('orders').listen('order.created', (data) => { /* … *\/ });
 *
 * Reconexión con backoff exponencial (1s → 30s) + heartbeat.
 *
 * Protocolo (spec §7): todos los frames son JSON.
 *   server → client: { "event": "<name>", "data": {…} }
 *                     { "type": "event", "channel": "…", "event": "…", "data": {…} }  (BC)
 *                     { "type": "connected", "connection_id": "…", "user_id": … }
 *                     { "type": "pong" }
 *                     { "type": "error", "code": "…", "message": "…" }
 *   client → server: { "type": "subscribe", "channel": "…" }
 *                     { "type": "unsubscribe", "channel": "…" }
 *                     { "type": "ping" }
 *                     { "type": "authenticate" }
 */
(function (global) {
    'use strict';

    const DEFAULT_OPTIONS = {
        url: '',
        key: 'app_apollo',
        token: null,                  // JWT para autenticar la conexión (?token=<jwt>)
        maxReconnectAttempts: 10,
        reconnectBaseDelayMs: 1000,
        maxReconnectDelayMs: 30000,
        heartbeatIntervalMs: 15000,
        auth: null,                   // (channel) => Promise<{auth, user_id, user_info}>
        debug: false,
    };

    function nowIso() { return new Date().toISOString(); }
    function log(enabled, ...args) { if (enabled) { try { console.log('[Realtime]', nowIso(), ...args); } catch (e) {} } }

    class RealtimeClient {
        constructor(options) {
            this.options = Object.assign({}, DEFAULT_OPTIONS, options || {});
            this.socket = null;
            this.connectionId = null;
            this.userId = null;
            this.channels = new Map();   // name -> Channel
            this.listeners = new Map();  // event name -> [fn]
            this.manuallyClosed = false;
            this.reconnectAttempts = 0;
            this.heartbeatTimer = null;
            this.connected = false;

            if (this.options.url) {
                this.connect();
            }
        }

        /**
         * Conectar al servidor WebSocket.
         */
        connect() {
            if (this.socket && (this.socket.readyState === WebSocket.OPEN || this.socket.readyState === WebSocket.CONNECTING)) {
                return;
            }

            if (!this.options.url) {
                log(this.options.debug, 'connect() sin url');
                return;
            }

            const url = this.buildUrl();
            log(this.options.debug, 'connecting to', url);
            this.socket = new WebSocket(url);

            this.socket.onopen = () => {
                this.connected = true;
                this.reconnectAttempts = 0;
                log(this.options.debug, 'connected');
                this.startHeartbeat();
                // Re-suscribir canales tras reconexión
                this.channels.forEach((ch) => {
                    if (typeof ch.resubscribe === 'function') {
                        ch.resubscribe();
                    }
                });
            };

            this.socket.onmessage = (event) => this.handleMessage(event.data);

            this.socket.onclose = (ev) => {
                this.connected = false;
                this.stopHeartbeat();
                log(this.options.debug, 'closed', ev && ev.code, ev && ev.reason);

                if (!this.manuallyClosed) {
                    this.scheduleReconnect();
                }
            };

            this.socket.onerror = (err) => {
                log(this.options.debug, 'error', err);
                if (this.socket) {
                    try { this.socket.close(); } catch (e) { /* noop */ }
                }
            };
        }

        /**
         * Cierra la conexión manualmente.
         */
        disconnect() {
            this.manuallyClosed = true;
            this.stopHeartbeat();
            if (this.socket) {
                try { this.socket.close(); } finally { this.socket = null; }
            }
        }

        /**
         * Registra un listener para un evento (top-level).
         *   socket.on('notification', (data) => ...)
         */
        on(event, fn) {
            if (!this.listeners.has(event)) {
                this.listeners.set(event, []);
            }
            this.listeners.get(event).push(fn);
            return this;
        }

        /**
         * Quita un listener. Si no se pasa fn, quita todos los del evento.
         */
        off(event, fn) {
            if (!this.listeners.has(event)) {
                return this;
            }
            if (!fn) {
                this.listeners.delete(event);
                return this;
            }
            const arr = this.listeners.get(event).filter((f) => f !== fn);
            this.listeners.set(event, arr);
            return this;
        }

        /**
         * Alias de send(): enviar un frame JSON al servidor.
         */
        emit(event, data) {
            return this.send({ event: event, data: data });
        }

        /**
         * Envía un objeto arbitrario (serializado a JSON) al servidor.
         */
        send(obj) {
            if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                this.socket.send(JSON.stringify(obj));
                return true;
            }
            return false;
        }

        /**
         * API channel-based (BC). Devuelve un Channel para suscribirse a un canal.
         */
        channel(name) {
            if (!this.channels.has(name)) {
                this.channels.set(name, new Channel(this, name));
            }
            return this.channels.get(name);
        }

        presence(name) {
            return this.channel(name);
        }

        // ---------- internos ----------

        buildUrl() {
            const base = this.options.url;
            const params = [];
            if (this.options.key) {
                params.push('key=' + encodeURIComponent(this.options.key));
            }
            if (this.options.token) {
                params.push('token=' + encodeURIComponent(this.options.token));
            }
            if (params.length === 0) {
                return base;
            }
            return base + (base.includes('?') ? '&' : '?') + params.join('&');
        }

        handleMessage(raw) {
            let msg;
            try {
                msg = JSON.parse(raw);
            } catch (e) {
                log(this.options.debug, 'invalid JSON from server');
                return;
            }

            // Conectado / autenticado
            if (msg.type === 'connected') {
                this.connectionId = msg.connection_id || null;
                this.userId = msg.user_id || null;
                this.emitLocal('connected', msg);
                return;
            }

            // Heartbeat
            if (msg.type === 'pong' || msg.type === 'ping') {
                return;
            }

            // Errores
            if (msg.type === 'error') {
                this.emitLocal('error', msg);
                return;
            }

            // Evento channel-based (BC): {type:'event', channel, event, data}
            if (msg.type === 'event' && msg.channel && msg.event) {
                this.emitLocal(msg.event, msg.data, { channel: msg.channel, event: msg.event });
                this.emitChannel(msg.channel, msg.event, msg.data);
                return;
            }

            // Frame top-level spec §7: {event, data}
            if (msg.event && msg.data !== undefined) {
                this.emitLocal(msg.event, msg.data, { event: msg.event });
                return;
            }

            // subscribed / unsubscribed (BC)
            if ((msg.type === 'subscribed' || msg.type === 'unsubscribed') && msg.channel) {
                this.emitChannel(msg.channel, msg.type, msg);
                return;
            }
        }

        emitLocal(event, data, meta) {
            const arr = this.listeners.get(event) || [];
            for (let i = 0; i < arr.length; i++) {
                try { arr[i](data, meta || {}); } catch (e) { log(this.options.debug, 'listener error', event, e); }
            }
            // Catch-all '*'
            const any = this.listeners.get('*') || [];
            for (let i = 0; i < any.length; i++) {
                try { any[i](event, data, meta || {}); } catch (e) { /* noop */ }
            }
        }

        emitChannel(channel, event, data) {
            const ch = this.channels.get(channel);
            if (ch && typeof ch.emit === 'function') {
                ch.emit(event, data);
            }
        }

        scheduleReconnect() {
            if (this.manuallyClosed || this.reconnectAttempts >= this.options.maxReconnectAttempts) {
                log(this.options.debug, 'no reconnect (manuallyClosed o intentos máximos)');
                return;
            }

            const delay = Math.min(
                this.options.reconnectBaseDelayMs * Math.pow(2, this.reconnectAttempts),
                this.options.maxReconnectDelayMs
            );
            this.reconnectAttempts += 1;
            log(this.options.debug, 'reconnect in', delay, 'ms (attempt', this.reconnectAttempts, ')');
            setTimeout(() => this.connect(), delay);
        }

        startHeartbeat() {
            this.stopHeartbeat();
            this.heartbeatTimer = setInterval(() => {
                if (this.connected && this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.send({ type: 'ping', time: Date.now() });
                }
            }, this.options.heartbeatIntervalMs);
        }

        stopHeartbeat() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
        }
    }

    /**
     * Channel (BC) — suscripción y listeners por canal.
     */
    class Channel {
        constructor(client, name) {
            this.client = client;
            this.name = name;
            this.isPrivate = name.startsWith('private-');
            this.isPresence = name.startsWith('presence-');
        }

        listen(event, fn) {
            return this.client.on(this.name + ':' + event, fn);
        }

        on(event, fn) {
            return this.listen(event, fn);
        }

        async subscribe() {
            const frame = { type: 'subscribe', channel: this.name };

            if (this.isPrivate || this.isPresence) {
                const auth = typeof this.client.options.auth === 'function'
                    ? await this.client.options.auth(this.name)
                    : null;

                if (auth && auth.auth) {
                    frame.auth = auth.auth;
                    frame.user_id = auth.user_id != null ? auth.user_id : null;
                    frame.user_info = auth.user_info || {};
                }
            }

            this.client.send(frame);
            return this;
        }

        resubscribe() {
            return this.subscribe();
        }

        unsubscribe() {
            this.client.send({ type: 'unsubscribe', channel: this.name });
            return this;
        }

        emit(event, data) {
            this.client.emitLocal(this.name + ':' + event, data, { channel: this.name, event: event });
        }
    }

    // Auto-conexión si hay atributos data-realtime-url / data-realtime-token en el DOM
    if (typeof document !== 'undefined') {
        const auto = document.querySelector('[data-realtime-url]');
        if (auto) {
            new RealtimeClient({
                url: auto.getAttribute('data-realtime-url'),
                key: auto.getAttribute('data-realtime-key') || 'app_apollo',
                token: auto.getAttribute('data-realtime-token') || null,
            });
        }
    }

    global.RealtimeClient = RealtimeClient;
})(typeof window !== 'undefined' ? window : globalThis);
