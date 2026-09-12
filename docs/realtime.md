# Módulo Realtime / WebSockets / Notificaciones

El módulo de **tiempo real** de Apollo vive en el **core** (`core/Realtime/`): no es una app opcional, es parte del framework. Provee:

- **Servidor WebSocket** sobre Workerman (paquete Composer, sin extensión PHP nativa; compatible con Windows y Linux).
- **Abstracción `EventBus`** (`local` en proceso, `redis` para multi-instancia) y canales `public`/`private` (ticket HMAC) / `presence`.
- **`NotificationService::sendToUser()`** — API de alto nivel para crear notificaciones (persiste en la tabla `notifications`, migración 009; el servidor las entrega vía polling de BD a los clientes WebSocket conectados).
- **CLI** `realtime:{start,stop,restart,status,test}` y scripts `composer websocket*`.
- **SDK JS** en `public/js/realtime.js` (reconexión con backoff + heartbeat + API top-level `on/emit/off` + token JWT en handshake).

> **¿Cómo expongo una API REST de notificaciones para mi proyecto?** El framework NO incluye una app REST de notificaciones pre-registrada (a diferencia de `apps/ApolloAuth` o `apps/Users`). El módulo core provee la lógica (`NotificationService`, `NotificationRepository`, `ChannelAuthenticator`); tú construyes los endpoints HTTP en **tu propia app** (ver [`docs/websockets.md` §13 — Guía de integración REST](websockets.md#13-guía-de-integración-rest-de-notificaciones) y el ejemplo completo). Esto mantiene el core reusable y evita acoplar el framework a rutas concretas.

---

## Componentes del core

```
core/Realtime/
├── Auth/
│   ├── ChannelAuthenticator.php       (HMAC para canales private/presence)
│   └── ConnectionAuthenticator.php    (JWT en handshake WS)
├── Bus/
│   ├── LocalEventBus.php              (in-memory)
│   └── RedisEventBus.php              (Pub/Sub multi-instancia)
├── Channels/
│   ├── ChannelManager.php
│   ├── Channel.php / PublicChannel.php / PrivateChannel.php / PresenceChannel.php
├── Connections/
│   ├── Connection.php
│   └── ConnectionManager.php          (+sendToUser, +sweep, +getUserIds)
├── Contracts/
│   ├── EventBus.php / NotificationChannel.php / NotificationRepository.php / RealtimeEvent.php / RedisConnection.php
├── Events/
│   ├── Broadcaster.php                (Realtime::to()->emit())
│   └── EventDispatcher.php
├── Notifications/
│   ├── Notification.php               (clase abstracta para notificaciones tipadas)
│   ├── NotificationManager.php        (despacha por canales database/realtime)
│   ├── NotificationService.php        (API de alto nivel sendToUser — recomendado)
│   ├── MySqlNotificationRepository.php
│   └── Channels/
│       ├── DatabaseChannel.php
│       └── RealtimeChannel.php
├── Support/
│   ├── RealtimeConfig.php
│   ├── RealtimeManager.php            (mode-aware: app vs server)
│   └── RedisClient.php                (cliente RESP nativo)
├── WebSocket/
│   ├── WebSocketServer.php            (Workerman; un solo Worker websocket://)
│   ├── MessageHandler.php             (subscribe/unsubscribe/authenticate/ping)
│   └── Heartbeat.php
├── Realtime.php                       (fachada estática: Realtime::broadcast(), Realtime::to()->emit())
└── (sin app REST — se integra desde tu app)
```

## Configuración (`config/realtime.php`, env)

Las variables se resuelven con prioridad `WEBSOCKET_*` y fallback a `REALTIME_*` (legacy).

```env
WEBSOCKET_ENABLED=true
WEBSOCKET_HOST=127.0.0.1
WEBSOCKET_PORT=8080
WEBSOCKET_MAX_CONNECTIONS=10000
WEBSOCKET_MAX_MESSAGE_SIZE=8192
WEBSOCKET_POLL_INTERVAL=1
WEBSOCKET_HEARTBEAT_INTERVAL=30
WEBSOCKET_CONNECTION_TIMEOUT=60
WEBSOCKET_APP_ID=apollo
WEBSOCKET_APP_KEY=app_apollo
WEBSOCKET_APP_SECRET=                # obligatorio para canales privados y Bearer en REST
WEBSOCKET_SSL_ENABLED=false
WEBSOCKET_SSL_LOCAL_CERT=
WEBSOCKET_SSL_LOCAL_PKEY=

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## Uso básico (PHP)

```php
// Fachada (canales, in-process)
Realtime::broadcast('orders', 'order.created', ['id' => 123]);
Realtime::to('orders')->emit('order.updated', ['id' => 2]);

// NotificationService (recomendado para notificaciones a usuarios)
$service = app(\Apollo\Core\Realtime\Notifications\NotificationService::class);
$record = $service->sendToUser(
    7,                              // user_id destinatario
    'ticket.created',
    [
        'title' => 'Nuevo ticket',
        'message' => 'Se creó el ticket #123',
        'data' => ['ticket_id' => 123],
    ]
);

// Notificación por clase (estilo DRF/Laravel)
\Apollo\Core\Realtime\Notifications\Notification::send($userId, new OrderShipped());
```

Persiste en `notifications` (migración 009). El servidor Workerman hace polling de la tabla y entrega vía WebSocket a todas las conexiones del `user_id`. Si el servidor está caído, la notificación queda persistida y se entrega al reconectar (high-water-mark por usuario en `runtime/realtime-delivery.json`).

## SDK JS (`public/js/realtime.js`)

```javascript
const realtime = new RealtimeClient({
    url: 'ws://localhost:8080',
    token: jwt,                          // autentica la conexión
    auth: (channel) => fetch('/api/realtime/auth', { /* … */ }).then(r => r.json()),
});

// API top-level (spec §9)
realtime.on('notification', (data) => console.log('🔔', data));

// API channel-based (canales public/private/presence)
realtime.channel('orders').listen('order.created', (data) => console.log(data));
realtime.presence('presence-chat.1').on('member.joined', (m) => console.log('entró', m));
```

## CLI

```bash
composer websocket                # php apollo realtime:start (foreground)
composer websocket:start-d        # daemon (Linux)
composer websocket:stop
composer websocket:restart
composer websocket:status
composer websocket:test           # health check (PHP, Workerman, Redis, BD, secret)
```

Standalone (sin apollo CLI):

```bash
php websocket/server.php start
php websocket/server.php start -d    # Linux
php websocket/server.php stop
php websocket/server.php status
```

## Drivers del bus

| Driver | Cuándo | Comunicación |
|---|---|---|
| `local` | desarrollo, hosting simple, una instancia | en memoria del proceso (sin Redis) |
| `redis` | multi-instancia/cluster | Redis Pub/Sub (`RedisEventBus`) |
| `auto` | recomendado | ping a Redis → `redis` si responde, si no `local` |

`Realtime::driver()` devuelve el driver efectivo.

## Activación en un proyecto

1. `composer install` (instala `workerman/workerman`).
2. Configurar env (`app_secret` al menos para canales privados).
3. Correr la migración de notificaciones: `php setup_database.php` (incluye 009).
4. Servidor: `php apollo realtime:start` (Workerman; sin extensión nativa).
5. **REST**: crea los endpoints en **tu propia app** siguiendo la guía de [`docs/websockets.md` §13](websockets.md#13-guía-de-integración-rest-de-notificaciones).

## Producción (WSS + Nginx)

Nginx hace terminación TLS y proxy a Workerman en `127.0.0.1:8080`. Cliente: `wss://midominio.com/ws`. Ver [`docs/websockets.md` §11 — Nginx + WSS](websockets.md#11-nginx--wss) para el bloque Nginx completo y el systemd unit.

## Limitaciones documentadas

- El servidor WebSocket es **single-process en Windows** (Workerman no soporta multi-Worker por archivo en Windows); `-d` daemon no funciona en Windows.
- En `local` driver, la entrega cross-process se hace por **polling server-side** de la tabla `notifications` (latencia = `WEBSOCKET_POLL_INTERVAL`, default 1s). Multi-instancia con Redis (true multi-process) es el paso 2 (requeriría `workerman/channel`).
- Presence se mantiene en memoria del proceso (presencia distribuida con Redis en fases posteriores).
- MySQL/SQLite solo persiste notificaciones; nunca es broker de eventos (el broker es Redis cuando se activa).
- WSS directo (sin Nginx) requiere `WEBSOCKET_SSL_ENABLED=true` + cert + key.

Para la guía completa (instalación, autenticación, protocolo, reconexión, troubleshooting, **guía de integración REST**), ver **`docs/websockets.md`**.
