# Módulo Realtime / WebSockets / Notificaciones

Módulo **opcional** del framework (inspirado en Laravel Reverb/Pusher):

- WebSockets vía **OpenSwoole** (requiere la extensión; si no está, `realtime:start` da error claro).
- **Redis opcional**: `REALTIME_DRIVER=auto|redis|local` con health check automático (nunca se asume instalado).
- Canales públicos, **privados** (ticket HMAC, nunca confiar en el cliente) y **presence** (miembros en memoria).
- Notificaciones desacopladas: canales `database` (persistencia MySQL/SQLite, migración 009) y `realtime`; futuras (email/push/webhook) sin tocar el núcleo.
- API simple: `Realtime::broadcast(...)`, `Realtime::to(...)->emit(...)`, `Notification::send(...)`.
- SDK JS en `public/js/realtime.js` (reconexión con backoff automática + heartbeat).
- REST opcional: app `apps/Realtime` (no registrada por defecto).

## Configuración (`config/realtime.php`, env)

```env
REALTIME_DRIVER=auto            # auto | redis | local
REALTIME_HOST=0.0.0.0
REALTIME_PORT=8080
REALTIME_HEARTBEAT_INTERVAL=30
REALTIME_CONNECTION_TIMEOUT=60
REALTIME_APP_ID=apollo
REALTIME_APP_KEY=app_apollo
REALTIME_APP_SECRET=            # obligatorio para canales privados
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## Uso básico

```php
// Backend — publicar un evento
Realtime::broadcast('orders', 'order.created', ['id' => 123]);

// o fluido
Realtime::to('orders')->emit('order.created', ['id' => 123]);

// Notificación (persistida + realtime)
\Apollo\Core\Realtime\Notifications\Notification::send($userId, new OrderShipped());
```

```javascript
// Frontend
const realtime = new RealtimeClient({
    url: 'ws://localhost:8080',
    key: 'app_apollo',
    auth: (channel) => fetch('/v1/realtime/auth', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ channel }),
        credentials: 'include',
    }).then((r) => r.json()),
});

realtime.channel('orders').listen('order.created', (data) => console.log(data));
realtime.presence('presence-chat.1').on('member.joined', (member) => console.log('entró', member));
```

## CLI

```bash
php apollo realtime:start      # requiere extension=openswoole
php apollo realtime:stop
php apollo realtime:restart
php apollo realtime:status
php apollo realtime:test       # health check (PHP/OpenSwoole/Redis/MySQL/driver)
```

## Drivers

| Driver | Cuándo | Comunicación |
|---|---|---|
| `local` | desarrollo, hosting simple, una instancia | en memoria del proceso (sin Redis) |
| `redis` | multi-instancia/cluster | Redis Pub/Sub (`RedisEventBus`) |
| `auto` | recomendado | ping a Redis → `redis` si responde, si no `local` |

`Realtime::driver()` devuelve el driver efectivo.

## Activación en un proyecto

1. Configurar env (`app_secret` al menos para privados).
2. Correr la migración de notificaciones: `php setup_database.php` (incluye 009).
3. Servidor: `php apollo realtime:start` (requiere OpenSwoole).
4. REST API opcional: añadir `'Realtime'` a `config/apps.php` (endpoints `/v1/events`, `/v1/realtime/auth`, `/v1/notifications`…).

## Instalar OpenSwoole (extensión, no paquete Composer)

OpenSwoole es una **extensión de PHP**, no un paquete Composer instalable vía `composer require`
(el paquete `openswoole/openswoole` en Packagist son stubs/IDE, no la extensión).

- **Linux/macOS:** `pecl install openswoole` (o docker con imágenes `openswoole/swoole`).
- **Windows: NO soportado** — `realtime:start` dará el error claro
  "Realtime server requires the OpenSwoole PHP extension." y `realtime:test` mostrará OpenSwoole ✗.
  Puedes desarrollar con el driver **local** (canales/eventos/notificaciones testeados sin servidor).
- Composer solo puede *sugerirlo*: `composer show -s` lo lista en `suggest` (`ext-openswoole`).
  El framework lo detecta en runtime (`extension_loaded('openswoole')`) — nunca asume instalado.

## Limitaciones documentadas

- El servidor WebSocket requiere la extensión `openswoole`.
- `local` NO comunica entre instancias del servidor (una sola).
- Presence se mantiene en memoria (presencia distribuida con Redis en fases posteriores).
- MySQL solo persiste notificaciones; nunca es broker de eventos.