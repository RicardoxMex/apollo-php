/**
 * Apollo Realtime JS SDK — cliente WebSocket mínimo.
 *
 *   const realtime = new RealtimeClient({
 *       url: 'ws://localhost:8080',
 *       key: 'app_apollo',            // app_key público (nunca el secreto)
 *       auth: (channel) => fetch('/v1/realtime/auth', {...}).then(r => r.json()),
 *   });
 *
 *   realtime.channel('orders').listen('order.created', (data) => console.log(data));
 *   realtime.presence('presence-chat.1').on('member.joined', (member) => ...);
 */
(function (global) {
    'use strict';

    const DEFAULT_OPTIONS = {
        key: 'app_apollo',
        maxReconnectAttempts: 10,
        reconnectBaseDelayMs: 1000,
        maxReconnectDelayMs: 30000,
        heartbeatIntervalMs: 15000,
        auth: null, // (channel) => Promise<{auth, user_id}>
    };

    class RealtimeClient {
        constructor(options) {
            this.options = Object.assign({}, DEFAULT_OPTIONS, options || {});
            this.socket = null;
            this.connectionId = null;
            this.channels = new Map();   // name -> Channel
            this.listeners = new Map();  // channel -> { event -> [fn] }
            this.manuallyClosed = false;
            this.reconnectAttempts = 0;
            this.heartbeatTimer = null;

            if (this.options.key && this.options.url) {
                this.connect();
            }
        }

        connect() {
            if (this.socket && (this.socket.readyState === WebSocket.OPEN || this.socket.readyState === WebSocket.CONNECTING)) {
                return;
            }

            const url = this.options.url + (this.options.url.includes('?') ? '&' : '?') + 'key=' + encodeURIComponent(this.options.key);
            this.socket = new WebSocket(url);

            this.socket.onopen = () => {
                this.connected = true;
                this.reconnectAttempts = 0;
                this.startHeartbeat();
                // Re-suscribir canales tras reconexión
                this.channels.forEach((ch) => ch.resubscribe());
            };

            this.socket.onmessage = (event) => this.handleMessage(event.data);

            this.socket.onclose = () => {
                this.connected = false;
                this.stopHeartbeat();

                if (!this.manuallyClosed) {
                    this.scheduleReconnect();
                }
            };

            this.socket.onerror = () => {
                if (this.socket) {
                    try { this.socket.close(); } catch (e) { /* noop */ }
                }
            };
        }

        handleMessage(raw) {
            let msg;
            try {
                msg = JSON.parse(raw);
            } catch (e) {
                return;
            }

            if (msg.type === 'connected') {
                this.connectionId = msg.connection_id;
                return;
            }

            if (msg.type === 'pong' || msg.type === 'ping') {
                return;
            }

            if (msg.type === 'event' || msg.type === 'presence') {
                this.emit(msg.channel, msg.event, msg.data);
                return;
            }

            if (msg.type === 'subscribed' && this.channels.has(msg.channel)) {
                this.channels.get(msg.channel).emit('subscribed', msg);
            }

            if (msg.type === 'error') {
                this.emit('*', 'error', msg);
            }
        }

        channel(name) {
            if (!this.channels.has(name)) {
                this.channels.set(name, new Channel(this, name));
            }
            return this.channels.get(name);
        }

        presence(name) {
            return this.channel(name);
        }

        disconnect() {
            this.manuallyClosed = true;
            this.stopHeartbeat();
            if (this.socket) {
                try { this.socket.close(); } finally { this.socket = null; }
            }
        }

        scheduleReconnect() {
            if (this.manuallyClosed || this.reconnectAttempts >= this.options.maxReconnectAttempts) {
                return;
            }

            const delay = Math.min(
                this.options.reconnectBaseDelayMs * Math.pow(2, this.reconnectAttempts),
                this.options.maxReconnectDelayMs
            );
            this.reconnectAttempts += 1;
            setTimeout(() => this.connect(), delay);
        }

        startHeartbeat() {
            this.stopHeartbeat();
            this.heartbeatTimer = setInterval(() => {
                if (this.connected && this.socket && this.socket.readyState === WebSocket.OPEN) {
                    this.sendFrame({ type: 'ping', time: Date.now() });
                }
            }, this.options.heartbeatIntervalMs);
        }

        stopHeartbeat() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
        }

        sendFrame(frame) {
            if (this.socket && this.socket.readyState === WebSocket.OPEN) {
                this.socket.send(JSON.stringify(frame));
            }
        }

        emit(channel, event, data) {
            const listeners = this.listeners.get(channel);
            if (listeners && listeners.get(event)) {
                listeners.get(event).forEach((fn) => fn(data, { channel, event }));
            }

            // Catch-all por evento
            const any = this.listeners.get('*');
            if (any && any.get(event)) {
                any.get(event).forEach((fn) => fn(data, { channel, event }));
            }
        }

        on(channel, event, fn) {
            if (!this.listeners.has(channel)) {
                this.listeners.set(channel, new Map());
            }
            if (!this.listeners.get(channel).has(event)) {
                this.listeners.get(channel).set(event, []);
            }
            this.listeners.get(channel).get(event).push(fn);
            return this;
        }
    }

    class Channel {
        constructor(client, name) {
            this.client = client;
            this.name = name;
            this.isPrivate = name.startsWith('private-');
            this.isPresence = name.startsWith('presence-');
        }

        listen(event, fn) {
            return this.client.on(this.name, event, fn);
        }

        on(event, fn) { // alias (presence member.joined/left)
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

            this.client.sendFrame(frame);
        }

        resubscribe() {
            return this.subscribe();
        }

        unsubscribe() {
            this.client.sendFrame({ type: 'unsubscribe', channel: this.name });
            return this;
        }

        emit(event, data) {
            this.client.emit(this.name, event, data);
        }
    }

    // Conveniencia: auto-connect con data attributes
    const auto = typeof document !== 'undefined' && document.querySelector('[data-realtime-url]');
    if (auto) {
        new RealtimeClient({
            url: auto.getAttribute('data-realtime-url'),
            key: auto.getAttribute('data-realtime-key') || 'app_apollo',
        });
    }

    global.RealtimeClient = RealtimeClient;
})(typeof window !== 'undefined' ? window : globalThis);